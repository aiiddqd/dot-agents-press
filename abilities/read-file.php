<?php
/**
 * Read File Ability
 *
 * @package DotAgentsPress\Abilities
 */

namespace DotAgentsPress\Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Ability to safely read file contents from allowed directories.
 */
class ReadFile extends AbilityAbstract {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->name        = 'dot-agents-press/read-file';
		$this->label       = 'Read File';
		$this->description = 'Safely read file contents from allowed directories';
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
				'file_path' => [
					'type'        => 'string',
					'description' => 'Path to the file to read',
				],
			],
			'required' => [ 'file_path' ],
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
				'content' => [
					'type'        => 'string',
					'description' => 'File contents',
				],
				'size'    => [
					'type'        => 'integer',
					'description' => 'File size in bytes',
				],
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
		$file_path = isset( $args['file_path'] ) ? (string) $args['file_path'] : '';

		if ( empty( $file_path ) ) {
			return new \WP_Error( 'invalid_input', 'file_path is required' );
		}

		$real_path = realpath( $file_path );
		if ( ! $real_path ) {
			return new \WP_Error( 'not_found', 'File not found' );
		}

		$allowed = $this->get_allowed_dirs();
		if ( ! $this->is_path_allowed( $real_path, $allowed ) ) {
			return new \WP_Error( 'access_denied', 'Path not allowed' );
		}

		if ( ! is_file( $real_path ) ) {
			return new \WP_Error( 'not_a_file', 'Path is not a file' );
		}

		if ( ! is_readable( $real_path ) ) {
			return new \WP_Error( 'not_readable', 'File is not readable' );
		}

		$content = @file_get_contents( $real_path );
		if ( $content === false ) {
			return new \WP_Error( 'read_failed', 'Failed to read file' );
		}

		return [
			'content' => $content,
			'size'    => strlen( $content ),
		];
	}
}
