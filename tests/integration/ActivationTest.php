<?php
/**
 * A freshly activated plugin changes nothing: tagging starts switched off, and while it
 * is off neither the page-mode output buffer filter nor the click-mode script is added.
 *
 * @package Outbound_UTM_Tagger
 */

class ActivationTest extends Outbound_UTM_TestCase {

	public function test_wordpress_loaded_and_booted_the_plugin() {
		$this->assertTrue( defined( 'OUTBOUND_UTM_FILE' ) );
		$this->assertSame( 10, has_action( 'plugins_loaded', 'outbound_utm_boot' ) );
		$this->assertGreaterThan( 0, did_action( 'plugins_loaded' ) );

		// Outbound_UTM_Settings::init() runs first thing in the boot, whatever the settings are.
		$this->assertSame( 10, has_action( 'update_option_' . Outbound_UTM_Settings::OPTION, array( 'Outbound_UTM_Settings', 'after_save' ) ) );
		$this->assertSame( 10, has_action( 'add_option_' . Outbound_UTM_Settings::OPTION, array( 'Outbound_UTM_Settings', 'after_save' ) ) );
	}

	public function test_fresh_activation_saves_nothing_and_tagging_is_off() {
		// Read in tests/bootstrap.php right after WordPress booted with the plugin active, before
		// any set_up() deleted the option, so a boot that saved settings would show up here.
		$this->assertArrayHasKey( 'outbound_utm_option_at_boot', $GLOBALS );
		$this->assertFalse( $GLOBALS['outbound_utm_option_at_boot'], 'Booting the plugin saved its option.' );

		// The plugin has no activation routine; firing the hook, or booting again, must not write settings.
		do_action( 'activate_' . plugin_basename( OUTBOUND_UTM_FILE ) );
		$this->assertFalse( get_option( Outbound_UTM_Settings::OPTION ) );
		outbound_utm_boot();
		$this->assertFalse( get_option( Outbound_UTM_Settings::OPTION ), 'outbound_utm_boot() saved the option.' );

		$settings = Outbound_UTM_Settings::get();
		$this->assertSame( 0, $settings['enabled'] );
		$this->assertSame( 'page', $settings['mode'] );
		$this->assertSame( 1, $settings['tidy'] );
		$this->assertSame( $this->site_domain(), $settings['utm_source'] );
		$this->assertSame( 'referral', $settings['utm_medium'] );
		$this->assertSame( '', $settings['utm_campaign'] );
		$this->assertSame( '', $settings['utm_term'] );
		$this->assertSame( '{slug}', $settings['utm_content'] );
		$this->assertSame( array(), $settings['skip'] );
	}

	public function test_boot_while_off_adds_no_output_buffer_filter_and_no_click_hook() {
		// On a classic theme (this test site's) core forces page buffering on at priority 0. Taken
		// out, WordPress only buffers when something registered for the buffer, so this shows
		// whether the plugin asks WordPress to buffer every page.
		remove_all_filters( 'wp_should_output_buffer_template_for_enhancement' );

		// WordPress booted this test site with the plugin active and nothing saved.
		$this->assertFalse( $this->tagger_filter_priority(), 'The output buffer filter was registered on a fresh install.' );
		$this->assertFalse( $this->click_hook_priority(), 'The click script hook was registered on a fresh install.' );
		$this->assertFalse( wp_should_output_buffer_template_for_enhancement(), 'WordPress would buffer every page on a fresh install.' );

		// Switched off, neither mode registers anything when the plugin boots.
		foreach ( array( 'page', 'click' ) as $mode ) {
			$this->save_settings(
				array(
					'enabled' => 0,
					'mode'    => $mode,
				)
			);
			outbound_utm_boot();

			$this->assertFalse( $this->tagger_filter_priority(), "Off in {$mode} mode, but the output buffer filter was registered." );
			$this->assertFalse( $this->click_hook_priority(), "Off in {$mode} mode, but the click script hook was registered." );
			$this->assertFalse( wp_should_output_buffer_template_for_enhancement(), "Off in {$mode} mode, but WordPress would buffer every page." );
		}
	}

	public function test_front_end_gets_no_click_script_while_off() {
		$this->save_settings(
			array(
				'enabled' => 0,
				'mode'    => 'click',
			)
		);
		outbound_utm_boot();

		$this->visit( home_url( '/' ) );
		do_action( 'wp_enqueue_scripts' );
		$this->assertFalse( wp_script_is( Outbound_UTM_Click::HANDLE, 'registered' ) );

		// Even when called directly, the click script is not added while tagging is off.
		Outbound_UTM_Click::enqueue();
		$this->assertFalse( wp_script_is( Outbound_UTM_Click::HANDLE, 'registered' ) );
	}

	public function test_page_filter_returns_the_page_untouched_while_off() {
		$this->save_settings( array( 'enabled' => 0 ) );

		$html = self::document( '<a href="https://partner.com/page">Partner</a>' );
		$this->assertSame( $html, Outbound_UTM_Tagger::filter_html( $html ) );
	}
}
