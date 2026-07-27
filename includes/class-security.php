<?php
/**
 * Authentication for the MCP endpoint.
 *
 * The transport layer depends only on the interface below, so the token
 * mechanism can be swapped for OAuth without touching the transport.
 *
 * @package PluginLens
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface PluginLens_Auth_Interface {

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
class PluginLens_Token_Auth implements PluginLens_Auth_Interface {

	const OPTION_ENABLED = 'pluginlens_enabled';
	const OPTION_TOKEN   = 'pluginlens_token';

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
	 * Generates and stores a fresh token, replacing any previous one.
	 *
	 * @return string The new token, hex encoded.
	 */
	public static function generate_token() {
		$token = bin2hex( random_bytes( 32 ) );
		delete_option( self::OPTION_TOKEN );
		add_option( self::OPTION_TOKEN, $token, '', false );
		return $token;
	}

	/**
	 * Invalidates the current token without creating a new one.
	 *
	 * @return void
	 */
	public static function revoke_token() {
		delete_option( self::OPTION_TOKEN );
	}
}
