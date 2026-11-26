<?php
/**
 * Tests of Scheduler.
 *
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * @package WpInaturalist
 */

namespace Evlist\WpInaturalist\Tests;

use Evlist\WpInaturalist\Config;
use Evlist\WpInaturalist\Scheduler;

require_once __DIR__ . '/TestCase.php';

/**
 * The daily event and the lifecycle callbacks.
 */
final class SchedulerTest extends TestCase {

	public function test_next_run_is_later_today_when_the_hour_has_not_come() {
		$zone = new \DateTimeZone( 'Europe/Paris' );
		$now  = strtotime( '2026-11-25 01:00:00 UTC' ); // 02:00 in Paris.
		$this->assertSame( strtotime( '2026-11-25 02:00:00 UTC' ), Scheduler::nextRun( $now, 3, $zone ) ); // 03:00 in Paris is 02:00 UTC.
	}

	public function test_next_run_is_tomorrow_when_the_hour_has_passed_or_is_now() {
		$zone = new \DateTimeZone( 'Europe/Paris' );
		$this->assertSame( strtotime( '2026-11-26 02:00:00 UTC' ), Scheduler::nextRun( strtotime( '2026-11-25 02:00:00 UTC' ), 3, $zone ) );
		$this->assertSame( strtotime( '2026-11-26 02:00:00 UTC' ), Scheduler::nextRun( strtotime( '2026-11-25 12:00:00 UTC' ), 3, $zone ) );
	}

	public function test_the_daily_event_is_scheduled_once() {
		update_option( Config::REFRESH_HOUR, 5 );
		Scheduler::ensureScheduled();
		Scheduler::ensureScheduled();
		$events = $GLOBALS['wpinat_test']['scheduled'];
		$this->assertCount( 1, $events );
		$this->assertSame( Config::CRON_HOOK, $events[0]['hook'] );
		$this->assertSame( 'daily', $events[0]['recurrence'] );
		$this->assertGreaterThan( time(), $events[0]['time'] );
		$this->assertSame( '05', ( new \DateTimeImmutable( '@' . $events[0]['time'] ) )->setTimezone( wp_timezone() )->format( 'H' ) );
	}

	public function test_changing_the_hour_reschedules_the_event() {
		update_option( Config::REFRESH_HOUR, 5 );
		Scheduler::ensureScheduled();
		update_option( Config::REFRESH_HOUR, 20 );
		Scheduler::reschedule();
		$events = $GLOBALS['wpinat_test']['scheduled'];
		$this->assertCount( 1, $events );
		$this->assertSame( '20', ( new \DateTimeImmutable( '@' . $events[0]['time'] ) )->setTimezone( wp_timezone() )->format( 'H' ) );
	}

	public function test_deactivation_removes_both_events_and_keeps_the_data() {
		update_option( Config::LOGIN, 'me' );
		Scheduler::ensureScheduled();
		wp_schedule_single_event( 1, Config::STEP_HOOK );
		Scheduler::deactivate();
		$this->assertSame( array(), $GLOBALS['wpinat_test']['scheduled'] );
		$this->assertSame( 'me', get_option( Config::LOGIN ), 'options are kept' );
		$this->assertSame( array(), $GLOBALS['wpdb']->queries, 'the table is kept' );
	}

	public function test_the_deactivation_hook_is_registered_by_the_main_file() {
		$this->assertStringContainsString( "register_deactivation_hook( __FILE__, array( \\Evlist\\WpInaturalist\\Scheduler::class, 'deactivate' ) )", file_get_contents( WPINAT_FILE ) );
	}

	public function test_the_daily_callback_starts_a_rolling_job() {
		update_option( Config::LOGIN, 'me' );
		Scheduler::runDaily();
		$state = Scheduler::job()->state();
		$this->assertSame( 'running', $state['status'] );
		$this->assertSame( 'cron', $state['trigger'] );
	}

	public function test_the_daily_callback_synchronises_once_a_full_load_exists() {
		update_option( Config::LOGIN, 'me' );
		\Evlist\WpInaturalist\SyncState::fullLoadDone( 'me', time() - 86400, '2019-02-01' );
		Scheduler::runDaily();
		$this->assertSame( 'sync', Scheduler::job()->state()['trigger'] );
	}
}
