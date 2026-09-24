<?php
/**
 * A stand-in for SiteGround Speed Optimizer's purge function that counts its calls.
 *
 * Only SettingsSaveTest's separate-process test loads this. A PHP function cannot be removed
 * once defined, and the rest of the suite needs Speed Optimizer to be absent.
 *
 * @package Outbound_UTM_Tagger
 */

/**
 * Counts one purge.
 *
 * @param string|false $url Speed Optimizer's own argument; the plugin never passes one.
 */
function sg_cachepress_purge_cache( $url = false ) {
	$GLOBALS['outbound_utm_test_purges'][] = $url;
}
