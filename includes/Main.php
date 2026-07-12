<?php
/**
 * Main plugin class – bootstraps all subsystems.
 *
 * @package DotAgentsPress
 */

namespace DotAgentsPress;

defined( 'ABSPATH' ) || exit;

/**
 * Singleton that wires together all plugin components.
 */
final class Main {

	/** @var Main|null */
	private static ?Main $instance = null;

	/** @var Agent */
	public Agent $agent;

	/** @var Admin */
	public Admin $admin;

	/** @var Api */
	public Api $api;

	/** @var Settings */
	private Settings $settings;

	/** @var TelegramBridge */
	private TelegramBridge $telegram_bridge;

	// -------------------------------------------------------------------------
	// Bootstrap
	// -------------------------------------------------------------------------

	private function __construct() {
		$this->load_dependencies();
		$this->set_locale();

		$this->agent           = new Agent();
		$this->api             = new Api();
		$this->settings        = new Settings();
		$this->telegram_bridge = new TelegramBridge();

		add_action( 'rest_api_init', [ $this->telegram_bridge, 'register_routes' ] );
		$this->register_cli_commands();
		$this->register_abilities();

		if ( is_admin() ) {
			$this->admin = new Admin();
		}

		add_action( 'plugins_loaded', [ $this, 'on_plugins_loaded' ] );
		register_activation_hook( DAP_PLUGIN_FILE, [ $this, 'activate' ] );
		register_deactivation_hook( DAP_PLUGIN_FILE, [ $this, 'deactivate' ] );
	}

	/**
	 * Returns the singleton instance.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	// -------------------------------------------------------------------------
	// Accessors
	// -------------------------------------------------------------------------

	/** Returns the Settings instance. */
	public function settings(): Settings {
		return $this->settings;
	}

	/** Returns the TelegramBridge instance. */
	public function telegram_bridge(): TelegramBridge {
		return $this->telegram_bridge;
	}

	// -------------------------------------------------------------------------
	// Hooks
	// -------------------------------------------------------------------------

	/** Fires after all plugins have loaded. */
	public function on_plugins_loaded(): void {
		do_action( 'dot_agents_press_loaded' );
	}

	/** Creates the database table on activation. */
	public function activate(): void {
		$this->agent->install();
		flush_rewrite_rules();
	}

	/** Runs on deactivation. */
	public function deactivate(): void {
		flush_rewrite_rules();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function load_dependencies(): void {
		require_once DAP_PLUGIN_DIR . 'includes/Agent.php';
		require_once DAP_PLUGIN_DIR . 'includes/Admin.php';
		require_once DAP_PLUGIN_DIR . 'includes/Api.php';
		require_once DAP_PLUGIN_DIR . 'includes/Settings.php';
		if ( $this->is_wp_cli_runtime() ) {
			require_once DAP_PLUGIN_DIR . 'includes/Commands.php';
		}
		require_once DAP_PLUGIN_DIR . 'includes/AgentsProtocol.php';
		require_once DAP_PLUGIN_DIR . 'includes/TelegramBridge.php';
		require_once DAP_PLUGIN_DIR . 'abilities/base.php';
		require_once DAP_PLUGIN_DIR . 'abilities/registry.php';
	}

	/**
	 * Returns true when plugin code is running inside a real WP-CLI process.
	 */
	private function is_wp_cli_runtime(): bool {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return false;
		}

		if ( PHP_SAPI !== 'cli' && PHP_SAPI !== 'phpdbg' ) {
			return false;
		}

		return class_exists( '\\WP_CLI_Command' );
	}

	private function set_locale(): void {
		add_action( 'init', function () {
			load_plugin_textdomain(
				'dot-agents-press',
				false,
				DAP_PLUGIN_DIR . 'languages/'
			);
		} );
	}

	/**
	 * Register WP-CLI commands (if WP-CLI is available).
	 */
	public function register_cli_commands(): void {
		if ( ! $this->is_wp_cli_runtime() || ! class_exists( Commands::class ) ) {
			return;
		}

		call_user_func( [ '\\WP_CLI', 'add_command' ], 'dap', Commands::class );
	}

	/**
	 * Register all abilities from the abilities/ directory.
	 */
	private function register_abilities(): void {
		add_action( 'dot_agents_press_loaded', function () {
			$registry = new \DotAgentsPress\Abilities\Registry();
			$registry->register_all();
		} );
	}
}
