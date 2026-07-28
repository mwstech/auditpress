<?php
/**
 * Database collector: non-core tables under the site's table prefix.
 *
 * Reads SHOW TABLE STATUS only. Makes no network calls.
 *
 * @package AuditPress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Collects non-core table names and sizes.
 */
class AuditPress_Database_Collector {

	/**
	 * Core tables, excluded by explicit allowlist — never by pattern
	 * guessing. Includes the multisite set, which differs from single-site.
	 *
	 * @var string[]
	 */
	const CORE_TABLES = array(
		'posts',
		'postmeta',
		'options',
		'comments',
		'commentmeta',
		'terms',
		'term_taxonomy',
		'term_relationships',
		'termmeta',
		'users',
		'usermeta',
		'links',
		// Multisite.
		'blogs',
		'blogmeta',
		'blog_versions',
		'site',
		'sitemeta',
		'signups',
		'registration_log',
	);

	/**
	 * Non-core tables under the site prefix.
	 *
	 * Row counts come from SHOW TABLE STATUS, which estimates for InnoDB.
	 * They are labeled approximate downstream; running COUNT(*) on unknown,
	 * possibly huge tables is not worth exactness.
	 *
	 * Tables that do not use the WordPress prefix are invisible here — a
	 * stated limitation, not a bug to fix with guesswork.
	 *
	 * @return array[] Rows of {name, stripped_name, rows_approx, data_bytes, index_bytes}.
	 */
	public function collect() {
		global $wpdb;

		$status = $wpdb->get_results( 'SHOW TABLE STATUS', ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only metadata query.
		$prefix = $wpdb->prefix;

		$tables = array();
		foreach ( (array) $status as $row ) {
			$name = isset( $row['Name'] ) ? (string) $row['Name'] : '';
			if ( '' === $name || 0 !== strpos( $name, $prefix ) ) {
				continue;
			}
			$stripped = substr( $name, strlen( $prefix ) );

			// Multisite sub-site tables look like {prefix}{blog_id}_{core}.
			$normalized = preg_replace( '/^\d+_/', '', $stripped );
			if ( in_array( $normalized, self::CORE_TABLES, true ) ) {
				continue;
			}

			$tables[] = array(
				'name'          => $name,
				'stripped_name' => $stripped,
				'rows_approx'   => isset( $row['Rows'] ) ? (int) $row['Rows'] : null,
				'data_bytes'    => isset( $row['Data_length'] ) ? (int) $row['Data_length'] : null,
				'index_bytes'   => isset( $row['Index_length'] ) ? (int) $row['Index_length'] : null,
			);
		}
		return $tables;
	}
}
