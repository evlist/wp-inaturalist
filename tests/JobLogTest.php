<?php
/**
 * Tests of JobLog.
 *
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * @package WpInaturalist
 */

namespace Evlist\WpInaturalist\Tests;

use Evlist\WpInaturalist\Config;
use Evlist\WpInaturalist\JobLog;

require_once __DIR__ . '/TestCase.php';

/**
 * Bounded log.
 */
final class JobLogTest extends TestCase {

	public function test_messages_are_kept_in_order_with_their_time_and_level() {
		$log = new JobLog(
			static function () {
				return 42;
			}
		);
		$log->add( 'info', 'one' );
		$log->add( 'error', 'two' );
		$this->assertSame(
			array(
				array(
					'time'    => 42,
					'level'   => 'info',
					'message' => 'one',
				),
				array(
					'time'    => 42,
					'level'   => 'error',
					'message' => 'two',
				),
			),
			$log->entries()
		);
	}

	public function test_only_the_last_messages_are_kept_and_the_option_is_not_autoloaded() {
		$log = new JobLog();
		for ( $i = 1; $i <= JobLog::MAX + 5; $i++ ) {
			$log->add( 'info', (string) $i );
		}
		$entries = $log->entries();
		$this->assertCount( JobLog::MAX, $entries );
		$this->assertSame( '6', $entries[0]['message'] );
		$this->assertFalse( $GLOBALS['wpinat_test']['autoload'][ Config::JOB_LOG ] );
	}

	public function test_a_corrupted_option_is_an_empty_log() {
		update_option( Config::JOB_LOG, 'garbage' );
		$this->assertSame( array(), ( new JobLog() )->entries() );
	}
}
