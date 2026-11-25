<?php
/**
 * Tests of the Tools page and its actions.
 *
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * @package WpInaturalist
 */

namespace Evlist\WpInaturalist\Tests;

use Evlist\WpInaturalist\Cache;
use Evlist\WpInaturalist\Config;
use Evlist\WpInaturalist\Job;
use Evlist\WpInaturalist\JobLog;
use Evlist\WpInaturalist\Scheduler;
use Evlist\WpInaturalist\ToolsPage;

require_once __DIR__ . '/TestCase.php';

/**
 * Every action checks the capability and the nonce before doing anything.
 *
 * The real admin-post.php routing and the redirect with exit were never tried in WordPress.
 */
final class ToolsPageTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		update_option( Config::LOGIN, 'me' );
		unset( $_GET['wpinat_notice'] );
	}

	/**
	 * Every action with a way to call it and to see whether it did anything.
	 *
	 * @return array<string,array>
	 */
	public function actions(): array {
		return array(
			'start'  => array(
				ToolsPage::ACTION_START,
				static function () {
					return ToolsPage::handleStart(
						array(
							'from' => '2026-11-01',
							'to'   => '2026-11-05',
						)
					);
				},
			),
			'stop'   => array(
				ToolsPage::ACTION_STOP,
				static function () {
						return ToolsPage::handleStop();
				},
			),
			'resume' => array(
				ToolsPage::ACTION_RESUME,
				static function () {
						return ToolsPage::handleResume();
				},
			),
			'clear'  => array(
				ToolsPage::ACTION_CLEAR,
				static function () {
						return ToolsPage::handleClear();
				},
			),
		);
	}

	/**
	 * @dataProvider actions
	 * @param string   $action Action name.
	 * @param callable $call   Calls the handler.
	 */
	public function test_a_user_without_the_capability_gets_403_and_nothing_happens( string $action, callable $call ) {
		$GLOBALS['wpinat_test']['can'] = false;
		$GLOBALS['wpdb']->rows         = array( 1 => array() );
		try {
			$call();
			$this->fail( 'expected wp_die' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'wp_die', $e->getMessage() );
		}
		$this->assertSame( 403, $GLOBALS['wpinat_test']['dies'][0][1]['response'] );
		$this->assertSame( array(), $GLOBALS['wpinat_test']['nonces'], 'the capability is checked first' );
		$this->assertSame( array(), $GLOBALS['wpinat_test']['scheduled'] );
		$this->assertCount( 1, $GLOBALS['wpdb']->rows );
		$this->assertFalse( get_option( Config::JOB_STATE, false ) );
	}

	/**
	 * @dataProvider actions
	 * @param string   $action Action name.
	 * @param callable $call   Calls the handler.
	 */
	public function test_a_wrong_nonce_stops_everything( string $action, callable $call ) {
		$GLOBALS['wpinat_test']['nonce_ok'] = false;
		$GLOBALS['wpdb']->rows              = array( 1 => array() );
		try {
			$call();
			$this->fail( 'expected wp_die' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'wp_die', $e->getMessage() );
		}
		$this->assertSame( array( $action ), $GLOBALS['wpinat_test']['nonces'], 'the nonce is bound to the action' );
		$this->assertSame( array(), $GLOBALS['wpinat_test']['scheduled'] );
		$this->assertCount( 1, $GLOBALS['wpdb']->rows );
		$this->assertFalse( get_option( Config::JOB_STATE, false ) );
	}

	public function test_start_with_the_submitted_days() {
		$this->assertSame(
			'started',
			ToolsPage::handleStart(
				array(
					'from' => '2026-11-01',
					'to'   => '2026-11-05',
				)
			)
		);
		$this->assertSame( 'manual', Scheduler::job()->state()['trigger'] );
		$this->assertSame( array( array( '2026-11-01', '2026-11-05' ) ), Scheduler::job()->state()['ranges'] );
	}

	public function test_start_with_garbage_dates() {
		$this->assertSame(
			'bad_range',
			ToolsPage::handleStart(
				array(
					'from' => '<b>x',
					'to'   => array( 'a' ),
				)
			)
		);
		$this->assertSame( 'bad_range', ToolsPage::handleStart( array() ) );
		$this->assertFalse( get_option( Config::JOB_STATE, false ) );
	}

	public function test_stop_resume_and_clear_report_what_happened() {
		$this->assertSame( 'not_running', ToolsPage::handleStop() );
		$this->assertSame( 'not_resumable', ToolsPage::handleResume() );
		ToolsPage::handleStart(
			array(
				'from' => '2026-11-01',
				'to'   => '2026-11-05',
			)
		);
		$this->assertSame( 'stopped', ToolsPage::handleStop() );
		$this->assertSame( 'resumed', ToolsPage::handleResume() );

		$GLOBALS['wpdb']->rows = array( 1 => array() );
		$this->assertSame( 'cleared', ToolsPage::handleClear() );
		$this->assertSame( array(), $GLOBALS['wpdb']->rows );
		$this->assertContains( 'The cache was emptied.', array_column( ( new JobLog() )->entries(), 'message' ) );
	}

	public function test_every_code_the_handlers_return_has_a_notice() {
		foreach ( array( 'started', 'busy', 'no_login', 'bad_range', 'stopped', 'not_running', 'resumed', 'not_resumable', 'cleared' ) as $code ) {
			$this->assertArrayHasKey( $code, ToolsPage::notices() );
		}
	}

	public function test_the_menu_entry_requires_manage_options() {
		ToolsPage::addMenu();
		$this->assertSame( array( 'manage_options', 'wpinat-tools', array( ToolsPage::class, 'render' ) ), $GLOBALS['wpinat_test']['menu'] );
	}

	public function test_render_refuses_a_user_without_the_capability() {
		$GLOBALS['wpinat_test']['can'] = false;
		ob_start();
		try {
			ToolsPage::render();
			$this->fail( 'expected wp_die' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( '', ob_get_clean() );
		}
	}

	public function test_render_shows_status_forms_with_nonces_and_the_escaped_log() {
		$job = Scheduler::job();
		$job->start( '2026-11-01', '2026-11-05', 'manual' );
		( new JobLog() )->add( 'error', '<script>alert(1)</script>' );
		update_option( Config::JOB_STATE, array_merge( $job->state(), array( 'error' => '"><img src=x>' ) ) );
		$_GET['wpinat_notice'] = 'busy';

		ob_start();
		ToolsPage::render();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'running', $html );
		$this->assertStringContainsString( 'A refresh is already running.', $html );
		$this->assertStringContainsString( 'name="action" value="wpinat_job_start"', $html );
		foreach ( array( 'wpinat_job_start', 'wpinat_job_stop', 'wpinat_job_resume', 'wpinat_cache_clear' ) as $action ) {
			$this->assertStringContainsString( 'value="nonce-' . $action . '"', $html );
		}
		$this->assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt;', $html );
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringNotContainsString( '<img', $html );
		$this->assertStringContainsString( 'Resume disabled', $html, 'nothing to resume while running' );
		$this->assertStringNotContainsString( 'Stop disabled', $html );
	}

	public function test_an_unknown_notice_code_shows_nothing() {
		$_GET['wpinat_notice'] = '<script>x';
		ob_start();
		ToolsPage::render();
		$html = ob_get_clean();
		$this->assertStringNotContainsString( 'notice-', $html );
		$this->assertStringNotContainsString( 'script', $html );
	}

	/**
	 * The page outputs, with cached observations of the account.
	 *
	 * @param array $times Times of the observations by id.
	 */
	private function renderWithObservations( array $times ): string {
		$list = array();
		foreach ( $times as $id => $time ) {
			$o                 = $this->observation( $id, '2026-11-20' );
			$o->timeObservedAt = $time;
			$list[]            = $o;
		}
		( new Cache( $GLOBALS['wpdb'] ) )->replaceRange( 'me', '2026-11-01', '2026-11-30', $list, 'fr', 1 );
		ob_start();
		ToolsPage::render();
		return ob_get_clean();
	}

	public function test_the_photo_report_says_why_each_observation_is_or_is_not_linked() {
		$GLOBALS['wpdb']->scripted_vars             = array( null, '300', null, null ); // Manual link only for the 2nd call.
		$GLOBALS['wpdb']->scripted_results          = array(
			array(
				(object) array(
					'post_id'    => 100,
					'meta_value' => serialize( array( 'image_meta' => array( 'created_timestamp' => (string) gmmktime( 9, 0, 0, 11, 20, 2026 ) ) ) ),
				),
			),
			array(),
		);
		$GLOBALS['wpinat_test']['attachments'][100] = 'https://blog.example.test/a.jpg';
		$GLOBALS['wpinat_test']['attachments'][300] = 'https://blog.example.test/b.jpg';
		// Newest first: 3 (no time), 2 (manual), 1 (auto)... the order of the answers above follows it.
		$html = $this->renderWithObservations(
			array(
				1 => '2026-11-20T09:00:00+01:00',
				2 => '2026-11-20T10:00:00+01:00',
				3 => null,
			)
		);

		$this->assertStringContainsString( 'Photos of the media library', $html );
		$this->assertStringContainsString( 'No time of day in the observation', $html );
		$this->assertStringContainsString( 'Linked by hand', $html );
		$this->assertStringContainsString( 'of the 3 latest observations are linked', $html );
		$this->assertStringContainsString( 'post.php?post=300&amp;action=edit', $html, 'the linked photo links to its edit screen' );
	}

	public function test_the_photo_report_says_when_the_option_is_off() {
		update_option( Config::USE_MEDIA, 0 );
		ob_start();
		ToolsPage::render();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'This option is off in the settings', $html );
	}

	public function test_the_photo_report_says_when_there_is_nothing_cached() {
		ob_start();
		ToolsPage::render();
		$this->assertStringContainsString( 'No cached observation with a photo yet.', ob_get_clean() );
	}

	public function test_the_photo_report_never_calls_the_api_or_changes_anything() {
		$GLOBALS['wpdb']->scripted_vars    = array( null );
		$GLOBALS['wpdb']->scripted_results = array( array() );
		$this->renderWithObservations( array( 1 => '2026-11-20T09:00:00+01:00' ) );
		$this->assertSame( array(), $GLOBALS['wpinat_test']['scheduled'] );
		$this->assertArrayNotHasKey( 'attachment_id', $GLOBALS['wpdb']->rows[1], 'the report does not write links' );
	}
}
