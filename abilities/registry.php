<?php
/**
 * Ability Registry — Auto-discovery and registration
 *
 * @package DotAgentsPress\Abilities
 */

namespace DotAgentsPress\Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Registry for discovering and registering all abilities from the abilities/ directory.
 */
class Registry {

	/**
	 * Path to the abilities directory.
	 *
	 * @var string
	 */
	private string $abilities_dir;

	/**
	 * Constructor.
	 *
	 * @param string|null $abilities_dir Optional custom abilities directory path.
	 */
	public function __construct( ?string $abilities_dir = null ) {
		$this->abilities_dir = $abilities_dir ?: DAP_PLUGIN_DIR . 'abilities/';
	}

	/**
	 * Discover and register all abilities.
	 *
	 * @return void
	 */
	public function register_all(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		foreach ( $this->discover_files() as $file ) {
			$this->load_and_register_ability( $file );
		}
	}

	/**
	 * Load and register a single ability file.
	 *
	 * @param string $file Path to the ability file.
	 * @return void
	 */
	private function load_and_register_ability( string $file ): void {
		require_once $file;

		$class_name = $this->resolve_class_from_file( $file );
		if ( ! class_exists( $class_name ) ) {
			return;
		}

		try {
			$ability = new $class_name();
			if ( ! $ability instanceof AbilityAbstract ) {
				return;
			}

			wp_register_ability( $ability->name, $ability->get_definition() );
		} catch ( \Exception $e ) {
			wp_trigger_error(
				'dap_ability_registration',
				sprintf(
					'Failed to register ability from %s: %s',
					basename( $file ),
					$e->getMessage()
				)
			);
		}
	}

	/**
	 * Discover all ability files in the abilities directory.
	 *
	 * @return array Array of file paths.
	 */
	private function discover_files(): array {
		$files = glob( $this->abilities_dir . '*.php' ) ?: [];
		return array_values(
			array_filter(
				$files,
				static fn( string $path ) => ! str_ends_with( $path, 'base.php' )
					&& ! str_ends_with( $path, 'registry.php' )
			)
		);
	}

	/**
	 * Resolve the class name from a file path.
	 *
	 * Converts filename to class name:
	 * - read-file.php → ReadFile → DotAgentsPress\Abilities\ReadFile
	 *
	 * @param string $file Path to the ability file.
	 * @return string Fully qualified class name.
	 */
	private function resolve_class_from_file( string $file ): string {
		$slug = basename( $file, '.php' ); // read-file
		$slug = str_replace( '-', ' ', $slug );
		$slug = str_replace( ' ', '_', ucwords( $slug ) );

		return 'DotAgentsPress\\Abilities\\' . $slug;
	}
}
