<?php
/**
 * Plugin Name:       Outbound UTM Tagger
 * Description:       Adds UTM parameters to links that point to other websites. Stage 1: sitewide defaults, placeholders, and the skip list.
 * Version:           0.2.1
 * Requires at least: 6.9
 * Requires PHP:      7.4
 * Update URI:        false
 * License:           GPLv2 or later
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
	echo esc_html( 'Outbound UTM Tagger needs WordPress 6.9 or newer. Nothing is being tagged until this site is updated.' );
	echo '</p></div>';
}
