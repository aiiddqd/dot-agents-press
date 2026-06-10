<?php
/**
 * WP-CLI commands for dot-agents-press.
 *
 * @package DotAgentsPress
 */

namespace DotAgentsPress;

defined( 'ABSPATH' ) || exit;

/**
 * CLI command group for `wp dap`.
 */
class Commands extends \WP_CLI_Command {

	/**
	 * Send a single message to the first enabled agent.
	 *
	 * ## OPTIONS
	 *
	 * <message>
	 * : User message text.
	 *
	 * [--timeout=<seconds>]
	 * : HTTP timeout in seconds.
	 * ---
	 * default: 60
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp dap chat "Hello"
	 *
	 * @when after_wp_load
	 */
	public function chat( array $args, array $assoc_args ): void {
		$message = isset( $args[0] ) ? sanitize_textarea_field( (string) $args[0] ) : '';

		if ( $message === '' ) {
			\WP_CLI::error( 'Message is required. Usage: wp dap chat <message>' );
		}

		$agent = AgentsProtocol::first_enabled_agent();

		if ( ! $agent ) {
			\WP_CLI::error( 'No enabled agents found. Enable one in dap_agents or add an enabled agent in .agents/agents/<slug>/agent.md.' );
		}

		$api_key = dot_agents_press()->agent->resolve_api_key( $agent );
		if ( $api_key === '' ) {
			$api_key = AgentsProtocol::resolve_api_key( $agent );
		}

		if ( $api_key === '' ) {
			\WP_CLI::error( 'API key is not configured. Run: wp dap status' );
		}

		$messages = [
			[
				'role'    => 'user',
				'content' => $message,
			],
		];

		$system_prompt = AgentsProtocol::build_system_prompt( (string) ( $agent->system_prompt ?? '' ) );
		if ( $system_prompt !== '' ) {
			array_unshift(
				$messages,
				[
					'role'    => 'system',
					'content' => $system_prompt,
				]
			);
		}

		$timeout = isset( $assoc_args['timeout'] ) ? max( 1, (int) $assoc_args['timeout'] ) : 60;
		$model   = (string) ( $agent->model ?? '' );
		if ( $model === '' ) {
			$model = (string) dot_agents_press()->settings()->get( 'default_model', 'deepseek/deepseek-v4-pro' );
		}

		$response = wp_remote_post(
			'https://openrouter.ai/api/v1/chat/completions',
			[
				'timeout' => $timeout,
				'headers' => [
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
					'HTTP-Referer'  => home_url(),
				],
				'body'    => wp_json_encode(
					[
						'model'       => $model,
						'messages'    => $messages,
						'temperature' => isset( $agent->temperature ) ? (float) $agent->temperature : 0.7,
						'max_tokens'  => (int) dot_agents_press()->settings()->get( 'max_tokens', 2048 ),
					]
				),
			]
		);

		if ( is_wp_error( $response ) ) {
			\WP_CLI::error( $response->get_error_message() );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( $code !== 200 ) {
			$error_message = $data['error']['message'] ?? 'Unknown API error.';
			\WP_CLI::error( $error_message );
		}

		$content = (string) ( $data['choices'][0]['message']['content'] ?? '' );
		if ( $content === '' ) {
			\WP_CLI::line( 'Warning: Assistant response is empty.' );
			return;
		}

		\WP_CLI::line( $content );
	}

	/**
	 * Show plugin status and available CLI commands.
	 *
	 * @when after_wp_load
	 */
	public function status(): void {
		$settings = dot_agents_press()->settings();
		$agents   = AgentsProtocol::list_agents();

		$enabled_count = 0;
		$active_agent  = null;

		foreach ( $agents as $agent ) {
			if ( ! empty( $agent->enabled ) ) {
				++$enabled_count;
				if ( null === $active_agent ) {
					$active_agent = $agent;
				}
			}
		}

		$provider = (string) ( $active_agent->provider ?? 'openrouter' );
		$model    = (string) ( $active_agent->model ?? $settings->get( 'default_model', 'deepseek/deepseek-v4-pro' ) );

		$api_key = '';
		if ( $active_agent ) {
			$api_key = dot_agents_press()->agent->resolve_api_key( $active_agent );
		}
		if ( $api_key === '' ) {
			$api_key = (string) get_option( 'connectors_ai_openrouter_api_key', '' );
		}

		\WP_CLI::line( sprintf( 'dot-agents-press v%s', DAP_VERSION ) );
		\WP_CLI::line( sprintf( 'Provider : %s', $provider ) );
		\WP_CLI::line( sprintf( 'Model    : %s', $model ) );
		\WP_CLI::line( sprintf( 'API key  : %s', $this->mask_api_key( $api_key ) ) );
		\WP_CLI::line( sprintf( 'Agents   : %d registered, %d enabled', count( $agents ), $enabled_count ) );
		\WP_CLI::line( '' );
		\WP_CLI::line( 'Available commands:' );
		\WP_CLI::line( '  wp dap chat      Send a message to an agent' );
		\WP_CLI::line( '  wp dap status    Show current provider / model / config' );
		\WP_CLI::line( '  wp dap agents    Manage agents (list)' );
		\WP_CLI::line( '  wp dap telegram  Manage Telegram webhook' );
	}

	/**
	 * Manage agents.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : Supported action: list.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp dap agents list
	 *     wp dap agents list --format=json
	 *
	 * @when after_wp_load
	 */
	public function agents( array $args, array $assoc_args ): void {
		$action = isset( $args[0] ) ? sanitize_key( (string) $args[0] ) : 'list';

		if ( $action !== 'list' ) {
			\WP_CLI::error( 'Unknown action. Supported action: list.' );
		}

		$format = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'table';
		$items  = [];

		foreach ( AgentsProtocol::list_agents() as $agent ) {
			$items[] = [
				'id'       => (string) $agent->id,
				'slug'     => (string) $agent->slug,
				'name'     => (string) $agent->name,
				'provider' => (string) $agent->provider,
				'model'    => (string) $agent->model,
				'enabled'  => ! empty( $agent->enabled ) ? 'true' : 'false',
			];
		}

		\WP_CLI\Utils\format_items( $format, $items, [ 'id', 'slug', 'name', 'provider', 'model', 'enabled' ] );
	}

	/**
	 * Manage Telegram webhook commands.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : Supported actions: set-webhook, delete-webhook, status.
	 *
	 * ## EXAMPLES
	 *
	 *     wp dap telegram set-webhook
	 *     wp dap telegram delete-webhook
	 *     wp dap telegram status
	 *
	 * @when after_wp_load
	 */
	public function telegram( array $args ): void {
		$action = isset( $args[0] ) ? sanitize_key( (string) $args[0] ) : '';

		if ( $action === 'set-webhook' ) {
			$result = dot_agents_press()->telegram_bridge()->set_webhook();
			if ( $result ) {
				\WP_CLI::line( 'Telegram webhook set successfully.' );
				return;
			}
			\WP_CLI::error( 'Failed to set Telegram webhook. Check your bot token in settings.' );
		}

		if ( $action === 'delete-webhook' ) {
			$result = dot_agents_press()->telegram_bridge()->delete_webhook();
			if ( $result ) {
				\WP_CLI::line( 'Telegram webhook deleted.' );
				return;
			}
			\WP_CLI::error( 'Failed to delete Telegram webhook.' );
		}

		if ( $action === 'status' ) {
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
			\WP_CLI::line( sprintf( 'URL:             %s', $info['url'] ?? '(none)' ) );
			\WP_CLI::line( sprintf( 'Has custom cert: %s', ! empty( $info['has_custom_certificate'] ) ? 'yes' : 'no' ) );
			\WP_CLI::line( sprintf( 'Pending updates: %d', $info['pending_update_count'] ?? 0 ) );
			if ( ! empty( $info['last_error_message'] ) ) {
				\WP_CLI::line( sprintf( 'Warning: Last error: %s (date: %s)', $info['last_error_message'], $info['last_error_date'] ?? 'unknown' ) );
			}
			return;
		}

		\WP_CLI::error( 'Unknown action. Supported actions: set-webhook, delete-webhook, status.' );
	}

	/**
	 * Mask API key for safe CLI output.
	 */
	private function mask_api_key( string $api_key ): string {
		if ( $api_key === '' ) {
			return '(not configured)';
		}

		$key_length = strlen( $api_key );
		if ( $key_length <= 8 ) {
			return str_repeat( '•', $key_length );
		}

		return substr( $api_key, 0, 8 ) . str_repeat( '•', max( 0, $key_length - 12 ) ) . substr( $api_key, -4 );
	}
}