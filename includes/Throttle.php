<?php
/**
 * Minimal delay between two requests to the iNaturalist API.
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
 * Keeps at least $minInterval seconds between two requests, using a transient (not a file in the temporary directory).
 *
 * It does not lock: the refresh job (slice 004) is the only caller and runs one at a time.
 */
final class Throttle {

	const TRANSIENT = 'wpinat_last_request';

	/**
	 * Minimal delay in seconds.
	 *
	 * @var float
	 */
	private $minInterval;

	/**
	 * Returns the current time as a float number of seconds.
	 *
	 * @var callable
	 */
	private $clock;

	/**
	 * Sleeps for a float number of seconds.
	 *
	 * @var callable
	 */
	private $sleep;

	/**
	 * Constructor.
	 *
	 * @param float         $minInterval Minimal delay in seconds.
	 * @param callable|null $clock       Replaceable clock (tests).
	 * @param callable|null $sleep       Replaceable sleep (tests).
	 */
	public function __construct( float $minInterval = 1.0, ?callable $clock = null, ?callable $sleep = null ) {
		$this->minInterval = $minInterval;
		$this->clock       = $clock ? $clock : 'microtime';
		$this->sleep       = $sleep ? $sleep : static function ( $seconds ) {
			usleep( (int) round( $seconds * 1000000 ) );
		};
	}

	/**
	 * Waits if the last request is too recent, then records the time of this one.
	 */
	public function wait(): void {
		$last = get_transient( self::TRANSIENT );
		$now  = (float) call_user_func( $this->clock, true );
		if ( is_numeric( $last ) ) {
			$remaining = $this->minInterval - ( $now - (float) $last );
			if ( $remaining > 0 ) {
				call_user_func( $this->sleep, $remaining );
				$now += $remaining;
			}
		}
		set_transient( self::TRANSIENT, $now, 60 );
	}
}
