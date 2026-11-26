<?php
/**
 * Tests of Offer.
 *
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * @package WpInaturalist
 */

namespace Evlist\WpInaturalist\Tests;

use Evlist\WpInaturalist\Config;
use Evlist\WpInaturalist\Offer;
use Evlist\WpInaturalist\Scheduler;
use Evlist\WpInaturalist\SyncState;
use Evlist\WpInaturalist\ToolsPage;

require_once __DIR__ . '/TestCase.php';

/**
 * The notice that proposes to load everything.
 */
final class OfferTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		update_option( Config::LOGIN, 'me' );
		$GLOBALS['wpinat_test']['screen'] = (object) array( 'id' => 'dashboard' );
	}

	private function html(): string {
		ob_start();
		Offer::render();
		return ob_get_clean();
	}

	public function test_it_proposes_the_load_with_a_form_posting_to_admin_post_with_its_own_nonce() {
		$html = $this->html();
		$this->assertStringContainsString( 'notice notice-info', $html );
		$this->assertStringContainsString( 'action="https://blog.example.test/wp-admin/admin-post.php"', $html );
		$this->assertStringContainsString( 'name="action" value="wpinat_load_all"', $html );
		$this->assertStringContainsString( 'value="nonce-wpinat_load_all"', $html );
		$this->assertStringContainsString( 'Load all my observations', $html );
	}

	/**
	 * @dataProvider screens
	 * @param string $id       Screen id.
	 * @param bool   $expected Whether the notice shows.
	 */
	public function test_it_only_shows_on_a_few_screens( string $id, bool $expected ) {
		$GLOBALS['wpinat_test']['screen'] = (object) array( 'id' => $id );
		$this->assertSame( $expected, '' !== $this->html() );
	}

	public function screens(): array {
		return array(
			'dashboard'   => array( 'dashboard', true ),
			'plugins'     => array( 'plugins', true ),
			'settings'    => array( 'settings_page_wp-inaturalist', true ),
			'tools'       => array( 'tools_page_wpinat-tools', true ),
			'the editor'  => array( 'post', false ),
			'other tools' => array( 'tools', false ),
			'media'       => array( 'upload', false ),
		);
	}

	public function test_it_does_not_show_without_a_login_or_once_loaded_or_for_users_who_cannot_manage_options() {
		update_option( Config::LOGIN, '' );
		$this->assertSame( '', $this->html() );
		update_option( Config::LOGIN, 'me' );
		SyncState::fullLoadDone( 'me', 1764000000, '2019-02-01' );
		$this->assertSame( '', $this->html() );
		update_option( Config::SYNC, array() );
		$GLOBALS['wpinat_test']['can'] = false;
		$this->assertSame( '', $this->html() );
	}

	public function test_it_shows_again_after_a_change_of_login() {
		SyncState::fullLoadDone( 'me', 1764000000, '2019-02-01' );
		$this->assertSame( '', $this->html() );
		update_option( Config::LOGIN, 'someone-else' );
		$this->assertNotSame( '', $this->html() );
	}

	public function test_it_does_not_show_while_the_initial_load_is_running_but_does_during_another_job() {
		Scheduler::job()->start( '2019-02-01', '2019-03-01', 'initial' );
		$this->assertSame( '', $this->html() );
		update_option( Config::JOB_STATE, array_merge( Scheduler::job()->state(), array( 'trigger' => 'cron' ) ) );
		$this->assertNotSame( '', $this->html() );
	}

	public function test_it_does_nothing_without_a_screen() {
		$GLOBALS['wpinat_test']['screen'] = null;
		$this->assertSame( '', $this->html() );
	}

	public function test_showing_the_notice_never_calls_the_api_or_changes_anything() {
		$this->html();
		$this->assertSame( array(), $GLOBALS['wpinat_test']['scheduled'] );
		$this->assertFalse( get_option( Config::JOB_STATE, false ) );
		$this->assertSame( array(), $GLOBALS['wpdb']->queries );
	}

	public function test_the_action_the_form_posts_is_the_one_the_tools_page_handles() {
		$this->assertSame( 'wpinat_load_all', ToolsPage::ACTION_LOAD );
	}
}
