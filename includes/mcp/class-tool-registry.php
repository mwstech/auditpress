<?php
/**
 * Tool definitions and dispatch.
 *
 * @package PluginLens
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Holds the MCP tool catalog. Knows nothing about transport or WordPress state.
 */
class PluginLens_Tool_Registry {

	/**
	 * Registered tools, keyed by name.
	 *
	 * @var array<string, array{description: string, input_schema: array, callback: callable}>
	 */
	private $tools = array();

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
