<?php
/**
 * Autoload collector: which options load on every request, and how heavy.
 *
 * Reads the options table directly. Never selects option values, only names
 * and lengths. Makes no network calls.
 *
 * @package AuditPress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Collects autoloaded option names and sizes.
 */
class AuditPress_Autoload_Collector {

	/**
	 * Maximum autoloaded options examined in one pass.
	 */
	const MAX_OPTIONS = 5000;

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

		// Names and sizes only, never values, and row-capped so a pathological
		// options table cannot exhaust memory. The cap is far above any real
		// site; heaviest options come first so a truncated view is still the
		// useful one.
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only aggregate over option metadata; caching belongs to callers.
			$wpdb->prepare(
				"SELECT option_name, LENGTH(option_value) AS size FROM {$wpdb->options} WHERE autoload IN ('yes','on','auto','auto-on') ORDER BY LENGTH(option_value) DESC LIMIT %d",
				self::MAX_OPTIONS
			),
			ARRAY_A
		);

		$options = array();
		foreach ( (array) $rows as $row ) {
			$options[ (string) $row['option_name'] ] = (int) $row['size'];
		}
		arsort( $options );
		return $options;
	}
}
