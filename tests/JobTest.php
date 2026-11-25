<?php
/**
 * Tests of Job.
 *
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * @package WpInaturalist
 */

namespace Evlist\WpInaturalist\Tests;

use Evlist\WpInaturalist\Cache;
use Evlist\WpInaturalist\Config;
use Evlist\WpInaturalist\INatException;
use Evlist\WpInaturalist\Job;
use Evlist\WpInaturalist\JobLog;

require_once __DIR__ . '/TestCase.php';

/**
 * The refresh job with a fake API client and an in-memory cache.
 */
final class JobTest extends TestCase {

	/**
	 * Fake clock.
	 *
	 * @var int
	 */
	private $time = 1700000000;

	/**
	 * Calls received by the fake client: arrays of login, from, to, options.
	 *
	 * @var array
	 */
	private $calls = array();

	/**
	 * What the fake client does for each call: a list of observations, an exception, or a callable.
	 *
	 * @var array
	 */
	private $script = array();

	protected function setUp(): void {
		parent::setUp();
		$this->time  = 1700000000;
		$this->calls = array();
		update_option( Config::LOGIN, 'me' );
		update_option( Config::NAMES_LOCALE, 'fr' );
	}

	private function job(): Job {
		$test   = $this;
		$client = new class( $test ) {
			private $test;
			public function __construct( $test ) {
				$this->test = $test;
			}
			public function fetchObservations( $login, $from, $to, $options ) {
				return $this->test->answer( $login, $from, $to, $options );
			}
		};
		return new Job(
			new Cache( $GLOBALS['wpdb'] ),
			static function () use ( $client ) {
				return $client;
			},
			new JobLog(
				function () {
					return $this->time;
				}
			),
			function () {
				return $this->time;
			}
		);
	}

	/**
	 * Called by the fake client (public only for that reason).
	 *
	 * @param string             $login   Login.
	 * @param \DateTimeInterface $from    From.
	 * @param \DateTimeInterface $to      To.
	 * @param array              $options Options.
	 * @throws \Throwable What the script says the client throws.
	 */
	public function answer( $login, $from, $to, $options ) {
		$this->calls[] = array( $login, $from->format( 'Y-m-d' ), $to->format( 'Y-m-d' ), $options );
		$next          = array_shift( $this->script );
		if ( $next instanceof \Throwable ) {
			throw $next;
		}
		if ( is_callable( $next ) ) {
			return $next();
		}
		return null === $next ? array() : $next;
	}

	private function logMessages(): array {
		return array_column( ( new JobLog() )->entries(), 'message' );
	}

	private function scheduledSteps(): array {
		return array_values(
			array_filter(
				$GLOBALS['wpinat_test']['scheduled'],
				static function ( $e ) {
					return Config::STEP_HOOK === $e['hook'];
				}
			)
		);
	}

	/**
	 * @dataProvider chunkCases
	 * @param string $from     First day.
	 * @param string $to       Last day.
	 * @param array  $expected Chunks.
	 */
	public function test_chunks( string $from, string $to, array $expected ) {
		$this->assertSame( $expected, Job::chunks( $from, $to ) );
	}

	public function chunkCases(): array {
		return array(
			'one day'       => array( '2026-11-05', '2026-11-05', array( array( '2026-11-05', '2026-11-05' ) ) ),
			'exactly 31'    => array( '2026-10-01', '2026-10-31', array( array( '2026-10-01', '2026-10-31' ) ) ),
			'32 days'       => array( '2026-10-01', '2026-11-01', array( array( '2026-10-01', '2026-10-31' ), array( '2026-11-01', '2026-11-01' ) ) ),
			'across a year' => array( '2025-12-20', '2026-02-10', array( array( '2025-12-20', '2026-01-19' ), array( '2026-01-20', '2026-02-10' ) ) ),
		);
	}

