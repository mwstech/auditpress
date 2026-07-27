<?php
/**
 * Removes every option the plugin created.
 *
 * @package PluginLens
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'pluginlens_enabled' );
delete_option( 'pluginlens_token' );
delete_transient( 'pluginlens_disk_footprint' );
