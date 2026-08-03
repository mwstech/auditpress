<?php
/**
 * CLI harness that speaks raw JSON-RPC to a Auditra MCP endpoint.
 *
 * Usage:
 *   php tests/mcp-client.php <endpoint-url> [--insecure] [--modern] [--call <tool> [--args '<json>']]
 *
 * With no --call, runs the standard Phase 0 sequence: initialize,
 * notifications/initialized, tools/list, tools/call get_capabilities, ping.
 * Prints every response and its byte size. Exits non-zero on any failure.
 *
 * With --modern, speaks MCP revision 2026-07-28 instead: per-request _meta
 * and Mcp-* headers, server/discover in place of the retired handshake, and
 * negative cases for header mismatch, unsupported version, and missing
 * required _meta.
 *
 * This file never ships to wp.org and never runs inside WordPress.
 *
 * @package Auditra
 */

if ( PHP_SAPI !== 'cli' ) {
	fwrite( STDERR, "CLI only.\n" );
	exit( 1 );
}

$options = auditra_client_parse_argv( array_slice( $argv, 1 ) );
if ( null === $options ) {
	fwrite( STDERR, "Usage: php tests/mcp-client.php <endpoint-url> [--insecure] [--call <tool> [--args '<json>']]\n" );
	exit( 1 );
}

$client = new Auditra_MCP_Client( $options['url'], $options['insecure'] );

$failures = 0;

if ( $options['modern'] ) {
	exit( auditra_run_modern_sequence( $client, $options ) );
}

// 1. initialize.
$init = $client->request(
	'initialize',
	array(
		'protocolVersion' => '2025-11-25',
		'capabilities'    => new stdClass(),
		'clientInfo'      => array(
			'name'    => 'auditra-test-harness',
			'version' => '0.1.0',
		),
	)
);
$failures += $client->assert_result(
	$init,
	'initialize',
	function ( $result ) {
		return isset( $result['protocolVersion'], $result['serverInfo']['name'], $result['capabilities']['tools'] );
	}
);
if ( isset( $init['body']['result']['protocolVersion'] ) ) {
	$client->set_protocol_version( $init['body']['result']['protocolVersion'] );
}

// 2. notifications/initialized: expect HTTP 202 and an empty body.
$notified = $client->notify( 'notifications/initialized' );
if ( 202 === $notified['status'] && '' === trim( (string) $notified['raw'] ) ) {
	$client->report_pass( 'notifications/initialized', $notified );
} else {
	$client->report_fail( 'notifications/initialized', $notified, 'expected HTTP 202 with empty body' );
	++$failures;
}

if ( null !== $options['call'] ) {
	// Single ad-hoc tool call, for later phases.
	$call      = $client->request(
		'tools/call',
		array(
			'name'      => $options['call'],
			'arguments' => $options['args'],
		)
	);
	$failures += $client->assert_result(
		$call,
		'tools/call ' . $options['call'],
		function ( $result ) {
			return isset( $result['content'][0]['text'] ) && empty( $result['isError'] );
		}
	);
} else {
	// 3. tools/list: must contain get_capabilities.
	$list      = $client->request( 'tools/list', null );
	$failures += $client->assert_result(
		$list,
		'tools/list',
		function ( $result ) {
			foreach ( isset( $result['tools'] ) ? $result['tools'] : array() as $tool ) {
				if ( isset( $tool['name'] ) && 'get_capabilities' === $tool['name'] ) {
					return true;
				}
			}
			return false;
		}
	);

	// 4. tools/call get_capabilities: text block must itself be valid JSON.
	$call      = $client->request(
		'tools/call',
		array(
			'name'      => 'get_capabilities',
			'arguments' => new stdClass(),
		)
	);
	$failures += $client->assert_result(
		$call,
		'tools/call get_capabilities',
		function ( $result ) {
			if ( ! isset( $result['content'][0]['text'] ) || ! empty( $result['isError'] ) ) {
				return false;
			}
			return null !== json_decode( $result['content'][0]['text'], true );
		}
	);

	// 5. ping.
	$ping      = $client->request( 'ping', null );
	$failures += $client->assert_result(
		$ping,
		'ping',
		function ( $result ) {
			return is_array( $result );
		}
	);
}

