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
		require_once DAP_PLUGIN_DIR . 'app/AgentsProtocol.php';
		require_once DAP_PLUGIN_DIR . 'app/TelegramBridge.php';
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

		\WP_CLI::add_command( 'dap telegram set-webhook', function () {
			$result = dot_agents_press()->telegram_bridge()->set_webhook();
			if ( $result ) {
				\WP_CLI::success( 'Telegram webhook set successfully.' );
			} else {
				\WP_CLI::error( 'Failed to set Telegram webhook. Check your bot token in settings.' );
			}
		} );

		\WP_CLI::add_command( 'dap telegram delete-webhook', function () {
			$result = dot_agents_press()->telegram_bridge()->delete_webhook();
			if ( $result ) {
				\WP_CLI::success( 'Telegram webhook deleted.' );
			} else {
				\WP_CLI::error( 'Failed to delete Telegram webhook.' );
			}
		} );

		\WP_CLI::add_command( 'dap telegram status', function () {
			$token = dot_agents_press()->settings()->get( 'telegram_bot_token', '' );
			if ( ! $token ) {
				\WP_CLI::error( 'Telegram bot token not configured in settings.' );
			}

			$response = wp_remote_get( "https://api.telegram.org/bot{$token}/getWebhookInfo", [ 'timeout' => 10 ] );
			if ( is_wp_error( $response ) ) {
				\WP_CLI::error( $response->get_error_message() );
			}

			$data = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( empty( $data['ok'] ) ) {
				\WP_CLI::error( 'Failed to get webhook info.' );
			}

			$info = $data['result'];
			\WP_CLI::log( sprintf( 'URL:             %s', $info['url'] ?? '(none)' ) );
			\WP_CLI::log( sprintf( 'Has custom cert: %s', ! empty( $info['has_custom_certificate'] ) ? 'yes' : 'no' ) );
			\WP_CLI::log( sprintf( 'Pending updates: %d', $info['pending_update_count'] ?? 0 ) );
			if ( ! empty( $info['last_error_message'] ) ) {
				\WP_CLI::warning( sprintf( 'Last error: %s (date: %s)', $info['last_error_message'], $info['last_error_date'] ?? 'unknown' ) );
			}
		} );
	}
}
