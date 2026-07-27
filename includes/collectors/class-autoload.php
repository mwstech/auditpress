<?php
/**
 * Autoload collector: which options load on every request, and how heavy.
 *
 * Reads the options table directly. Never selects option values, only names
 * and lengths. Makes no network calls.
 *
 * @package PluginLens
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Collects autoloaded option names and sizes.
 */
class PluginLens_Autoload_Collector {

	/**
	 * Autoloaded options as name => byte size.
	 *
	 * WordPress 6.6 changed the autoload column values; matching both the old
	 * ('yes') and new ('on', 'auto', 'auto-on') sets is what keeps this
	 * working on either schema. Getting it wrong silently returns nothing on
	 * modern sites.
	 *
	 * @return array<string, int>
	 */
	public function collect() {
		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only aggregate over option metadata; caching belongs to callers.
			"SELECT option_name, LENGTH(option_value) AS size FROM {$wpdb->options} WHERE autoload IN ('yes','on','auto','auto-on')",
			ARRAY_A
		);

		$options = array();
		foreach ( (array) $rows as $row ) {
			$options[ (string) $row['option_name'] ] = (int) $row['size'];
		}
		arsort( $options );
		return $options;
	}

	/**
	 * The distinct autoload column values actually present, for verification
	 * and honest reporting about which schema this site runs.
	 *
	 * @return string[]
	 */
	public function autoload_values_in_use() {
		global $wpdb;

		$values = $wpdb->get_col( "SELECT DISTINCT autoload FROM {$wpdb->options}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only, tiny.
		return array_map( 'strval', (array) $values );
	}
}
