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
 * @package AuditPress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Orchestrates enrichment clients. One instance per request, shared across
 * clients so unavailability is reported once per source.
 */
class AuditPress_Enrichment_Manager {

	/**
	 * A slow third-party API must never make the MCP endpoint appear hung,
	 * but a populated response is legitimately slower than an empty one.
	 * Total timeout is filterable via auditpress_http_timeout.
	 */
	const CONNECT_TIMEOUT = 3;
	const TOTAL_TIMEOUT   = 10;

	/**
	 * Parallel fetches run in batches of this size. 45 simultaneous
	 * connections to a volunteer-run API is abuse, not parallelism.
	 */
	const MAX_CONCURRENCY = 8;

	/**
	 * Largest enrichment response accepted from any upstream, in bytes.
	 * Real payloads are kilobytes; anything past this is treated as an
	 * outage rather than parsed.
	 */
	const MAX_RESPONSE_BYTES = 2097152;

	/**
	 * Persistent store: a single non-autoloaded option holding a keyed map of
	 * entry => {data, fetched_at}. See docs/DECISIONS.md 22.
	 */
	const STORE_OPTION = 'auditpress_enrich_store';

	/**
	 * Failed lookups negative-cache with escalating backoff on consecutive
	 * failures. At wordpress.org scale, thousands of installs retrying a
	 * broken free service every 15 minutes forever is how a plugin gets
	 * blocked at the network level (docs/DECISIONS.md 34). Reset on success.
	 *
	 * @var int[]
	 */
	const FAILURE_BACKOFF = array( 900, 3600, 21600, 86400 );

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
					'User-Agent' => 'AuditPress/' . AUDITPRESS_VERSION . ' (WordPress plugin; https://www.macronimous.com/free-tools/auditpress/)',
				),
			);
		}

		/**
		 * Filters the total HTTP timeout for enrichment requests, in seconds.
		 *
		 * The default errs generous: populated vulnerability responses do more
		 * backend work than empty ones, and silent degradation already
		 * protects the endpoint from hanging.
		 *
		 * @param int $timeout Default 10.
		 */
		$total_timeout = max( 1, (int) apply_filters( 'auditpress_http_timeout', self::TOTAL_TIMEOUT ) );

		$options = array(
			'timeout'          => $total_timeout,
			'connect_timeout'  => self::CONNECT_TIMEOUT,
			// Requests is used directly for parallel fetching, which means
			// wp_safe_remote_get()'s SSRF guards do not apply. The three
			// hosts are hardcoded literals and none of them redirect, so
			// refusing to follow redirects costs nothing and removes the only
			// path by which a compromised upstream could point this plugin at
			// an internal address.
			'follow_redirects' => false,
			'verify'           => true,
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
				$body = (string) $response->body;
				// A compromised or spoofed upstream must not be able to
				// exhaust memory with an enormous body. Oversized responses
				// are discarded, which degrades exactly like an outage.
				if ( strlen( $body ) > self::MAX_RESPONSE_BYTES ) {
					continue;
				}
				$bodies[ $key ] = array(
					'status' => (int) $response->status_code,
					'body'   => $body,
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

		if ( array_key_exists( 'data', $entry ) && null === $entry['data'] ) {
			$failures = isset( $entry['failures'] ) ? max( 1, (int) $entry['failures'] ) : 1;
			$ttl      = self::FAILURE_BACKOFF[ min( $failures, count( self::FAILURE_BACKOFF ) ) - 1 ];
		} else {
			$ttl = $max_age;
		}

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
	 * A null-data entry records a failure and carries a consecutive-failure
	 * count that store_get() turns into escalating backoff; any successful
	 * entry resets the count.
	 *
	 * @param string $key  Entry key.
	 * @param mixed  $data Data, or null to record a failed lookup.
	 * @return void
	 */
	public function store_put( $key, $data ) {
		$store = $this->load_store();
		$entry = array(
			'data'       => $data,
			'fetched_at' => time(),
		);
		if ( null === $data ) {
			// array_key_exists, not isset: the stored failure data IS null.
			$prev_entry        = isset( $store[ $key ] ) && is_array( $store[ $key ] ) ? $store[ $key ] : array();
			$was_failure       = array_key_exists( 'data', $prev_entry ) && null === $prev_entry['data'];
			$entry['failures'] = ( $was_failure && isset( $prev_entry['failures'] ) ? (int) $prev_entry['failures'] : 0 ) + 1;
		}
		$store[ $key ]     = $entry;
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
		return get_transient( 'auditpress_enrich_' . $key );
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
		set_transient( 'auditpress_enrich_' . $key, $value, $ttl );
	}
}
