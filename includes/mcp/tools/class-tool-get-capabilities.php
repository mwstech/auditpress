<?php
/**
 * The get_capabilities tool.
 *
 * @package PluginLens
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Describes what this server will be able to answer and what it will not.
 * Phase 0: content is a hardcoded orientation document; the round trip is
 * what matters.
 */
class PluginLens_Tool_Get_Capabilities {

	/**
	 * Registers the tool.
	 *
	 * @param PluginLens_Tool_Registry $registry Tool registry.
	 * @return void
	 */
	public static function register( $registry ) {
		$registry->register(
			'get_capabilities',
			'Describes what this PluginLens server can and cannot answer about the WordPress site it runs on. Call this first to orient yourself.',
			array(
				'type'       => 'object',
				'properties' => new stdClass(),
			),
			array( __CLASS__, 'run' )
		);
	}

	/**
	 * Runs the tool.
	 *
	 * @return string JSON string.
	 */
	public static function run() {
		$capabilities = array(
			'server'           => 'PluginLens',
			'version'          => PLUGINLENS_VERSION,
			'read_only'        => true,
			'description'      => 'A read-only MCP server for inspecting this WordPress site\'s plugin estate. It reports facts, never verdicts; analysis is the client\'s job.',
			'planned_tools'    => array(
				'get_site_overview'     => 'WordPress, PHP, and database versions, active theme, multisite status, plugin counts, with end-of-life support status.',
				'list_plugins'          => 'Inventory of all plugins with status, versions, updates, and health flags such as abandoned or closed on wordpress.org.',
				'get_plugin_details'    => 'Deep record for up to five named plugins: inventory, wordpress.org data, vulnerabilities, autoload, cron, tables, usage.',
				'check_vulnerabilities' => 'Known vulnerabilities matched against installed plugin versions.',
				'analyze_autoload'      => 'Autoloaded option weight attributed per plugin, with confidence levels.',
				'analyze_cron'          => 'Scheduled events per plugin, plus orphaned hooks with no registered callback.',
				'analyze_database'      => 'Non-core tables with sizes, attributed to plugins, plus orphaned tables.',
				'analyze_usage'         => 'Shortcodes, blocks, and custom post types per plugin with real usage counts in content.',
			),
			'available_now'    => array( 'get_capabilities' ),
			'does_not_measure' => array(
				'per_plugin_runtime_cost' => 'Per-plugin execution time cannot be measured without a profiler and is never reported.',
				'front_end_asset_weight'  => 'Front-end asset attribution is not measured.',
				'write_operations'        => 'This server performs no write operation of any kind against the site.',
			),
		);

		return wp_json_encode( $capabilities );
	}
}
