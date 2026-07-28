<?php
/**
 * Removes every option the plugin created.
 *
 * @package AuditPress
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'auditpress_enabled' );
delete_option( 'auditpress_token' );
delete_option( 'auditpress_enrich_store' );
delete_option( 'auditpress_auth_log' );
delete_option( 'auditpress_show_activation_notice' );
delete_transient( 'auditpress_disk_footprint' );
delete_transient( 'auditpress_enrich_eol_php' );
delete_transient( 'auditpress_enrich_eol_wordpress' );
delete_transient( 'auditpress_enrich_eol_mysql' );
delete_transient( 'auditpress_enrich_eol_mariadb' );
delete_transient( 'auditpress_rate_buckets' );
