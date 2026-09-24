<?php
/**
 * Base class for the integration tests.
 *
 * The plugin keeps a few per-request caches in static properties (settings, placeholder
 * values, the settings screen's hook suffix). A real request starts with them empty, so
 * every test does too. Each test also gets fresh script and style registries.
 *
 * @package Outbound_UTM_Tagger
 */

abstract class Outbound_UTM_TestCase extends WP_UnitTestCase {

	/**
	 * Script registry as it was before the test.
	 *
	 * @var WP_Scripts|null
	 */
	private $saved_scripts;

	/**
	 * Style registry as it was before the test.
	 *
	 * @var WP_Styles|null
	 */
	private $saved_styles;

	public function set_up() {
		parent::set_up();

		$this->saved_scripts   = isset( $GLOBALS['wp_scripts'] ) ? $GLOBALS['wp_scripts'] : null;
		$this->saved_styles    = isset( $GLOBALS['wp_styles'] ) ? $GLOBALS['wp_styles'] : null;
		$GLOBALS['wp_scripts'] = null;
		$GLOBALS['wp_styles']  = null;

		delete_option( Outbound_UTM_Settings::OPTION );
		self::reset_plugin_state();
	}

	public function tear_down() {
		$GLOBALS['wp_scripts'] = $this->saved_scripts;
		$GLOBALS['wp_styles']  = $this->saved_styles;
		self::reset_plugin_state();

		parent::tear_down();
	}

	/**
	 * Empties the plugin's per-request caches, as at the start of a new request.
	 */
	protected static function reset_plugin_state() {
		self::set_static( 'Outbound_UTM_Settings', 'cache', null );
		self::set_static( 'Outbound_UTM_Settings', 'hook', '' );
		self::set_static( 'Outbound_UTM_Context', 'terms', array() );
		self::set_static( 'Outbound_UTM_Context', 'values', array() );
		self::set_static( 'Outbound_UTM_Context', 'page', null );
	}

	protected static function set_static( $class_name, $property, $value ) {
		$reflection = new ReflectionProperty( $class_name, $property );
		if ( PHP_VERSION_ID < 80100 ) {
			$reflection->setAccessible( true );
		}
		$reflection->setValue( null, $value );
	}

	protected static function get_static( $class_name, $property ) {
		$reflection = new ReflectionProperty( $class_name, $property );
		if ( PHP_VERSION_ID < 80100 ) {
			$reflection->setAccessible( true );
		}
		return $reflection->getValue();
	}

	/**
	 * Saves settings the way the settings screen stores them (already clean), switched on by default.
	 *
	 * @param array $overrides Values to change from the baseline.
	 * @return array The settings saved.
	 */
	protected function save_settings( array $overrides = array() ) {
		$settings = array_merge(
			array(
				'enabled'      => 1,
				'mode'         => 'page',
				'tidy'         => 1,
				'utm_source'   => $this->site_domain(),
				'utm_medium'   => 'referral',
				'utm_campaign' => '',
				'utm_term'     => '',
				'utm_content'  => '',
				'skip'         => array(),
			),
			$overrides
		);
		update_option( Outbound_UTM_Settings::OPTION, $settings );
		self::reset_plugin_state();
		return $settings;
	}

	/** This test site's domain without "www.", e.g. example.org. */
	protected function site_domain() {
		$host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		return 0 === strpos( $host, 'www.' ) ? substr( $host, 4 ) : $host;
	}

	/**
	 * Runs the main query for a URL, as a new front-end request would.
	 *
	 * @param string $url URL or path to request.
	 */
	protected function visit( $url ) {
		$this->go_to( $url );
		self::reset_plugin_state();
	}

	/** Priority of the page-mode output buffer filter, or false when it is not registered. */
	protected function tagger_filter_priority() {
		return has_filter( 'wp_template_enhancement_output_buffer', array( 'Outbound_UTM_Tagger', 'filter_html' ) );
	}

	/** Priority of the click-mode enqueue callback, or false when it is not registered. */
	protected function click_hook_priority() {
		return has_action( 'wp_enqueue_scripts', array( 'Outbound_UTM_Click', 'enqueue' ) );
	}

	/**
	 * A whole HTML document wrapped around some body markup.
	 *
	 * @param string $body Body markup.
	 * @return string
	 */
	protected static function document( $body ) {
		return "<!DOCTYPE html>\n<html lang=\"en\"><head><meta charset=\"utf-8\"><title>Test</title></head><body>\n{$body}\n</body></html>\n";
	}

	/**
	 * The href of every link that has an id, keyed by that id, read with the HTML API
	 * (so entities are decoded exactly as a browser would read them).
	 *
	 * @param string $html Markup.
	 * @return array<string, string|null>
	 */
	protected static function hrefs( $html ) {
		$hrefs     = array();
		$processor = new WP_HTML_Tag_Processor( $html );
		while ( $processor->next_tag( 'A' ) ) {
			$id = $processor->get_attribute( 'id' );
			if ( is_string( $id ) ) {
				$href         = $processor->get_attribute( 'href' );
				$hrefs[ $id ] = is_string( $href ) ? $href : null;
			}
		}
		return $hrefs;
	}
}
