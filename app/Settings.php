<?php
/**
 * Settings — WordPress Settings API integration.
 *
 * @package Dot_Agents_Press
 */

namespace DotAgentsPress;

defined( 'ABSPATH' ) || exit;

/**
 * Manages the dot_agents_config option via the WordPress Settings API.
 */
class Settings {

	private const OPTION_GROUP = 'dot_agents_config_group';
	private const OPTION_NAME  = 'dot_agents_config';
	private const PAGE_SLUG    = 'dot-agents-config';
	private const CAP          = 'manage_options';

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'add_page' ] );
		add_action( 'admin_init', [ $this, 'register' ] );
		add_filter( 'plugin_action_links_' . plugin_basename( DAP_PLUGIN_FILE ), [ $this, 'add_settings_link' ] );
	}

	// -------------------------------------------------------------------------
	// Getters / Setters
	// -------------------------------------------------------------------------

	/**
	 * Get a single key from the dot_agents_config option.
	 *
	 * @param string $key     Option key.
	 * @param mixed  $default Fallback if key not set.
	 * @return mixed
	 */
	public function get( string $key, $default = null ) {
		$options = get_option( self::OPTION_NAME, [] );
		return $options[ $key ] ?? $default;
	}

	/**
	 * Set a single key in the dot_agents_config option (preserves other keys).
	 *
	 * @param string $key   Option key.
	 * @param mixed  $value Value to store.
	 * @return bool
	 */
	public function set( string $key, $value ): bool {
		$options         = get_option( self::OPTION_NAME, [] );
		$options[ $key ] = $value;
		return update_option( self::OPTION_NAME, $options );
	}

	// -------------------------------------------------------------------------
	// Menu
	// -------------------------------------------------------------------------

	public function add_page(): void {
		add_options_page(
			__( 'Dot Agents Config', 'dot-agents-press' ),
			__( 'Dot Agents Config', 'dot-agents-press' ),
			self::CAP,
			self::PAGE_SLUG,
			[ $this, 'render' ]
		);
	}

	/**
	 * Add a «Settings» link on the Plugins list screen.
	 *
	 * @param array $links Existing action links.
	 * @return array
	 */
	public function add_settings_link( array $links ): array {
		$url = admin_url( 'options-general.php?page=' . self::PAGE_SLUG );
		$links[] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'dot-agents-press' ) . '</a>';
		return $links;
	}

	// -------------------------------------------------------------------------
	// Registration
	// -------------------------------------------------------------------------

	public function register(): void {
		register_setting(
			self::OPTION_GROUP,
			self::OPTION_NAME,
			[
				'type'              => 'object',
				'sanitize_callback' => [ $this, 'sanitize' ],
				'default'           => [
					'telegram_bot_token'           => '',
					'telegram_webhook_secret'      => '',
					'telegram_default_agent_slug'  => '',
					'telegram_authorized_user_id'  => '',
					'default_model'                => 'deepseek/deepseek-v4-pro',
					'max_tokens'                   => 2048,
				],
			]
		);

		add_settings_section(
			'dap_config_main',
			__( 'Agent Configuration', 'dot-agents-press' ),
			[ $this, 'section_main' ],
			self::PAGE_SLUG
		);

		// Field 1 — Telegram Bot Token
		add_settings_field(
			'telegram_bot_token',
			__( 'Telegram Bot Token', 'dot-agents-press' ),
			[ $this, 'field_bot_token' ],
			self::PAGE_SLUG,
			'dap_config_main',
			[ 'label_for' => 'dap_bot_token' ]
		);

		// Field 2 — Telegram Authorized User ID
		add_settings_field(
			'telegram_authorized_user_id',
			__( 'Telegram User ID', 'dot-agents-press' ),
			[ $this, 'field_authorized_user_id' ],
			self::PAGE_SLUG,
			'dap_config_main',
			[ 'label_for' => 'dap_authorized_user_id' ]
		);

		// Field 3 — Webhook Secret
		add_settings_field(
			'telegram_webhook_secret',
			__( 'Webhook Secret', 'dot-agents-press' ),
			[ $this, 'field_webhook_secret' ],
			self::PAGE_SLUG,
			'dap_config_main',
			[ 'label_for' => 'dap_webhook_secret' ]
		);

		// Field 4 — Default Agent Slug
		add_settings_field(
			'telegram_default_agent_slug',
			__( 'Default Agent Slug', 'dot-agents-press' ),
			[ $this, 'field_default_agent_slug' ],
			self::PAGE_SLUG,
			'dap_config_main',
			[ 'label_for' => 'dap_default_agent_slug' ]
		);

		// Field 5 — Default Model
		add_settings_field(
			'default_model',
			__( 'Default Model', 'dot-agents-press' ),
			[ $this, 'field_default_model' ],
			self::PAGE_SLUG,
			'dap_config_main',
			[ 'label_for' => 'dap_default_model' ]
		);

		// Field 6 — Max Tokens
		add_settings_field(
			'max_tokens',
			__( 'Max Tokens', 'dot-agents-press' ),
			[ $this, 'field_max_tokens' ],
			self::PAGE_SLUG,
			'dap_config_main',
			[ 'label_for' => 'dap_max_tokens' ]
		);
	}

	// -------------------------------------------------------------------------
	// Sanitize
	// -------------------------------------------------------------------------

	public function sanitize( $input ): array {
		$sanitized = [];
		$input     = is_array( $input ) ? $input : [];

		$sanitized['telegram_bot_token']           = sanitize_text_field( $input['telegram_bot_token'] ?? '' );
		$sanitized['telegram_webhook_secret']      = sanitize_text_field( $input['telegram_webhook_secret'] ?? '' );
		$sanitized['telegram_default_agent_slug']  = sanitize_text_field( $input['telegram_default_agent_slug'] ?? '' );
		$sanitized['telegram_authorized_user_id']  = sanitize_text_field( $input['telegram_authorized_user_id'] ?? '' );
		$sanitized['default_model']                = sanitize_text_field( $input['default_model'] ?? 'deepseek/deepseek-v4-pro' );
		$sanitized['max_tokens']                   = absint( $input['max_tokens'] ?? 2048 );

		if ( $sanitized['max_tokens'] < 1 ) {
			$sanitized['max_tokens'] = 1;
			add_settings_error(
				self::OPTION_NAME,
				'max_tokens_invalid',
				__( 'Max Tokens must be at least 1.', 'dot-agents-press' )
			);
		}

		return $sanitized;
	}

	// -------------------------------------------------------------------------
	// Section callback
	// -------------------------------------------------------------------------

	public function section_main(): void {
		echo '<p class="description">';
		esc_html_e( 'Configure global defaults for your AI agents.', 'dot-agents-press' );
		echo '</p>';
	}

	// -------------------------------------------------------------------------
	// Field callbacks
	// -------------------------------------------------------------------------

	public function field_bot_token( array $args ): void {
		$options = get_option( self::OPTION_NAME, [] );
		$value   = $options['telegram_bot_token'] ?? '';
		printf(
			'<input type="password" id="%1$s" name="%2$s[telegram_bot_token]" value="%3$s" class="regular-text" autocomplete="new-password" placeholder="123456:ABC-DEF1234ghIkl-zyx57W2v1u123ew11">',
			esc_attr( $args['label_for'] ),
			esc_attr( self::OPTION_NAME ),
			esc_attr( $value )
		);
		echo '<p class="description">';
		esc_html_e( 'Token from @BotFather for your Telegram bot.', 'dot-agents-press' );
		echo '</p>';
	}

	public function field_authorized_user_id( array $args ): void {
		$options = get_option( self::OPTION_NAME, [] );
		$value   = $options['telegram_authorized_user_id'] ?? '';
		printf(
			'<input type="text" id="%1$s" name="%2$s[telegram_authorized_user_id]" value="%3$s" class="regular-text" placeholder="123456789">',
			esc_attr( $args['label_for'] ),
			esc_attr( self::OPTION_NAME ),
			esc_attr( $value )
		);
		echo '<p class="description">';
		esc_html_e( 'Your Telegram numeric user ID. Only messages from this user will be accepted (private assistant mode).', 'dot-agents-press' );
		echo '</p>';
	}

	public function field_webhook_secret( array $args ): void {
		$options = get_option( self::OPTION_NAME, [] );
		$value   = $options['telegram_webhook_secret'] ?? '';
		printf(
			'<input type="text" id="%1$s" name="%2$s[telegram_webhook_secret]" value="%3$s" class="regular-text" autocomplete="off" placeholder="%4$s">',
			esc_attr( $args['label_for'] ),
			esc_attr( self::OPTION_NAME ),
			esc_attr( $value ),
			esc_attr( wp_generate_password( 32, false ) )
		);
		echo '<p class="description">';
		esc_html_e( 'Optional secret for webhook verification (X-Telegram-Bot-Api-Secret-Token header).', 'dot-agents-press' );
		echo '</p>';
	}

	public function field_default_agent_slug( array $args ): void {
		$options = get_option( self::OPTION_NAME, [] );
		$value   = $options['telegram_default_agent_slug'] ?? '';
		printf(
			'<input type="text" id="%1$s" name="%2$s[telegram_default_agent_slug]" value="%3$s" class="regular-text" placeholder="personal-assistant">',
			esc_attr( $args['label_for'] ),
			esc_attr( self::OPTION_NAME ),
			esc_attr( $value )
		);
		echo '<p class="description">';
		esc_html_e( 'Slug of the agent (folder name in .agents/agents/) that handles Telegram messages. Leave empty to use the first enabled agent.', 'dot-agents-press' );
		echo '</p>';
	}

	public function field_default_model( array $args ): void {
		$options = get_option( self::OPTION_NAME, [] );
		$value   = $options['default_model'] ?? 'deepseek/deepseek-v4-pro';
		$models  = [
			'deepseek/deepseek-v4-pro'       => 'DeepSeek V4 Pro (OpenRouter)',
			'openai/gpt-4o'                  => 'GPT-4o (OpenRouter)',
			'openai/gpt-4o-mini'             => 'GPT-4o Mini (OpenRouter)',
			'anthropic/claude-haiku-4-5'     => 'Claude Haiku 4.5 (OpenRouter)',
			'anthropic/claude-sonnet-4'      => 'Claude Sonnet 4 (OpenRouter)',
			'google/gemini-2.5-flash'        => 'Gemini 2.5 Flash (OpenRouter)',
		];
		printf( '<select id="%s" name="%s[default_model]">', esc_attr( $args['label_for'] ), esc_attr( self::OPTION_NAME ) );
		foreach ( $models as $model_id => $model_label ) {
			printf(
				'<option value="%s" %s>%s</option>',
				esc_attr( $model_id ),
				selected( $value, $model_id, false ),
				esc_html( $model_label )
			);
		}
		echo '</select>';
		echo '<p class="description">';
		esc_html_e( 'Model used when an agent has no model override.', 'dot-agents-press' );
		echo '</p>';
	}

	public function field_max_tokens( array $args ): void {
		$options = get_option( self::OPTION_NAME, [] );
		$value   = $options['max_tokens'] ?? 2048;
		printf(
			'<input type="number" id="%1$s" name="%2$s[max_tokens]" value="%3$s" class="small-text" min="1" step="1">',
			esc_attr( $args['label_for'] ),
			esc_attr( self::OPTION_NAME ),
			esc_attr( (string) $value )
		);
		echo '<p class="description">';
		esc_html_e( 'Maximum completion tokens per request (1–16384).', 'dot-agents-press' );
		echo '</p>';
	}

	// -------------------------------------------------------------------------
	// Page render
	// -------------------------------------------------------------------------

	public function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'dot-agents-press' ) );
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( self::OPTION_GROUP );
				do_settings_sections( self::PAGE_SLUG );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}
}