	/**
	 * @dataProvider badRanges
	 * @param string $from First day.
	 * @param string $to   Last day.
	 */
	public function test_bad_ranges_are_refused( string $from, string $to ) {
		$this->expectException( \InvalidArgumentException::class );
		Job::chunks( $from, $to );
	}

	public function badRanges(): array {
		return array(
			'reversed'     => array( '2026-11-05', '2026-11-04' ),
			'not a date'   => array( '2026-02-30', '2026-03-01' ),
			'wrong format' => array( '05/11/2026', '2026-11-06' ),
			'empty'        => array( '', '' ),
			'too long'     => array( '2000-01-01', '2026-01-01' ),
		);
	}

	public function test_start_without_login_does_nothing_but_log() {
		update_option( Config::LOGIN, '' );
		$this->assertSame( 'no_login', $this->job()->start( '2026-11-01', '2026-11-05', 'manual' ) );
		$this->assertSame( Job::IDLE, $this->job()->state()['status'] );
		$this->assertSame( array(), $this->scheduledSteps() );
		$this->assertContains( 'No iNaturalist login is configured: nothing to refresh.', $this->logMessages() );
	}

	public function test_start_with_a_bad_range_does_nothing() {
		$this->assertSame( 'bad_range', $this->job()->start( '2026-11-05', '2026-11-01', 'manual' ) );
		$this->assertSame( Job::IDLE, $this->job()->state()['status'] );
	}

	public function test_start_records_the_state_and_schedules_a_step() {
		$this->assertSame( 'started', $this->job()->start( '2026-10-01', '2026-11-05', 'manual' ) );
		$state = $this->job()->state();
		$this->assertSame( Job::RUNNING, $state['status'] );
		$this->assertSame( 'manual', $state['trigger'] );
		$this->assertCount( 2, $state['ranges'] );
		$this->assertSame( 0, $state['next'] );
		$this->assertCount( 1, $this->scheduledSteps() );
		$this->assertFalse( $GLOBALS['wpinat_test']['autoload'][ Config::JOB_STATE ], 'the state is not autoloaded' );
	}

	public function test_a_second_start_while_running_is_refused() {
		$this->job()->start( '2026-11-01', '2026-11-05', 'manual' );
		$this->assertSame( 'busy', $this->job()->start( '2026-11-01', '2026-11-05', 'cron' ) );
		$this->assertCount( 1, $this->scheduledSteps() );
	}

	public function test_the_rolling_start_covers_the_last_days_in_site_time() {
		$this->time = strtotime( '2026-11-25 23:30:00 UTC' ); // 00:30 on the 26th in Paris.
		$this->job()->startRolling();
		$ranges = $this->job()->state()['ranges'];
		$this->assertSame( '2026-11-13', $ranges[0][0] );
		$this->assertSame( '2026-11-26', $ranges[ count( $ranges ) - 1 ][1] );
		$this->assertSame( 'cron', $this->job()->state()['trigger'] );
	}

	public function test_a_job_runs_to_the_end_and_fills_the_cache() {
		$this->job()->start( '2026-10-01', '2026-11-05', 'manual' );
		$this->script = array( array( $this->observation( 1, '2026-10-10' ) ), array( $this->observation( 2, '2026-11-02' ), $this->observation( 3, '2026-11-03' ) ) );
		$this->job()->step();

		$state = $this->job()->state();
		$this->assertSame( Job::DONE, $state['status'] );
		$this->assertSame( 2, $state['next'] );
		$this->assertSame( 3, $state['fetched'] );
		$this->assertSame( array( 1, 2, 3 ), array_keys( $GLOBALS['wpdb']->rows ) );
		$this->assertSame( array( 'me', '2026-10-01', '2026-10-31' ), array_slice( $this->calls[0], 0, 3 ) );
		$this->assertSame( array( 'me', '2026-11-01', '2026-11-05' ), array_slice( $this->calls[1], 0, 3 ) );
		$this->assertSame(
			array(
				'locale'  => 'fr',
				'quality' => 'research',
			),
			$this->calls[0][3]
		);
		$this->assertContains( 'Refresh done: 3 observation(s) fetched, 3 in the cache.', $this->logMessages() );
		$this->assertFalse( get_option( Config::JOB_LOCK, false ), 'the lock is released' );
	}

