<?php
/**
 * endoflife.date client.
 *
 * Prefers the versioned v1 API and falls back to the legacy path; both shapes
 * were verified against the live service on 2026-07-27. Returns data or null,
 * never throws. Caches 7 days per product.
 *
 * @package Auditra
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Answers "how supported is this version?" for PHP, WordPress, and the
 * database engine.
 */
class Auditra_Endoflife_Client implements Auditra_Enrichment_Client_Interface {

	const HOST      = 'endoflife.date';
	const CACHE_TTL = WEEK_IN_SECONDS;

	/**
	 * How old a cached cycle list may be and still be served once the API is
	 * unreachable. Thirty days: release-cycle and end-of-life dates are
	 * announced months ahead and almost never move, so a month-old copy gives
	 * the same answer as a fresh one (docs/DECISIONS.md 53).
	 */
	const MAX_STALE = MONTH_IN_SECONDS;

	/**
	 * Shared manager.
	 *
	 * @var Auditra_Enrichment_Manager
	 */
	private $manager;

	/**
	 * Constructor.
	 *
	 * @param Auditra_Enrichment_Manager $manager Shared manager.
	 */
	public function __construct( Auditra_Enrichment_Manager $manager ) {
		$this->manager = $manager;
	}

	/**
	 * Source name for _meta.sources.
	 *
	 * @return string
	 */
	public function name() {
		return 'endoflife';
	}

	/**
	 * Support status for several product/version pairs at once. Uncached
	 * products are fetched in a single parallel round trip.
	 *
	 * @param array<string, string> $products Map of product => running version,
	 *                                        e.g. array( 'php' => '8.2.29' ).
	 * @return array<string, ?array> Map of product => status array or null.
	 */
	public function support_statuses( $products ) {
		$statuses = array_fill_keys( array_keys( $products ), null );
		$to_fetch = array();
		$fallback = array();

		foreach ( $products as $product => $version ) {
			$lookup = $this->manager->store_lookup( 'eol_' . $product, self::CACHE_TTL, self::MAX_STALE );
			if ( 'fresh' === $lookup['state'] ) {
				$this->manager->record_ok( $this->name() );
				$statuses[ $product ] = $this->match_cycle( $lookup['data'], $product, $version );
				continue;
			}
			if ( 'stale' === $lookup['state'] ) {
				$this->manager->record_stale( $this->name(), $lookup['fetched_at'] );
				$statuses[ $product ] = $this->match_cycle( $lookup['data'], $product, $version );
				continue;
			}
			if ( 'blocked' === $lookup['state'] ) {
				$this->manager->record_unavailable( $this->name(), Auditra_Enrichment_Manager::REASON_BACKOFF, $lookup['next_retry'] );
				continue;
			}
			$to_fetch[ $product ] = $version;
			$fallback[ $product ] = $lookup;
		}

		if ( array() === $to_fetch ) {
			return $statuses;
		}

		if ( $this->manager->is_blocked( self::HOST ) ) {
			foreach ( $to_fetch as $product => $version ) {
				$cycles = $this->degrade( $fallback[ $product ], Auditra_Enrichment_Manager::REASON_NO_OUTBOUND );
				if ( null !== $cycles ) {
					$statuses[ $product ] = $this->match_cycle( $cycles, $product, $version );
				}
			}
			return $statuses;
		}

		// Fetch v1 and legacy for every uncached product in one parallel call;
		// v1 wins when both parse.
		$urls = array();
		foreach ( $to_fetch as $product => $version ) {
			$urls[ $product . '|v1' ]     = 'https://endoflife.date/api/v1/products/' . rawurlencode( $product ) . '/';
			$urls[ $product . '|legacy' ] = 'https://endoflife.date/api/' . rawurlencode( $product ) . '.json';
		}
		$bodies = $this->manager->fetch_multiple( $urls );
		$any_ok = false;

		foreach ( $to_fetch as $product => $version ) {
			$cycles = $this->parse_v1( $bodies[ $product . '|v1' ] );
			if ( null === $cycles ) {
				$cycles = $this->parse_legacy( $bodies[ $product . '|legacy' ] );
			}
			if ( null === $cycles ) {
				$this->manager->store_put_failure( 'eol_' . $product );
				$cycles = $this->degrade( $fallback[ $product ], $this->manager->failure_reason( $product . '|v1' ) );
				if ( null === $cycles ) {
					continue;
				}
			} else {
				$this->manager->store_put( 'eol_' . $product, $cycles );
				$this->manager->record_ok( $this->name() );
				$any_ok = true;
			}
			$statuses[ $product ] = $this->match_cycle( $cycles, $product, $version );
		}
		if ( $any_ok ) {
			$this->manager->record_last_success( $this->name() );
		}
		$this->manager->store_flush();

		return $statuses;
	}

	/**
	 * Falls back to a stale cached cycle list after a failed fetch, or reports
	 * the product unanswered.
	 *
	 * @param array  $lookup Store lookup that preceded the fetch.
	 * @param string $reason Reason code for the failure.
	 * @return array[]|null
	 */
	private function degrade( $lookup, $reason ) {
		$age = time() - $lookup['fetched_at'];
		if ( is_array( $lookup['data'] ) && $lookup['fetched_at'] > 0 && $age < self::MAX_STALE ) {
			$this->manager->record_stale( $this->name(), $lookup['fetched_at'] );
			return $lookup['data'];
		}
		$this->manager->record_unavailable( $this->name(), $reason );
		return null;
	}

