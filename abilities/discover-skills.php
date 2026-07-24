<?php
/**
 * Discover Skills Ability
 *
 * @package DotAgentsPress\Abilities
 */

namespace DotAgentsPress\Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Ability to discover SKILL.md files in the project.
 */
class DiscoverSkills extends AbilityAbstract {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->name        = 'dot-agents-press/discover-skills';
		$this->label       = 'Discover Skills';
		$this->description = 'Find and list all SKILL.md files in the project';
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
				'directories' => [
					'type'        => 'array',
					'items'       => [ 'type' => 'string' ],
					'description' => 'Directories to search (defaults to .agents/skills and agents-template/skills)',
				],
			],
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
				'skills' => [
					'type'  => 'array',
					'items' => [
						'type' => 'object',
						'properties' => [
							'name'        => [ 'type' => 'string' ],
							'path'        => [ 'type' => 'string' ],
							'description' => [ 'type' => 'string' ],
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
		$directories = isset( $args['directories'] ) && is_array( $args['directories'] )
			? $args['directories']
			: $this->get_default_skill_directories();

		$skills = [];

		foreach ( $directories as $dir ) {
			$real_path = realpath( $dir );
			if ( ! $real_path || ! is_dir( $real_path ) ) {
				continue;
			}

			$allowed = $this->get_allowed_dirs();
			if ( ! $this->is_path_allowed( $real_path, $allowed ) ) {
				continue;
			}

			$found = $this->find_skills_in_directory( $real_path );
			$skills = array_merge( $skills, $found );
		}

		return [
			'skills' => $skills,
			'count'  => count( $skills ),
		];
	}

	/**
	 * Get default skill directories.
	 *
	 * @return array
	 */
	private function get_default_skill_directories(): array {
		$project_root = dirname( ABSPATH ) . '/';

		return [
			$project_root . '.agents/skills/',
			DAP_PLUGIN_DIR . 'agents-template/skills/',
		];
	}

	/**
	 * Find all SKILL.md files in a directory.
	 *
	 * @param string $directory Directory path.
	 * @return array
	 */
	private function find_skills_in_directory( string $directory ): array {
		$skills = [];

		$iterator = new \RecursiveDirectoryIterator(
			$directory,
			\RecursiveDirectoryIterator::SKIP_DOTS
		);
		$recursive = new \RecursiveIteratorIterator( $iterator );

		foreach ( $recursive as $file ) {
			if ( $file->getBasename() === 'SKILL.md' ) {
				$dir_name = basename( dirname( $file->getPathname() ) );
				$skills[] = [
					'name'        => $dir_name,
					'path'        => $file->getPathname(),
					'description' => $this->extract_skill_description( $file->getPathname() ),
				];
			}
		}

		return $skills;
	}

	/**
	 * Extract the first line or description from a SKILL.md file.
	 *
	 * @param string $file_path Path to SKILL.md.
	 * @return string
	 */
	private function extract_skill_description( string $file_path ): string {
		$content = @file_get_contents( $file_path );
		if ( ! $content ) {
			return '';
		}

		$lines = explode( "\n", $content );
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( ! empty( $line ) && ! str_starts_with( $line, '#' ) ) {
				return substr( $line, 0, 100 );
			}
		}

		return '';
	}
}
