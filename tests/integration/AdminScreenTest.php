<?php
/**
 * The settings screen: admin hooks only in the admin, and its stylesheet only on its own screen.
 *
 * @package Outbound_UTM_Tagger
 */

class AdminScreenTest extends Outbound_UTM_TestCase {

	const STYLE = 'outbound-utm-admin';

	/** @var array|null */
	private $saved_admin_page_hooks;

	/** @var string|null */
	private $saved_request_uri;

	public function set_up() {
		parent::set_up();

		// Admin-only APIs that a front-end bootstrap does not load.
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
		require_once ABSPATH . 'wp-admin/includes/screen.php';
		require_once ABSPATH . 'wp-admin/includes/template.php';

		$this->saved_admin_page_hooks = isset( $GLOBALS['admin_page_hooks'] ) ? $GLOBALS['admin_page_hooks'] : null;
		$this->saved_request_uri      = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : null;

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down() {
		$GLOBALS['admin_page_hooks'] = $this->saved_admin_page_hooks;
		unset( $_GET['page'], $_GET['settings-updated'] );
		if ( null === $this->saved_request_uri ) {
			unset( $_SERVER['REQUEST_URI'] );
		} else {
			$_SERVER['REQUEST_URI'] = $this->saved_request_uri;
		}
		unregister_setting( Outbound_UTM_Settings::GROUP, Outbound_UTM_Settings::OPTION );
		parent::tear_down();
	}

	/** The settings screen as an administrator sees it. */
	private function render_settings_screen() {
		$_SERVER['REQUEST_URI'] = '/wp-admin/options-general.php?page=outbound-utm-tagger';
		return get_echo( array( 'Outbound_UTM_Settings', 'render' ) );
	}

	/**
	 * Every form control on the page, in order.
	 *
	 * @param string $html Markup.
	 * @return array<int, array{name: string|null, type: string, value: string|null, checked: bool}>
	 */
	private static function form_controls( $html ) {
		$controls  = array();
		$processor = new WP_HTML_Tag_Processor( $html );
		while ( $processor->next_tag() ) {
			$tag = $processor->get_tag();
			if ( 'INPUT' !== $tag && 'TEXTAREA' !== $tag && 'SELECT' !== $tag && 'BUTTON' !== $tag ) {
				continue;
			}
			$name       = $processor->get_attribute( 'name' );
			$value      = 'TEXTAREA' === $tag ? $processor->get_modifiable_text() : $processor->get_attribute( 'value' );
			$controls[] = array(
				'name'    => is_string( $name ) ? $name : null,
				'type'    => 'INPUT' === $tag ? strtolower( (string) $processor->get_attribute( 'type' ) ) : strtolower( $tag ),
				'value'   => is_string( $value ) ? $value : null,
				'checked' => null !== $processor->get_attribute( 'checked' ),
			);
		}
		return $controls;
	}

	/**
	 * What a browser posts for this form, parsed the way PHP builds $_POST.
	 *
	 * @param array $controls From form_controls().
	 * @param array $changes  What the admin changes before saving: field key => text typed,
	 *                        true/false to tick or untick a checkbox, or the radio value picked.
	 * @return array
	 */
	private static function submit( array $controls, array $changes = array() ) {
		$pairs = array();
		foreach ( $controls as $control ) {
			if ( null === $control['name'] || 'submit' === $control['type'] ) {
				continue;
			}
			$key   = preg_match( '/^' . preg_quote( Outbound_UTM_Settings::OPTION, '/' ) . '\[([a-z_]+)\]$/', $control['name'], $match ) ? $match[1] : null;
			$value = (string) $control['value'];
			$on    = $control['checked'];
			if ( null !== $key && array_key_exists( $key, $changes ) ) {
				if ( 'checkbox' === $control['type'] ) {
					$on = (bool) $changes[ $key ];
				} elseif ( 'radio' === $control['type'] ) {
					$on = ( $changes[ $key ] === $value );
				} else {
					$value = (string) $changes[ $key ];
				}
			}
			if ( ( 'checkbox' === $control['type'] || 'radio' === $control['type'] ) && ! $on ) {
				continue;
			}
			$pairs[] = rawurlencode( $control['name'] ) . '=' . rawurlencode( $value );
		}

		parse_str( implode( '&', $pairs ), $post );
		return $post;
	}

	public function test_settings_form_sends_exactly_the_fields_sanitize_reads() {
		$controls = self::form_controls( $this->render_settings_screen() );

		$prefix = Outbound_UTM_Settings::OPTION . '[';
		$fields = array();
		foreach ( $controls as $control ) {
			if ( null !== $control['name'] && 0 === strpos( $control['name'], $prefix ) ) {
				$fields[] = array( $control['name'], $control['type'], 'text' === $control['type'] || 'textarea' === $control['type'] ? null : $control['value'] );
			}
		}

		$o = Outbound_UTM_Settings::OPTION;
		$this->assertSame(
			array(
				array( "{$o}[enabled]", 'checkbox', '1' ),
				array( "{$o}[mode]", 'radio', 'page' ),
				array( "{$o}[mode]", 'radio', 'click' ),
				array( "{$o}[utm_source]", 'text', null ),
				array( "{$o}[utm_medium]", 'text', null ),
				array( "{$o}[utm_campaign]", 'text', null ),
				array( "{$o}[utm_term]", 'text', null ),
				array( "{$o}[utm_content]", 'text', null ),
				array( "{$o}[tidy]", 'checkbox', '1' ),
				array( "{$o}[skip]", 'textarea', null ),
			),
			$fields
		);

		// The keys the form sends are exactly the keys sanitize() reads (every key it returns is read from its own input key).
		$sent = array();
		foreach ( $fields as $field ) {
			$sent[] = substr( $field[0], strlen( $prefix ), -1 );
		}
		$this->assertEqualsCanonicalizing( array_keys( Outbound_UTM_Settings::sanitize( array() ) ), array_values( array_unique( $sent ) ) );

		// options.php saves only the options allowed for the posted option_page, after checking its nonce.
		$post = self::submit( $controls );
		$this->assertSame( Outbound_UTM_Settings::GROUP, $post['option_page'] );
		$this->assertSame( 'update', $post['action'] );
		$this->assertSame( 1, wp_verify_nonce( $post['_wpnonce'], $post['option_page'] . '-options' ) );

		Outbound_UTM_Settings::register();
		$allowed = option_update_filter( array() );
		$this->assertArrayHasKey( $post['option_page'], $allowed );
		$this->assertContains( Outbound_UTM_Settings::OPTION, $allowed[ $post['option_page'] ] );

		// Besides the settings, the form posts only options.php's own fields.
		$other = array_diff( array_keys( $post ), array( Outbound_UTM_Settings::OPTION, 'option_page', 'action', '_wpnonce', '_wp_http_referer' ) );
		$this->assertSame( array(), array_values( $other ) );
	}

	public function test_saving_the_form_unchanged_keeps_every_setting() {
		$saved = $this->save_settings(
			array(
				'enabled'      => 1,
				'mode'         => 'click',
				'tidy'         => 0,
				'utm_source'   => 'Source "Q3" & Co',
				'utm_medium'   => 'medium-2',
				'utm_campaign' => 'Spring%20Sale',
				'utm_term'     => 'term-4',
				'utm_content'  => '{slug}-{link_text}',
				'skip'         => array( 'partner.com', 'xn--mnchen-3ya.de' ),
			)
		);

		$post = self::submit( self::form_controls( $this->render_settings_screen() ) );

		$this->assertSame( $saved, Outbound_UTM_Settings::sanitize( $post[ Outbound_UTM_Settings::OPTION ] ) );
	}

	public function test_what_the_admin_changes_in_the_form_is_what_gets_saved() {
		// A fresh install: nothing saved, tagging off, page mode, tidy on.
		$controls = self::form_controls( $this->render_settings_screen() );

		$post = self::submit(
			$controls,
			array(
				'enabled'      => true,
				'mode'         => 'click',
				'tidy'         => false,
				'utm_source'   => 'my-site',
				'utm_medium'   => 'partner',
				'utm_campaign' => 'launch',
				'utm_term'     => '',
				'utm_content'  => '{post_type}',
				'skip'         => "www.Partner.com\nMünchen.de",
			)
		);

		Outbound_UTM_Settings::register();
		update_option( Outbound_UTM_Settings::OPTION, $post[ Outbound_UTM_Settings::OPTION ] );

		$this->assertSame(
			array(
				'enabled'      => 1,
				'mode'         => 'click',
				'tidy'         => 0,
				'utm_source'   => 'my-site',
				'utm_medium'   => 'partner',
				'utm_campaign' => 'launch',
				'utm_term'     => '',
				'utm_content'  => '{post_type}',
				'skip'         => array( 'partner.com', function_exists( 'idn_to_ascii' ) ? 'xn--mnchen-3ya.de' : 'münchen.de' ),
			),
			get_option( Outbound_UTM_Settings::OPTION )
		);
	}

	public function test_settings_screen_prints_nothing_for_an_editor() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame( '', $this->render_settings_screen() );
	}

