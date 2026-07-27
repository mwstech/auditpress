<?php
/**
 * Plugin bootstrap.
 *
 * @package PluginLens
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires the plugin together. Nothing here touches WordPress state.
 */
class PluginLens_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var PluginLens_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Returns the shared instance, booting it on first call.
	 *
	 * @return PluginLens_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->boot();
		}
		return self::$instance;
	}

	/**
	 * Loads components and registers hooks.
	 *
	 * @return void
	 */
	private function boot() {
		require_once PLUGINLENS_PLUGIN_DIR . 'includes/class-security.php';
		require_once PLUGINLENS_PLUGIN_DIR . 'includes/mcp/class-tool-registry.php';
		require_once PLUGINLENS_PLUGIN_DIR . 'includes/mcp/class-mcp-server.php';
		require_once PLUGINLENS_PLUGIN_DIR . 'includes/collectors/class-inventory.php';
		require_once PLUGINLENS_PLUGIN_DIR . 'includes/collectors/class-site-context.php';
		require_once PLUGINLENS_PLUGIN_DIR . 'includes/enrichment/interface-enrichment-client.php';
		require_once PLUGINLENS_PLUGIN_DIR . 'includes/enrichment/class-enrichment-manager.php';
		require_once PLUGINLENS_PLUGIN_DIR . 'includes/enrichment/class-endoflife-client.php';
		require_once PLUGINLENS_PLUGIN_DIR . 'includes/enrichment/class-wpvulnerability-client.php';
		require_once PLUGINLENS_PLUGIN_DIR . 'includes/enrichment/class-wporg-client.php';

		$server = new PluginLens_MCP_Server( new PluginLens_Token_Auth(), $this->build_registry() );
		add_action( 'rest_api_init', array( $server, 'register_routes' ) );
		add_filter( 'rest_pre_serve_request', array( $server, 'serve_empty_accepted_response' ), 10, 4 );

		if ( is_admin() ) {
			require_once PLUGINLENS_PLUGIN_DIR . 'includes/class-settings.php';
			$settings = new PluginLens_Settings();
			$settings->register();
		}
	}

	/**
	 * Builds the tool registry for this phase.
	 *
	 * @return PluginLens_Tool_Registry
	 */
	private function build_registry() {
		$registry = new PluginLens_Tool_Registry();
		$registry->load_tools_from( PLUGINLENS_PLUGIN_DIR . 'includes/mcp/tools' );
		return $registry;
	}
}
