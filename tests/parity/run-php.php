<?php
/**
 * Runs the shared parity cases through the real HTML-mode (page mode) classes, following
 * the same per-link path as Outbound_UTM_Tagger::filter_html(): clean the link text,
 * resolve the fields, build the query, then decide whether and how to tag the href.
 * WordPress is not loaded; the handful of core functions these classes touch are
 * stubbed below. Exits 1 on any mismatch.
 *
 * Usage: php tests/parity/run-php.php
 *
 * @package Outbound_UTM_Tagger
 */

// phpcs:ignoreFile -- Test harness, not shipped (see .distignore).

define( 'ABSPATH', __DIR__ . '/' );

$outbound_utm_test_options = array();

function get_option( $name, $fallback = false ) {
	global $outbound_utm_test_options;
	return array_key_exists( $name, $outbound_utm_test_options ) ? $outbound_utm_test_options[ $name ] : $fallback;
}
function wp_parse_args( $args, $defaults ) {
	return array_merge( $defaults, (array) $args );
}
function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}
function wp_strip_all_tags( $text ) {
	return trim( strip_tags( $text ) );
}
function wp_check_invalid_utf8( $text, $strip = false ) {
	return $text;
}

$root = dirname( __DIR__, 2 );
require $root . '/includes/class-outbound-utm-context.php';
require $root . '/includes/class-outbound-utm-settings.php';
require $root . '/includes/class-outbound-utm-tagger.php';

$data = json_decode( file_get_contents( __DIR__ . '/cases.json' ), true );
$site = $data['site'];

function home_url( $path = '' ) {
	global $site;
	return 'https://' . $site . $path;
}
function site_url( $path = '' ) {
	return home_url( $path );
}

function call_private( $class, $method, ...$args ) {
	$m = new ReflectionMethod( $class, $method );
	$m->setAccessible( true );
	return $m->invokeArgs( null, $args );
}

function use_settings( array $settings ) {
	global $outbound_utm_test_options;
	$outbound_utm_test_options['outbound_utm_settings'] = $settings;
	$cache = new ReflectionProperty( 'Outbound_UTM_Settings', 'cache' );
	$cache->setAccessible( true );
	$cache->setValue( null, null );
}

function run_case( array $c ) {
	$settings = array(
		'enabled' => 1,
		'mode'    => 'page',
		'tidy'    => $c['tidy'] ? 1 : 0,
		'skip'    => $c['skip'],
	);
	foreach ( Outbound_UTM_Settings::fields() as $field ) {
		$settings[ $field ] = isset( $c['fields'][ $field ] ) ? $c['fields'][ $field ] : '';
	}
	use_settings( $settings );

	// Split fields the way filter_html() does: {link_text} fields are worked out per link.
	$fixed    = array();
	$per_link = array();
	foreach ( Outbound_UTM_Settings::fields() as $field ) {
		$value = (string) $settings[ $field ];
		if ( '' === $value ) {
			continue;
		}
		if ( false !== strpos( $value, '{link_text}' ) ) {
			$per_link[ $field ] = $value;
		} else {
			$fixed[ $field ] = $value;
		}
	}

	$fixed_resolved = call_private( 'Outbound_UTM_Tagger', 'resolve', $fixed, '' );
	$text           = call_private( 'Outbound_UTM_Tagger', 'clean_link_text', $c['text'] );
	$params         = call_private( 'Outbound_UTM_Tagger', 'merge', $fixed_resolved, $per_link, $text );
	$query          = call_private( 'Outbound_UTM_Tagger', 'build_query', $params );
	if ( '' === $query ) {
		return null;
	}

	return call_private( 'Outbound_UTM_Tagger', 'tag_href', $c['href'], $query, Outbound_UTM_Tagger::internal_hosts(), $c['skip'] );
}

$failed = 0;
foreach ( $data['cases'] as $c ) {
	$got = run_case( $c );
	if ( $got === $c['expected'] ) {
		echo "ok    {$c['name']}\n";
	} else {
		++$failed;
		echo "FAIL  {$c['name']}\n      expected: " . var_export( $c['expected'], true ) . "\n      got:      " . var_export( $got, true ) . "\n";
	}
}

// PHP-only: skip-list entries are stored in ASCII form (needs the intl extension).
$stored = Outbound_UTM_Settings::clean_domain( 'https://www.M' . "\u{00fc}" . 'nchen.de/page' );
if ( function_exists( 'idn_to_ascii' ) ) {
	$total = count( $data['cases'] ) + 1;
	if ( 'xn--mnchen-3ya.de' === $stored ) {
		echo "ok    clean_domain stores IDN skip entries as punycode\n";
	} else {
		++$failed;
		echo "FAIL  clean_domain stores IDN skip entries as punycode\n      got: {$stored}\n";
	}
} else {
	$total = count( $data['cases'] );
	echo "WARN  intl extension missing: IDN normalization untested and inactive\n";
}

echo "\nHTML mode: " . ( $total - $failed ) . "/{$total} parity cases pass\n";
exit( $failed ? 1 : 0 );