	/**
	 * Adds the settings page the way wp-admin/menu.php would, where the Settings menu's
	 * hook name comes from its (translated) title.
	 *
	 * @param string $settings_menu_hook_name Hook name WordPress gives the Settings menu.
	 * @return string The settings screen's hook suffix.
	 */
	private function add_settings_page( $settings_menu_hook_name = 'settings' ) {
		$GLOBALS['admin_page_hooks']['options-general.php'] = $settings_menu_hook_name;
		Outbound_UTM_Settings::menu();
		return (string) self::get_static( 'Outbound_UTM_Settings', 'hook' );
	}

	public function test_admin_hooks_are_added_only_in_the_admin() {
		// WordPress booted the plugin on the front end.
		$this->assertFalse( is_admin() );
		$this->assertFalse( has_action( 'admin_enqueue_scripts', array( 'Outbound_UTM_Settings', 'enqueue_styles' ) ) );
		$this->assertFalse( has_action( 'admin_menu', array( 'Outbound_UTM_Settings', 'menu' ) ) );

		set_current_screen( 'options-general' );
		$this->assertTrue( is_admin() );
		Outbound_UTM_Settings::init();

		$this->assertSame( 10, has_action( 'admin_enqueue_scripts', array( 'Outbound_UTM_Settings', 'enqueue_styles' ) ) );
		$this->assertSame( 10, has_action( 'admin_menu', array( 'Outbound_UTM_Settings', 'menu' ) ) );
		$this->assertSame( 10, has_action( 'admin_init', array( 'Outbound_UTM_Settings', 'register' ) ) );
	}

