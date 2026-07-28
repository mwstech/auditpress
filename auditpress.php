<?php
/**
 * Plugin Name: AuditPress
 * Plugin URI: https://www.macronimous.com/free-tools/auditpress/
 * Description: Turns this site into a read-only MCP server so AI clients can inspect and reason about its plugin estate.
 * Version: 0.1.0
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

define( 'AUDITPRESS_VERSION', '0.1.0' );
define( 'AUDITPRESS_PLUGIN_FILE', __FILE__ );
define( 'AUDITPRESS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once AUDITPRESS_PLUGIN_DIR . 'includes/class-plugin.php';

AuditPress_Plugin::instance();
