<?php
/**
 * Admin view — Agents list.
 *
 * @package Dot_Agents_Press
 * @var DAP_Agent $agent_repo
 */

defined( 'ABSPATH' ) || exit;

$agent_repo = dot_agents_press()->agent;

$search   = isset( $_GET['s'] ) ? sanitize_text_field( $_GET['s'] ) : '';
$per_page = 20;
$paged    = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
$offset   = ( $paged - 1 ) * $per_page;

$query_args = [ 'per_page' => $per_page, 'offset' => $offset ];
if ( $search ) {
	$query_args['search'] = $search;
}

$agents     = $agent_repo->get_all( $query_args );
$total      = $agent_repo->count( $search ? [ 'search' => $search ] : [] );
$total_pages = ceil( $total / $per_page );
?>
<div class="wrap dap-wrap">
	<h1 class="wp-heading-inline">
		<?php esc_html_e( 'AI Agents', 'dot-agents-press' ); ?>
	</h1>
	<a href="<?php echo esc_url( admin_url( 'admin.php?page=dot-agents-press-new' ) ); ?>"
	   class="page-title-action">
		<?php esc_html_e( 'Add New', 'dot-agents-press' ); ?>
	</a>
	<hr class="wp-header-end">

	<form method="get">
		<input type="hidden" name="page" value="dot-agents-press">
		<?php
		$search_val = esc_attr( $search );
		echo "<p class=\"search-box\">
			<input type=\"search\" name=\"s\" value=\"{$search_val}\"
				placeholder=\"" . esc_attr__( 'Search agents…', 'dot-agents-press' ) . "\">
			<input type=\"submit\" class=\"button\" value=\"" . esc_attr__( 'Search', 'dot-agents-press' ) . "\">
		</p>";
		?>
	</form>

	<?php if ( empty( $agents ) ) : ?>
		<div class="dap-empty">
			<p>
				<?php
				if ( $search ) {
					esc_html_e( 'No agents match your search.', 'dot-agents-press' );
				} else {
					printf(
						/* translators: %s: Add New link */
						esc_html__( 'No agents yet. %s to get started.', 'dot-agents-press' ),
						'<a href="' . esc_url( admin_url( 'admin.php?page=dot-agents-press-new' ) ) . '">'
							. esc_html__( 'Create your first agent', 'dot-agents-press' )
						. '</a>'
					);
				}
				?>
			</p>
		</div>
	<?php else : ?>
	<table class="wp-list-table widefat fixed striped">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Name', 'dot-agents-press' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Slug', 'dot-agents-press' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Provider', 'dot-agents-press' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Model', 'dot-agents-press' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Status', 'dot-agents-press' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Shortcode', 'dot-agents-press' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php foreach ( $agents as $a ) :
			$edit_url   = admin_url( 'admin.php?page=dot-agents-press-edit&agent_id=' . $a->id );
			$delete_url = wp_nonce_url(
				admin_url( 'admin-post.php?action=dap_delete_agent&agent_id=' . $a->id ),
				'dap_admin_action'
			);
			$shortcode  = '[dot_agent id="' . $a->id . '"]';
		?>
			<tr>
				<td>
					<strong>
						<a href="<?php echo esc_url( $edit_url ); ?>">
							<?php echo esc_html( $a->name ); ?>
						</a>
					</strong>
					<div class="row-actions">
						<span class="edit">
							<a href="<?php echo esc_url( $edit_url ); ?>">
								<?php esc_html_e( 'Edit', 'dot-agents-press' ); ?>
							</a> |
						</span>
						<span class="trash">
							<a href="<?php echo esc_url( $delete_url ); ?>"
							   onclick="return confirm('<?php echo esc_js( __( 'Delete this agent? This cannot be undone.', 'dot-agents-press' ) ); ?>')"
							   class="submitdelete">
								<?php esc_html_e( 'Delete', 'dot-agents-press' ); ?>
							</a>
						</span>
					</div>
				</td>
				<td><code><?php echo esc_html( $a->slug ); ?></code></td>
				<td><?php echo esc_html( ucfirst( $a->provider ) ); ?></td>
				<td><code><?php echo esc_html( $a->model ); ?></code></td>
				<td>
					<?php if ( $a->enabled ) : ?>
						<span class="dap-badge dap-badge--active"><?php esc_html_e( 'Active', 'dot-agents-press' ); ?></span>
					<?php else : ?>
						<span class="dap-badge dap-badge--inactive"><?php esc_html_e( 'Inactive', 'dot-agents-press' ); ?></span>
					<?php endif; ?>
				</td>
				<td>
					<code class="dap-shortcode" data-shortcode="<?php echo esc_attr( $shortcode ); ?>">
						<?php echo esc_html( $shortcode ); ?>
					</code>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>

	<?php if ( $total_pages > 1 ) : ?>
		<div class="tablenav bottom">
			<div class="tablenav-pages">
				<?php
				echo paginate_links( [
					'base'      => add_query_arg( 'paged', '%#%' ),
					'format'    => '',
					'current'   => $paged,
					'total'     => $total_pages,
					'prev_text' => '&laquo;',
					'next_text' => '&raquo;',
				] );
				?>
			</div>
		</div>
	<?php endif; ?>
	<?php endif; ?>
</div>
