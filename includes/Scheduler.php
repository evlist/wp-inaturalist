<?php
/**
 * WP-Cron wiring: the daily event, the step event and the objects they use.
 *
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * @package WpInaturalist
 */

namespace Evlist\WpInaturalist;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Callbacks of the cron events and of the lifecycle hooks.
 */
final class Scheduler {

	/**
	 * Builds the job with its real collaborators.
	 */
	public static function job(): Job {
		global $wpdb;
		return new Job(
			new Cache( $wpdb ),
			static function () {
				return new INatClient( new Throttle() );
			},
			new JobLog(),
			null,
			new PhotoLinker( new Cache( $wpdb ), new AttachmentIndex( $wpdb ) ),
			new TaxonResolver( new Taxa( $wpdb ), new WikidataClient( new Throttle( 1.0, null, null, Throttle::WIKIDATA_TRANSIENT ) ) )
		);
	}

	/**
	 * Timestamp of the next occurrence of an hour of the day in the time zone of the site.
	 *
	 * @param int           $now  Current timestamp.
	 * @param int           $hour Hour, 0 to 23.
	 * @param \DateTimeZone $zone Time zone.
	 */
	public static function nextRun( int $now, int $hour, \DateTimeZone $zone ): int {
		$moment = ( new \DateTimeImmutable( '@' . $now ) )->setTimezone( $zone )->setTime( $hour, 0, 0 );
		if ( $moment->getTimestamp() <= $now ) {
			$moment = $moment->modify( '+1 day' );
		}
		return $moment->getTimestamp();
	}

	/**
	 * Schedules the daily event when it is missing (on init).
	 */
	public static function ensureScheduled(): void {
		if ( false === wp_next_scheduled( Config::CRON_HOOK ) ) {
			wp_schedule_event( self::nextRun( time(), Config::getRefreshHour(), wp_timezone() ), 'daily', Config::CRON_HOOK );
		}
	}

	/**
	 * Schedules the daily event again after the hour setting changed.
	 */
	public static function reschedule(): void {
		wp_clear_scheduled_hook( Config::CRON_HOOK );
		self::ensureScheduled();
	}

	/**
	 * Cron callback of the daily event.
	 */
	public static function runDaily(): void {
		self::job()->startDaily();
	}

	/**
	 * Cron callback of the step event.
	 */
	public static function runStep(): void {
		self::job()->step();
	}

	/**
	 * Deactivation: removes the scheduled events (the data is kept; uninstall removes it).
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( Config::CRON_HOOK );
		wp_clear_scheduled_hook( Config::STEP_HOOK );
	}
}
