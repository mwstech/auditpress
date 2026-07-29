<?php
/**
 * MCP transport: route registration and JSON-RPC 2.0 envelope.
 *
 * Speaks Streamable HTTP with plain application/json responses (permitted by
 * the MCP spec as the non-SSE variant). Stateless: no session ID is issued.
 *
 * @package AuditPress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers POST /wp-json/auditpress/v1/mcp/{token} and dispatches JSON-RPC.
 */
class AuditPress_MCP_Server {

	const REST_NAMESPACE = 'auditpress/v1';

	/**
	 * Legacy protocol versions this server supports, newest first: the
	 * revisions that negotiate through an initialize handshake. Verified
	 * against the live MCP specification on 2026-07-27.
	 *
	 * @var string[]
	 */
	const PROTOCOL_VERSIONS = array( '2025-11-25', '2025-06-18', '2025-03-26' );

	/**
	 * Modern protocol versions: revisions that carry version, identity, and
	 * capabilities in per-request _meta (spec 2026-07-28 and later). A request
	 * is served in the era its shape declares; the server holds no state
	 * about which era a client speaks.
	 *
	 * @var string[]
	 */
	const MODERN_VERSIONS = array( '2026-07-28' );

	/**
	 * Reserved _meta keys from the 2026-07-28 revision.
	 */
	const META_PROTOCOL_VERSION    = 'io.modelcontextprotocol/protocolVersion';
	const META_CLIENT_CAPABILITIES = 'io.modelcontextprotocol/clientCapabilities';
	const META_SERVER_INFO         = 'io.modelcontextprotocol/serverInfo';

	/**
	 * Spec-defined error codes (2026-07-28), allocated from the sub-range the
	 * MCP specification reserves.
	 */
	const ERR_HEADER_MISMATCH     = -32020;
	const ERR_UNSUPPORTED_VERSION = -32022;

	/**
	 * Freshness hint on cacheable list results, in milliseconds: 24 hours.
	 * The tool catalog is fixed at build time and changes only when the
	 * plugin itself is updated, so a long TTL is honest; a day bounds how
	 * long a client can miss a new tool after an update without reconnecting
	 * (docs/DECISIONS.md 58).
	 */
	const LIST_TTL_MS = 86400000;

	/**
	 * Shared intermediaries must never cache MCP responses from this server:
	 * the token rides in the URL path, and a cached response is a cached
	 * secret-addressed answer.
	 */
	const LIST_CACHE_SCOPE = 'private';

	/**
	 * Authentication mechanism.
	 *
	 * @var AuditPress_Auth_Interface
	 */
	private $auth;

	/**
	 * Tool catalog.
	 *
	 * @var AuditPress_Tool_Registry
	 */
	private $registry;