	public function test_the_quality_setting_reaches_the_client() {
		update_option( Config::QUALITY, 'any' );
		$this->job()->start( '2026-11-01', '2026-11-05', 'manual' );
		$this->job()->step();
		$this->assertSame( 'any', $this->calls[0][3]['quality'] );
	}

	public function test_a_later_refresh_removes_what_is_gone_from_iNaturalist() {
		$this->job()->start( '2026-11-01', '2026-11-05', 'manual' );
		$this->script = array( array( $this->observation( 1, '2026-11-02' ), $this->observation( 2, '2026-11-03' ) ) );
		$this->job()->step();
		$this->job()->start( '2026-11-01', '2026-11-05', 'manual' );
		$this->script = array( array( $this->observation( 2, '2026-11-03' ) ) );
		$this->job()->step();
		$this->assertSame( array( 2 ), array_keys( $GLOBALS['wpdb']->rows ) );
	}

	public function test_when_the_time_budget_is_spent_the_next_step_is_scheduled() {
		$this->job()->start( '2026-09-01', '2026-11-05', 'manual' ); // 3 chunks.
		$GLOBALS['wpinat_test']['scheduled'] = array();
		$slow                                = function () {
			$this->time += Job::BUDGET_SECONDS + 5;
			return array();
		};
		$this->script                        = array( $slow, $slow, $slow );

		$this->job()->step();
		$state = $this->job()->state();
		$this->assertSame( Job::RUNNING, $state['status'] );
		$this->assertSame( 1, $state['next'], 'one chunk per step when each is slow' );
		$this->assertCount( 1, $this->calls );
		$steps = $this->scheduledSteps();
		$this->assertCount( 1, $steps );
		$this->assertSame( $this->time + 1, $steps[0]['time'] );

		$this->job()->step();
		$this->job()->step();
		$this->assertSame( Job::DONE, $this->job()->state()['status'] );
	}

	public function test_a_retryable_error_is_retried_later_with_a_growing_delay() {
		$this->job()->start( '2026-11-01', '2026-11-05', 'manual' );
		$GLOBALS['wpinat_test']['scheduled'] = array();
		$this->script                        = array( new INatException( INatException::NETWORK, 'timeout' ) );
		$this->job()->step();

		$state = $this->job()->state();
		$this->assertSame( Job::RUNNING, $state['status'] );
		$this->assertSame( 1, $state['attempt'] );
		$this->assertSame( 0, $state['next'] );
		$this->assertSame( $this->time + 120, $this->scheduledSteps()[0]['time'] );
		$this->assertStringContainsString( 'attempt 1 of 5', implode( "\n", $this->logMessages() ) );
	}

	public function test_the_delay_asked_by_the_service_is_obeyed() {
		$this->job()->start( '2026-11-01', '2026-11-05', 'manual' );
		$GLOBALS['wpinat_test']['scheduled'] = array();
		$this->script                        = array( new INatException( INatException::RATE_LIMITED, 'Too many requests.', 30 ) );
		$this->job()->step();
		$this->assertSame( $this->time + 30, $this->scheduledSteps()[0]['time'] );
	}

	public function test_after_five_attempts_the_job_fails_and_can_be_resumed() {
		$this->job()->start( '2026-11-01', '2026-11-05', 'manual' );
		for ( $i = 0; $i < 5; $i++ ) {
			$this->script[] = new INatException( INatException::SERVER, 'down' );
		}
		for ( $i = 0; $i < 5; $i++ ) {
			$this->job()->step();
		}
		$state = $this->job()->state();
		$this->assertSame( Job::FAILED, $state['status'] );
		$this->assertStringContainsString( '5 attempts', $state['error'] );

		$this->assertSame( 'resumed', $this->job()->resume() );
		$this->assertSame( 0, $this->job()->state()['attempt'] );
		$this->job()->step();
		$this->assertSame( Job::DONE, $this->job()->state()['status'] );
	}

