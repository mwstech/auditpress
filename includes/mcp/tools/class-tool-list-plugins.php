<?php
/**
 * The list_plugins tool.
 *
 * @package PluginLens
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Paginated plugin inventory. Compact rows by default, detail on request.
 * This phase carries only flags knowable without network access.
 */
class PluginLens_Tool_List_Plugins {

	const DEFAULT_LIMIT = 25;
	const MAX_LIMIT     = 100;

	// Detail mode is for looking closely at a handful of plugins, not for
	// dumping the estate; its lower cap is what keeps Phase 3 enrichment
	// inside the 20 KB budget (docs/DECISIONS.md 17).
	const MAX_DETAIL_LIMIT = 10;

	/**
	 * Registers the tool.
	 *
	 * @param PluginLens_Tool_Registry $registry Tool registry.
	 * @return void
	 */
	public static function register( $registry ) {
		$registry->register(
			'list_plugins',
			'Returns the installed plugin inventory of this WordPress site: for each plugin its slug, name, version, active/inactive/mu/dropin status, whether an update is available, and offline health flags such as single_file or requires_newer_php. Paginated (default 25, max 100 rows). Pass detail=true for author, description, requirements, auto-update setting, and disk footprint per plugin; detail mode is for close inspection and caps at 10 rows per page.',
			array(
				'type'       => 'object',
				'properties' => array(
					'status'     => array(
						'type'        => 'string',
						'enum'        => array( 'all', 'active', 'inactive', 'mu', 'dropin' ),
						'default'     => 'all',
						'description' => 'Filter by plugin status.',
					),
					'has_update' => array(
						'type'        => 'boolean',
						'description' => 'When true, only plugins with an available update; when false, only plugins without one.',
					),
					'limit'      => array(
						'type'        => 'integer',
						'default'     => self::DEFAULT_LIMIT,
						'minimum'     => 1,
						'maximum'     => self::MAX_LIMIT,
						'description' => 'Rows per page, capped server-side: 100 for compact rows, 10 when detail=true.',
					),
					'offset'     => array(
						'type'        => 'integer',
						'default'     => 0,
						'minimum'     => 0,
						'description' => 'Rows to skip, for pagination.',
					),
					'detail'     => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'Include expanded fields per plugin.',
					),
				),
			),
			array( __CLASS__, 'run' )
		);
	}

	/**
	 * Runs the tool.
	 *
	 * @param array $args Tool arguments.
	 * @return string JSON string.
	 */
	public static function run( $args ) {
		$status     = isset( $args['status'] ) ? (string) $args['status'] : 'all';
		$has_update = isset( $args['has_update'] ) ? (bool) $args['has_update'] : null;
		$detail     = ! empty( $args['detail'] );
		$max_limit  = $detail ? self::MAX_DETAIL_LIMIT : self::MAX_LIMIT;
		$limit      = isset( $args['limit'] ) ? (int) $args['limit'] : min( self::DEFAULT_LIMIT, $max_limit );
		$limit      = max( 1, min( $max_limit, $limit ) );
		$offset     = isset( $args['offset'] ) ? max( 0, (int) $args['offset'] ) : 0;

		$collector = new PluginLens_Inventory_Collector();
		$records   = $collector->collect();

		$records = array_values(
			array_filter(
				$records,
				function ( $record ) use ( $status, $has_update ) {
					if ( 'all' !== $status && $record['status'] !== $status ) {
						return false;
					}
					if ( null !== $has_update && $record['update_available'] !== $has_update ) {
						return false;
					}
					return true;
				}
			)
		);

		$total = count( $records );
		$page  = array_slice( $records, $offset, $limit );

		$rows = array();
		foreach ( $page as $record ) {
			$rows[] = self::row( $record, $detail );
		}

		$payload = array(
			'plugins' => $rows,
			'counts'  => self::status_counts( $collector ),
			'limit'   => $limit,
			'offset'  => $offset,
		);

		return PluginLens_Tool_Registry::with_meta(
			$payload,
			$total,
			count( $rows ),
			( $offset + count( $rows ) ) < $total
		);
	}

	/**
	 * Overall status counts, independent of the active filter, so the client
	 * always sees the shape of the whole site.
	 *
	 * @param PluginLens_Inventory_Collector $collector Collector (reuses its cache).
	 * @return array
	 */
	private static function status_counts( $collector ) {
		$counts = array(
			'active'   => 0,
			'inactive' => 0,
			'mu'       => 0,
			'dropin'   => 0,
		);
		foreach ( $collector->collect() as $record ) {
			if ( isset( $counts[ $record['status'] ] ) ) {
				++$counts[ $record['status'] ];
			}
		}
		return $counts;
	}

	/**
	 * Builds one response row.
	 *
	 * @param array $record Inventory record.
	 * @param bool  $detail Whether to include expanded fields.
	 * @return array
	 */
	private static function row( $record, $detail ) {
		$row = array(
			'slug'             => $record['slug'],
			'name'             => $record['name'],
			'version'          => $record['version'],
			'status'           => $record['status'],
			'update_available' => $record['update_available'],
			'latest_version'   => $record['latest_version'],
			'flags'            => self::flags( $record ),
		);

		if ( $detail ) {
			$row['author']      = $record['author'];
			$row['description'] = self::truncate( $record['description'], 200 );
			$row['plugin_uri']  = $record['plugin_uri'];
			// The text domain almost always equals the slug; only the
			// exceptions are worth bytes.
			if ( $record['text_domain'] !== $record['slug'] ) {
				$row['text_domain'] = $record['text_domain'];
			}
			$row['requires_wp']  = $record['requires_wp'];
			$row['requires_php'] = $record['requires_php'];
			if ( $record['auto_update'] ) {
				$row['auto_update'] = true;
			}
			$row['disk_size']  = PluginLens_Tool_Registry::format_bytes( $record['disk_size'] );
			$row['file_count'] = $record['file_count'];
		}

		// Null, empty-string, and empty-array fields carry no information;
		// omitting them keeps a full-site detail response inside the 20 KB
		// budget.
		return array_filter(
			$row,
			function ( $value ) {
				return null !== $value && '' !== $value && array() !== $value;
			}
		);
	}

	/**
	 * Offline-only health flags for a record.
	 *
	 * @param array $record Inventory record.
	 * @return string[]
	 */
	private static function flags( $record ) {
		$flags = array();

		if ( $record['network_active'] ) {
			$flags[] = 'network_active';
		}
		if ( 'mu' === $record['status'] ) {
			$flags[] = 'mu_plugin';
		} elseif ( 'dropin' === $record['status'] ) {
			$flags[] = 'dropin';
		} elseif ( $record['single_file'] ) {
			$flags[] = 'single_file';
		}
		if ( '' !== $record['requires_php'] && version_compare( PHP_VERSION, $record['requires_php'], '<' ) ) {
			$flags[] = 'requires_newer_php';
		}
		if ( '' !== $record['requires_wp'] && version_compare( get_bloginfo( 'version' ), $record['requires_wp'], '<' ) ) {
			$flags[] = 'requires_newer_wp';
		}

		return $flags;
	}

	/**
	 * Truncates free text. Descriptions are capped at 200 characters, no
	 * exceptions (SPEC section 10).
	 *
	 * @param string $text Text to truncate.
	 * @param int    $max  Maximum characters.
	 * @return string
	 */
	private static function truncate( $text, $max ) {
		if ( function_exists( 'mb_substr' ) && function_exists( 'mb_strlen' ) ) {
			return mb_strlen( $text ) > $max ? mb_substr( $text, 0, $max - 1 ) . '…' : $text;
		}
		return strlen( $text ) > $max ? substr( $text, 0, $max - 1 ) . '…' : $text;
	}
}
