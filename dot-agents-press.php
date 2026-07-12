<?php
/**
 * Plugin Name:       Dot Agents Press
 * Plugin URI:        https://github.com/aiiddqd/dot-agents-press
 * Description:       Your AI Agents on the web. OpenClaw Alternative — manage and embed conversational AI agents directly in WordPress.
 * Version:           0.260508.1
 * Requires at least: 7.0
 * Requires PHP:      8.1
 * Author:            Dot Agents Press Contributors
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       dot-agents-press
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;


define( 'DAP_VERSION', '1.0.0' );
define( 'DAP_PLUGIN_FILE', __FILE__ );
define( 'DAP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'DAP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once DAP_PLUGIN_DIR . 'includes/Main.php';

/**
 * Returns the main plugin instance.
 *
	 * @return \DotAgentsPress\Main
 */
function dot_agents_press(): \DotAgentsPress\Main {
	return \DotAgentsPress\Main::instance();
}

dot_agents_press();
