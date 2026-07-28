<?php
/**
 * Tool definitions and dispatch.
 *
 * @package AuditPress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Holds the MCP tool catalog. Knows nothing about transport or WordPress state.
 */
class AuditPress_Tool_Registry {

	/**
	 * Registered tools, keyed by name.
	 *
	 * @var array<string, array{description: string, input_schema: array, callback: callable}>
	 */
	private $tools = array();

	/**
	 * Auto-discovers tools from a directory. Every class-tool-*.php file is
	 * loaded and its class (derived from the file name) is asked to register
	 * itself. Adding a tool means dropping one file here, nothing else.
	 *
	 * @param string $dir Absolute path to the tools directory.
	 * @return void
	 */
	public function load_tools_from( $dir ) {
		foreach ( glob( trailingslashit( $dir ) . 'class-tool-*.php' ) as $file ) {
			require_once $file;
			$stem  = substr( basename( $file, '.php' ), strlen( 'class-' ) );
			$class = 'AuditPress_' . implode( '_', array_map( 'ucfirst', explode( '-', $stem ) ) );
			if ( class_exists( $class ) && method_exists( $class, 'register' ) ) {
				call_user_func( array( $class, 'register' ), $this );
			}
		}
	}

	/**
	 * Wraps a tool payload with the mandatory _meta object and encodes it.
	 * Every tool response goes through this, per SPEC section 6.
	 *
	 * @param array $payload             Tool-specific payload.
	 * @param int   $total               Total records matching before pagination.
	 * @param int   $returned            Records actually included.
	 * @param bool  $truncated           Whether pagination cut the result short.
	 * @param array $sources_unavailable Enrichment sources that failed or are absent.
	 * @return string JSON string.
	 */
	public static function with_meta( $payload, $total, $returned, $truncated, $sources_unavailable = array() ) {
		$payload['_meta'] = array(
			'total'               => $total,
			'returned'            => $returned,
			'truncated'           => $truncated,
			'sources_unavailable' => $sources_unavailable,
			'generated_at'        => gmdate( 'Y-m-d\TH:i:s\Z' ),
		);
		// Escaped slashes and \uXXXX sequences get escaped again inside the
		// MCP text block; emitting them raw keeps responses well under budget.
		return wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/**
	 * Rounds a byte count to two significant figures with a labeled unit.
	 *
	 * @param int|null $bytes Byte count, or null when unmeasured.
	 * @return string|null e.g. "1.2 MB", "640 KB", or null.
	 */
	public static function format_bytes( $bytes ) {
		if ( null === $bytes ) {
			return null;
		}
		$bytes     = (float) $bytes;
		$units     = array( 'B', 'KB', 'MB', 'GB' );
		$max_index = count( $units ) - 1;
		$i         = 0;
		while ( $bytes >= 1024 && $i < $max_index ) {
			$bytes /= 1024;
			++$i;
		}
		$digits  = ( $bytes >= 10 || 0 === (int) round( $bytes * 10 ) % 10 ) ? 0 : 1;
		$rounded = ( $bytes >= 100 ) ? round( $bytes / 10 ) * 10 : round( $bytes, $digits );
		return $rounded . ' ' . $units[ $i ];
	}

	/**
	 * Registers a tool.
	 *
	 * @param string   $name         Tool name.
	 * @param string   $description  Human-readable description.
	 * @param array    $input_schema JSON Schema for the tool arguments.
	 * @param callable $callback     Callable receiving the arguments array, returning a JSON string.
	 * @return void
	 */
	public function register( $name, $description, $input_schema, $callback ) {
		$this->tools[ $name ] = array(
			'description'  => $description,
			'input_schema' => $input_schema,
			'callback'     => $callback,
		);
	}

	/**
	 * Tool list in the shape MCP tools/list expects.
	 *
	 * @return array[]
	 */
	public function list_tools() {
		$out = array();
		foreach ( $this->tools as $name => $tool ) {
			$out[] = array(
				'name'        => $name,
				'description' => $tool['description'],
				'inputSchema' => $tool['input_schema'],
			);
		}
		return $out;
	}

	/**
	 * Whether a tool exists.
	 *
	 * @param string $name Tool name.
	 * @return bool
	 */
	public function has( $name ) {
		return isset( $this->tools[ $name ] );
	}

	/**
	 * Runs a tool and wraps its output in an MCP content block.
	 *
	 * @param string $name      Tool name.
	 * @param array  $arguments Tool arguments.
	 * @return array MCP tools/call result.
	 */
	public function call( $name, $arguments ) {
		try {
			$text = call_user_func( $this->tools[ $name ]['callback'], $arguments );
			return array(
				'content' => array(
					array(
						'type' => 'text',
						'text' => $text,
					),
				),
				'isError' => false,
			);
		} catch ( Exception $e ) {
			return array(
				'content' => array(
					array(
						'type' => 'text',
						'text' => 'Tool execution failed: ' . $e->getMessage(),
					),
				),
				'isError' => true,
			);
		}
	}
}
