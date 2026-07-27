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
			'available_now'    => array(
				'get_capabilities'      => 'This orientation document.',
				'list_plugins'          => 'Paginated inventory of every installed plugin, mu-plugin, and drop-in: slug, name, version, status, update availability, and offline health flags. detail=true adds author, truncated description, requirements, auto-update setting, disk size, and file count. No network data yet: wordpress.org health flags and vulnerability data arrive in later phases.',
				'get_site_overview'     => 'The site environment: WordPress, PHP, and database versions each with end-of-life support facts (cycle, EOL date, whether passed), theme, multisite status, object cache, debug mode, memory limits, cron state, plugin counts, and published post count.',
				'check_vulnerabilities' => 'Known published vulnerabilities matched against the plugin versions actually installed, plus WordPress core: CVE identifiers, CVSS score and severity as published, affected range, and fixed-in version. Version matches only, never slug matches.',
			),
			'tool_list_note'   => 'If a tool listed in available_now does not appear in your tool list, your tool list is stale; refresh it by reconnecting to this server.',
			'planned_tools'    => array(
				'get_plugin_details' => 'Deep record for up to five named plugins: inventory, wordpress.org data, vulnerabilities, autoload, cron, tables, usage.',
				'analyze_autoload'   => 'Autoloaded option weight attributed per plugin, with confidence levels.',
				'analyze_cron'       => 'Scheduled events per plugin, plus orphaned hooks with no registered callback.',
				'analyze_database'   => 'Non-core tables with sizes, attributed to plugins, plus orphaned tables.',
				'analyze_usage'      => 'Shortcodes, blocks, and custom post types per plugin with real usage counts in content.',
			),
			'does_not_measure' => array(
				'per_plugin_runtime_cost' => 'Per-plugin execution time cannot be measured without a profiler and is never reported.',
				'front_end_asset_weight'  => 'Front-end asset attribution is not measured.',
				'write_operations'        => 'This server performs no write operation of any kind against the site.',
			),
		);

		return PluginLens_Tool_Registry::with_meta( $capabilities, 1, 1, false );
	}
}
