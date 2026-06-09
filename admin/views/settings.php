<?php
/**
 * Admin view — Plugin Settings.
 *
 * @package Dot_Agents_Press
 */

defined( 'ABSPATH' ) || exit;

$settings = get_option( 'dap_settings', [] );
$openai_key    = ! empty( $settings['openai_api_key'] )    ? '••••••••' : '';
$anthropic_key = ! empty( $settings['anthropic_api_key'] ) ? '••••••••' : '';
?>
<div class="wrap dap-wrap">
	<h1><?php esc_html_e( 'Dot Agents Press — Settings', 'dot-agents-press' ); ?></h1>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="dap_save_settings">
		<?php wp_nonce_field( 'dap_admin_action' ); ?>

		<h2><?php esc_html_e( 'Global API Keys', 'dot-agents-press' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'These keys are used for agents that do not have an agent-specific API key configured. Global keys are stored in plain text in the WordPress options table — for production use consider storing them in wp-config.php as PHP constants instead (see below). Per-agent API key overrides are stored encrypted.', 'dot-agents-press' ); ?>
		</p>

		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">
					<label for="dap-openai-key"><?php esc_html_e( 'OpenAI API Key', 'dot-agents-press' ); ?></label>
				</th>
				<td>
					<input type="password" id="dap-openai-key" name="openai_api_key"
						   value=""
						   class="regular-text"
						   autocomplete="new-password"
						   placeholder="<?php echo $openai_key ? esc_attr__( '(key stored — leave blank to keep)', 'dot-agents-press' ) : 'sk-…'; ?>">
					<?php if ( $openai_key ) : ?>
						<span class="dap-key-indicator"><?php esc_html_e( '✔ key stored', 'dot-agents-press' ); ?></span>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row">
					<label for="dap-anthropic-key"><?php esc_html_e( 'Anthropic API Key', 'dot-agents-press' ); ?></label>
				</th>
				<td>
					<input type="password" id="dap-anthropic-key" name="anthropic_api_key"
						   value=""
						   class="regular-text"
						   autocomplete="new-password"
						   placeholder="<?php echo $anthropic_key ? esc_attr__( '(key stored — leave blank to keep)', 'dot-agents-press' ) : 'sk-ant-…'; ?>">
					<?php if ( $anthropic_key ) : ?>
						<span class="dap-key-indicator"><?php esc_html_e( '✔ key stored', 'dot-agents-press' ); ?></span>
					<?php endif; ?>
				</td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'Tip: Using Constants in wp-config.php', 'dot-agents-press' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'You can alternatively define the API keys as PHP constants for better security:', 'dot-agents-press' ); ?>
		</p>
		<pre class="dap-code-block">define( 'DAP_OPENAI_API_KEY',    'sk-…' );
define( 'DAP_ANTHROPIC_API_KEY', 'sk-ant-…' );</pre>
		<p class="description">
			<?php esc_html_e( 'Constants take precedence over the values stored here.', 'dot-agents-press' ); ?>
		</p>

		<?php submit_button( __( 'Save Settings', 'dot-agents-press' ) ); ?>
	</form>
</div>
