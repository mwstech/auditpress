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
	 * Parallel fetches run in batches of this size. 45 simultaneous
	 * connections to a volunteer-run API is abuse, not parallelism.
	 */
	const MAX_CONCURRENCY = 8;

	/**
	 * Persistent store: a single non-autoloaded option holding a keyed map of
	 * entry => {data, fetched_at}. See docs/DECISIONS.md 22.
	 */
	const STORE_OPTION = 'pluginlens_enrich_store';

	/**
	 * Failed lookups are cached too, briefly, so a down API is not hammered
	 * on every MCP request.
	 */
	const FAILURE_TTL = 900;

	/**
	 * Source names that failed during this request.
	 *
	 * @var string[]
	 */
	private $unavailable = array();

	/**
	 * In-memory copy of the persistent store for this request.
	 *
	 * @var array|null
	 */
	private $store = null;

	/**
	 * Whether the store has unwritten changes.
	 *
	 * @var bool
	 */
	private $store_dirty = false;

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
		$bodies = array();
		foreach ( $this->fetch_multiple_raw( $urls ) as $key => $response ) {
			$ok             = null !== $response && $response['status'] >= 200 && $response['status'] < 300 && '' !== $response['body'];
			$bodies[ $key ] = $ok ? $response['body'] : null;
		}
		return $bodies;
	}

	/**
	 * Like fetch_multiple, but preserves the HTTP status so callers can treat
	 * meaningful non-2xx answers (wordpress.org replies 404 for both closed
	 * and unknown plugins) as answers rather than failures. Null means the
	 * request itself failed at the transport level.
	 *
	 * @param array<string, string> $urls Map of key => URL.
	 * @return array<string, ?array{status: int, body: string}>
	 */
	public function fetch_multiple_raw( $urls ) {
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

		// Batched, not all at once: politeness toward volunteer-run APIs.
		foreach ( array_chunk( $requests, self::MAX_CONCURRENCY, true ) as $batch ) {
			try {
				if ( class_exists( '\WpOrg\Requests\Requests' ) ) {
					$responses = \WpOrg\Requests\Requests::request_multiple( $batch, $options );
				} else {
					// WordPress < 6.2 ships the legacy class name.
					$responses = \Requests::request_multiple( $batch, $options ); // phpcs:ignore PHPCompatibility -- legacy fallback.
				}
			} catch ( \Throwable $e ) {
				continue;
			}

			foreach ( $responses as $key => $response ) {
				if ( ! is_object( $response ) || ! isset( $response->status_code, $response->body ) ) {
					continue; // Exceptions come back as objects without a status.
				}
				$bodies[ $key ] = array(
					'status' => (int) $response->status_code,
					'body'   => (string) $response->body,
				);
			}
		}

		return $bodies;
	}

	/**
	 * Reads an entry from the persistent store. Unlike transients, the store
	 * is a plain non-autoloaded option: it always hits the database, so
	 * persistence does not depend on the site's object cache. Expiry is
	 * checked here in PHP.
	 *
	 * Returns the stored data (which may itself be null for a cached
	 * failure), or false when absent or expired.
	 *
	 * @param string $key     Entry key.
	 * @param int    $max_age Maximum age in seconds for stored data.
	 * @return mixed
	 */
	public function store_get( $key, $max_age ) {
		$store = $this->load_store();
		if ( ! isset( $store[ $key ] ) || ! is_array( $store[ $key ] ) ) {
			return false;
		}
		$entry = $store[ $key ];
		$age   = time() - ( isset( $entry['fetched_at'] ) ? (int) $entry['fetched_at'] : 0 );
		$ttl   = ( array_key_exists( 'data', $entry ) && null === $entry['data'] ) ? self::FAILURE_TTL : $max_age;
		if ( $age >= $ttl ) {
			return false;
		}
		return array(
			'data' => isset( $entry['data'] ) ? $entry['data'] : null,
		);
	}

	/**
	 * Queues an entry for the persistent store. Call store_flush() once per
	 * batch; one option write per request, not one per plugin.
	 *
	 * @param string $key  Entry key.
	 * @param mixed  $data Data, or null to record a failed lookup.
	 * @return void
	 */
	public function store_put( $key, $data ) {
		$store             = $this->load_store();
		$store[ $key ]     = array(
			'data'       => $data,
			'fetched_at' => time(),
		);
		$this->store       = $store;
		$this->store_dirty = true;
	}

	/**
	 * Writes queued store entries in a single option update, pruning entries
	 * old enough to be useless to anyone.
	 *
	 * @return void
	 */
	public function store_flush() {
		if ( ! $this->store_dirty || null === $this->store ) {
			return;
		}
		$cutoff = time() - WEEK_IN_SECONDS;
		foreach ( $this->store as $key => $entry ) {
			if ( ! is_array( $entry ) || ( isset( $entry['fetched_at'] ) && (int) $entry['fetched_at'] < $cutoff ) ) {
				unset( $this->store[ $key ] );
			}
		}
		if ( false === get_option( self::STORE_OPTION, false ) ) {
			add_option( self::STORE_OPTION, $this->store, '', false );
		} else {
			update_option( self::STORE_OPTION, $this->store, false );
		}
		$this->store_dirty = false;
	}

	/**
	 * Loads the persistent store once per request.
	 *
	 * @return array
	 */
	private function load_store() {
		if ( null === $this->store ) {
			$stored      = get_option( self::STORE_OPTION, array() );
			$this->store = is_array( $stored ) ? $stored : array();
		}
		return $this->store;
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