	public function test_styles_enqueue_only_on_the_settings_screen() {
		$hook = $this->add_settings_page();
		$this->assertSame( 'settings_page_outbound-utm-tagger', $hook );

		$others = array( 'index.php', 'options-general.php', 'plugins.php', 'post.php', 'settings_page_another-plugin', 'toplevel_page_outbound-utm-tagger', '' );
		foreach ( $others as $other ) {
			Outbound_UTM_Settings::enqueue_styles( $other );
			$this->assertFalse( wp_style_is( self::STYLE, 'enqueued' ), "The stylesheet loaded on '{$other}'." );
		}

		Outbound_UTM_Settings::enqueue_styles( $hook );
		$this->assertTrue( wp_style_is( self::STYLE, 'enqueued' ) );

		$style = wp_styles()->registered[ self::STYLE ];
		$this->assertStringEndsWith( '/assets/outbound-utm-admin.css', $style->src );
		$this->assertSame( Outbound_UTM_Settings::VERSION, $style->ver );
	}

	public function test_styles_follow_the_real_hook_suffix_on_a_translated_admin() {
		// On a German admin the Settings menu is "Einstellungen", so the hook suffix changes with it.
		$hook = $this->add_settings_page( 'einstellungen' );
		$this->assertSame( 'einstellungen_page_outbound-utm-tagger', $hook );

		Outbound_UTM_Settings::enqueue_styles( 'settings_page_outbound-utm-tagger' );
		$this->assertFalse( wp_style_is( self::STYLE, 'enqueued' ) );

		Outbound_UTM_Settings::enqueue_styles( $hook );
		$this->assertTrue( wp_style_is( self::STYLE, 'enqueued' ) );
	}

	public function test_styles_enqueue_through_the_admin_enqueue_scripts_action() {
		set_current_screen( 'options-general' );
		Outbound_UTM_Settings::init();
		$hook = $this->add_settings_page();

		// Only the plugin's callback, so core's own admin assets stay out of this test.
		remove_all_actions( 'admin_enqueue_scripts' );
		add_action( 'admin_enqueue_scripts', array( 'Outbound_UTM_Settings', 'enqueue_styles' ) );

		do_action( 'admin_enqueue_scripts', 'index.php' );
		$this->assertFalse( wp_style_is( self::STYLE, 'enqueued' ) );

		do_action( 'admin_enqueue_scripts', $hook );
		$this->assertTrue( wp_style_is( self::STYLE, 'enqueued' ) );
	}

	public function test_no_settings_page_and_no_styles_without_manage_options() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertSame( '', $this->add_settings_page(), 'Editors must not get the settings page.' );

		// With no page, the empty hook suffix must not match anything, including ''.
		foreach ( array( '', 'settings_page_outbound-utm-tagger', 'index.php' ) as $hook ) {
			Outbound_UTM_Settings::enqueue_styles( $hook );
			$this->assertFalse( wp_style_is( self::STYLE, 'enqueued' ), "The stylesheet loaded on '{$hook}'." );
		}
	}

	public function test_plugins_screen_link_points_at_the_settings_page() {
		$links = Outbound_UTM_Settings::action_links( array( 'deactivate' => '<a href="#">Deactivate</a>' ) );

		$this->assertCount( 2, $links );
		$this->assertStringContainsString( 'options-general.php?page=outbound-utm-tagger', reset( $links ) );
	}

	public function test_cache_notice_shows_only_after_saving_this_screen() {
		$_GET['page'] = 'outbound-utm-tagger';
		$this->assertSame( '', get_echo( array( 'Outbound_UTM_Settings', 'cache_notice' ) ), 'No notice before a save.' );

		$_GET['settings-updated'] = 'true';
		$this->assertStringContainsString( 'Your page cache was not cleared', get_echo( array( 'Outbound_UTM_Settings', 'cache_notice' ) ) );

		$_GET['page'] = 'another-plugin';
		$this->assertSame( '', get_echo( array( 'Outbound_UTM_Settings', 'cache_notice' ) ), 'No notice on another screen.' );
	}
}
