<?php
/**
 * Runs only when the plugin is deleted from the Plugins screen, not when it is deactivated.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'outbound_utm_settings' );
