<?php
/**
 * Authentication for the MCP endpoint.
 *
 * The transport layer depends only on the interface below, so the token
 * mechanism can be swapped for OAuth without touching the transport.
 *
 * @package Auditra
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface Auditra_Auth_Interface {

	/**
	 * Whether the MCP endpoint is enabled at all.
	 *
	 * @return bool
	 */
	public function is_endpoint_enabled();

	/**
	 * Verifies a credential presented by a client.
	 *
	 * @param string $credential Credential taken from the request.
	 * @return bool
	 */
	public function verify( $credential );
}

/**
 * Shared-secret token authentication. Token lives in a non-autoloaded option.
 */
class Auditra_Token_Auth implements Auditra_Auth_Interface {

	const OPTION_ENABLED = 'auditra_enabled';
	const OPTION_TOKEN   = 'auditra_token';

	/**
	 * Whether the admin has switched the endpoint on.
	 *
	 * @return bool
	 */
	public function is_endpoint_enabled() {
		return '1' === get_option( self::OPTION_ENABLED, '' );
	}

	/**
	 * Constant-time comparison against the stored token.
	 *
	 * @param string $credential Token taken from the request path.
	 * @return bool
	 */
	public function verify( $credential ) {
		$stored = get_option( self::OPTION_TOKEN, '' );
		if ( ! is_string( $stored ) || '' === $stored || ! is_string( $credential ) || '' === $credential ) {
			return false;
		}
		return hash_equals( $stored, $credential );
	}

	/**
	 * Generates and stores a fresh token, replacing any previous one. The
	 * auth log clears too: recorded failures against a dead token are noise.
	 *
	 * @return string The new token, hex encoded.
	 */
	public static function generate_token() {
		$token = bin2hex( random_bytes( 32 ) );
		delete_option( self::OPTION_TOKEN );
		add_option( self::OPTION_TOKEN, $token, '', false );
		delete_option( Auditra_Request_Guard::OPTION_AUTH_LOG );
		return $token;
	}
}

/**
 * Per-request protections for the MCP endpoint: rate limiting and the failed
 * authentication log.
 */
class Auditra_Request_Guard {

	const OPTION_AUTH_LOG = 'auditra_auth_log';
	const AUTH_LOG_MAX    = 50;
	const DEFAULT_LIMIT   = 60;
	const RATE_TRANSIENT  = 'auditra_rate_buckets';

	/**
	 * Transient-backed per-IP rate limit. Returns true when the request is
	 * allowed. Window is one minute; the limit is filterable.
	 *
	 * @return bool
	 */
	public static function allow_request() {
		/**
		 * Filters the MCP endpoint rate limit, in requests per minute.
		 *
		 * @param int $limit Default 60.
		 */
		$limit = max( 1, (int) apply_filters( 'auditra_rate_limit', self::DEFAULT_LIMIT ) );

		// One named transient holding every IP bucket, so uninstall can
		// remove it by name; per-IP keys would be unenumerable without the
		// wildcard SQL this plugin forbids itself.
		$ip_key  = md5( self::client_ip() );
		$now     = time();
		$buckets = get_transient( self::RATE_TRANSIENT );
		if ( ! is_array( $buckets ) ) {
			$buckets = array();
		}
		foreach ( $buckets as $key => $bucket ) {
			if ( ! is_array( $bucket ) || ! isset( $bucket['start'] ) || ( $now - (int) $bucket['start'] ) >= 2 * MINUTE_IN_SECONDS ) {
				unset( $buckets[ $key ] );
			}
		}

		if ( ! isset( $buckets[ $ip_key ] ) || ( $now - (int) $buckets[ $ip_key ]['start'] ) >= MINUTE_IN_SECONDS ) {
			$buckets[ $ip_key ] = array(
				'start' => $now,
				'count' => 0,
			);
		}

		// Already over the limit inside this window: refuse without writing.
		// The bucket is recorded and its window is running, so counting past
		// the limit changes no decision — and the write is the expensive part
		// of a check that runs before authentication, which is exactly what a
		// flood would otherwise get for free.
		if ( $buckets[ $ip_key ]['count'] >= $limit ) {
			return false;
		}

		++$buckets[ $ip_key ]['count'];
		set_transient( self::RATE_TRANSIENT, $buckets, 2 * MINUTE_IN_SECONDS );

		return true;
	}

	/**
	 * Records a failed authentication attempt: timestamp, IP, user agent.
	 * Never any token material — not a value, not a fragment, not a hash.
	 *
	 * @return void
	 */
	public static function log_failed_auth() {
		$log = get_option( self::OPTION_AUTH_LOG, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}

		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 120 ) : '';

		array_unshift(
			$log,
			array(
				'time'       => gmdate( 'Y-m-d H:i:s' ),
				'ip'         => self::client_ip(),
				'user_agent' => $user_agent,
			)
		);
		$log = array_slice( $log, 0, self::AUTH_LOG_MAX );

		if ( false === get_option( self::OPTION_AUTH_LOG, false ) ) {
			add_option( self::OPTION_AUTH_LOG, $log, '', false );
		} else {
			update_option( self::OPTION_AUTH_LOG, $log, false );
		}
	}

	/**
	 * The failed-auth log, newest first.
	 *
	 * @return array[]
	 */
	public static function auth_log() {
		$log = get_option( self::OPTION_AUTH_LOG, array() );
		return is_array( $log ) ? $log : array();
	}

	/**
	 * Client IP. REMOTE_ADDR only, deliberately: forwarded headers are
	 * spoofable and this feeds a rate limiter.
	 *
	 * @return string
	 */
	private static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return '' !== $ip ? $ip : 'unknown';
	}
}
