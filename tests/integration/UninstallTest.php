<?php
/**
 * uninstall.php removes the settings: from the one site, or from every site on a network.
 *
 * @package Outbound_UTM_Tagger
 */

class UninstallTest extends Outbound_UTM_TestCase {

	const CANARY = 'outbound_utm_test_canary';

	/**
	 * Runs uninstall.php the way WordPress does when the plugin is deleted.
	 */
	private function run_uninstall() {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', plugin_basename( OUTBOUND_UTM_FILE ) );
		}
		include dirname( __DIR__, 2 ) . '/uninstall.php';
	}

	public function test_uninstall_removes_the_option_on_a_single_site() {
		$this->skipWithMultisite();

		update_option( Outbound_UTM_Settings::OPTION, array( 'enabled' => 1 ) );
		update_option( self::CANARY, 'keep' );
		$this->assertNotFalse( get_option( Outbound_UTM_Settings::OPTION ) );

		$this->run_uninstall();

		$this->assertFalse( get_option( Outbound_UTM_Settings::OPTION ) );
		$this->assertSame( 'keep', get_option( self::CANARY ), 'Only the plugin\'s own option is removed.' );
	}

	public function test_uninstall_removes_the_option_from_every_site_on_a_network() {
		$this->skipWithoutMultisite();

		$main     = get_current_blog_id();
		$site_ids = array_merge( array( $main ), self::factory()->blog->create_many( 3 ) );
		$this->assertCount( 4, array_unique( $site_ids ) );

		foreach ( $site_ids as $site_id ) {
			update_blog_option( $site_id, Outbound_UTM_Settings::OPTION, array( 'enabled' => 1 ) );
			update_blog_option( $site_id, self::CANARY, 'keep' );
			$this->assertNotFalse( get_blog_option( $site_id, Outbound_UTM_Settings::OPTION ) );
		}

		$this->run_uninstall();

		foreach ( $site_ids as $site_id ) {
			$this->assertFalse( get_blog_option( $site_id, Outbound_UTM_Settings::OPTION ), "Site {$site_id} still has the option." );
			$this->assertSame( 'keep', get_blog_option( $site_id, self::CANARY ), "Site {$site_id} lost an unrelated option." );
		}
		$this->assertSame( $main, get_current_blog_id(), 'Uninstall left WordPress switched to another site.' );
		$this->assertFalse( ms_is_switched() );
	}

	public function test_uninstall_pages_through_a_network_larger_than_one_page() {
		$this->skipWithoutMultisite();

		// uninstall.php asks get_sites() for 100 sites at a time. The main site plus 101 more is 102: two pages.
		$site_ids = array_merge( array( get_current_blog_id() ), self::factory()->blog->create_many( 101 ) );
		$this->assertCount( 102, array_unique( $site_ids ) );

		foreach ( $site_ids as $site_id ) {
			update_blog_option( $site_id, Outbound_UTM_Settings::OPTION, array( 'enabled' => 1 ) );
		}

		$this->run_uninstall();

		$left = array();
		foreach ( $site_ids as $site_id ) {
			if ( false !== get_blog_option( $site_id, Outbound_UTM_Settings::OPTION ) ) {
				$left[] = $site_id;
			}
		}
		$this->assertSame( array(), $left, 'These sites still have the option after uninstall.' );
	}
}
