<?php
/**
 * Admin view — Add / Edit Agent.
 *
 * @package Dot_Agents_Press
 */

defined( 'ABSPATH' ) || exit;

$agent_id   = isset( $_GET['agent_id'] ) ? (int) $_GET['agent_id'] : 0;
$agent_repo = dot_agents_press()->agent;
$agent      = $agent_id > 0 ? $agent_repo->get( $agent_id ) : null;

$is_new = ! $agent;

// Default values.
$name          = $agent->name          ?? '';
$slug          = $agent->slug          ?? '';
$description   = $agent->description   ?? '';
$system_prompt = $agent->system_prompt ?? '';
$welcome_msg   = $agent->welcome_msg   ?? '';
$provider      = $agent->provider      ?? 'openai';
$model         = $agent->model         ?? 'gpt-4o';
$temperature   = $agent->temperature   ?? '0.70';
$enabled       = isset( $agent->enabled ) ? (bool) $agent->enabled : true;
$has_key       = ! empty( $agent->api_key ?? '' );

$page_title = $is_new
	? __( 'Add New Agent', 'dot-agents-press' )
	: sprintf( __( 'Edit Agent: %s', 'dot-agents-press' ), esc_html( $name ) );

// Provider → model presets (used by JS).
$model_presets = [
	'openai'    => [ 'gpt-4o', 'gpt-4o-mini', 'gpt-4-turbo', 'gpt-4', 'gpt-3.5-turbo' ],
	'anthropic' => [ 'claude-opus-4-5', 'claude-sonnet-4-5', 'claude-haiku-4-5', 'claude-3-opus-20240229', 'claude-3-5-sonnet-20241022', 'claude-3-haiku-20240307' ],
	'custom'    => [],
];
?>
<div class="wrap dap-wrap">
	<h1><?php echo esc_html( $page_title ); ?></h1>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action"   value="dap_save_agent">
		<input type="hidden" name="agent_id" value="<?php echo esc_attr( $agent_id ); ?>">
		<?php wp_nonce_field( 'dap_admin_action' ); ?>

		<div id="poststuff">
			<div id="post-body" class="metabox-holder columns-2">

				<!-- ── Main column ──────────────────────────────────────────── -->
				<div id="post-body-content">

					<!-- Identity -->
					<div class="postbox">
						<h2 class="hndle"><?php esc_html_e( 'Identity', 'dot-agents-press' ); ?></h2>
						<div class="inside">
							<table class="form-table" role="presentation">
								<tr>
									<th scope="row"><label for="dap-name"><?php esc_html_e( 'Name', 'dot-agents-press' ); ?></label></th>
									<td>
										<input type="text" id="dap-name" name="name"
											   value="<?php echo esc_attr( $name ); ?>"
											   class="regular-text" required>
									</td>
								</tr>
								<tr>
									<th scope="row"><label for="dap-slug"><?php esc_html_e( 'Slug', 'dot-agents-press' ); ?></label></th>
									<td>
										<input type="text" id="dap-slug" name="slug"
											   value="<?php echo esc_attr( $slug ); ?>"
											   class="regular-text"
											   placeholder="<?php esc_attr_e( 'auto-generated from name', 'dot-agents-press' ); ?>">
										<p class="description"><?php esc_html_e( 'Used in shortcode: [dot_agent slug="your-slug"]', 'dot-agents-press' ); ?></p>
									</td>
								</tr>
								<tr>
									<th scope="row"><label for="dap-description"><?php esc_html_e( 'Description', 'dot-agents-press' ); ?></label></th>
									<td>
										<textarea id="dap-description" name="description"
												  rows="3" class="large-text"><?php echo esc_textarea( $description ); ?></textarea>
									</td>
								</tr>
							</table>
						</div>
					</div><!-- /.postbox -->

					<!-- Behaviour -->
					<div class="postbox">
						<h2 class="hndle"><?php esc_html_e( 'Behaviour', 'dot-agents-press' ); ?></h2>
						<div class="inside">
							<table class="form-table" role="presentation">
								<tr>
									<th scope="row"><label for="dap-system-prompt"><?php esc_html_e( 'System Prompt', 'dot-agents-press' ); ?></label></th>
									<td>
										<textarea id="dap-system-prompt" name="system_prompt"
												  rows="6" class="large-text"><?php echo esc_textarea( $system_prompt ); ?></textarea>
										<p class="description"><?php esc_html_e( 'Sent as the system message at the start of every conversation.', 'dot-agents-press' ); ?></p>
									</td>
								</tr>
								<tr>
									<th scope="row"><label for="dap-welcome-msg"><?php esc_html_e( 'Welcome Message', 'dot-agents-press' ); ?></label></th>
									<td>
										<textarea id="dap-welcome-msg" name="welcome_msg"
												  rows="3" class="large-text"><?php echo esc_textarea( $welcome_msg ); ?></textarea>
										<p class="description"><?php esc_html_e( 'First assistant bubble shown when the chat loads.', 'dot-agents-press' ); ?></p>
									</td>
								</tr>
							</table>
						</div>
					</div><!-- /.postbox -->

				</div><!-- /#post-body-content -->

				<!-- ── Side column ──────────────────────────────────────────── -->
				<div id="postbox-container-1" class="postbox-container">

					<!-- Publish -->
					<div class="postbox">
						<h2 class="hndle"><?php esc_html_e( 'Status', 'dot-agents-press' ); ?></h2>
						<div class="inside">
							<label>
								<input type="checkbox" name="enabled" value="1" <?php checked( $enabled ); ?>>
								<?php esc_html_e( 'Active (visible on site)', 'dot-agents-press' ); ?>
							</label>
							<div class="dap-actions">
								<?php if ( ! $is_new ) : ?>
									<a href="<?php echo esc_url( admin_url( 'admin.php?page=dot-agents-press' ) ); ?>"
									   class="button">
										<?php esc_html_e( '← All Agents', 'dot-agents-press' ); ?>
									</a>
								<?php endif; ?>
								<button type="submit" class="button button-primary">
									<?php echo $is_new ? esc_html__( 'Create Agent', 'dot-agents-press' ) : esc_html__( 'Update Agent', 'dot-agents-press' ); ?>
								</button>
							</div>
						</div>
					</div>

					<!-- Shortcode -->
					<?php if ( ! $is_new ) : ?>
					<div class="postbox">
						<h2 class="hndle"><?php esc_html_e( 'Embed', 'dot-agents-press' ); ?></h2>
						<div class="inside">
							<p><?php esc_html_e( 'Paste either shortcode into any page or post:', 'dot-agents-press' ); ?></p>
							<code class="dap-shortcode" data-shortcode="<?php echo esc_attr( '[dot_agent id="' . $agent_id . '"]' ); ?>">
								[dot_agent id="<?php echo esc_html( $agent_id ); ?>"]
							</code><br>
							<code class="dap-shortcode" data-shortcode="<?php echo esc_attr( '[dot_agent slug="' . $slug . '"]' ); ?>">
								[dot_agent slug="<?php echo esc_html( $slug ); ?>"]
							</code>
						</div>
					</div>
					<?php endif; ?>

					<!-- API -->
					<div class="postbox">
						<h2 class="hndle"><?php esc_html_e( 'AI Provider', 'dot-agents-press' ); ?></h2>
						<div class="inside">
							<table class="form-table" role="presentation">
								<tr>
									<th scope="row"><label for="dap-provider"><?php esc_html_e( 'Provider', 'dot-agents-press' ); ?></label></th>
									<td>
										<select id="dap-provider" name="provider">
											<option value="openai"    <?php selected( $provider, 'openai' ); ?>><?php esc_html_e( 'OpenAI', 'dot-agents-press' ); ?></option>
											<option value="anthropic" <?php selected( $provider, 'anthropic' ); ?>><?php esc_html_e( 'Anthropic', 'dot-agents-press' ); ?></option>
											<option value="custom"    <?php selected( $provider, 'custom' ); ?>><?php esc_html_e( 'Custom (OpenAI-compatible)', 'dot-agents-press' ); ?></option>
										</select>
									</td>
								</tr>
								<tr>
									<th scope="row"><label for="dap-model"><?php esc_html_e( 'Model', 'dot-agents-press' ); ?></label></th>
									<td>
										<input type="text" id="dap-model" name="model"
											   value="<?php echo esc_attr( $model ); ?>"
											   class="regular-text" list="dap-model-list">
										<datalist id="dap-model-list">
											<?php foreach ( $model_presets as $prov_models ) :
												foreach ( $prov_models as $m ) : ?>
													<option value="<?php echo esc_attr( $m ); ?>">
												<?php endforeach;
											endforeach; ?>
										</datalist>
									</td>
								</tr>
								<tr>
									<th scope="row"><label for="dap-temperature"><?php esc_html_e( 'Temperature', 'dot-agents-press' ); ?></label></th>
									<td>
										<input type="number" id="dap-temperature" name="temperature"
											   value="<?php echo esc_attr( $temperature ); ?>"
											   min="0" max="2" step="0.01" class="small-text">
										<p class="description"><?php esc_html_e( '0 = deterministic, 2 = very creative.', 'dot-agents-press' ); ?></p>
									</td>
								</tr>
								<tr>
									<th scope="row"><label for="dap-api-key"><?php esc_html_e( 'API Key Override', 'dot-agents-press' ); ?></label></th>
									<td>
										<input type="password" id="dap-api-key" name="api_key"
											   value=""
											   class="regular-text"
											   autocomplete="new-password"
											   placeholder="<?php echo $has_key ? esc_attr__( '(key stored — leave blank to keep)', 'dot-agents-press' ) : esc_attr__( 'Uses global key from Settings', 'dot-agents-press' ); ?>">
										<p class="description"><?php esc_html_e( 'Leave blank to use the global API key from Settings.', 'dot-agents-press' ); ?></p>
									</td>
								</tr>
							</table>
						</div>
					</div><!-- /.postbox -->

				</div><!-- /#postbox-container-1 -->

			</div><!-- /#post-body -->
		</div><!-- /#poststuff -->
	</form>
</div>

<script>
var DAP_MODEL_PRESETS = <?php echo wp_json_encode( $model_presets ); ?>;
</script>
