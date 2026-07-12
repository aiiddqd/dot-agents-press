<?php
/**
 * Admin controller — menus, list table, edit form, and settings page.
 *
 * @package Dot_Agents_Press
 */

namespace DotAgentsPress;

defined( 'ABSPATH' ) || exit;

/** Registers and renders all WP-Admin pages for the plugin. */
class Admin {

	private const MENU_SLUG     = 'dot-agents-press';
	private const CAP_MANAGE    = 'manage_options';
	private const NONCE_ACTION  = 'dap_admin_action';

	public function __construct() {
		add_action( 'admin_menu',             [ $this, 'register_menus' ] );
		add_action( 'admin_enqueue_scripts',  [ $this, 'enqueue_assets' ] );
		add_action( 'admin_post_dap_save_agent',    [ $this, 'handle_save_agent' ] );
		add_action( 'admin_post_dap_delete_agent',  [ $this, 'handle_delete_agent' ] );
		add_action( 'admin_post_dap_save_settings', [ $this, 'handle_save_settings' ] );
		add_action( 'admin_notices',          [ $this, 'admin_notices' ] );
	}

	// -------------------------------------------------------------------------
	// Menus
	// -------------------------------------------------------------------------

	public function register_menus(): void {
		add_menu_page(
			__( 'Dot Agents Press', 'dot-agents-press' ),
			__( 'AI Agents', 'dot-agents-press' ),
			self::CAP_MANAGE,
			self::MENU_SLUG,
			[ $this, 'page_agents_list' ],
			'dashicons-superhero-alt',
			30
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'All Agents', 'dot-agents-press' ),
			__( 'All Agents', 'dot-agents-press' ),
			self::CAP_MANAGE,
			self::MENU_SLUG,
			[ $this, 'page_agents_list' ]
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Add New Agent', 'dot-agents-press' ),
			__( 'Add New', 'dot-agents-press' ),
			self::CAP_MANAGE,
			self::MENU_SLUG . '-new',
			[ $this, 'page_agent_edit' ]
		);

		add_submenu_page(
			self::MENU_SLUG,
			__( 'Settings', 'dot-agents-press' ),
			__( 'Settings', 'dot-agents-press' ),
			self::CAP_MANAGE,
			self::MENU_SLUG . '-settings',
			[ $this, 'page_settings' ]
		);

