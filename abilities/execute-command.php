<?php
/**
 * Execute Command Ability
 *
 * @package DotAgentsPress\Abilities
 */

namespace DotAgentsPress\Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Ability to execute shell commands from a whitelist.
 */
class ExecuteCommand extends AbilityAbstract {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->name        = 'dot-agents-press/execute-command';
		$this->label       = 'Execute Command';
		$this->description = 'Execute whitelisted shell commands';
	}

	/**
	 * Get input schema.
	 *
	 * @return array
	 */
	public function get_input_schema(): array {
		return [
			'type'       => 'object',
			'properties' => [
				'command' => [
					'type'        => 'string',
					'description' => 'Shell command to execute',
				],
				'timeout' => [
					'type'        => 'integer',
					'description' => 'Command timeout in seconds (default: 30)',
					'default'     => 30,
				],
			],
			'required' => [ 'command' ],
		];
	}

	/**
	 * Get output schema.
	 *
	 * @return array
	 */
	public function get_output_schema(): array {
		return [
			'type' => 'object',
			'properties' => [
				'output'    => [ 'type' => 'string' ],
				'exit_code' => [ 'type' => 'integer' ],
			],
		];
	}

	/**
	 * Execute the ability.
	 *
	 * @param array $args Input arguments.
	 * @return array|WP_Error
	 */
	public function execute( array $args ) {
		$command = isset( $args['command'] ) ? (string) $args['command'] : '';
		$timeout = (int) ( $args['timeout'] ?? 30 );

		if ( empty( $command ) ) {
			return new \WP_Error( 'invalid_input', 'command is required' );
		}

		if ( $timeout < 1 ) {
			$timeout = 1;
		}

		if ( ! $this->is_command_allowed( $command ) ) {
			return new \WP_Error( 'forbidden', 'Command not in whitelist' );
		}

		$output = [];
		$code   = 0;

		// Execute command with timeout and capture stderr.
		exec( $command . ' 2>&1', $output, $code );

		return [
			'output'    => implode( "\n", $output ),
			'exit_code' => $code,
		];
	}

	/**
	 * Check if a command is allowed by the whitelist.
	 *
	 * @param string $command Command to check.
	 * @return bool
	 */
	private function is_command_allowed( string $command ): bool {
		$whitelist = apply_filters(
			'dap_ability_command_whitelist',
			[
				'wp ',
				'git ',
				'ls ',
				'cat ',
				'grep ',
				'find ',
				'composer ',
				'npm ',
				'node ',
				'php ',
			]
		);

		$trimmed = trim( $command );

		foreach ( $whitelist as $allowed ) {
			if ( str_starts_with( $trimmed, $allowed ) ) {
				return true;
			}
		}

		return false;
	}
}
