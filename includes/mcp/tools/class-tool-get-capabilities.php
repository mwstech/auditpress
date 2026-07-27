<?php
/**
 * The get_capabilities tool.
 *
 * @package PluginLens
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Describes what this server will be able to answer and what it will not.
 * Phase 0: content is a hardcoded orientation document; the round trip is
 * what matters.
 */
class PluginLens_Tool_Get_Capabilities {

	/**
	 * Registers the tool.
	 *
	 * @param PluginLens_Tool_Registry $registry Tool registry.
	 * @return void
	 */
	public static function register( $registry ) {
		$registry->register(
			'get_capabilities',
			'Describes what this PluginLens server can and cannot answer about the WordPress site it runs on. Call this first to orient yourself.',
			array(
				'type'       => 'object',
				'properties' => new stdClass(),
			),
			array( __CLASS__, 'run' )
		);
	}

	/**
	 * Runs the tool.
	 *
	 * @return string JSON string.
	 */
	public static function run() {
		$capabilities = array(
			'server'           => 'PluginLens',
			'version'          => PLUGINLENS_VERSION,
			'read_only'        => true,
			'description'      => 'A read-only MCP server for inspecting this WordPress site\'s plugin estate. It reports facts, never verdicts; analysis is the client\'s job.',
			'available_now'    => array(
				'get_capabilities'      => 'This orientation document.',
				'list_plugins'          => 'Paginated inventory of every installed plugin, mu-plugin, and drop-in: slug, name, version, status, update availability, and health flags (see flag_definitions). detail=true adds author, truncated description, requirements, auto-update setting, disk footprint, and raw wordpress.org figures: last updated, tested-up-to, active installs, rating, rating count, support threads, resolved ratio.',
				'get_site_overview'     => 'The site environment: WordPress, PHP, and database versions each with end-of-life support facts (cycle, EOL date, whether passed), theme, multisite status, object cache, debug mode, memory limits, cron state, plugin counts, and published post count.',
				'check_vulnerabilities' => 'Known published vulnerabilities matched against the plugin versions actually installed, plus WordPress core: CVE identifiers, CVSS score and severity as published, affected range, and fixed-in version. Version matches only, never slug matches.',
				'analyze_autoload'      => 'Autoloaded option weight per plugin with attribution confidence, the largest individual options, and an explicit unattributed bucket with its share of total bytes.',
				'analyze_cron'          => 'Scheduled events grouped by owning plugin, WP-Cron state, and orphaned hooks with no registered callback (with the conditional-registration caveat stated).',
				'analyze_database'      => 'Non-core tables with approximate row counts and sizes, attributed to plugins with confidence; orphaned tables listed separately. Tables outside the WordPress prefix are invisible.',
			),
			'attribution_note' => 'Attribution confidence: high = curated slug-to-prefix mapping; medium = prefix derived mechanically from the plugin slug; anything else is reported unattributed rather than guessed. Known limits: cron orphans can be false positives when a plugin registers callbacks conditionally, and database tables that do not use the WordPress table prefix are not visible.',
			'tool_list_note'   => 'If a tool listed in available_now does not appear in your tool list, your tool list is stale; refresh it by reconnecting to this server.',
			'flag_definitions' => array(
				'has_vulnerability'   => 'At least one published vulnerability record whose affected version range includes the installed version.',
				'closed_on_wporg'     => 'wordpress.org explicitly reports this plugin closed (its API returns closed:true, usually with a date and reason).',
				'no_wporg_record'     => 'wordpress.org returned "not found" for this slug. Could be premium, custom, renamed, or removed; these are not distinguishable, so no stronger claim is made.',
				'not_updated_2y'      => 'Last wordpress.org update more than 730 days ago (and not more than 1460; see not_updated_4y).',
				'not_updated_4y'      => 'Last wordpress.org update more than 1460 days ago. Implies not_updated_2y; only this stronger flag is emitted.',
				'untested_current_wp' => 'The tested-up-to value resolves to a WordPress release cycle three or more positions behind the newest cycle on endoflife.date\'s wordpress release list.',
				'requires_newer_php'  => 'The plugin header requires a PHP version newer than the one running.',
				'requires_newer_wp'   => 'The plugin header requires a WordPress version newer than the one running.',
				'network_active'      => 'Active network-wide on a multisite.',
				'single_file'         => 'A single-file plugin living directly in the plugins directory.',
				'mu_plugin'           => 'A must-use plugin; always active, never on the plugins screen.',
				'dropin'              => 'A drop-in (e.g. object-cache.php) occupying a WordPress override slot.',
			),
			'planned_tools'    => array(
				'get_plugin_details' => 'Deep record for up to five named plugins: inventory, wordpress.org data, vulnerabilities, autoload, cron, tables, usage.',
				'analyze_usage'      => 'Shortcodes, blocks, and custom post types per plugin with real usage counts in content.',
			),
			'does_not_measure' => array(
				'per_plugin_runtime_cost' => 'Per-plugin execution time cannot be measured without a profiler and is never reported.',
				'front_end_asset_weight'  => 'Front-end asset attribution is not measured.',
				'write_operations'        => 'This server performs no write operation of any kind against the site.',
			),
		);

		return PluginLens_Tool_Registry::with_meta( $capabilities, 1, 1, false );
	}
}
