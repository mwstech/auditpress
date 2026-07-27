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
delete_option( 'pluginlens_enrich_store' );
delete_option( 'pluginlens_auth_log' );
delete_transient( 'pluginlens_disk_footprint' );
delete_transient( 'pluginlens_enrich_eol_php' );
delete_transient( 'pluginlens_enrich_eol_wordpress' );
delete_transient( 'pluginlens_enrich_eol_mysql' );
delete_transient( 'pluginlens_enrich_eol_mariadb' );
delete_transient( 'pluginlens_rate_buckets' );