	public function test_a_permanent_error_fails_at_once() {
		$this->job()->start( '2026-11-01', '2026-11-05', 'manual' );
		$this->script = array( new INatException( INatException::HTTP, 'Unexpected answer 422.' ) );
		$this->job()->step();
		$state = $this->job()->state();
		$this->assertSame( Job::FAILED, $state['status'] );
		$this->assertSame( 'Unexpected answer 422. (http)', $state['error'] );
		$this->assertSame( array(), array_slice( $this->scheduledSteps(), 1 ), 'no retry is scheduled' );
	}

	public function test_a_database_error_fails_the_job_and_keeps_the_position() {
		$this->job()->start( '2026-10-01', '2026-11-05', 'manual' );
		$GLOBALS['wpdb']->fail_replace = true;
		$this->script                  = array( array( $this->observation( 1, '2026-10-10' ) ) );
		$this->job()->step();
		$state = $this->job()->state();
		$this->assertSame( Job::FAILED, $state['status'] );
		$this->assertSame( 0, $state['next'] );
		$this->assertSame( 'The database refused to store an observation.', $state['error'] );
	}

	public function test_stop_prevents_further_work_and_removes_the_scheduled_step() {
		$this->job()->start( '2026-11-01', '2026-11-05', 'manual' );
		$this->assertSame( 'stopped', $this->job()->stop() );
		$this->assertSame( array(), $this->scheduledSteps() );
		$this->job()->step();
		$this->assertSame( array(), $this->calls );
		$this->assertSame( Job::STOPPED, $this->job()->state()['status'] );
	}

	public function test_stop_when_nothing_runs_is_reported() {
		$this->assertSame( 'not_running', $this->job()->stop() );
	}

	public function test_resume_goes_on_from_the_chunk_where_it_stopped() {
		$this->job()->start( '2026-10-01', '2026-11-05', 'manual' );
		$this->script = array( array( $this->observation( 1, '2026-10-10' ) ) );
		$job          = $this->job();
		$slow         = function () use ( $job ) {
			$this->time += Job::BUDGET_SECONDS + 5;
			return array( $this->observation( 1, '2026-10-10' ) );
		};
		$this->script = array( $slow );
		$job->step(); // First chunk done, budget spent.
		$job->stop();
		$this->assertSame( 'resumed', $job->resume() );
		$this->script = array( array() );
		$job->step();
		$this->assertSame( array( '2026-11-01', '2026-11-05' ), array_slice( $this->calls[1], 1, 2 ) );
		$this->assertSame( Job::DONE, $job->state()['status'] );
	}

	public function test_a_stop_during_a_fetch_wins_over_the_progress() {
		$this->job()->start( '2026-10-01', '2026-11-05', 'manual' );
		$job          = $this->job();
		$this->script = array(
			function () use ( $job ) {
				$job->stop();
				return array();
			},
		);
		$job->step();
		$state = $job->state();
		$this->assertSame( Job::STOPPED, $state['status'] );
		$this->assertSame( 0, $state['next'], 'the chunk in progress is not counted: it is fetched again on resume' );
		$this->assertCount( 1, $this->calls );
	}

	public function test_resume_refuses_when_there_is_nothing_to_resume() {
		$this->assertSame( 'not_resumable', $this->job()->resume() );
		$this->job()->start( '2026-11-01', '2026-11-05', 'manual' );
		$this->assertSame( 'not_resumable', $this->job()->resume(), 'a running job is not resumable' );
	}

