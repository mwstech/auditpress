<?php
/**
 * Plugin Name: AuditPress Supply-Chain Mock (test fixture)
 * Description: Injects WPVulnerability supply-chain audit entries in the live-verified shape, including the trunk-revision range that no version comparison can resolve. Copy into mu-plugins for verification, remove after. Never ships.
 * Version: 1.0
 *
 * @package AuditPress
 */

add_filter(
	'auditpress_wpvulnerability_body',
	function ( $body, $type, $key ) {
		if ( 'plugin' !== $type || null === $body ) {
			return $body;
		}
		$data = json_decode( $body, true );
		if ( ! is_array( $data ) || ! isset( $data['data'] ) ) {
			return $body;
		}

		// Verbatim shape of audit 21 (simply-show-hooks), fetched from the live
		// API on 2026-07-28. The bounds are Subversion revisions, not version
		// numbers, which is the case no version comparison can resolve.
		if ( 'hello-dolly' === $key ) {
			$data['data']['supplychain'] = array(
				array(
					'verdict'           => 'malicious',
					'audit_id'          => 21,
					'affected_versions' => array(
						'min_version'  => 'trunk@r1522935',
						'min_operator' => 'ge',
						'max_version'  => null,
						'max_operator' => null,
					),
					'baseline_version'  => 'trunk@r1522935',
					'head_version'      => 'trunk@r3105891',
					'ioc_count'         => 0,
					'closed_by_wporg'   => false,
					'c2_infrastructure' => array(),
					'signals'           => array(),
					'wpbeacon_url'      => 'https://wpbeacon.io/audits/21/',
					'published_at'      => '2024-06-24',
				),
			);
		}

		// A `cleaned` verdict with a resolvable range, so the installed version
		// sits inside a compromise that was later fixed.
		if ( 'akismet' === $key ) {
			$data['data']['supplychain'] = array(
				array(
					'verdict'           => 'cleaned',
					'audit_id'          => 9001,
					'affected_versions' => array(
						'min_version'  => '5.0',
						'min_operator' => 'ge',
						'max_version'  => '5.8',
						'max_operator' => 'lt',
					),
					'baseline_version'  => '5.0',
					'head_version'      => '5.8',
					'ioc_count'         => 3,
					'closed_by_wporg'   => true,
					'published_at'      => '2026-01-15',
				),
			);
		}

		// Hostile shapes: arrays where scalars belong, an unknown verdict, and
		// an entry with no verdict at all. None may reach version_compare(),
		// and none may be reported as a finding.
		if ( 'classic-widgets' === $key ) {
			$data['data']['supplychain'] = array(
				array(
					'verdict'           => array( 'malicious' ),
					'affected_versions' => array( 'min_version' => '0.1' ),
				),
				array(
					'verdict'           => 'weaponized',
					'affected_versions' => array( 'min_version' => '0.1' ),
				),
				array(
					'audit_id'          => 'not-a-number',
					'affected_versions' => array( 'min_version' => array( '0.1' ) ),
				),
				array(
					'verdict'           => 'suspicious',
					'affected_versions' => array(
						'min_version'  => array( '0.1' ),
						'min_operator' => 'ge',
					),
				),
			);
		}

		return wp_json_encode( $data );
	},
	10,
	3
);
