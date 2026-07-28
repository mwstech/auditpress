<?php
/**
 * Plugin Name: AuditPress
 * Plugin URI: https://www.macronimous.com/free-tools/auditpress/
 * Description: Turns this site into a read-only MCP server so AI clients can inspect and reason about its plugin estate.
 * Version: 1.0.0
 * Author: Macronimous Web Solutions
 * Author URI: https://www.macronimous.com/
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: auditpress
 * Requires at least: 6.0
 * Requires PHP: 7.4
 *
 * Multisite note: v1 operates on the individual site it runs on and is not
 * network-tested. On multisite, managing AuditPress requires a network admin
 * (manage_network_options). See docs/DECISIONS.md 35.
 *
 * @package AuditPress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AUDITPRESS_VERSION', '1.0.0' );
define( 'AUDITPRESS_PLUGIN_FILE', __FILE__ );
define( 'AUDITPRESS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once AUDITPRESS_PLUGIN_DIR . 'includes/class-plugin.php';

/**
 * Flags a fresh activation so the admin gets one orienting notice.
 *
 * The plugin deliberately does nothing until an administrator enables the
 * endpoint, which without this notice reads as a broken install.
 *
 * @return void
 */
function auditpress_on_activation() {
	add_option( 'auditpress_show_activation_notice', '1', '', false );
}
register_activation_hook( __FILE__, 'auditpress_on_activation' );

AuditPress_Plugin::instance();