	public function test_a_fresh_lock_makes_the_step_do_nothing() {
		$this->job()->start( '2026-11-01', '2026-11-05', 'manual' );
		add_option( Config::JOB_LOCK, $this->time - 10, '', 'no' );
		$this->job()->step();
		$this->assertSame( array(), $this->calls );
		$this->assertSame( $this->time - 10, get_option( Config::JOB_LOCK ), 'the lock of the other step is left alone' );
	}

	public function test_a_stale_lock_is_taken_over() {
		$this->job()->start( '2026-11-01', '2026-11-05', 'manual' );
		add_option( Config::JOB_LOCK, $this->time - Job::LOCK_TTL - 1, '', 'no' );
		$this->job()->step();
		$this->assertCount( 1, $this->calls );
		$this->assertContains( 'A stale lock was taken over.', $this->logMessages() );
	}

	public function test_a_step_without_a_running_job_does_nothing() {
		$this->job()->step();
		$this->assertSame( array(), $this->calls );
	}

	public function test_removing_the_login_midway_fails_the_job_instead_of_calling_the_api() {
		$this->job()->start( '2026-11-01', '2026-11-05', 'manual' );
		update_option( Config::LOGIN, '' );
		$this->job()->step();
		$this->assertSame( array(), $this->calls );
		$this->assertSame( Job::FAILED, $this->job()->state()['status'] );
	}

	public function test_a_request_when_idle_starts_at_once() {
		$this->assertSame( 'started', $this->job()->request( '2026-11-01', '2026-11-05', 'post' ) );
		$this->assertSame( array(), $this->job()->queue() );
	}

	public function test_a_request_while_running_is_queued_and_started_when_the_job_ends() {
		$this->job()->start( '2026-11-01', '2026-11-05', 'manual' );
		$this->assertSame( 'queued', $this->job()->request( '2026-10-01', '2026-10-03', 'post' ) );
		$this->assertSame( array( array( '2026-10-01', '2026-10-03', 'post' ) ), $this->job()->queue() );
		$this->assertFalse( $GLOBALS['wpinat_test']['autoload'][ Config::JOB_QUEUE ] );

		$this->script                        = array( array( $this->observation( 1, '2026-11-02' ) ), array( $this->observation( 2, '2026-10-02' ) ) );
		$GLOBALS['wpinat_test']['scheduled'] = array();
		$this->job()->step();

		$state = $this->job()->state();
		$this->assertSame( Job::RUNNING, $state['status'], 'the queued range has started' );
		$this->assertSame( 'post', $state['trigger'] );
		$this->assertSame( array( array( '2026-10-01', '2026-10-03' ) ), $state['ranges'] );
		$this->assertSame( array(), $this->job()->queue() );
		$this->assertCount( 1, $this->scheduledSteps(), 'a step is scheduled for it' );

		$this->job()->step();
		$this->assertSame( Job::DONE, $this->job()->state()['status'] );
		$this->assertSame( array( 1, 2 ), array_keys( $GLOBALS['wpdb']->rows ) );
	}

	public function test_the_same_range_is_not_queued_twice() {
		$this->job()->start( '2026-11-01', '2026-11-05', 'manual' );
		$this->job()->request( '2026-10-01', '2026-10-03', 'post' );
		$this->job()->request( '2026-10-01', '2026-10-03', 'cron' );
		$this->assertCount( 1, $this->job()->queue() );
	}

	public function test_the_queue_is_bounded() {
		$this->job()->start( '2026-11-01', '2026-11-05', 'manual' );
		for ( $i = 1; $i <= Config::MAX_QUEUE + 3; $i++ ) {
			$this->job()->request( sprintf( '2025-01-%02d', $i ), sprintf( '2025-01-%02d', $i ), 'post' );
		}
		$this->assertCount( Config::MAX_QUEUE, $this->job()->queue() );
		$this->assertStringContainsString( 'queue of refreshes is full', implode( "\n", $this->logMessages() ) );
	}

