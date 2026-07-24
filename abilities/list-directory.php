<?php
/**
 * List Directory Ability
 *
 * @package DotAgentsPress\Abilities
 */

namespace DotAgentsPress\Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Ability to list files and directories in allowed paths.
 */
class ListDirectory extends AbilityAbstract {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->name        = 'dot-agents-press/list-directory';
		$this->label       = 'List Directory';
		$this->description = 'List files and directories in allowed paths';
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
				'directory' => [
					'type'        => 'string',
					'description' => 'Path to the directory to list',
				],
				'recursive' => [
					'type'        => 'boolean',
					'description' => 'Whether to list recursively',
					'default'     => false,
				],
				'max_depth' => [
					'type'        => 'integer',
					'description' => 'Maximum recursion depth (default: 3)',
					'default'     => 3,
				],
			],
			'required' => [ 'directory' ],
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
				'items' => [
					'type'  => 'array',
					'items' => [
						'type' => 'object',
						'properties' => [
							'name'  => [ 'type' => 'string' ],
							'type'  => [ 'type' => 'string', 'enum' => [ 'file', 'dir' ] ],
							'size'  => [ 'type' => 'integer' ],
							'mtime' => [ 'type' => 'integer' ],
						],
					],
				],
				'count' => [ 'type' => 'integer' ],
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
		$directory = isset( $args['directory'] ) ? (string) $args['directory'] : '';
		$recursive = (bool) ( $args['recursive'] ?? false );
		$max_depth = (int) ( $args['max_depth'] ?? 3 );

		if ( empty( $directory ) ) {
			return new \WP_Error( 'invalid_input', 'directory is required' );
		}

		$real_path = realpath( $directory );
		if ( ! $real_path ) {
			return new \WP_Error( 'not_found', 'Directory not found' );
		}

		$allowed = $this->get_allowed_dirs();
		if ( ! $this->is_path_allowed( $real_path, $allowed ) ) {
			return new \WP_Error( 'access_denied', 'Path not allowed' );
		}

		if ( ! is_dir( $real_path ) ) {
			return new \WP_Error( 'not_a_directory', 'Path is not a directory' );
		}

		if ( ! is_readable( $real_path ) ) {
			return new \WP_Error( 'not_readable', 'Directory is not readable' );
		}

		$items = $this->scan_directory( $real_path, $recursive, $max_depth, 0 );

		return [
			'items' => $items,
			'count' => count( $items ),
		];
	}

	/**
	 * Recursively scan a directory.
	 *
	 * @param string $path     Directory path.
	 * @param bool   $recursive Whether to recurse.
	 * @param int    $max_depth Maximum depth.
	 * @param int    $depth     Current depth.
	 * @return array
	 */
	private function scan_directory( string $path, bool $recursive, int $max_depth, int $depth ): array {
		$items = [];

		$entries = @scandir( $path );
		if ( ! $entries ) {
			return $items;
		}

		foreach ( $entries as $entry ) {
			if ( $entry === '.' || $entry === '..' ) {
				continue;
			}

			$full_path = $path . '/' . $entry;
			$is_dir    = is_dir( $full_path );

			$items[] = [
				'name'  => $entry,
				'type'  => $is_dir ? 'dir' : 'file',
				'size'  => $is_dir ? 0 : (int) @filesize( $full_path ),
				'mtime' => (int) @filemtime( $full_path ),
			];

			if ( $recursive && $is_dir && $depth < $max_depth ) {
				$subitems = $this->scan_directory( $full_path, $recursive, $max_depth, $depth + 1 );
				foreach ( $subitems as $subitem ) {
					$subitem['name'] = $entry . '/' . $subitem['name'];
					$items[]         = $subitem;
				}
			}
		}

		return $items;
	}
}
