<?php
/**
 * Runs only when the plugin is deleted from the Plugins screen, not when it is deactivated.
 *
 * Settings are stored per site, so on a multisite network every site's copy is removed,
 * a page of sites at a time so very large networks are not loaded all at once.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( is_multisite() ) {
	$outbound_utm_page   = 100;
	$outbound_utm_offset = 0;
	do {
		$outbound_utm_site_ids = get_sites(
			array(
				'fields' => 'ids',
				'number' => $outbound_utm_page,
				'offset' => $outbound_utm_offset,
			)
		);
		foreach ( $outbound_utm_site_ids as $outbound_utm_site_id ) {
			switch_to_blog( $outbound_utm_site_id );
			delete_option( 'outbound_utm_settings' );
			restore_current_blog();
		}
		$outbound_utm_offset += $outbound_utm_page;
	} while ( count( $outbound_utm_site_ids ) === $outbound_utm_page );
} else {
	delete_option( 'outbound_utm_settings' );
}