	/**
	 * The full ordered cycle list for one product, newest first, from cache
	 * or a single fetch. Used by list_plugins to place a tested-up-to value
	 * relative to the current release.
	 *
	 * @param string $product Product name. Case-insensitive: endoflife.date
	 *                        product slugs are lowercase (which a doc-comment
	 *                        example cannot spell without tripping the
	 *                        CapitalPDangit sniff), so it is lowercased here,
	 *                        keeping cache keys aligned with support_statuses().
	 * @return array[]|null Cycle rows, or null when unavailable.
	 */
	public function cycles( $product ) {
		$product = strtolower( $product );
		$lookup  = $this->manager->store_lookup( 'eol_' . $product, self::CACHE_TTL, self::MAX_STALE );

		if ( 'fresh' === $lookup['state'] ) {
			$this->manager->record_ok( $this->name() );
			return $lookup['data'];
		}
		if ( 'stale' === $lookup['state'] ) {
			$this->manager->record_stale( $this->name(), $lookup['fetched_at'] );
			return $lookup['data'];
		}
		if ( 'blocked' === $lookup['state'] ) {
			$this->manager->record_unavailable( $this->name(), Auditra_Enrichment_Manager::REASON_BACKOFF, $lookup['next_retry'] );
			return null;
		}

		if ( $this->manager->is_blocked( self::HOST ) ) {
			return $this->degrade( $lookup, Auditra_Enrichment_Manager::REASON_NO_OUTBOUND );
		}

		$bodies = $this->manager->fetch_multiple(
			array(
				'v1'     => 'https://endoflife.date/api/v1/products/' . rawurlencode( $product ) . '/',
				'legacy' => 'https://endoflife.date/api/' . rawurlencode( $product ) . '.json',
			)
		);

		$cycles = $this->parse_v1( $bodies['v1'] );
		if ( null === $cycles ) {
			$cycles = $this->parse_legacy( $bodies['legacy'] );
		}
		if ( null === $cycles ) {
			$this->manager->store_put_failure( 'eol_' . $product );
			$fallback = $this->degrade( $lookup, $this->manager->failure_reason( 'v1' ) );
			$this->manager->store_flush();
			return $fallback;
		}
		$this->manager->store_put( 'eol_' . $product, $cycles );
		$this->manager->record_ok( $this->name() );
		$this->manager->record_last_success( $this->name() );
		$this->manager->store_flush();
		return $cycles;
	}

	/**
	 * Normalizes a v1 API payload to cycle rows.
	 *
	 * @param string|null $body Response body.
	 * @return array[]|null
	 */
	private function parse_v1( $body ) {
		if ( null === $body ) {
			return null;
		}
		$data = json_decode( $body, true );
		if ( ! isset( $data['result']['releases'] ) || ! is_array( $data['result']['releases'] ) ) {
			return null;
		}
		$cycles = array();
		foreach ( $data['result']['releases'] as $release ) {
			if ( ! isset( $release['name'] ) ) {
				continue;
			}
			$cycles[] = array(
				'cycle'                => (string) $release['name'],
				'eol_date'             => isset( $release['eolFrom'] ) ? $release['eolFrom'] : null,
				'eol_passed'           => ! empty( $release['isEol'] ),
				'active_support_ended' => ! empty( $release['isEoas'] ),
				'latest_in_cycle'      => isset( $release['latest']['name'] ) ? (string) $release['latest']['name'] : null,
			);
		}
		return array() === $cycles ? null : $cycles;
	}

	/**
	 * Normalizes a legacy API payload to cycle rows.
	 *
	 * @param string|null $body Response body.
	 * @return array[]|null
	 */
	private function parse_legacy( $body ) {
		if ( null === $body ) {
			return null;
		}
		$data = json_decode( $body, true );
		if ( ! is_array( $data ) ) {
			return null;
		}
		$now    = gmdate( 'Y-m-d' );
		$cycles = array();
		foreach ( $data as $release ) {
			if ( ! isset( $release['cycle'] ) ) {
				continue;
			}
			// In the legacy shape, eol/support are either a date string or a
			// boolean (true = already over, false = not scheduled).
			$eol     = isset( $release['eol'] ) ? $release['eol'] : null;
			$support = isset( $release['support'] ) ? $release['support'] : null;

			$cycles[] = array(
				'cycle'                => (string) $release['cycle'],
				'eol_date'             => is_string( $eol ) ? $eol : null,
				'eol_passed'           => ( true === $eol ) || ( is_string( $eol ) && $eol < $now ),
				'active_support_ended' => ( true === $support ) || ( is_string( $support ) && $support < $now ),
				'latest_in_cycle'      => isset( $release['latest'] ) ? (string) $release['latest'] : null,
			);
		}
		return array() === $cycles ? null : $cycles;
	}

	/**
	 * Matches a running version to its release cycle.
	 *
	 * @param array[] $cycles  Normalized cycle rows.
	 * @param string  $product Product name.
	 * @param string  $version Running version.
	 * @return array|null Status row, or null when no cycle matches.
	 */
	private function match_cycle( $cycles, $product, $version ) {
		$best = null;
		foreach ( $cycles as $cycle ) {
			$prefix = $cycle['cycle'] . '.';
			if ( $cycle['cycle'] === $version || 0 === strpos( $version . '.', $prefix ) ) {
				if ( null === $best || strlen( $cycle['cycle'] ) > strlen( $best['cycle'] ) ) {
					$best = $cycle;
				}
			}
		}
		if ( null === $best ) {
			return null;
		}
		return array_merge(
			array(
				'product' => $product,
				'version' => $version,
			),
			$best
		);
	}
}
