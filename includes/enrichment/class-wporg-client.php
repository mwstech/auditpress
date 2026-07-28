<?php
/**
 * wordpress.org plugin API client.
 *
 * Response behavior verified against the live API on 2026-07-27:
 * - Known plugin: HTTP 200 with the full record.
 * - Closed plugin: HTTP 404 with {"error":"closed","closed":true,
 *   "closed_date":"...","reason":"..."} — unambiguous, so closed_on_wporg is
 *   a legitimate flag (docs/DECISIONS.md 26).
 * - Unknown slug: HTTP 404 with {"error":"Plugin not found."}.
 *
 * Both 404 shapes are answers, not failures, and cache for the full TTL.
 * Uses the persistent option store; one lookup per installed plugin means the
 * cache must not depend on the object cache. Returns data or null, never
 * throws. Caches 24 hours.
 *
 * @package AuditPress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Answers "what does wordpress.org know about these plugins?".
 */
class AuditPress_WPOrg_Client implements AuditPress_Enrichment_Client_Interface {

	const HOST      = 'api.wordpress.org';
	const CACHE_TTL = DAY_IN_SECONDS;

	/**
	 * How old a cached wordpress.org record may be and still be served once
	 * the API is unreachable. Thirty days, an order of magnitude looser than
	 * the vulnerability limit, because staleness barely matters here: a
	 * last-updated date, a tested-up-to value, and an install count move on a
	 * scale of weeks, and a month-old answer to "is this plugin abandoned?" is
	 * the same answer (docs/DECISIONS.md 53).
	 */
	const MAX_STALE = MONTH_IN_SECONDS;

	/**
	 * Shared manager.
	 *
	 * @var AuditPress_Enrichment_Manager
	 */
	private $manager;

	/**
	 * Constructor.
	 *
	 * @param AuditPress_Enrichment_Manager $manager Shared manager.
	 */
	public function __construct( AuditPress_Enrichment_Manager $manager ) {
		$this->manager = $manager;
	}

	/**
	 * Source name for _meta.sources.
	 *
	 * @return string
	 */
	public function name() {
		return 'wporg';
	}

	/**
	 * wordpress.org records for a set of slugs, from store or network.
	 *
	 * Each value is one of:
	 * - array with found=true and the trimmed record fields
	 * - array with found=false, closed=true|false (a definitive 404 answer)
	 * - null when the lookup failed (source unavailable for that slug)
	 *
	 * @param string[] $slugs Plugin slugs.
	 * @return array<string, ?array>
	 */
	public function records( $slugs ) {
		$out      = array();
		$to_fetch = array();
		$fallback = array();

		foreach ( $slugs as $slug ) {
			$lookup = $this->manager->store_lookup( 'wporg_' . $slug, self::CACHE_TTL, self::MAX_STALE );
			if ( 'fresh' === $lookup['state'] ) {
				$out[ $slug ] = $lookup['data'];
				$this->manager->record_ok( $this->name() );
				continue;
			}
			if ( 'stale' === $lookup['state'] ) {
				$out[ $slug ] = $lookup['data'];
				$this->manager->record_stale( $this->name(), $lookup['fetched_at'] );
				continue;
			}
			if ( 'blocked' === $lookup['state'] ) {
				$out[ $slug ] = null;
				$this->manager->record_unavailable( $this->name(), AuditPress_Enrichment_Manager::REASON_BACKOFF, $lookup['next_retry'] );
				continue;
			}
			$to_fetch[]        = $slug;
			$fallback[ $slug ] = $lookup;
		}

		if ( array() === $to_fetch ) {
			return $out;
		}

		if ( $this->manager->is_blocked( self::HOST ) ) {
			// Configuration, not failure: no negative cache.
			foreach ( $to_fetch as $slug ) {
				$out[ $slug ] = $this->degrade( $fallback[ $slug ], AuditPress_Enrichment_Manager::REASON_NO_OUTBOUND );
			}
			return $out;
		}

		$urls = array();
		foreach ( $to_fetch as $slug ) {
			$urls[ $slug ] = 'https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request%5Bslug%5D=' . rawurlencode( $slug ) . '&request%5Bfields%5D%5Bsections%5D=false';
		}
		$responses = $this->manager->fetch_multiple_raw( $urls );
		$any_ok    = false;

		foreach ( $to_fetch as $slug ) {
			$answer = $this->interpret( $responses[ $slug ] );
			if ( null === $answer ) {
				$status = null === $responses[ $slug ] ? 0 : (int) $responses[ $slug ]['status'];
				$this->manager->store_put_failure( 'wporg_' . $slug );
				$out[ $slug ] = $this->degrade( $fallback[ $slug ], $this->manager->failure_reason( $slug, $status ) );
				continue;
			}
			$this->manager->store_put( 'wporg_' . $slug, $answer );
			$this->manager->record_ok( $this->name() );
			$out[ $slug ] = $answer;
			$any_ok       = true;
		}
		if ( $any_ok ) {
			$this->manager->record_last_success( $this->name() );
		}
		$this->manager->store_flush();

		return $out;
	}

