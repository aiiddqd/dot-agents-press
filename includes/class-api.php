<?php
/**
 * REST API endpoints and shortcode handler.
 *
 * Shortcode usage:
 *   [dot_agent id="3"]
 *   [dot_agent slug="my-agent"]
 *
 * REST endpoint:
 *   POST /wp-json/dot-agents-press/v1/chat
 *   {
 *     "agent_id": 3,
 *     "messages": [ {"role":"user","content":"Hello"} ]
 *   }
 *
 * @package Dot_Agents_Press
 */

defined( 'ABSPATH' ) || exit;

/** Registers the REST route and the [dot_agent] shortcode. */
class DAP_API {

	private const REST_NAMESPACE = 'dot-agents-press/v1';

	public function __construct() {
		add_action( 'rest_api_init',  [ $this, 'register_routes' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_public_assets' ] );
		add_shortcode( 'dot_agent',   [ $this, 'shortcode' ] );
	}

	// -------------------------------------------------------------------------
	// REST routes
	// -------------------------------------------------------------------------

	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/chat',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'handle_chat' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'agent_id' => [
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					],
					'messages' => [
						'required' => true,
						'type'     => 'array',
					],
				],
			]
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/agents',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'list_agents' ],
				'permission_callback' => [ $this, 'check_manage_permission' ],
			]
		);
	}

	/** Checks that the current user can manage options (used for admin-only routes). */
	public function check_manage_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	// -------------------------------------------------------------------------
	// Chat endpoint
	// -------------------------------------------------------------------------

	/**
	 * Proxies a chat message list to the configured AI provider and returns
	 * the assistant reply.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_chat( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$agent_repo = dot_agents_press()->agent;

		$agent = $agent_repo->get( $request->get_param( 'agent_id' ) );

		if ( ! $agent ) {
			return new \WP_Error( 'not_found', __( 'Agent not found.', 'dot-agents-press' ), [ 'status' => 404 ] );
		}

		if ( ! $agent->enabled ) {
			return new \WP_Error( 'disabled', __( 'This agent is currently disabled.', 'dot-agents-press' ), [ 'status' => 403 ] );
		}

		$messages = $request->get_param( 'messages' );

		if ( ! is_array( $messages ) || empty( $messages ) ) {
			return new \WP_Error( 'invalid_messages', __( 'messages must be a non-empty array.', 'dot-agents-press' ), [ 'status' => 400 ] );
		}

		// Sanitize and validate each message.
		$clean_messages = [];
		foreach ( $messages as $msg ) {
			if ( ! isset( $msg['role'], $msg['content'] ) ) {
				return new \WP_Error( 'invalid_message', __( 'Each message must have role and content.', 'dot-agents-press' ), [ 'status' => 400 ] );
			}
			$role = sanitize_text_field( $msg['role'] );
			if ( ! in_array( $role, [ 'user', 'assistant', 'system' ], true ) ) {
				return new \WP_Error( 'invalid_role', __( 'Invalid message role.', 'dot-agents-press' ), [ 'status' => 400 ] );
			}
			$clean_messages[] = [
				'role'    => $role,
				'content' => sanitize_textarea_field( $msg['content'] ),
			];
		}

		$api_key = $agent_repo->resolve_api_key( $agent );

		if ( empty( $api_key ) ) {
			return new \WP_Error(
				'no_api_key',
				__( 'No API key configured for this agent. Please add one in the plugin settings.', 'dot-agents-press' ),
				[ 'status' => 500 ]
			);
		}

		// Prepend system prompt if set.
		if ( ! empty( $agent->system_prompt ) ) {
			array_unshift( $clean_messages, [
				'role'    => 'system',
				'content' => $agent->system_prompt,
			] );
		}

		switch ( $agent->provider ) {
			case 'anthropic':
				return $this->call_anthropic( $agent, $clean_messages, $api_key );
			case 'openai':
			default:
				return $this->call_openai( $agent, $clean_messages, $api_key );
		}
	}

	// -------------------------------------------------------------------------
	// OpenAI
	// -------------------------------------------------------------------------

	private function call_openai( object $agent, array $messages, string $api_key ): \WP_REST_Response|\WP_Error {
		$body = [
			'model'       => $agent->model,
			'messages'    => $messages,
			'temperature' => (float) $agent->temperature,
		];

		$response = wp_remote_post(
			'https://api.openai.com/v1/chat/completions',
			[
				'timeout' => 60,
				'headers' => [
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				],
				'body'    => wp_json_encode( $body ),
			]
		);

		return $this->parse_openai_response( $response );
	}

	/**
	 * @param array|\WP_Error $response wp_remote_post() result.
	 */
	private function parse_openai_response( array|\WP_Error $response ): \WP_REST_Response|\WP_Error {
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'http_error', $response->get_error_message(), [ 'status' => 502 ] );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( $code !== 200 ) {
			$msg = $data['error']['message'] ?? __( 'Unknown API error.', 'dot-agents-press' );
			return new \WP_Error( 'api_error', $msg, [ 'status' => 502 ] );
		}

		$content = $data['choices'][0]['message']['content'] ?? '';

		return new \WP_REST_Response( [
			'role'    => 'assistant',
			'content' => $content,
			'model'   => $data['model'] ?? '',
			'usage'   => $data['usage'] ?? [],
		], 200 );
	}

	// -------------------------------------------------------------------------
	// Anthropic
	// -------------------------------------------------------------------------

	private function call_anthropic( object $agent, array $messages, string $api_key ): \WP_REST_Response|\WP_Error {
		// Extract system message (Anthropic uses a top-level system param).
		$system   = '';
		$filtered = [];
		foreach ( $messages as $msg ) {
			if ( $msg['role'] === 'system' ) {
				$system = $msg['content'];
			} else {
				$filtered[] = $msg;
			}
		}

		$body = [
			'model'       => $agent->model,
			'max_tokens'  => 4096,
			'messages'    => $filtered,
			'temperature' => (float) $agent->temperature,
		];

		if ( $system ) {
			$body['system'] = $system;
		}

		$response = wp_remote_post(
			'https://api.anthropic.com/v1/messages',
			[
				'timeout' => 60,
				'headers' => [
					'x-api-key'         => $api_key,
					'anthropic-version' => '2023-06-01',
					'Content-Type'      => 'application/json',
				],
				'body'    => wp_json_encode( $body ),
			]
		);

		return $this->parse_anthropic_response( $response );
	}

	/**
	 * @param array|\WP_Error $response wp_remote_post() result.
	 */
	private function parse_anthropic_response( array|\WP_Error $response ): \WP_REST_Response|\WP_Error {
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'http_error', $response->get_error_message(), [ 'status' => 502 ] );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$raw  = wp_remote_retrieve_body( $response );
		$data = json_decode( $raw, true );

		if ( $code !== 200 ) {
			$msg = $data['error']['message'] ?? __( 'Unknown Anthropic API error.', 'dot-agents-press' );
			return new \WP_Error( 'api_error', $msg, [ 'status' => 502 ] );
		}

		$content = $data['content'][0]['text'] ?? '';

		return new \WP_REST_Response( [
			'role'    => 'assistant',
			'content' => $content,
			'model'   => $data['model'] ?? '',
			'usage'   => $data['usage'] ?? [],
		], 200 );
	}

	// -------------------------------------------------------------------------
	// Admin-only agent list
	// -------------------------------------------------------------------------

	public function list_agents( \WP_REST_Request $request ): \WP_REST_Response {
		$agents = dot_agents_press()->agent->get_all( [ 'per_page' => 100 ] );
		// Strip encrypted keys from the response.
		foreach ( $agents as $a ) {
			$a->api_key = ! empty( $a->api_key ) ? '••••••••' : '';
		}
		return new \WP_REST_Response( $agents, 200 );
	}

	// -------------------------------------------------------------------------
	// Shortcode
	// -------------------------------------------------------------------------

	/**
	 * Renders the chat widget.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public function shortcode( array $atts ): string {
		$atts = shortcode_atts(
			[
				'id'   => 0,
				'slug' => '',
			],
			$atts,
			'dot_agent'
		);

		$agent_repo = dot_agents_press()->agent;

		if ( ! empty( $atts['slug'] ) ) {
			$agent = $agent_repo->get( sanitize_title( $atts['slug'] ) );
		} elseif ( $atts['id'] ) {
			$agent = $agent_repo->get( (int) $atts['id'] );
		} else {
			return '';
		}

		if ( ! $agent || ! $agent->enabled ) {
			return '';
		}

		ob_start();
		require DAP_PLUGIN_DIR . 'public/views/chat-widget.php';
		return ob_get_clean() ?: '';
	}

	// -------------------------------------------------------------------------
	// Public assets (only loaded when shortcode is present)
	// -------------------------------------------------------------------------

	public function enqueue_public_assets(): void {
		if ( is_admin() ) {
			return;
		}

		wp_register_style(
			'dap-chat',
			DAP_PLUGIN_URL . 'public/css/chat.css',
			[],
			DAP_VERSION
		);

		wp_register_script(
			'dap-chat',
			DAP_PLUGIN_URL . 'public/js/chat.js',
			[],
			DAP_VERSION,
			true
		);

		wp_localize_script( 'dap-chat', 'DAP_CHAT', [
			'rest_url' => esc_url_raw( rest_url( self::REST_NAMESPACE . '/chat' ) ),
			'nonce'    => wp_create_nonce( 'wp_rest' ),
			'i18n'     => [
				'sending'      => __( 'Sending…', 'dot-agents-press' ),
				'error'        => __( 'Something went wrong. Please try again.', 'dot-agents-press' ),
				'placeholder'  => __( 'Type your message…', 'dot-agents-press' ),
				'send'         => __( 'Send', 'dot-agents-press' ),
			],
		] );
	}
}