	/**
	 * Constructor.
	 *
	 * @param AuditPress_Auth_Interface $auth     Authentication mechanism.
	 * @param AuditPress_Tool_Registry  $registry Tool catalog.
	 */
	public function __construct( AuditPress_Auth_Interface $auth, AuditPress_Tool_Registry $registry ) {
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

		// Rate limiting sits before authentication so failed-token attempts
		// are throttled too.
		if ( ! AuditPress_Request_Guard::allow_request() ) {
			return new WP_REST_Response( array( 'error' => 'rate_limited' ), 429 );
		}

		if ( ! $this->auth->verify( (string) $request->get_param( 'token' ) ) ) {
			AuditPress_Request_Guard::log_failed_auth();
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
		$meta   = isset( $params['_meta'] ) && is_array( $params['_meta'] ) ? $params['_meta'] : array();

		// Era selection, per request, exactly as the 2026-07-28 versioning
		// page specifies for dual-era servers: a request carrying modern
		// per-request _meta is served statelessly under the new revision;
		// anything else is served under the legacy handshake revisions. No
		// state is held about which era a client spoke last time.
		if ( array_key_exists( self::META_PROTOCOL_VERSION, $meta ) ) {
			return $this->handle_modern( $request, $id, $message['method'], $params, $meta );
		}

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
	 * Serves one request under the 2026-07-28 revision.
	 *
	 * Validation order: header agreement first (a body and header that
	 * disagree is a request-smuggling shape and is rejected outright, never
	 * resolved in favor of either), then required _meta fields, then version
	 * support, then dispatch.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @param mixed           $id      JSON-RPC id.
	 * @param string          $method  JSON-RPC method.
	 * @param array           $params  Request params.
	 * @param array           $meta    Request _meta.
	 * @return WP_REST_Response
	 */
	private function handle_modern( $request, $id, $method, $params, $meta ) {
		$version = is_scalar( $meta[ self::META_PROTOCOL_VERSION ] ) ? (string) $meta[ self::META_PROTOCOL_VERSION ] : '';

		// The MCP-Protocol-Version header MUST be present and MUST match the
		// _meta value; Mcp-Method MUST be present and match the body method;
		// Mcp-Name MUST accompany tools/call and match params.name. Missing
		// and mismatched are the same failure: HeaderMismatch, HTTP 400.
		$header_version = (string) $request->get_header( 'MCP-Protocol-Version' );
		if ( $header_version !== $version ) {
			return $this->error_response( $id, self::ERR_HEADER_MISMATCH, 'Header mismatch: MCP-Protocol-Version header does not match the _meta protocol version.', 400 );
		}

		$header_method = (string) $request->get_header( 'Mcp-Method' );
		if ( $header_method !== $method ) {
			return $this->error_response( $id, self::ERR_HEADER_MISMATCH, 'Header mismatch: Mcp-Method header does not match the request body method.', 400 );
		}

		if ( 'tools/call' === $method ) {
			$header_name = $this->decode_header_value( (string) $request->get_header( 'Mcp-Name' ) );
			$body_name   = isset( $params['name'] ) && is_scalar( $params['name'] ) ? (string) $params['name'] : '';
			if ( $header_name !== $body_name ) {
				return $this->error_response( $id, self::ERR_HEADER_MISMATCH, 'Header mismatch: Mcp-Name header does not match the request body tool name.', 400 );
			}
		}

		// clientCapabilities is required on every modern request; a request
		// without it is malformed.
		if ( ! array_key_exists( self::META_CLIENT_CAPABILITIES, $meta ) ) {
			return $this->error_response( $id, -32602, 'Invalid params: _meta is missing io.modelcontextprotocol/clientCapabilities.', 400 );
		}

		if ( ! in_array( $version, self::MODERN_VERSIONS, true ) ) {
			return $this->error_response(
				$id,
				self::ERR_UNSUPPORTED_VERSION,
				'Unsupported protocol version',
				400,
				array(
					'supported' => array_merge( self::MODERN_VERSIONS, self::PROTOCOL_VERSIONS ),
					'requested' => $version,
				)
			);
		}

		switch ( $method ) {
			case 'server/discover':
				return $this->result_response( $id, $this->discover_result() );

			case 'tools/list':
				return $this->result_response(
					$id,
					$this->modern_result(
						array(
							'tools'      => $this->registry->list_tools(),
							'ttlMs'      => self::LIST_TTL_MS,
							'cacheScope' => self::LIST_CACHE_SCOPE,
						)
					)
				);

			case 'tools/call':
				$response = $this->tools_call( $id, $params );
				$body     = $response->get_data();
				if ( isset( $body['result'] ) && is_array( $body['result'] ) ) {
					$body['result'] = $this->modern_result( $body['result'] );
					$response->set_data( $body );
				}
				return $response;

			default:
				// initialize and ping land here deliberately: the handshake
				// is retired in this revision and ping was removed from it.
				// Unknown method on Streamable HTTP is 404 with -32601.
				return $this->error_response( $id, -32601, 'Method not found', 404 );
		}
	}

	/**
	 * Builds the server/discover result. Its shape is defined fresh in the
	 * 2026-07-28 revision and does not mirror the old initialize result.
	 *
	 * @return array
	 */
	private function discover_result() {
		return $this->modern_result(
			array(
				'supportedVersions' => array_merge( self::MODERN_VERSIONS, self::PROTOCOL_VERSIONS ),
				'capabilities'      => array( 'tools' => new stdClass() ),
				'instructions'      => 'Read-only MCP server for inspecting this WordPress site\'s plugin estate. Call get_capabilities first to orient yourself; it documents every tool, flag, and degradation behavior.',
				'ttlMs'             => self::LIST_TTL_MS,
				'cacheScope'        => self::LIST_CACHE_SCOPE,
			)
		);
	}

	/**
	 * Stamps the fields the 2026-07-28 revision expects on every result:
	 * resultType (required) and the server's identity in _meta (SHOULD).
	 *
	 * @param array $result Bare result payload.
	 * @return array
	 */
	private function modern_result( $result ) {
		$result['resultType'] = 'complete';

		$result_meta                           = isset( $result['_meta'] ) && is_array( $result['_meta'] ) ? $result['_meta'] : array();
		$result_meta[ self::META_SERVER_INFO ] = array(
			'name'    => 'AuditPress',
			'version' => AUDITPRESS_VERSION,
		);
		$result['_meta']                       = $result_meta;

		return $result;
	}

	/**
	 * Decodes the Base64 sentinel format the transport defines for header
	 * values that cannot ride as plain ASCII (=?base64?...?=). Values not in
	 * sentinel form pass through untouched. The spec requires decoding before
	 * comparison against the body.
	 *
	 * @param string $value Raw header value.
	 * @return string
	 */
	private function decode_header_value( $value ) {
		if ( 0 !== strpos( $value, '=?base64?' ) || '?=' !== substr( $value, -2 ) ) {
			return $value;
		}
		$decoded = base64_decode( substr( $value, 9, -2 ), true );
		return false === $decoded ? $value : $decoded;
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
				'name'    => 'AuditPress',
				'version' => AUDITPRESS_VERSION,
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
	 * @param mixed      $id      JSON-RPC id, null when unknowable.
	 * @param int        $code    JSON-RPC error code.
	 * @param string     $message Error message.
	 * @param int        $status  HTTP status, 200 for well-formed requests.
	 * @param array|null $data    Optional error data member.
	 * @return WP_REST_Response
	 */
	private function error_response( $id, $code, $message, $status = 200, $data = null ) {
		$error = array(
			'code'    => $code,
			'message' => $message,
		);
		if ( null !== $data ) {
			$error['data'] = $data;
		}
		return new WP_REST_Response(
			array(
				'jsonrpc' => '2.0',
				'id'      => $id,
				'error'   => $error,
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
