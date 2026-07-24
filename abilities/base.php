<?php
/**
 * Base Ability Abstract Class
 *
 * @package DotAgentsPress\Abilities
 */

namespace DotAgentsPress\Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Abstract base class for all abilities.
 *
 * Each ability must extend this class and implement:
 * - get_input_schema()
 * - execute()
 *
 * Optionally override:
 * - get_output_schema()
 * - check_permission()
 */
abstract class AbilityAbstract {

	/**
	 * Machine name of the ability (e.g., 'dot-agents-press/read-file').
	 *
	 * @var string
	 */
	public string $name;

	/**
	 * Human-readable label.
	 *
	 * @var string
	 */
	public string $label;

	/**
	 * Description of what the ability does.
	 *
	 * @var string
	 */
	public string $description;

	/**
	 * Category for grouping abilities.
	 *
	 * @var string
	 */
	public string $category = 'dot-agents-press';

	/**
	 * Get the input schema (JSON Schema format).
	 *
	 * @return array
	 */
	abstract public function get_input_schema(): array;

	/**
	 * Get the output schema (JSON Schema format).
	 *
	 * @return array
	 */
	public function get_output_schema(): array {
		return [
			'type' => 'object',
		];
	}

	/**
	 * Execute the ability with the given arguments.
	 *
	 * @param array $args Input arguments.
	 * @return mixed Result (can be string, array, WP_Error, etc.).
	 */
	abstract public function execute( array $args );

	/**
	 * Get the ability definition for registration.
	 *
	 * @return array
	 */
	public function get_definition(): array {
		return [
			'name'                => $this->name,
			'label'               => $this->label,
			'description'         => $this->description,
			'category'            => $this->category,
			'input_schema'        => $this->get_input_schema(),
			'output_schema'       => $this->get_output_schema(),
			'execute_callback'    => [ $this, 'execute' ],
			'permission_callback' => [ $this, 'check_permission' ],
		];
	}

	/**
	 * Check if the current user has permission to execute this ability.
	 *
	 * @return bool
	 */
	public function check_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Get allowed directories for file operations.
	 *
	 * @return array
	 */
	protected function get_allowed_dirs(): array {
		return apply_filters(
			'dap_ability_allowed_dirs',
			[
				ABSPATH,
				WP_CONTENT_DIR,
				DAP_PLUGIN_DIR,
				dirname( ABSPATH ) . '/.agents/',
			]
		);
	}

	/**
	 * Check if a real path is within allowed directories.
	 *
	 * @param string $real_path Normalized path from realpath().
	 * @param array  $allowed   Array of allowed base directories.
	 * @return bool
	 */
	protected function is_path_allowed( string $real_path, array $allowed ): bool {
		foreach ( $allowed as $dir ) {
			$allowed_real = realpath( $dir );
			if ( $allowed_real && str_starts_with( $real_path, $allowed_real ) ) {
				return true;
			}
		}
		return false;
	}
}