echo str_repeat( '-', 60 ) . "\n";
if ( 0 === $failures ) {
	echo "All checks passed.\n";
	exit( 0 );
}
echo $failures . " check(s) failed.\n";
exit( 1 );

/**
 * Runs the 2026-07-28 sequence: server/discover, tools/list, tools/call,
 * then the failure shapes the revision defines.
 *
 * @param Auditra_MCP_Client $client  Client.
 * @param array                 $options Parsed CLI options.
 * @return int Exit code.
 */
function auditra_run_modern_sequence( $client, $options ) {
	$failures = 0;

	if ( null !== $options['call'] ) {
		$call     = $client->modern_request(
			'tools/call',
			array(
				'name'      => $options['call'],
				'arguments' => $options['args'],
			)
		);
		$failures = $client->assert_result(
			$call,
			'tools/call ' . $options['call'] . ' (modern)',
			function ( $result ) {
				return isset( $result['content'][0]['text'] ) && empty( $result['isError'] ) && 'complete' === $result['resultType'];
			}
		);
		echo str_repeat( '-', 60 ) . "\n";
		echo 0 === $failures ? "All checks passed.\n" : "1 check failed.\n";
		return 0 === $failures ? 0 : 1;
	}

	// 1. server/discover: supported versions, capabilities, identity, cache fields.
	$discover  = $client->modern_request( 'server/discover', null );
	$failures += $client->assert_result(
		$discover,
		'server/discover',
		function ( $result ) {
			return isset( $result['supportedVersions'], $result['capabilities']['tools'], $result['ttlMs'], $result['cacheScope'] )
				&& in_array( '2026-07-28', $result['supportedVersions'], true )
				&& 'complete' === $result['resultType']
				&& isset( $result['_meta']['io.modelcontextprotocol/serverInfo']['name'] );
		}
	);

	// 2. tools/list: catalog plus the CacheableResult fields.
	$list      = $client->modern_request( 'tools/list', null );
	$failures += $client->assert_result(
		$list,
		'tools/list (modern)',
		function ( $result ) {
			if ( ! isset( $result['ttlMs'], $result['cacheScope'] ) || 'complete' !== $result['resultType'] ) {
				return false;
			}
			foreach ( isset( $result['tools'] ) ? $result['tools'] : array() as $tool ) {
				if ( isset( $tool['name'] ) && 'get_capabilities' === $tool['name'] ) {
					return true;
				}
			}
			return false;
		}
	);

	// 3. tools/call with matching Mcp-Name header.
	$call      = $client->modern_request(
		'tools/call',
		array(
			'name'      => 'get_capabilities',
			'arguments' => new stdClass(),
		)
	);
	$failures += $client->assert_result(
		$call,
		'tools/call (modern)',
		function ( $result ) {
			return isset( $result['content'][0]['text'] ) && empty( $result['isError'] )
				&& 'complete' === $result['resultType']
				&& null !== json_decode( $result['content'][0]['text'], true );
		}
	);

	// 4. ping was removed from this revision: unknown method, 404.
	$ping      = $client->modern_request( 'ping', null );
	$failures += $client->assert_error( $ping, 'ping rejected (removed)', -32601, 404 );

	// 5. initialize is retired for modern requests: unknown method, 404.
	$init      = $client->modern_request( 'initialize', null );
	$failures += $client->assert_error( $init, 'initialize rejected (retired)', -32601, 404 );

	// 6. Mcp-Name header disagreeing with the body: HeaderMismatch, 400.
	$mismatch  = $client->modern_request(
		'tools/call',
		array(
			'name'      => 'get_capabilities',
			'arguments' => new stdClass(),
		),
		array(),
		array( 'Mcp-Name' => 'a_different_tool' )
	);
	$failures += $client->assert_error( $mismatch, 'Mcp-Name mismatch rejected', -32020, 400 );

	// 7. Missing Mcp-Method header: HeaderMismatch, 400.
	$missing   = $client->modern_request( 'tools/list', null, array(), array( 'Mcp-Method' => null ) );
	$failures += $client->assert_error( $missing, 'missing Mcp-Method rejected', -32020, 400 );

	// 8. Header and _meta versions disagreeing: HeaderMismatch, 400.
	$verhdr    = $client->modern_request( 'tools/list', null, array(), array( 'MCP-Protocol-Version' => '2026-01-01' ) );
	$failures += $client->assert_error( $verhdr, 'version header mismatch rejected', -32020, 400 );

	// 9. Consistent but unsupported version: UnsupportedProtocolVersion with
	// the supported list, 400.
	$unsupported = $client->modern_request(
		'tools/list',
		null,
		array( 'io.modelcontextprotocol/protocolVersion' => '1990-01-01' ),
		array( 'MCP-Protocol-Version' => '1990-01-01' )
	);
	$failures   += $client->assert_error( $unsupported, 'unsupported version rejected', -32022, 400 );
	if ( ! isset( $unsupported['body']['error']['data']['supported'] ) || ! in_array( '2026-07-28', $unsupported['body']['error']['data']['supported'], true ) ) {
		$client->report_fail( 'unsupported version lists supported', $unsupported, 'error.data.supported missing 2026-07-28' );
		++$failures;
	}

	// 10. Missing required clientCapabilities: Invalid params, 400.
	$nocaps    = $client->modern_request( 'tools/list', null, array( 'io.modelcontextprotocol/clientCapabilities' => null ) );
	$failures += $client->assert_error( $nocaps, 'missing clientCapabilities rejected', -32602, 400 );

	echo str_repeat( '-', 60 ) . "\n";
	if ( 0 === $failures ) {
		echo "All checks passed.\n";
		return 0;
	}
	echo $failures . " check(s) failed.\n";
	return 1;
}