	/**
	 * Falls back to a stale cached record after a failed fetch, or reports the
	 * slug unanswered. Either way the reason is recorded against the source.
	 *
	 * @param array  $lookup Store lookup that preceded the fetch.
	 * @param string $reason Reason code for the failure.
	 * @return array|null
	 */
	private function degrade( $lookup, $reason ) {
		$age = time() - $lookup['fetched_at'];
		if ( null !== $lookup['data'] && $lookup['fetched_at'] > 0 && $age < self::MAX_STALE ) {
			$this->manager->record_stale( $this->name(), $lookup['fetched_at'] );
			return $lookup['data'];
		}
		$this->manager->record_unavailable( $this->name(), $reason );
		return null;
	}

	/**
	 * Interprets one raw response into a cacheable answer or a failure.
	 *
	 * @param array|null $response Raw {status, body} or null.
	 * @return array|null
	 */
	private function interpret( $response ) {
		if ( null === $response || '' === $response['body'] ) {
			return null;
		}
		$data = json_decode( $response['body'], true );
		if ( ! is_array( $data ) ) {
			return null;
		}

		if ( $response['status'] >= 200 && $response['status'] < 300 && isset( $data['slug'] ) && ! isset( $data['error'] ) ) {
			return $this->trim_record( $data );
		}

		if ( 404 === $response['status'] && isset( $data['error'] ) ) {
			if ( ! empty( $data['closed'] ) || 'closed' === $data['error'] ) {
				$answer = array(
					'found'  => false,
					'closed' => true,
				);
				if ( isset( $data['closed_date'] ) ) {
					$answer['closed_date'] = (string) $data['closed_date'];
				}
				if ( isset( $data['reason_text'] ) ) {
					$answer['closed_reason'] = substr( (string) $data['reason_text'], 0, 100 );
				}
				return $answer;
			}
			return array(
				'found'  => false,
				'closed' => false,
			);
		}

		return null; // Rate limiting, server errors, unexpected shapes.
	}

	/**
	 * Keeps only the fields the product uses. The full record carries
	 * contributor avatars, banners, and screenshots that would bloat a
	 * 45-plugin persistent store for nothing.
	 *
	 * @param array $data Full API record.
	 * @return array
	 */
	private function trim_record( $data ) {
		$record = array( 'found' => true );
		foreach ( array( 'version', 'requires', 'requires_php', 'tested', 'last_updated', 'active_installs', 'rating', 'num_ratings', 'support_threads', 'support_threads_resolved' ) as $field ) {
			if ( isset( $data[ $field ] ) && '' !== $data[ $field ] ) {
				$record[ $field ] = is_numeric( $data[ $field ] ) ? $data[ $field ] + 0 : (string) $data[ $field ];
			}
		}
		if ( isset( $record['last_updated'] ) ) {
			$parsed = strtotime( $record['last_updated'] );
			if ( false !== $parsed ) {
				$record['last_updated'] = gmdate( 'Y-m-d', $parsed );
			}
		}
		return $record;
	}
}
