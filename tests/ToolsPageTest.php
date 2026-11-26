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
use Evlist\WpInaturalist\INatException;
use Evlist\WpInaturalist\SyncState;
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
			'load'   => array(
				ToolsPage::ACTION_LOAD,
				static function () {
					return ToolsPage::handleLoadAll(
						new class() {
							public function summary() {
								throw new \LogicException( 'the API must not be asked' );
							}
						}
					);
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

	/**
	 * A fake client with a scripted summary.
	 *
	 * @param array|\Throwable $answer Summary or exception.
	 */
	private function summaryClient( $answer ) {
		return new class( $answer ) {
			public $asked = array();
			private $answer;
			public function __construct( $answer ) {
				$this->answer = $answer;
			}
			public function summary( $login, $quality ) {
				$this->asked[] = array( $login, $quality );
				if ( $this->answer instanceof \Throwable ) {
					throw $this->answer;
				}
				return $this->answer;
			}
		};
	}

	public function test_load_all_checks_the_account_then_starts_from_the_first_observation_to_today() {
		$client = $this->summaryClient(
			array(
				'total' => 861,
				'first' => '2019-02-01',
			)
		);
		$this->assertSame( 'started', ToolsPage::handleLoadAll( $client ) );
		$this->assertSame( array( array( 'me', 'research' ) ), $client->asked, 'with the same quality filter as the load' );
		$state = Scheduler::job()->state();
		$this->assertSame( 'initial', $state['trigger'] );
		$this->assertSame( '2019-02-01', $state['ranges'][0][0] );
		$this->assertSame( gmdate( 'Y-m-d' ), end( $state['ranges'] )[1] );
		$this->assertSame( 861, SyncState::get()['probe']['total'] );
		$this->assertContains( 'Loading all observations: 861 found on iNaturalist, from 2019-02-01.', array_column( ( new JobLog() )->entries(), 'message' ) );
	}

	public function test_load_all_without_a_login_does_not_ask_the_api() {
		update_option( Config::LOGIN, '' );
		$client = $this->summaryClient( new \LogicException( 'must not be called' ) );
		$this->assertSame( 'no_login', ToolsPage::handleLoadAll( $client ) );
		$this->assertSame( array(), $client->asked );
	}

	public function test_an_account_without_observations_closes_the_offer_and_says_so() {
		$this->assertSame(
			'nothing_found',
			ToolsPage::handleLoadAll(
				$this->summaryClient(
					array(
						'total' => 0,
						'first' => null,
					)
				)
			)
		);
		$this->assertFalse( SyncState::loadNeeded(), 'the offer is closed' );
		$this->assertFalse( get_option( Config::JOB_STATE, false ), 'no job' );
	}

	public function test_a_huge_account_starts_nothing_and_shows_the_numbers() {
		$this->assertSame(
			'too_many',
			ToolsPage::handleLoadAll(
				$this->summaryClient(
					array(
						'total' => Config::MAX_AUTO_LOAD + 1,
						'first' => '2010-01-01',
					)
				)
			)
		);
		$this->assertFalse( get_option( Config::JOB_STATE, false ) );
		$this->assertSame( Config::MAX_AUTO_LOAD + 1, SyncState::get()['probe']['total'] );
		$this->assertTrue( SyncState::loadNeeded(), 'the offer stays: nothing was loaded' );
		$this->assertSame(
			'started',
			ToolsPage::handleLoadAll(
				$this->summaryClient(
					array(
						'total' => Config::MAX_AUTO_LOAD,
						'first' => '2010-01-01',
					)
				)
			),
			'the limit itself is allowed'
		);
	}

	public function test_an_unavailable_service_changes_nothing() {
		$client = $this->summaryClient( new INatException( INatException::SERVER, 'down' ) );
		$this->assertSame( 'unavailable', ToolsPage::handleLoadAll( $client ) );
		$this->assertFalse( get_option( Config::JOB_STATE, false ) );
		$this->assertSame( array(), SyncState::get()['probe'], 'nothing recorded' );
	}

	public function test_a_new_login_first_removes_the_observations_of_the_other_accounts() {
		( new Cache( $GLOBALS['wpdb'] ) )->replaceRange( 'old', '2026-11-01', '2026-11-30', array( $this->observation( 1 ) ), 'fr', 1 );
		( new Cache( $GLOBALS['wpdb'] ) )->replaceRange( 'me', '2026-11-01', '2026-11-30', array( $this->observation( 2 ) ), 'fr', 1 );
		SyncState::fullLoadDone( 'old', 1764000000, '2019-02-01' );
		$this->assertSame(
			'started',
			ToolsPage::handleLoadAll(
				$this->summaryClient(
					array(
						'total' => 5,
						'first' => '2026-11-01',
					)
				)
			)
		);
		$this->assertSame( array( 2 ), array_keys( $GLOBALS['wpdb']->rows ) );
	}

	public function test_loading_again_for_the_same_login_removes_nothing() {
		( new Cache( $GLOBALS['wpdb'] ) )->replaceRange( 'other', '2026-11-01', '2026-11-30', array( $this->observation( 1 ) ), 'fr', 1 );
		SyncState::fullLoadDone( 'me', 1764000000, '2019-02-01' );
		ToolsPage::handleLoadAll(
			$this->summaryClient(
				array(
					'total' => 5,
					'first' => '2026-11-01',
				)
			)
		);
		$this->assertSame( array( 1 ), array_keys( $GLOBALS['wpdb']->rows ) );
	}

	public function test_while_a_job_runs_the_initial_load_is_queued() {
		Scheduler::job()->start( '2026-11-01', '2026-11-05', 'manual' );
		$this->assertSame(
			'queued',
			ToolsPage::handleLoadAll(
				$this->summaryClient(
					array(
						'total' => 5,
						'first' => '2026-11-01',
					)
				)
			)
		);
		$this->assertSame( 'initial', Scheduler::job()->queue()[0][2] );
	}

	public function test_every_code_of_the_load_has_a_notice() {
		foreach ( array( 'loading_all', 'nothing_found', 'too_many', 'unavailable', 'queued' ) as $code ) {
			$this->assertArrayHasKey( $code, ToolsPage::notices() );
		}
	}

	public function test_the_page_shows_the_account_check_and_the_dates_of_the_loads_and_the_button() {
		SyncState::recordProbe( 'me', 861, '2019-02-01', 1764000000 );
		SyncState::fullLoadDone( 'me', 1764003600, '2019-02-01' );
		SyncState::syncDone( 1764090000 );
		ob_start();
		ToolsPage::render();
		$html = ob_get_clean();
		$this->assertStringContainsString( '861 observations, from 2019-02-01 (checked 2025-11-24 16:00:00)', $html );
		$this->assertStringContainsString( '2025-11-24 17:00:00', $html, 'the full load' );
		$this->assertStringContainsString( '2025-11-25 17:00:00', $html, 'the last synchronisation' );
		$this->assertStringContainsString( 'name="action" value="wpinat_load_all"', $html );
		$this->assertStringContainsString( 'value="nonce-wpinat_load_all"', $html );
	}

	public function test_the_page_says_when_nothing_was_done_yet() {
		ob_start();
		ToolsPage::render();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'not done yet', $html );
		$this->assertStringContainsString( 'not done for this login', $html );
		$this->assertStringContainsString( 'never', $html );
	}

	public function test_a_hostile_probe_in_the_option_is_escaped() {
		update_option(
			Config::SYNC,
			array(
				'probe' => array(
					'total' => '5<script>',
					'first' => '"><img src=x>',
					'at'    => 0,
				),
			)
		);
		ob_start();
		ToolsPage::render();
		$html = ob_get_clean();
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringNotContainsString( '<img', $html );
	}
}
