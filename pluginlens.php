<?php
/**
 * Plugin Name: PluginLens
 * Plugin URI: https://www.macronimous.com/free-tools/pluginlens/
 * Description: Turns this site into a read-only MCP server so AI clients can inspect and reason about its plugin estate.
 * Version: 0.1.0
 * Author: Macronimous Web Solutions
 * Author URI: https://www.macronimous.com/
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: pluginlens
 * Requires at least: 6.0
 * Requires PHP: 7.4
 *
 * @package PluginLens
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PLUGINLENS_VERSION', '0.1.0' );
define( 'PLUGINLENS_PLUGIN_FILE', __FILE__ );
define( 'PLUGINLENS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once PLUGINLENS_PLUGIN_DIR . 'includes/class-plugin.php';

PluginLens_Plugin::instance();
