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

	public function set_up() {
		parent::set_up();

		// Admin-only APIs that a front-end bootstrap does not load.
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
		require_once ABSPATH . 'wp-admin/includes/screen.php';

		$this->saved_admin_page_hooks = isset( $GLOBALS['admin_page_hooks'] ) ? $GLOBALS['admin_page_hooks'] : null;

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down() {
		$GLOBALS['admin_page_hooks'] = $this->saved_admin_page_hooks;
		unset( $_GET['page'], $_GET['settings-updated'] );
		parent::tear_down();
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
