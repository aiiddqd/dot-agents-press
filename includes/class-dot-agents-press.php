<?php
/**
 * Main plugin class – bootstraps all subsystems.
 *
 * @package Dot_Agents_Press
 */

defined( 'ABSPATH' ) || exit;

/**
 * Singleton that wires together all plugin components.
 */
final class Dot_Agents_Press {

	/** @var Dot_Agents_Press|null */
	private static ?Dot_Agents_Press $instance = null;

	/** @var DAP_Agent */
	public DAP_Agent $agent;

	/** @var DAP_Admin */
	public DAP_Admin $admin;

	/** @var DAP_API */
	public DAP_API $api;

	/** @var \DotAgentsPress\Settings */
	private \DotAgentsPress\Settings $settings;

	/** @var \DotAgentsPress\TelegramBridge */
	private \DotAgentsPress\TelegramBridge $telegram_bridge;

	// -------------------------------------------------------------------------
	// Bootstrap
	// -------------------------------------------------------------------------

	private function __construct() {
		$this->load_dependencies();
		$this->set_locale();

		$this->agent           = new DAP_Agent();
		$this->api             = new DAP_API();
		$this->settings        = new \DotAgentsPress\Settings();
		$this->telegram_bridge = new \DotAgentsPress\TelegramBridge();

		add_action( 'rest_api_init', [ $this->telegram_bridge, 'register_routes' ] );
		$this->register_cli_commands();
		$this->register_abilities();

		if ( is_admin() ) {
			$this->admin = new DAP_Admin();
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
	public function settings(): \DotAgentsPress\Settings {
		return $this->settings;
	}

	/** Returns the TelegramBridge instance. */
	public function telegram_bridge(): \DotAgentsPress\TelegramBridge {
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
		require_once DAP_PLUGIN_DIR . 'includes/class-agent.php';
		require_once DAP_PLUGIN_DIR . 'includes/class-admin.php';
		require_once DAP_PLUGIN_DIR . 'includes/class-api.php';
		require_once DAP_PLUGIN_DIR . 'app/Settings.php';
		require_once DAP_PLUGIN_DIR . 'app/Commands.php';
		require_once DAP_PLUGIN_DIR . 'app/AgentsProtocol.php';
		require_once DAP_PLUGIN_DIR . 'app/TelegramBridge.php';
		require_once DAP_PLUGIN_DIR . 'abilities/base.php';
		require_once DAP_PLUGIN_DIR . 'abilities/registry.php';
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
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}

		call_user_func( [ '\\WP_CLI', 'add_command' ], 'dap', \DotAgentsPress\Commands::class );
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
