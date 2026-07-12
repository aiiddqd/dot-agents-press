<?php
/**
 * Telegram Bot Bridge — webhook handler that routes Telegram messages to an AI agent.
 *
 * @package DotAgentsPress
 */

namespace DotAgentsPress;

defined( 'ABSPATH' ) || exit;

/**
 * Accepts incoming Telegram updates via REST webhook, calls the configured
 * agent, and sends the reply back through the Telegram Bot API.
 */
class TelegramBridge {

	/**
	 * Register the webhook REST route.
	 */
	public function register_routes(): void {
		register_rest_route(
			'dot-agents-press/v1',
			'/telegram/webhook',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'handle_webhook' ],
				'permission_callback' => '__return_true',
			]
		);
	}

	/**
	 * Handle an incoming Telegram webhook update.
	 *
	 * Flow: extract message → find agent → call chat → send reply.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_webhook( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		// 1. Verify webhook secret (optional).
		if ( ! $this->verify_secret( $request ) ) {
			return new \WP_Error( 'unauthorized', 'Invalid webhook secret.', [ 'status' => 403 ] );
		}

		$body = $request->get_json_params();

		if ( ! $body ) {
			return new \WP_Error( 'empty_body', 'Empty request body.', [ 'status' => 400 ] );
		}

		// 2. Extract text message + chat_id.
		$message = $body['message'] ?? $body['edited_message'] ?? null;

		if ( ! $message ) {
			// Ignore non-message updates (e.g. status, polls) — respond 200 to avoid retries.
			return new \WP_REST_Response( [ 'ok' => true, 'skipped' => true ], 200 );
		}

		$chat_id = sanitize_text_field( (string) ( $message['chat']['id'] ?? '' ) );
		$text    = sanitize_text_field( $message['text'] ?? '' );

		if ( $chat_id === '' || $text === '' ) {
			return new \WP_REST_Response( [ 'ok' => true, 'skipped' => 'no text' ], 200 );
		}

		// 3. Authorize: only allow messages from the configured Telegram user ID.
		$settings                          = dot_agents_press()->settings();
		$authorized_user_id                = $settings->get( 'telegram_authorized_user_id', '' );

		if ( $authorized_user_id !== '' && $chat_id !== $authorized_user_id ) {
			$this->send_message( $chat_id, '⛔ Доступ запрещён. Этот бот — персональный ассистент.' );
			return new \WP_REST_Response( [ 'ok' => true, 'skipped' => 'unauthorized' ], 200 );
		}

		// 4. Resolve agent.
		$agent = $this->resolve_agent();

		if ( ! $agent ) {
			$this->send_message( $chat_id, '⚠️ Agent not found. Create .agents/agents/{slug}/agent.md in your project root.' );
			return new \WP_Error( 'no_agent', 'No agent found.', [ 'status' => 500 ] );
		}

		// 5. Build messages array and delegate to the existing chat handler.
		$fake_request = new \WP_REST_Request( 'POST', '/dot-agents-press/v1/chat' );
		$fake_request->set_param( 'agent_slug', $agent->slug );
		$fake_request->set_param( 'messages', [
			[ 'role' => 'user', 'content' => $text ],
		] );

		$chat_handler = dot_agents_press()->api;
		$result       = $chat_handler->handle_chat( $fake_request );

		// 6. Send reply back to Telegram.
		if ( is_wp_error( $result ) ) {
			$this->send_message( $chat_id, '❌ Ошибка: ' . $result->get_error_message() );
			return $result;
		}

		$data    = $result->get_data();
		$content = $data['content'] ?? '';

		if ( $content !== '' ) {
			$this->send_message( $chat_id, $content );
		}

		return new \WP_REST_Response( [ 'ok' => true ], 200 );
	}

	/**
	 * Set the Telegram webhook via Bot API.
	 *
	 * @return bool True on success.
	 */
	public function set_webhook(): bool {
		$token = dot_agents_press()->settings()->get( 'telegram_bot_token', '' );
		if ( ! $token ) {
			return false;
		}

		$url = rest_url( 'dot-agents-press/v1/telegram/webhook' );

		$body = [ 'url' => $url ];

		$secret = dot_agents_press()->settings()->get( 'telegram_webhook_secret', '' );
		if ( $secret ) {
			$body['secret_token'] = $secret;
		}

		$response = wp_remote_post(
			"https://api.telegram.org/bot{$token}/setWebhook",
			[
				'timeout' => 15,
				'headers' => [ 'Content-Type' => 'application/json' ],
				'body'    => wp_json_encode( $body ),
			]
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		return ( $data['ok'] ?? false ) === true;
	}

	/**
	 * Delete the Telegram webhook.
	 *
	 * @return bool True on success.
	 */
	public function delete_webhook(): bool {
		$token = dot_agents_press()->settings()->get( 'telegram_bot_token', '' );
		if ( ! $token ) {
			return false;
		}

		$response = wp_remote_get( "https://api.telegram.org/bot{$token}/deleteWebhook", [ 'timeout' => 10 ] );

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		return ( $data['ok'] ?? false ) === true;
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Send a text message back to a Telegram chat.
	 *
	 * @param string $chat_id Telegram chat ID.
	 * @param string $text    Message text (plain, not HTML/Markdown).
	 * @return array|WP_Error Decoded API response or error.
	 */
	private function send_message( string $chat_id, string $text ): array|\WP_Error {
		$token = dot_agents_press()->settings()->get( 'telegram_bot_token', '' );
		if ( ! $token ) {
			return new \WP_Error( 'no_token', 'Telegram bot token not configured.' );
		}

		$response = wp_remote_post(
			"https://api.telegram.org/bot{$token}/sendMessage",
			[
				'timeout' => 15,
				'headers' => [ 'Content-Type' => 'application/json' ],
				'body'    => wp_json_encode( [
					'chat_id' => $chat_id,
					'text'    => $text,
				] ),
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return json_decode( wp_remote_retrieve_body( $response ), true ) ?: [];
	}

	/**
	 * Verify the optional X-Telegram-Bot-Api-Secret-Token header.
	 *
	 * @param \WP_REST_Request $request
	 * @return bool True if valid or secret not configured.
	 */
	private function verify_secret( \WP_REST_Request $request ): bool {
		$configured_secret = dot_agents_press()->settings()->get( 'telegram_webhook_secret', '' );

		if ( $configured_secret === '' ) {
			return true; // Secret not configured — skip verification.
		}

		$header = $request->get_header( 'x-telegram-bot-api-secret-token' );

		return hash_equals( $configured_secret, $header );
	}

	/**
	 * Find the agent to use for Telegram messages.
	 *
	 * Priority:
	 * 1. telegram_default_agent_slug from Settings
	 * 2. First enabled agent in .agents/agents/
	 *
	 * @return object|null
	 */
	private function resolve_agent(): ?object {
		return AgentsProtocol::resolve_telegram_agent();
	}
}