	public function test_a_queued_request_is_checked_like_a_started_one() {
		$this->job()->start( '2026-11-01', '2026-11-05', 'manual' );
		$this->assertSame( 'bad_range', $this->job()->request( '2026-11-05', '2026-11-01', 'post' ) );
		update_option( Config::LOGIN, '' );
		$this->assertSame( 'no_login', $this->job()->request( '2026-10-01', '2026-10-03', 'post' ) );
		$this->assertSame( array(), $this->job()->queue() );
	}

	public function test_a_failed_job_keeps_its_queue_and_a_new_request_starts_at_once() {
		$this->job()->start( '2026-11-01', '2026-11-05', 'manual' );
		$this->job()->request( '2026-10-01', '2026-10-03', 'post' );
		$this->script = array( new INatException( INatException::HTTP, 'no' ) );
		$this->job()->step();
		$this->assertSame( Job::FAILED, $this->job()->state()['status'] );
		$this->assertCount( 1, $this->job()->queue(), 'nothing is lost' );
		$this->assertSame( 'started', $this->job()->request( '2026-09-01', '2026-09-02', 'post' ) );
	}

	public function test_the_daily_refresh_is_queued_behind_a_running_job() {
		$this->job()->start( '2026-01-01', '2026-02-05', 'manual' );
		$this->job()->startRolling();
		$this->assertSame( 'cron', $this->job()->queue()[0][2] );
	}

	/**
	 * A job with a linker (any object with linkRange()).
	 *
	 * @param object $linker Linker.
	 */
	private function jobWithLinker( $linker ): Job {
		$test   = $this;
		$client = new class( $test ) {
			private $test;
			public function __construct( $test ) {
				$this->test = $test;
			}
			public function fetchObservations( $login, $from, $to, $options ) {
				return $this->test->answer( $login, $from, $to, $options );
			}
		};
		return new Job(
			new Cache( $GLOBALS['wpdb'] ),
			static function () use ( $client ) {
				return $client;
			},
			new JobLog(
				function () {
					return $this->time;
				}
			),
			function () {
				return $this->time;
			},
			$linker
		);
	}

	public function test_the_photos_are_linked_after_each_range_is_stored() {
		$linker = new class() {
			public $calls = array();
			public $rows  = array();
			public function linkRange( $login, $from, $to ) {
				$this->calls[] = array( $login, $from, $to );
				$this->rows[]  = count( $GLOBALS['wpdb']->rows );
			}
		};
		$job    = $this->jobWithLinker( $linker );
		$job->start( '2026-10-01', '2026-11-05', 'manual' );
		$this->script = array( array( $this->observation( 1, '2026-10-10' ) ), array( $this->observation( 2, '2026-11-02' ) ) );
		$job->step();
		$this->assertSame( array( array( 'me', '2026-10-01', '2026-10-31' ), array( 'me', '2026-11-01', '2026-11-05' ) ), $linker->calls );
		$this->assertSame( array( 1, 2 ), $linker->rows, 'each call comes after the observations of its range are in the cache' );
		$this->assertSame( Job::DONE, $job->state()['status'] );
	}

	public function test_a_linking_failure_is_a_warning_and_never_fails_the_job() {
		$linker = new class() {
			public function linkRange( $login, $from, $to ) {
				throw new \RuntimeException( 'media library unavailable' );
			}
		};
		$job    = $this->jobWithLinker( $linker );
		$job->start( '2026-11-01', '2026-11-05', 'manual' );
		$this->script = array( array( $this->observation( 1, '2026-11-02' ) ) );
		$job->step();
		$this->assertSame( Job::DONE, $job->state()['status'] );
		$this->assertSame( array( 1 ), array_keys( $GLOBALS['wpdb']->rows ), 'the observations are kept' );
		$this->assertContains( 'Linking the photos of the media library failed: media library unavailable', $this->logMessages() );
		$levels = array_column( ( new JobLog() )->entries(), 'level' );
		$this->assertContains( 'warning', $levels );
	}
}
