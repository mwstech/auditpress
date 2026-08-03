<?php
/**
 * Attribution engine: maps option names, table names, and cron hooks back to
 * the plugin that most plausibly owns them.
 *
 * This is a heuristic and cannot be made perfect. The failure mode to avoid
 * is confident inaccuracy: a wrong owner is worse than no owner
 * (docs/DECISIONS.md 30). Every result carries its confidence tier and
 * everything unmatched stays visibly unattributed.
 *
 * @package Auditra
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Three tiers, tried in order: curated override (high), slug-derived prefix
 * (medium), no match (unattributed).
 */
class Auditra_Attribution {

	const CORE_SLUG = 'wordpress-core';

	/**
	 * Generic words that must never become a derived prefix on their own.
	 * "wp_" from wp-fastest-cache would swallow half the options table.
	 *
	 * @var string[]
	 */
	const GENERIC_WORDS = array( 'wp', 'all', 'my', 'the', 'a', 'core', 'plugin', 'site', 'easy', 'simple', 'best', 'super', 'smart', 'google', 'really' );

	/**
	 * Core autoloaded options, matched exactly. An allowlist, not a pattern
	 * guess. Not exhaustive; anything core-owned that is missing simply lands
	 * in unattributed, which is the honest direction to fail in.
	 *
	 * @var string[]
	 */
	const CORE_OPTIONS = array(
		'siteurl',
		'home',
		'blogname',
		'blogdescription',
		'users_can_register',
		'admin_email',
		'start_of_week',
		'use_balanceTags',
		'use_smilies',
		'require_name_email',
		'comments_notify',
		'posts_per_rss',
		'rss_use_excerpt',
		'mailserver_url',
		'mailserver_login',
		'mailserver_pass',
		'mailserver_port',
		'default_category',
		'default_comment_status',
		'default_ping_status',
		'default_pingback_flag',
		'posts_per_page',
		'date_format',
		'time_format',
		'links_updated_date_format',
		'comment_moderation',
		'moderation_notify',
		'permalink_structure',
		'rewrite_rules',
		'hack_file',
		'blog_charset',
		'moderation_keys',
		'active_plugins',
		'category_base',
		'ping_sites',
		'comment_max_links',
		'gmt_offset',
		'default_email_category',
		'recently_edited',
		'template',
		'stylesheet',
		'comment_registration',
		'html_type',
		'use_trackback',
		'default_role',
		'db_version',
		'uploads_use_yearmonth_folders',
		'upload_path',
		'blog_public',
		'default_link_category',
		'show_on_front',
		'tag_base',
		'show_avatars',
		'avatar_rating',
		'upload_url_path',
		'thumbnail_size_w',
		'thumbnail_size_h',
		'thumbnail_crop',
		'medium_size_w',
		'medium_size_h',
		'avatar_default',
		'large_size_w',
		'large_size_h',
		'image_default_link_type',
		'image_default_size',
		'image_default_align',
		'close_comments_for_old_posts',
		'close_comments_days_old',
		'thread_comments',
		'thread_comments_depth',
		'page_comments',
		'comments_per_page',
		'default_comments_page',
		'comment_order',
		'sticky_posts',
		'widget_categories',
		'widget_text',
		'widget_rss',
		'uninstall_plugins',
		'timezone_string',
		'page_for_posts',
		'page_on_front',
		'default_post_format',
		'link_manager_enabled',
		'finished_splitting_shared_terms',
		'site_icon',
		'medium_large_size_w',
		'medium_large_size_h',
		'wp_page_for_privacy_policy',
		'show_comments_cookies_opt_in',
		'admin_email_lifespan',
		'disallowed_keys',
		'comment_previously_approved',
		'auto_plugin_theme_update_emails',
		'auto_update_core_dev',
		'auto_update_core_minor',
		'auto_update_core_major',
		'wp_force_deactivated_plugins',
		'wp_attachment_pages_enabled',
		'initial_db_version',
		'wp_user_roles',
		'fresh_site',
		'user_count',
		'WPLANG',
		'blog_upload_space',
		'current_theme',
		'theme_switched',
		'https_detection_errors',
		'fileupload_url',
		'nonce_key',
		'nonce_salt',
		'auth_key',
		'auth_salt',
		'logged_in_key',
		'logged_in_salt',
		'cron',
		'nav_menu_options',
		'wp_calendar_block_has_published_posts',
		// Core-owned transient inner names (matched after wrapper stripping).
		'wp_core_block_css_files',
		'doing_cron',
		'update_core',
		'update_plugins',
		'update_themes',
		'theme_roots',
		'wp_theme_files_patterns',
		'health-check-site-status-result',
	);

	/**
	 * Core-owned prefixes safe to claim: no plugin may use these.
	 *
	 * @var string[]
	 */
	const CORE_PREFIXES = array( 'theme_mods_', 'widget_', 'sidebars_widgets', '_split_terms', 'category_children', 'db_upgraded', 'can_compress_scripts' );

	/**
	 * Core cron hooks, matched exactly.
	 *
	 * @var string[]
	 */
	const CORE_CRON_HOOKS = array(
		'wp_version_check',
		'wp_update_plugins',
		'wp_update_themes',
		'wp_scheduled_delete',
		'wp_scheduled_auto_draft_delete',
		'delete_expired_transients',
		'wp_privacy_delete_old_export_files',
		'recovery_mode_clean_expired_keys',
		'wp_site_health_scheduled_check',
		'wp_https_detection',
		'wp_update_user_counts',
		'wp_delete_temp_updater_backups',
		'upgrader_scheduled_cleanup',
		'importer_scheduled_cleanup',
		'publish_future_post',
		'do_pings',
		'wp_maybe_auto_update',
	);

