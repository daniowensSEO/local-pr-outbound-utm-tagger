<?php
/**
 * What a save does after WordPress stores the settings (Outbound_UTM_Settings::after_save()):
 * the plugin's settings cache is cleared, and SiteGround's page cache is purged when Speed
 * Optimizer is active.
 *
 * @package Outbound_UTM_Tagger
 */

class SettingsSaveTest extends Outbound_UTM_TestCase {

	public function test_first_save_clears_the_settings_cache() {
		// Nothing is saved yet, so get() caches the defaults.
		$this->assertFalse( get_option( Outbound_UTM_Settings::OPTION ) );
		$this->assertSame( 0, Outbound_UTM_Settings::get()['enabled'] );
		$this->assertNotNull( self::get_static( 'Outbound_UTM_Settings', 'cache' ) );

		// The very first save goes through add_option().
		update_option(
			Outbound_UTM_Settings::OPTION,
			array_merge(
				Outbound_UTM_Settings::defaults(),
				array(
					'enabled'    => 1,
					'utm_medium' => 'first-save',
				)
			)
		);

		$settings = Outbound_UTM_Settings::get();
		$this->assertSame( 1, $settings['enabled'], 'The settings cache still held the defaults after the first save.' );
		$this->assertSame( 'first-save', $settings['utm_medium'] );
	}

	public function test_later_saves_clear_the_settings_cache() {
		$this->save_settings( array( 'utm_medium' => 'before' ) );
		$this->assertSame( 'before', Outbound_UTM_Settings::get()['utm_medium'] );

		// A later save goes through update_option(). No reset_plugin_state() here: the save must clear the cache itself.
		update_option( Outbound_UTM_Settings::OPTION, array_merge( get_option( Outbound_UTM_Settings::OPTION ), array( 'utm_medium' => 'after' ) ) );

		$this->assertSame( 'after', Outbound_UTM_Settings::get()['utm_medium'], 'The settings cache still held the old value.' );
	}

	/**
	 * Runs in its own PHP process because it defines sg_cachepress_purge_cache(), which cannot
	 * be undefined afterwards.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_every_save_purges_the_siteground_cache() {
		$this->assertFalse( function_exists( 'sg_cachepress_purge_cache' ), 'Speed Optimizer should not be loaded in the test site.' );
		require dirname( __DIR__ ) . '/includes/siteground-stub.php';
		$GLOBALS['outbound_utm_test_purges'] = array();

		// The very first save goes through add_option().
		update_option( Outbound_UTM_Settings::OPTION, array( 'enabled' => 1 ) );
		$this->assertSame( array( false ), $GLOBALS['outbound_utm_test_purges'], 'The first save did not purge the cache exactly once.' );

		// A later save goes through update_option().
		update_option( Outbound_UTM_Settings::OPTION, array( 'enabled' => 0 ) );
		$this->assertCount( 2, $GLOBALS['outbound_utm_test_purges'], 'A later save did not purge the cache exactly once.' );

		// Saving the same values stores nothing, so there is nothing to purge.
		update_option( Outbound_UTM_Settings::OPTION, array( 'enabled' => 0 ) );
		$this->assertCount( 2, $GLOBALS['outbound_utm_test_purges'] );

		// With Speed Optimizer active, the screen does not tell the admin to clear the cache by hand.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_GET['page']             = Outbound_UTM_Settings::PAGE;
		$_GET['settings-updated'] = 'true';
		$this->assertSame( '', get_echo( array( 'Outbound_UTM_Settings', 'cache_notice' ) ) );
		unset( $_GET['page'], $_GET['settings-updated'] );
	}
}
