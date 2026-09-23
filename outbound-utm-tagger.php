<?php
/**
 * Plugin Name:       Outbound UTM Tagger
 * Description:       Adds UTM parameters to links that point to other websites. Stage 1: sitewide defaults, placeholders, and the skip list.
 * Version:           0.2.2
 * Requires at least: 6.9
 * Requires PHP:      8.0
 * Author:            Dani Owens
 * Author URI:        https://localpr.com/tools/
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       outbound-utm-tagger
 */

defined( 'ABSPATH' ) || exit;

define( 'OUTBOUND_UTM_FILE', __FILE__ );

require_once plugin_dir_path( __FILE__ ) . 'includes/class-outbound-utm-context.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-outbound-utm-settings.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-outbound-utm-tagger.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-outbound-utm-click.php';

add_action( 'plugins_loaded', 'outbound_utm_boot' );

/**
 * Starts the plugin in whichever mode is chosen.
 *
 * Click mode needs nothing special. Page mode needs the filter that WordPress 6.9
 * added, and the check looks for the function rather than the version number,
 * because some security plugins hide the version.
 */
function outbound_utm_boot() {
	Outbound_UTM_Settings::init();

	$settings = Outbound_UTM_Settings::get();

	// Nothing to do until an admin switches tagging on. Gating here means page mode
	// never registers the output-buffer filter (whose mere presence makes WordPress
	// buffer the whole page) while the plugin is off — which is the default state.
	if ( empty( $settings['enabled'] ) ) {
		return;
	}

	if ( 'click' === $settings['mode'] ) {
		Outbound_UTM_Click::init();
		return;
	}

	if ( ! function_exists( 'wp_start_template_enhancement_output_buffer' ) ) {
		add_action( 'admin_notices', 'outbound_utm_version_notice' );
		return;
	}

	Outbound_UTM_Tagger::init();
}

/**
 * Tells an administrator why nothing is being tagged.
 */
function outbound_utm_version_notice() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	echo '<div class="notice notice-warning"><p>';
	esc_html_e( 'Outbound UTM Tagger needs WordPress 6.9 or newer. Nothing is being tagged until this site is updated.', 'outbound-utm-tagger' );
	echo '</p></div>';
}
