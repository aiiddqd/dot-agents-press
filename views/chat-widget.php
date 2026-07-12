<?php
/**
 * Public view — Chat widget.
 *
 * @package Dot_Agents_Press
 * @var object $agent Agent row object.
 */

defined( 'ABSPATH' ) || exit;

wp_enqueue_style( 'dap-chat' );
wp_enqueue_script( 'dap-chat' );

$widget_id = 'dap-widget-' . $agent->id;
?>
<div class="dap-chat-widget"
	 id="<?php echo esc_attr( $widget_id ); ?>"
	 data-agent-id="<?php echo esc_attr( $agent->id ); ?>">

	<div class="dap-chat-header">
		<span class="dap-chat-name"><?php echo esc_html( $agent->name ); ?></span>
		<?php if ( ! empty( $agent->description ) ) : ?>
			<span class="dap-chat-description"><?php echo esc_html( $agent->description ); ?></span>
		<?php endif; ?>
	</div>

	<div class="dap-chat-messages" role="log" aria-live="polite" aria-label="<?php esc_attr_e( 'Chat messages', 'dot-agents-press' ); ?>">
		<?php if ( ! empty( $agent->welcome_msg ) ) : ?>
		<div class="dap-message dap-message--assistant">
			<div class="dap-message-bubble">
				<?php echo nl2br( esc_html( $agent->welcome_msg ) ); ?>
			</div>
		</div>
		<?php endif; ?>
	</div>

	<form class="dap-chat-form" data-agent-id="<?php echo esc_attr( $agent->id ); ?>">
		<div class="dap-chat-input-row">
			<label for="<?php echo esc_attr( $widget_id . '-input' ); ?>" class="screen-reader-text">
				<?php esc_html_e( 'Your message', 'dot-agents-press' ); ?>
			</label>
			<textarea
				id="<?php echo esc_attr( $widget_id . '-input' ); ?>"
				class="dap-chat-input"
				rows="1"
				placeholder="<?php esc_attr_e( 'Type your message…', 'dot-agents-press' ); ?>"
				required></textarea>
			<button type="submit" class="dap-chat-send">
				<?php esc_html_e( 'Send', 'dot-agents-press' ); ?>
			</button>
		</div>
	</form>

	<p class="dap-powered-by">
		<?php
		printf(
			/* translators: %s: plugin name */
			esc_html__( 'Powered by %s', 'dot-agents-press' ),
			'<a href="https://github.com/aiiddqd/dot-agents-press" target="_blank" rel="noopener noreferrer">Dot Agents Press</a>'
		);
		?>
	</p>
</div>
