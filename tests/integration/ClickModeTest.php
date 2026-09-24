<?php
/**
 * Click (JavaScript) mode: the front end gets assets/outbound-utm-click.js with an inline
 * window.outboundUtmConfig worked out for the page, and nothing when tagging is off.
 *
 * @package Outbound_UTM_Tagger
 */

class ClickModeTest extends Outbound_UTM_TestCase {

	const PREFIX = 'window.outboundUtmConfig = ';

	private function enable_click_mode( array $overrides = array() ) {
		$this->save_settings(
			array_merge(
				array(
					'mode'         => 'click',
					'utm_source'   => '{domain}',
					'utm_medium'   => 'Referral',
					'utm_campaign' => 'Spring Sale',
					'utm_term'     => '',
					'utm_content'  => '{slug}-{link_text}',
					'skip'         => array( 'skipme.com', 'xn--mnchen-3ya.de' ),
				),
				$overrides
			)
		);
		outbound_utm_boot();
	}

	/** The inline config, decoded. */
	private function config() {
		// WordPress 6.9+ appends a "//# sourceURL=..." line to inline script data, so match the config line itself.
		$inline = wp_scripts()->get_inline_script_data( Outbound_UTM_Click::HANDLE, 'before' );
		$this->assertStringStartsWith( self::PREFIX, $inline );
		$this->assertSame( 1, preg_match( '/^' . preg_quote( self::PREFIX, '/' ) . '(.*);$/m', $inline, $match ), "Unexpected inline script: {$inline}" );

		$config = json_decode( $match[1], true );
		$this->assertIsArray( $config, 'The inline config is not valid JSON.' );
		return $config;
	}

	public function test_script_is_enqueued_on_the_front_end_with_its_config() {
		$post_id = self::factory()->post->create(
			array(
				'post_name'  => 'hello-click',
				'post_title' => 'Hello Click',
			)
		);
		$this->enable_click_mode();

		$this->assertSame( 10, $this->click_hook_priority() );
		$this->assertFalse( $this->tagger_filter_priority(), 'Click mode must not buffer the page.' );

		$this->visit( get_permalink( $post_id ) );
		$this->assertTrue( is_singular() );
		do_action( 'wp_enqueue_scripts' );

		$this->assertTrue( wp_script_is( Outbound_UTM_Click::HANDLE, 'enqueued' ) );
		$script = wp_scripts()->registered[ Outbound_UTM_Click::HANDLE ];
		$this->assertStringEndsWith( '/assets/outbound-utm-click.js', $script->src );
		$this->assertSame( Outbound_UTM_Settings::VERSION, $script->ver );
		$this->assertSame( array(), $script->deps );
		$this->assertSame( 1, wp_scripts()->get_data( Outbound_UTM_Click::HANDLE, 'group' ), 'Loads in the footer.' );

		$config = $this->config();
		$this->assertSame( array( 'params', 'internal', 'skipHosts', 'skipClass', 'tidy' ), array_keys( $config ) );
		$this->assertSame(
			array(
				'utm_source'   => $this->site_domain(),
				'utm_medium'   => 'referral',
				'utm_campaign' => 'spring-sale',
				'utm_content'  => 'hello-click-{link_text}',
			),
			$config['params'],
			'Page placeholders are resolved on the server, {link_text} is left for the browser, and empty fields are left out.'
		);
		$this->assertSame( array( $this->site_domain() ), $config['internal'] );
		$this->assertSame( array( 'skipme.com', 'xn--mnchen-3ya.de' ), $config['skipHosts'] );
		$this->assertSame( 'noutm', $config['skipClass'] );
		$this->assertSame( 1, $config['tidy'] );
	}

	public function test_config_prints_before_the_script() {
		$this->enable_click_mode();
		$this->visit( home_url( '/' ) );
		do_action( 'wp_enqueue_scripts' );

		$printed = get_echo( array( wp_scripts(), 'do_items' ), array( array( Outbound_UTM_Click::HANDLE ) ) );

		$config_at = strpos( $printed, self::PREFIX );
		$script_at = strpos( $printed, 'assets/outbound-utm-click.js' );
		$this->assertNotFalse( $config_at, 'The inline config was not printed.' );
		$this->assertNotFalse( $script_at, 'The script tag was not printed.' );
		$this->assertLessThan( $script_at, $config_at, 'The config must be defined before the script runs.' );
	}

	public function test_tidy_off_is_passed_to_the_script() {
		$this->enable_click_mode(
			array(
				'tidy'         => 0,
				'utm_campaign' => 'Spring Sale',
			)
		);
		$this->visit( home_url( '/' ) );
		do_action( 'wp_enqueue_scripts' );

		$config = $this->config();
		$this->assertSame( 0, $config['tidy'] );
		$this->assertSame( 'Spring Sale', $config['params']['utm_campaign'] );
	}

	public function test_not_enqueued_when_tagging_is_off() {
		$this->enable_click_mode( array( 'enabled' => 0 ) );
		$this->assertFalse( $this->click_hook_priority() );

		$this->visit( home_url( '/' ) );
		do_action( 'wp_enqueue_scripts' );
		Outbound_UTM_Click::enqueue();

		$this->assertFalse( wp_script_is( Outbound_UTM_Click::HANDLE, 'registered' ) );
	}

	public function test_not_enqueued_in_page_mode() {
		$this->enable_click_mode( array( 'mode' => 'page' ) );
		$this->assertFalse( $this->click_hook_priority() );

		$this->visit( home_url( '/' ) );
		do_action( 'wp_enqueue_scripts' );
		Outbound_UTM_Click::enqueue();

		$this->assertFalse( wp_script_is( Outbound_UTM_Click::HANDLE, 'registered' ) );
	}

	public function test_not_enqueued_in_feeds() {
		$this->enable_click_mode();

		$this->visit( get_feed_link() );
		$this->assertTrue( is_feed() );
		do_action( 'wp_enqueue_scripts' );

		$this->assertFalse( wp_script_is( Outbound_UTM_Click::HANDLE, 'registered' ) );
	}

	public function test_not_enqueued_when_every_field_is_empty() {
		$this->enable_click_mode(
			array(
				'utm_source'   => '',
				'utm_medium'   => '',
				'utm_campaign' => '',
				'utm_content'  => '',
			)
		);

		$this->visit( home_url( '/' ) );
		do_action( 'wp_enqueue_scripts' );

		$this->assertFalse( wp_script_is( Outbound_UTM_Click::HANDLE, 'registered' ) );
	}
}
