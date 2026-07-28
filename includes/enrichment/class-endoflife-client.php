<?php
/**
 * endoflife.date client.
 *
 * Prefers the versioned v1 API and falls back to the legacy path; both shapes
 * were verified against the live service on 2026-07-27. Returns data or null,
 * never throws. Caches 7 days per product.
 *
 * @package AuditPress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Answers "how supported is this version?" for PHP, WordPress, and the
 * database engine.
 */
class AuditPress_Endoflife_Client implements AuditPress_Enrichment_Client_Interface {

	const HOST      = 'endoflife.date';
	const CACHE_TTL = WEEK_IN_SECONDS;

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
	 * Source name for _meta.sources_unavailable.
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

		foreach ( $products as $product => $version ) {
			$cycles = $this->manager->cache_get( 'eol_' . $product );
			if ( ! is_array( $cycles ) ) {
				$to_fetch[ $product ] = $version;
				continue;
			}
			$statuses[ $product ] = $this->match_cycle( $cycles, $product, $version );
		}

		if ( array() === $to_fetch ) {
			return $statuses;
		}

		if ( $this->manager->is_blocked( self::HOST ) ) {
			$this->manager->record_unavailable( $this->name() );
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

		foreach ( $to_fetch as $product => $version ) {
			$cycles = $this->parse_v1( $bodies[ $product . '|v1' ] );
			if ( null === $cycles ) {
				$cycles = $this->parse_legacy( $bodies[ $product . '|legacy' ] );
			}
			if ( null === $cycles ) {
				$this->manager->record_unavailable( $this->name() );
				continue;
			}
			$this->manager->cache_set( 'eol_' . $product, $cycles, self::CACHE_TTL );
			$statuses[ $product ] = $this->match_cycle( $cycles, $product, $version );
		}

		return $statuses;
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
		$cached  = $this->manager->cache_get( 'eol_' . $product );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		if ( $this->manager->is_blocked( self::HOST ) ) {
			$this->manager->record_unavailable( $this->name() );
			return null;
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
			$this->manager->record_unavailable( $this->name() );
			return null;
		}
		$this->manager->cache_set( 'eol_' . $product, $cycles, self::CACHE_TTL );
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
