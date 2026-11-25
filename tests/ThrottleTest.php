<?php
/**
 * Tests of Throttle.
 *
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * @package WpInaturalist
 */

namespace Evlist\WpInaturalist\Tests;

use Evlist\WpInaturalist\Throttle;

require_once __DIR__ . '/TestCase.php';

/**
 * Minimal delay between two requests.
 */
final class ThrottleTest extends TestCase {

	/**
	 * Builds a throttle with a fake clock and a recording sleep.
	 *
	 * @param float[] $times   Successive values of the clock.
	 * @param array   $sleeps  Receives the durations slept.
	 */
	private function make( array $times, array &$sleeps ): Throttle {
		return new Throttle(
			1.0,
			static function () use ( &$times ) {
				return array_shift( $times );
			},
			static function ( $seconds ) use ( &$sleeps ) {
				$sleeps[] = $seconds;
			}
		);
	}

	public function test_first_request_does_not_wait() {
		$sleeps = array();
		$this->make( array( 100.0 ), $sleeps )->wait();
		$this->assertSame( array(), $sleeps );
		$this->assertSame( 100.0, get_transient( Throttle::TRANSIENT ) );
	}

	public function test_a_request_too_soon_waits_for_the_remaining_time() {
		$sleeps   = array();
		$throttle = $this->make( array( 100.0, 100.25 ), $sleeps );
		$throttle->wait();
		$throttle->wait();
		$this->assertEqualsWithDelta( array( 0.75 ), $sleeps, 0.0001 );
		$this->assertEqualsWithDelta( 101.0, get_transient( Throttle::TRANSIENT ), 0.0001 );
	}

	public function test_a_late_request_does_not_wait() {
		$sleeps   = array();
		$throttle = $this->make( array( 100.0, 105.0 ), $sleeps );
		$throttle->wait();
		$throttle->wait();
		$this->assertSame( array(), $sleeps );
	}

	public function test_each_service_has_its_own_delay() {
		$sleeps = array();
		$a      = new Throttle(
			1.0,
			static function () {
				return 100.0;
			},
			static function ( $s ) use ( &$sleeps ) {
				$sleeps[] = $s;
			}
		);
		$b      = new Throttle(
			1.0,
			static function () {
				return 100.2;
			},
			static function ( $s ) use ( &$sleeps ) {
				$sleeps[] = $s;
			},
			Throttle::WIKIDATA_TRANSIENT
		);
		$a->wait();
		$b->wait();
		$this->assertSame( array(), $sleeps, 'a request to one service does not delay the other' );
		$this->assertSame( 100.0, get_transient( Throttle::TRANSIENT ) );
		$this->assertSame( 100.2, get_transient( Throttle::WIKIDATA_TRANSIENT ) );
	}
}
