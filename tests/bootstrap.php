<?php
/**
 * PHPUnit bootstrap for the integration tests.
 *
 * Loads the WordPress test suite that bin/install-wp-tests.sh installed (found through
 * WP_TESTS_DIR), the PHPUnit Polyfills that suite requires, and this plugin on
 * muplugins_loaded, so the plugin is already running when WordPress fires plugins_loaded,
 * exactly as an activated plugin would be.
 *
 * @package Outbound_UTM_Tagger
 */

$outbound_utm_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $outbound_utm_tests_dir ) {
	$outbound_utm_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $outbound_utm_tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "Could not find {$outbound_utm_tests_dir}/includes/functions.php. Run bin/install-wp-tests.sh first, or set WP_TESTS_DIR.\n" );
	exit( 1 );
}

$outbound_utm_root = dirname( __DIR__ );

// The WordPress test suite requires the PHPUnit Polyfills. Load Composer's copy and tell the suite where it is.
if ( ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $outbound_utm_root . '/vendor/yoast/phpunit-polyfills' );
}
require_once $outbound_utm_root . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';

// Gives access to tests_add_filter().
require_once $outbound_utm_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $outbound_utm_root ) {
		require $outbound_utm_root . '/outbound-utm-tagger.php';
	}
);

// Installs WordPress into the test database and boots it (single site, or multisite when WP_MULTISITE=1).
require $outbound_utm_tests_dir . '/includes/bootstrap.php';

require_once __DIR__ . '/includes/class-outbound-utm-testcase.php';

printf(
	'Outbound UTM Tagger integration tests: WordPress %s (%s), PHP %s' . PHP_EOL,
	get_bloginfo( 'version' ),
	is_multisite() ? 'multisite' : 'single site',
	PHP_VERSION
);
