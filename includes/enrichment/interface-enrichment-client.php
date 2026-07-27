<?php
/**
 * Contract every enrichment client implements.
 *
 * @package PluginLens
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface PluginLens_Enrichment_Client_Interface {

	/**
	 * Short source name used in _meta.sources_unavailable, e.g. 'endoflife'.
	 *
	 * @return string
	 */
	public function name();
}