/**
 * Parses CLI arguments.
 *
 * @param string[] $args Arguments after the script name.
 * @return array{url: string, insecure: bool, modern: bool, call: ?string, args: array|stdClass}|null
 */
function auditra_client_parse_argv( $args ) {
	$out = array(
		'url'      => '',
		'insecure' => false,
		'modern'   => false,
		'call'     => null,
		'args'     => new stdClass(),
	);
	$i   = 0;
	$n   = count( $args );
	while ( $i < $n ) {
		$arg = $args[ $i ];
		if ( '--insecure' === $arg ) {
			$out['insecure'] = true;
		} elseif ( '--modern' === $arg ) {
			$out['modern'] = true;
		} elseif ( '--call' === $arg && $i + 1 < $n ) {
			$out['call'] = $args[ ++$i ];
		} elseif ( '--args' === $arg && $i + 1 < $n ) {
			$decoded = json_decode( $args[ ++$i ], true );
			if ( ! is_array( $decoded ) ) {
				return null;
			}
			$out['args'] = $decoded;
		} elseif ( '' === $out['url'] && 0 !== strpos( $arg, '--' ) ) {
			$out['url'] = $arg;
		} else {
			return null;
		}
		++$i;
	}
	return '' === $out['url'] ? null : $out;
}

/**
 * Minimal JSON-RPC-over-HTTP client for the MCP endpoint.
 */
class Auditra_MCP_Client {

	/**
	 * Endpoint URL.
	 *
	 * @var string
	 */
	private $url;

	/**
	 * Skip TLS verification (Local self-signed certs).
	 *
	 * @var bool
	 */
	private $insecure;

	/**
	 * Negotiated protocol version, sent as MCP-Protocol-Version after initialize.
	 *
	 * @var string|null
	 */
	private $protocol_version = null;

	/**
	 * Auto-incrementing request id.
	 *
	 * @var int
	 */
	private $next_id = 1;

	/**
	 * Constructor.
	 *
	 * @param string $url      Endpoint URL.
	 * @param bool   $insecure Skip TLS verification.
	 */
	public function __construct( $url, $insecure ) {
		$this->url      = $url;
		$this->insecure = $insecure;
	}

