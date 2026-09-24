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

// The run must be the kind of install it asked for. The core bootstrap reads WP_MULTISITE (or the
// WP_TESTS_MULTISITE constant); if that ever stopped taking effect, a "multisite" run would boot a
// single site, skip its multisite tests and still pass, so it stops here instead.
$outbound_utm_wants_multisite = ( '1' === getenv( 'WP_MULTISITE' ) ) || ( defined( 'WP_TESTS_MULTISITE' ) && WP_TESTS_MULTISITE );
if ( is_multisite() !== $outbound_utm_wants_multisite ) {
	fwrite(
		STDERR,
		sprintf(
			"Asked for %s (WP_MULTISITE=%s) but WordPress booted as %s.\n",
			$outbound_utm_wants_multisite ? 'multisite' : 'a single site',
			var_export( getenv( 'WP_MULTISITE' ), true ),
			is_multisite() ? 'multisite' : 'a single site'
		)
	);
	exit( 1 );
}

// What the plugin's option held once WordPress had booted with the plugin active, before any
// test's set_up() deleted it. ActivationTest checks that booting the plugin saved nothing.
$GLOBALS['outbound_utm_option_at_boot'] = get_option( 'outbound_utm_settings' );

// Tests that run in a separate PHP process (@runInSeparateProcess) load this bootstrap again.
// They reuse the database installed above instead of dropping and reinstalling it mid-run.
putenv( 'WP_TESTS_SKIP_INSTALL=1' );

require_once __DIR__ . '/includes/class-outbound-utm-testcase.php';

printf(
	'Outbound UTM Tagger integration tests: WordPress %s (%s), PHP %s' . PHP_EOL,
	get_bloginfo( 'version' ),
	is_multisite() ? 'multisite' : 'single site',
	PHP_VERSION
);