	/**
	 * Prefix => array{slug, confidence}, longest prefixes first.
	 *
	 * @var array<string, array{slug: string, confidence: string}>|null
	 */
	private $prefix_map = null;

	/**
	 * Installed slugs, for orphan detection.
	 *
	 * @var string[]
	 */
	private $installed_slugs;

	/**
	 * Constructor.
	 *
	 * @param string[] $installed_slugs Slugs of every installed plugin.
	 */
	public function __construct( $installed_slugs ) {
		$this->installed_slugs = $installed_slugs;
	}

	/**
	 * Attributes one identifier (option name, cron hook, or table name minus
	 * the site prefix).
	 *
	 * @param string $name    Identifier.
	 * @param string $context 'option' or 'hook'. Options get core matching.
	 * @return array{slug: string, confidence: string}|null Null when unattributed.
	 */
	public function attribute( $name, $context = 'option' ) {
		if ( 'option' === $context ) {
			// Transients belong to whoever named their inner key.
			foreach ( array( '_site_transient_timeout_', '_site_transient_', '_transient_timeout_', '_transient_' ) as $wrapper ) {
				if ( 0 === strpos( $name, $wrapper ) ) {
					$name = substr( $name, strlen( $wrapper ) );
					break;
				}
			}
			// A few core options embed the site's table prefix, which is only
			// "wp_" on default installs ({prefix}user_roles on live sites with
			// custom prefixes).
			global $wpdb;
			if ( isset( $wpdb->prefix ) && 0 === strpos( $name, $wpdb->prefix ) ) {
				$unprefixed = substr( $name, strlen( $wpdb->prefix ) );
				if ( in_array( $unprefixed, array( 'user_roles', 'dashboard_quick_press_last_post_id' ), true ) ) {
					return array(
						'slug'       => self::CORE_SLUG,
						'confidence' => 'high',
					);
				}
			}

			if ( in_array( $name, self::CORE_OPTIONS, true ) ) {
				return array(
					'slug'       => self::CORE_SLUG,
					'confidence' => 'high',
				);
			}
			foreach ( self::CORE_PREFIXES as $prefix ) {
				if ( 0 === strpos( $name, $prefix ) ) {
					return array(
						'slug'       => self::CORE_SLUG,
						'confidence' => 'high',
					);
				}
			}
		}

		if ( 'hook' === $context && in_array( $name, self::CORE_CRON_HOOKS, true ) ) {
			return array(
				'slug'       => self::CORE_SLUG,
				'confidence' => 'high',
			);
		}

		$best = null;
		foreach ( $this->prefix_map() as $prefix => $owner ) {
			if ( 0 === strpos( $name, $prefix ) ) {
				$best = $owner;
				break; // Map is ordered longest-first; first hit wins.
			}
		}
		return $best;
	}

	/**
	 * Whether a slug belongs to an installed plugin (or core).
	 *
	 * @param string $slug Slug.
	 * @return bool
	 */
	public function is_installed( $slug ) {
		return self::CORE_SLUG === $slug || in_array( $slug, $this->installed_slugs, true );
	}

	/**
	 * Builds the prefix map: curated overrides (high) shadow derived prefixes
	 * (medium), longer prefixes shadow shorter ones.
	 *
	 * @return array<string, array{slug: string, confidence: string}>
	 */
	private function prefix_map() {
		if ( null !== $this->prefix_map ) {
			return $this->prefix_map;
		}

		$map = array();

		// Tier medium first, so tier high overwrites on collision.
		foreach ( $this->installed_slugs as $slug ) {
			foreach ( $this->derived_prefixes( $slug ) as $prefix ) {
				$map[ $prefix ] = array(
					'slug'       => $slug,
					'confidence' => 'medium',
				);
			}
		}

		foreach ( $this->overrides() as $slug => $prefixes ) {
			foreach ( (array) $prefixes as $prefix ) {
				$map[ (string) $prefix ] = array(
					'slug'       => $slug,
					'confidence' => 'high',
				);
			}
		}

		uksort(
			$map,
			function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			}
		);

		$this->prefix_map = $map;
		return $map;
	}

	/**
	 * Prefixes derivable from a slug: full slug underscored, first word,
	 * initialism. Generic and too-short candidates are discarded rather than
	 * risked (docs/DECISIONS.md 30).
	 *
	 * @param string $slug Plugin slug.
	 * @return string[]
	 */
	private function derived_prefixes( $slug ) {
		$words      = explode( '-', $slug );
		$candidates = array( str_replace( '-', '_', $slug ) );

		if ( count( $words ) > 1 ) {
			$candidates[] = $words[0];
			$candidates[] = implode(
				'',
				array_map(
					function ( $word ) {
						return substr( $word, 0, 1 );
					},
					$words
				)
			);
		}

		$prefixes = array();
		foreach ( $candidates as $candidate ) {
			if ( strlen( $candidate ) < 3 || in_array( strtolower( $candidate ), self::GENERIC_WORDS, true ) ) {
				continue;
			}
			$prefixes[] = $candidate . '_';
		}
		return $prefixes;
	}

	/**
	 * Loads the curated overrides file.
	 *
	 * @return array<string, string[]>
	 */
	private function overrides() {
		$path = AUDITRA_PLUGIN_DIR . 'includes/data/prefix-overrides.json';
		if ( ! is_readable( $path ) ) {
			return array();
		}
		$decoded = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local bundled data file, not a remote resource.
		return is_array( $decoded ) ? $decoded : array();
	}
}