	/**
	 * Records the negotiated protocol version.
	 *
	 * @param string $version Version string.
	 * @return void
	 */
	public function set_protocol_version( $version ) {
		$this->protocol_version = $version;
	}

	/**
	 * Sends a JSON-RPC request (with id) and returns status, raw body, parsed body.
	 *
	 * @param string $method JSON-RPC method.
	 * @param mixed  $params Params, or null to omit.
	 * @return array{status: int, raw: string, body: ?array}
	 */
	public function request( $method, $params ) {
		$message = array(
			'jsonrpc' => '2.0',
			'id'      => $this->next_id++,
			'method'  => $method,
		);
		if ( null !== $params ) {
			$message['params'] = $params;
		}
		return $this->post( $message );
	}

	/**
	 * Sends a JSON-RPC notification (no id).
	 *
	 * @param string $method JSON-RPC method.
	 * @return array{status: int, raw: string, body: ?array}
	 */
	public function notify( $method ) {
		return $this->post(
			array(
				'jsonrpc' => '2.0',
				'method'  => $method,
			)
		);
	}

	/**
	 * Sends a request in the 2026-07-28 shape: _meta on the params, version
	 * and method mirrored into headers. Overrides exist so the negative cases
	 * can send precisely malformed requests; null removes a field.
	 *
	 * @param string     $method           JSON-RPC method.
	 * @param array|null $params           Params, or null for _meta only.
	 * @param array      $meta_overrides   _meta keys to replace (null value removes).
	 * @param array      $header_overrides Headers to replace (null value removes).
	 * @return array{status: int, raw: string, body: ?array}
	 */
	public function modern_request( $method, $params, $meta_overrides = array(), $header_overrides = array() ) {
		$meta = array(
			'io.modelcontextprotocol/protocolVersion'    => '2026-07-28',
			'io.modelcontextprotocol/clientInfo'         => array(
				'name'    => 'auditra-test-harness',
				'version' => '0.2.0',
			),
			'io.modelcontextprotocol/clientCapabilities' => new stdClass(),
		);
		foreach ( $meta_overrides as $key => $value ) {
			if ( null === $value ) {
				unset( $meta[ $key ] );
			} else {
				$meta[ $key ] = $value;
			}
		}

		$params          = null === $params ? array() : $params;
		$params['_meta'] = $meta;

		$headers = array(
			'MCP-Protocol-Version' => isset( $meta['io.modelcontextprotocol/protocolVersion'] ) ? $meta['io.modelcontextprotocol/protocolVersion'] : null,
			'Mcp-Method'           => $method,
		);
		if ( 'tools/call' === $method && isset( $params['name'] ) ) {
			$headers['Mcp-Name'] = $params['name'];
		}
		foreach ( $header_overrides as $key => $value ) {
			if ( null === $value ) {
				unset( $headers[ $key ] );
			} else {
				$headers[ $key ] = $value;
			}
		}

		$lines = array();
		foreach ( $headers as $key => $value ) {
			if ( null !== $value ) {
				$lines[] = $key . ': ' . $value;
			}
		}

		return $this->post(
			array(
				'jsonrpc' => '2.0',
				'id'      => $this->next_id++,
				'method'  => $method,
				'params'  => $params,
			),
			$lines
		);
	}

	/**
	 * Checks that a response is the expected JSON-RPC error at the expected
	 * HTTP status. Prints either way.
	 *
	 * @param array  $response Response from a request method.
	 * @param string $label    Human label.
	 * @param int    $code     Expected JSON-RPC error code.
	 * @param int    $status   Expected HTTP status.
	 * @return int 0 on pass, 1 on fail.
	 */
	public function assert_error( $response, $label, $code, $status ) {
		$ok = $status === $response['status']
			&& is_array( $response['body'] )
			&& isset( $response['body']['error']['code'] )
			&& $code === $response['body']['error']['code'];

		if ( $ok ) {
			$this->report_pass( $label, $response );
			return 0;
		}
		$this->report_fail( $label, $response, sprintf( 'expected HTTP %d with error code %d', $status, $code ) );
		return 1;
	}

