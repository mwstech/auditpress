<?php
/**
 * MCP transport: route registration and JSON-RPC 2.0 envelope.
 *
 * Speaks Streamable HTTP with plain application/json responses (permitted by
 * the MCP spec as the non-SSE variant). Stateless: no session ID is issued.
 *
 * @package PluginLens
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers POST /wp-json/pluginlens/v1/mcp/{token} and dispatches JSON-RPC.
 */
class PluginLens_MCP_Server {

	const REST_NAMESPACE = 'pluginlens/v1';

	/**
	 * Protocol versions this server supports, newest first. Verified against
	 * the live MCP specification on 2026-07-27.
	 *
	 * @var string[]
	 */
	const PROTOCOL_VERSIONS = array( '2025-11-25', '2025-06-18', '2025-03-26' );

	/**
	 * Authentication mechanism.
	 *
	 * @var PluginLens_Auth_Interface
	 */
	private $auth;

	/**
	 * Tool catalog.
	 *
	 * @var PluginLens_Tool_Registry
	 */
	private $registry;

	/**
	 * Constructor.
	 *
	 * @param PluginLens_Auth_Interface $auth     Authentication mechanism.
	 * @param PluginLens_Tool_Registry  $registry Tool catalog.
	 */
	public function __construct( PluginLens_Auth_Interface $auth, PluginLens_Tool_Registry $registry ) {
		$this->auth     = $auth;
		$this->registry = $registry;
	}

	/**
	 * Registers the MCP route. Auth happens inside the handler so the error
	 * shapes stay under our control.
	 *
	 * @return void
	 */
	public function register_routes() {
		// Other plugins sometimes emit stray notices during REST bootstrap.
		// Capture everything from here on so the JSON body stays clean.
		if ( $this->is_mcp_request() ) {
			ob_start();
		}

		register_rest_route(
			self::REST_NAMESPACE,
			'/mcp/(?P<token>[A-Za-z0-9_-]+)',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'handle_post' ),
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'handle_get' ),
					'permission_callback' => '__return_true',
				),
			)
		);
	}

	/**
	 * There is no SSE stream in v1; GET is not part of this transport.
	 *
	 * @return WP_REST_Response
	 */
	public function handle_get() {
		return new WP_REST_Response( array( 'error' => 'method_not_allowed' ), 405 );
	}

	/**
	 * Handles a JSON-RPC message POSTed to the MCP endpoint.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function handle_post( $request ) {
		if ( ! $this->auth->is_endpoint_enabled() ) {
			return new WP_REST_Response( array( 'error' => 'not_found' ), 404 );
		}

		if ( ! $this->auth->verify( (string) $request->get_param( 'token' ) ) ) {
			return new WP_REST_Response( array( 'error' => 'unauthorized' ), 401 );
		}

		$message = json_decode( $request->get_body(), true );

		if ( null === $message && JSON_ERROR_NONE !== json_last_error() ) {
			return $this->error_response( null, -32700, 'Parse error', 400 );
		}

		if ( ! is_array( $message ) || ! isset( $message['jsonrpc'] ) || '2.0' !== $message['jsonrpc'] || empty( $message['method'] ) || ! is_string( $message['method'] ) ) {
			return $this->error_response( null, -32600, 'Invalid Request', 400 );
		}

		// Notifications and client responses get 202 Accepted with an empty
		// body and no JSON-RPC response object.
		if ( ! array_key_exists( 'id', $message ) ) {
			return new WP_REST_Response( null, 202 );
		}

		$id     = $message['id'];
		$params = isset( $message['params'] ) && is_array( $message['params'] ) ? $message['params'] : array();

		switch ( $message['method'] ) {
			case 'initialize':
				return $this->result_response( $id, $this->initialize_result( $params ) );

			case 'tools/list':
				return $this->result_response( $id, array( 'tools' => $this->registry->list_tools() ) );

			case 'tools/call':
				return $this->tools_call( $id, $params );

			case 'ping':
				return $this->result_response( $id, new stdClass() );

			default:
				return $this->error_response( $id, -32601, 'Method not found' );
		}
	}

	/**
	 * Builds the initialize result, negotiating the protocol version.
	 *
	 * @param array $params Client params.
	 * @return array
	 */
	private function initialize_result( $params ) {
		$requested = isset( $params['protocolVersion'] ) ? (string) $params['protocolVersion'] : '';
		$version   = in_array( $requested, self::PROTOCOL_VERSIONS, true ) ? $requested : self::PROTOCOL_VERSIONS[0];

		return array(
			'protocolVersion' => $version,
			'capabilities'    => array( 'tools' => new stdClass() ),
			'serverInfo'      => array(
				'name'    => 'PluginLens',
				'version' => PLUGINLENS_VERSION,
			),
		);
	}

	/**
	 * Dispatches tools/call to the registry.
	 *
	 * @param mixed $id     JSON-RPC id.
	 * @param array $params Call params.
	 * @return WP_REST_Response
	 */
	private function tools_call( $id, $params ) {
		$name = isset( $params['name'] ) ? (string) $params['name'] : '';

		if ( '' === $name || ! $this->registry->has( $name ) ) {
			return $this->error_response( $id, -32602, 'Unknown tool: ' . $name );
		}

		$arguments = isset( $params['arguments'] ) && is_array( $params['arguments'] ) ? $params['arguments'] : array();

		return $this->result_response( $id, $this->registry->call( $name, $arguments ) );
	}

	/**
	 * Wraps a result in a JSON-RPC envelope.
	 *
	 * @param mixed $id     JSON-RPC id.
	 * @param mixed $result Result value.
	 * @return WP_REST_Response
	 */
	private function result_response( $id, $result ) {
		return new WP_REST_Response(
			array(
				'jsonrpc' => '2.0',
				'id'      => $id,
				'result'  => $result,
			),
			200
		);
	}

	/**
	 * Wraps an error in a JSON-RPC envelope.
	 *
	 * @param mixed  $id      JSON-RPC id, null when unknowable.
	 * @param int    $code    JSON-RPC error code.
	 * @param string $message Error message.
	 * @param int    $status  HTTP status, 200 for well-formed requests.
	 * @return WP_REST_Response
	 */
	private function error_response( $id, $code, $message, $status = 200 ) {
		return new WP_REST_Response(
			array(
				'jsonrpc' => '2.0',
				'id'      => $id,
				'error'   => array(
					'code'    => $code,
					'message' => $message,
				),
			),
			$status
		);
	}

	/**
	 * Discards stray buffered output before WordPress writes the response,
	 * and serves 202 notifications with a genuinely empty body.
	 *
	 * Runs on rest_pre_serve_request for every REST request; acts only on ours.
	 *
	 * @param bool             $served  Whether the request has already been served.
	 * @param WP_REST_Response $result  Result to send.
	 * @param WP_REST_Request  $request Request used to generate the response.
	 * @param WP_REST_Server   $server  Server instance.
	 * @return bool
	 */
	public function serve_empty_accepted_response( $served, $result, $request, $server ) {
		unset( $server );

		if ( 0 !== strpos( $request->get_route(), '/' . self::REST_NAMESPACE . '/mcp/' ) ) {
			return $served;
		}

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		if ( 202 === $result->get_status() ) {
			return true;
		}

		return $served;
	}

	/**
	 * Whether the current request targets the MCP endpoint.
	 *
	 * @return bool
	 */
	private function is_mcp_request() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		return false !== strpos( $uri, self::REST_NAMESPACE . '/mcp/' );
	}
}
