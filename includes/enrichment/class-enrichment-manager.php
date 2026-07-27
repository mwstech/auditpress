<?php
/**
 * Enrichment manager: shared HTTP, caching, and failure accounting for every
 * enrichment client.
 *
 * The contract (SPEC section 8): every client returns data or null, never
 * throws, always caches, and every failure is recorded so tools can report
 * _meta.sources_unavailable honestly. Enrichment must never block or break a
 * response.
 *
 * @package PluginLens
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Orchestrates enrichment clients. One instance per request, shared across
 * clients so unavailability is reported once per source.
 */
class PluginLens_Enrichment_Manager {

	/**
	 * A slow third-party API must never make the MCP endpoint appear hung.
	 */
	const CONNECT_TIMEOUT = 3;
	const TOTAL_TIMEOUT   = 5;

	/**
	 * Source names that failed during this request.
	 *
	 * @var string[]
	 */
	private $unavailable = array();

	/**
	 * Records a source as unavailable for this request.
	 *
	 * @param string $name Source name.
	 * @return void
	 */
	public function record_unavailable( $name ) {
		if ( ! in_array( $name, $this->unavailable, true ) ) {
			$this->unavailable[] = $name;
		}
	}

	/**
	 * Source names that failed during this request, for _meta.
	 *
	 * @return string[]
	 */
	public function sources_unavailable() {
		return $this->unavailable;
	}

	/**
	 * Whether outbound HTTP to a host is blocked by site configuration.
	 * Honoring WP_HTTP_BLOCK_EXTERNAL keeps us a good citizen on locked-down
	 * hosts and gives tests a clean way to simulate a firewalled site.
	 *
	 * @param string $host Host name, e.g. 'endoflife.date'.
	 * @return bool
	 */
	public function is_blocked( $host ) {
		if ( ! defined( 'WP_HTTP_BLOCK_EXTERNAL' ) || ! WP_HTTP_BLOCK_EXTERNAL ) {
			return false;
		}
		if ( defined( 'WP_ACCESSIBLE_HOSTS' ) && WP_ACCESSIBLE_HOSTS ) {
			$allowed = array_map( 'trim', explode( ',', WP_ACCESSIBLE_HOSTS ) );
			return ! in_array( $host, $allowed, true );
		}
		return true;
	}

	/**
	 * Fetches several URLs in parallel with WordPress's bundled Requests
	 * library. Returns response bodies keyed like the input; a failed request
	 * (transport error, non-2xx status, empty body) yields null for its key.
	 * Never throws.
	 *
	 * @param array<string, string> $urls Map of key => URL.
	 * @return array<string, ?string> Map of key => body or null.
	 */
	public function fetch_multiple( $urls ) {
		$bodies = array_fill_keys( array_keys( $urls ), null );
		if ( array() === $urls ) {
			return $bodies;
		}

		$requests = array();
		foreach ( $urls as $key => $url ) {
			$requests[ $key ] = array(
				'url'     => $url,
				'type'    => 'GET',
				'headers' => array(
					'Accept'     => 'application/json',
					'User-Agent' => 'PluginLens/' . PLUGINLENS_VERSION . ' (WordPress plugin; https://www.macronimous.com/free-tools/pluginlens/)',
				),
			);
		}

		$options = array(
			'timeout'         => self::TOTAL_TIMEOUT,
			'connect_timeout' => self::CONNECT_TIMEOUT,
		);

		try {
			if ( class_exists( '\WpOrg\Requests\Requests' ) ) {
				$responses = \WpOrg\Requests\Requests::request_multiple( $requests, $options );
			} else {
				// WordPress < 6.2 ships the legacy class name.
				$responses = \Requests::request_multiple( $requests, $options ); // phpcs:ignore PHPCompatibility -- legacy fallback.
			}
		} catch ( \Throwable $e ) {
			return $bodies;
		}

		foreach ( $responses as $key => $response ) {
			if ( ! is_object( $response ) || ! isset( $response->status_code, $response->body ) ) {
				continue; // Exceptions come back as objects without a status.
			}
			if ( $response->status_code >= 200 && $response->status_code < 300 && '' !== (string) $response->body ) {
				$bodies[ $key ] = (string) $response->body;
			}
		}

		return $bodies;
	}

	/**
	 * Reads a cached enrichment payload.
	 *
	 * @param string $key Cache key suffix, prefixed automatically.
	 * @return mixed False when absent.
	 */
	public function cache_get( $key ) {
		return get_transient( 'pluginlens_enrich_' . $key );
	}

	/**
	 * Stores an enrichment payload.
	 *
	 * @param string $key   Cache key suffix.
	 * @param mixed  $value Value to store.
	 * @param int    $ttl   Lifetime in seconds.
	 * @return void
	 */
	public function cache_set( $key, $value, $ttl ) {
		set_transient( 'pluginlens_enrich_' . $key, $value, $ttl );
	}
}