	/**
	 * POSTs a message to the endpoint.
	 *
	 * @param array      $message       JSON-RPC message.
	 * @param array|null $extra_headers Extra header lines, replacing the legacy version header.
	 * @return array{status: int, raw: string, body: ?array}
	 */
	private function post( $message, $extra_headers = null ) {
		$payload = json_encode( $message );
		$headers = array(
			'Content-Type: application/json',
			'Accept: application/json, text/event-stream',
		);
		if ( null !== $extra_headers ) {
			$headers = array_merge( $headers, $extra_headers );
		} elseif ( null !== $this->protocol_version ) {
			$headers[] = 'MCP-Protocol-Version: ' . $this->protocol_version;
		}

		$ch = curl_init( $this->url );
		curl_setopt_array(
			$ch,
			array(
				CURLOPT_POST           => true,
				CURLOPT_POSTFIELDS     => $payload,
				CURLOPT_HTTPHEADER     => $headers,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_TIMEOUT        => 30,
				CURLOPT_FOLLOWLOCATION => false,
				CURLOPT_SSL_VERIFYPEER => ! $this->insecure,
				CURLOPT_SSL_VERIFYHOST => $this->insecure ? 0 : 2,
			)
		);
		$raw    = curl_exec( $ch );
		$status = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
		$err    = curl_error( $ch );
		if ( PHP_VERSION_ID < 80000 ) {
			curl_close( $ch ); // No-op from PHP 8.0, deprecated in 8.5.
		}

		if ( false === $raw ) {
			return array(
				'status' => 0,
				'raw'    => 'curl error: ' . $err,
				'body'   => null,
			);
		}

		return array(
			'status' => $status,
			'raw'    => (string) $raw,
			'body'   => json_decode( (string) $raw, true ),
		);
	}

	/**
	 * Checks a response envelope and a predicate on its result. Prints either way.
	 *
	 * @param array    $response  Response from request().
	 * @param string   $label     Human label.
	 * @param callable $predicate Receives the result array, returns bool.
	 * @return int 0 on pass, 1 on fail.
	 */
	public function assert_result( $response, $label, $predicate ) {
		$ok = 200 === $response['status']
			&& is_array( $response['body'] )
			&& isset( $response['body']['result'] )
			&& call_user_func( $predicate, $response['body']['result'] );

		if ( $ok ) {
			$this->report_pass( $label, $response );
			return 0;
		}
		$this->report_fail( $label, $response, 'unexpected response' );
		return 1;
	}

	/**
	 * Prints a passing response with its byte size.
	 *
	 * @param string $label    Human label.
	 * @param array  $response Response array.
	 * @return void
	 */
	public function report_pass( $label, $response ) {
		printf(
			"PASS  %-32s  HTTP %d  %d bytes\n      %s\n",
			$label,
			$response['status'],
			strlen( $response['raw'] ),
			'' === trim( $response['raw'] ) ? '(empty body)' : $this->excerpt( $response['raw'] )
		);
	}

	/**
	 * Prints a failing response.
	 *
	 * @param string $label    Human label.
	 * @param array  $response Response array.
	 * @param string $why      Failure reason.
	 * @return void
	 */
	public function report_fail( $label, $response, $why ) {
		printf(
			"FAIL  %-32s  HTTP %d  %d bytes  (%s)\n      %s\n",
			$label,
			$response['status'],
			strlen( $response['raw'] ),
			$why,
			$this->excerpt( $response['raw'] )
		);
	}

	/**
	 * First 500 characters of a body, on one line.
	 *
	 * @param string $raw Raw body.
	 * @return string
	 */
	private function excerpt( $raw ) {
		$one_line = preg_replace( '/\s+/', ' ', trim( $raw ) );
		return strlen( $one_line ) > 500 ? substr( $one_line, 0, 500 ) . '…' : $one_line;
	}
}
