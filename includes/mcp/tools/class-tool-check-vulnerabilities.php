<?php
/**
 * The check_vulnerabilities tool.
 *
 * @package PluginLens
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Known vulnerabilities matched against installed versions. Findings are
 * version matches, never slug matches. Severity and scores are reported as
 * the source returns them, never invented.
 */
class PluginLens_Tool_Check_Vulnerabilities {

	/**
	 * Registers the tool.
	 *
	 * @param PluginLens_Tool_Registry $registry Tool registry.
	 * @return void
	 */
	public static function register( $registry ) {
		$registry->register(
			'check_vulnerabilities',
			'Returns known published vulnerabilities that affect the plugin versions actually installed on this WordPress site, plus WordPress core. Each finding carries the affected slug, installed version, CVE identifiers, CVSS score and severity as published, the affected version range, and the fixed-in version where known. A plugin merely appearing in the vulnerability database is not reported; only version matches are.',
			array(
				'type'       => 'object',
				'properties' => array(
					'slugs'        => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => 'Plugin slugs to check. Omit to check every installed plugin.',
					),
					'include_core' => array(
						'type'        => 'boolean',
						'default'     => true,
						'description' => 'Also check the running WordPress core version.',
					),
				),
			),
			array( __CLASS__, 'run' )
		);
	}

	/**
	 * Runs the tool.
	 *
	 * @param array $args Tool arguments.
	 * @return string JSON string.
	 */
	public static function run( $args ) {
		$requested    = isset( $args['slugs'] ) && is_array( $args['slugs'] ) ? array_map( 'strval', $args['slugs'] ) : null;
		$include_core = ! isset( $args['include_core'] ) || (bool) $args['include_core'];

		$collector     = new PluginLens_Inventory_Collector();
		$slug_versions = array();
		foreach ( $collector->collect() as $record ) {
			// mu-plugins and drop-ins have no wp.org identity to look up.
			if ( ! in_array( $record['status'], array( 'active', 'inactive' ), true ) ) {
				continue;
			}
			if ( null !== $requested && ! in_array( $record['slug'], $requested, true ) ) {
				continue;
			}
			$slug_versions[ $record['slug'] ] = $record['version'];
		}

		$manager = new PluginLens_Enrichment_Manager();
		$client  = new PluginLens_WPVulnerability_Client( $manager );

		$result = $client->plugin_findings( $slug_versions );

		// An empty findings array under partial coverage is a false negative
		// waiting to be misread. Coverage is stated explicitly, with the
		// unchecked slugs named so nobody has to infer which ones nobody
		// looked at.
		$payload = array(
			'findings' => $result['findings'],
			'coverage' => array(
				'complete'        => array() === $result['unchecked'],
				'plugins_checked' => $result['checked'],
				'plugins_total'   => count( $slug_versions ),
				'unchecked_slugs' => $result['unchecked'],
			),
			'unparsed' => $result['unparsed'],
		);

		if ( $include_core ) {
			$core            = $client->core_findings( get_bloginfo( 'version' ) );
			$payload['core'] = array(
				'version' => get_bloginfo( 'version' ),
				'checked' => null !== $core,
			);
			if ( null !== $core ) {
				$payload['core']['findings'] = $core['findings'];
				$payload['unparsed']        += $core['unparsed'];
			}
		}

		return PluginLens_Tool_Registry::with_meta(
			$payload,
			count( $result['findings'] ),
			count( $result['findings'] ),
			false,
			$manager->sources_unavailable()
		);
	}
}
