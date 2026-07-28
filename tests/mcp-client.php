<?php
/**
 * CLI harness that speaks raw JSON-RPC to a AuditPress MCP endpoint.
 *
 * Usage:
 *   php tests/mcp-client.php <endpoint-url> [--insecure] [--call <tool> [--args '<json>']]
 *
 * With no --call, runs the standard Phase 0 sequence: initialize,
 * notifications/initialized, tools/list, tools/call get_capabilities, ping.
 * Prints every response and its byte size. Exits non-zero on any failure.
 *
 * This file never ships to wp.org and never runs inside WordPress.
 *
 * @package AuditPress
 */

if ( PHP_SAPI !== 'cli' ) {
	fwrite( STDERR, "CLI only.\n" );
	exit( 1 );
}

$options = auditpress_client_parse_argv( array_slice( $argv, 1 ) );
if ( null === $options ) {
	fwrite( STDERR, "Usage: php tests/mcp-client.php <endpoint-url> [--insecure] [--call <tool> [--args '<json>']]\n" );
	exit( 1 );
}

$client = new AuditPress_MCP_Client( $options['url'], $options['insecure'] );

$failures = 0;

// 1. initialize.
$init = $client->request(
	'initialize',
	array(
		'protocolVersion' => '2025-11-25',
		'capabilities'    => new stdClass(),
		'clientInfo'      => array(
			'name'    => 'auditpress-test-harness',
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
 * Parses CLI arguments.
 *
 * @param string[] $args Arguments after the script name.
 * @return array{url: string, insecure: bool, call: ?string, args: array|stdClass}|null
 */
function auditpress_client_parse_argv( $args ) {
	$out = array(
		'url'      => '',
		'insecure' => false,
		'call'     => null,
		'args'     => new stdClass(),
	);
	$i   = 0;
	$n   = count( $args );
	while ( $i < $n ) {
		$arg = $args[ $i ];
		if ( '--insecure' === $arg ) {
			$out['insecure'] = true;
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
class AuditPress_MCP_Client {

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
	 * POSTs a message to the endpoint.
	 *
	 * @param array $message JSON-RPC message.
	 * @return array{status: int, raw: string, body: ?array}
	 */
	private function post( $message ) {
		$payload = json_encode( $message );
		$headers = array(
			'Content-Type: application/json',
			'Accept: application/json, text/event-stream',
		);
		if ( null !== $this->protocol_version ) {
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