		// Hidden edit page (accessible via row action link).
		add_submenu_page(
			'',                        // no parent → hidden from menu
			__( 'Edit Agent', 'dot-agents-press' ),
			__( 'Edit Agent', 'dot-agents-press' ),
			self::CAP_MANAGE,
			self::MENU_SLUG . '-edit',
			[ $this, 'page_agent_edit' ]
		);
	}

	// -------------------------------------------------------------------------
	// Assets
	// -------------------------------------------------------------------------

	public function enqueue_assets( string $hook ): void {
		if ( ! str_contains( $hook, self::MENU_SLUG ) ) {
			return;
		}

		wp_enqueue_style(
			'dap-admin',
			DAP_PLUGIN_URL . 'admin/css/admin.css',
			[],
			DAP_VERSION
		);

		wp_enqueue_script(
			'dap-admin',
			DAP_PLUGIN_URL . 'admin/js/admin.js',
			[ 'jquery' ],
			DAP_VERSION,
			true
		);

		wp_localize_script( 'dap-admin', 'DAP', [
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( self::NONCE_ACTION ),
		] );
	}

	// -------------------------------------------------------------------------
	// Page renderers
	// -------------------------------------------------------------------------

	public function page_agents_list(): void {
		if ( ! current_user_can( self::CAP_MANAGE ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'dot-agents-press' ) );
		}
		require DAP_PLUGIN_DIR . 'admin/views/agents-list.php';
	}

	public function page_agent_edit(): void {
		if ( ! current_user_can( self::CAP_MANAGE ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'dot-agents-press' ) );
		}
		require DAP_PLUGIN_DIR . 'admin/views/agent-edit.php';
	}

	public function page_settings(): void {
		if ( ! current_user_can( self::CAP_MANAGE ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'dot-agents-press' ) );
		}
		require DAP_PLUGIN_DIR . 'admin/views/settings.php';
	}

	// -------------------------------------------------------------------------
	// Form handlers
	// -------------------------------------------------------------------------

	public function handle_save_agent(): void {
		if ( ! current_user_can( self::CAP_MANAGE ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'dot-agents-press' ) );
		}

		check_admin_referer( self::NONCE_ACTION );

		$agent_repo = dot_agents_press()->agent;
		$id         = isset( $_POST['agent_id'] ) ? (int) $_POST['agent_id'] : 0;

		$data = [
			'name'          => $_POST['name']          ?? '',
			'slug'          => $_POST['slug']          ?? '',
			'description'   => $_POST['description']   ?? '',
			'system_prompt' => $_POST['system_prompt'] ?? '',
			'welcome_msg'   => $_POST['welcome_msg']   ?? '',
			'provider'      => $_POST['provider']      ?? 'openai',
			'model'         => $_POST['model']         ?? 'gpt-4o',
			'temperature'   => $_POST['temperature']   ?? '0.70',
			'api_key'       => $_POST['api_key']       ?? '',
			'enabled'       => isset( $_POST['enabled'] ) ? 1 : 0,
		];

		if ( $id > 0 ) {
			$result = $agent_repo->update( $id, $data );
			$redirect_id = $id;
		} else {
			$result = $agent_repo->create( $data );
			$redirect_id = is_wp_error( $result ) ? 0 : $result;
		}

		if ( is_wp_error( $result ) ) {
			$this->set_notice( 'error', $result->get_error_message() );
			wp_safe_redirect( wp_get_referer() ?: admin_url( 'admin.php?page=' . self::MENU_SLUG ) );
			exit;
		}

		$this->set_notice( 'success', __( 'Agent saved.', 'dot-agents-press' ) );
		wp_safe_redirect(
			admin_url( 'admin.php?page=' . self::MENU_SLUG . '-edit&agent_id=' . $redirect_id )
		);
		exit;
	}

	public function handle_delete_agent(): void {
		if ( ! current_user_can( self::CAP_MANAGE ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'dot-agents-press' ) );
		}

		check_admin_referer( self::NONCE_ACTION );

		$id = isset( $_POST['agent_id'] ) ? (int) $_POST['agent_id'] : 0;

		if ( $id > 0 ) {
			dot_agents_press()->agent->delete( $id );
			$this->set_notice( 'success', __( 'Agent deleted.', 'dot-agents-press' ) );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG ) );
		exit;
	}

	public function handle_save_settings(): void {
		if ( ! current_user_can( self::CAP_MANAGE ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'dot-agents-press' ) );
		}

		check_admin_referer( self::NONCE_ACTION );

		// Load existing values so we can preserve keys that were left blank.
		$existing = get_option( 'dap_settings', [] );

		$new_openai    = sanitize_text_field( $_POST['openai_api_key']    ?? '' );
		$new_anthropic = sanitize_text_field( $_POST['anthropic_api_key'] ?? '' );

		$settings = [
			'openai_api_key'    => ! empty( $new_openai )
				? $new_openai
				: ( $existing['openai_api_key'] ?? '' ),
			'anthropic_api_key' => ! empty( $new_anthropic )
				? $new_anthropic
				: ( $existing['anthropic_api_key'] ?? '' ),
		];

		update_option( 'dap_settings', $settings );
		$this->set_notice( 'success', __( 'Settings saved.', 'dot-agents-press' ) );

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG . '-settings' ) );
		exit;
	}

	// -------------------------------------------------------------------------
	// Admin notices
	// -------------------------------------------------------------------------

	public function admin_notices(): void {
		$notice = get_transient( 'dap_admin_notice_' . get_current_user_id() );
		if ( ! $notice ) {
			return;
		}
		delete_transient( 'dap_admin_notice_' . get_current_user_id() );
		$class   = esc_attr( 'notice notice-' . $notice['type'] );
		$message = esc_html( $notice['message'] );
		echo "<div class=\"{$class} is-dismissible\"><p>{$message}</p></div>";
	}

	private function set_notice( string $type, string $message ): void {
		set_transient(
			'dap_admin_notice_' . get_current_user_id(),
			[ 'type' => $type, 'message' => $message ],
			60
		);
	}
}
