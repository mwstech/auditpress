<?php
/**
 * Cron collector: every scheduled event and whether its hook still has a
 * listener.
 *
 * Reads the cron array directly. Makes no network calls.
 *
 * @package AuditPress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Collects scheduled events with runtime callback presence.
 */
class AuditPress_Cron_Collector {

	/**
	 * Scheduled events, one row per (hook, occurrence).
	 *
	 * has_callback is checked at runtime with every active plugin loaded,
	 * which is what makes absence a meaningful orphan signal. It is still not
	 * certain: a plugin registering its callback conditionally (admin-only,
	 * setting-dependent) produces a false orphan. Callers must present that
	 * caveat, not bury it.
	 *
	 * @return array[]
	 */
	public function collect() {
		$cron = function_exists( '_get_cron_array' ) ? _get_cron_array() : array();
		if ( ! is_array( $cron ) ) {
			$cron = array();
		}

		$events = array();
		foreach ( $cron as $timestamp => $hooks ) {
			if ( ! is_array( $hooks ) ) {
				continue; // The 'version' key rides along in the cron array.
			}
			foreach ( $hooks as $hook => $instances ) {
				foreach ( (array) $instances as $instance ) {
					$events[] = array(
						'hook'         => (string) $hook,
						'schedule'     => isset( $instance['schedule'] ) && $instance['schedule'] ? (string) $instance['schedule'] : 'single',
						'next_run'     => gmdate( 'Y-m-d\TH:i:s\Z', (int) $timestamp ),
						'has_callback' => false !== has_action( $hook ),
					);
				}
			}
		}
		return $events;
	}
}
