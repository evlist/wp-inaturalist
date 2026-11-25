<?php
/**
 * Log of the refresh job.
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
 * The last messages of the job, kept in an option that is not autoloaded.
 */
final class JobLog {

	const MAX = 200;

	/**
	 * Returns the current time.
	 *
	 * @var callable
	 */
	private $now;

	/**
	 * Constructor.
	 *
	 * @param callable|null $now Replaceable clock (tests).
	 */
	public function __construct( ?callable $now = null ) {
		$this->now = $now ? $now : 'time';
	}

	/**
	 * Adds a message.
	 *
	 * @param string $level   "info", "warning" or "error".
	 * @param string $message Plain English text; escaped where displayed.
	 */
	public function add( string $level, string $message ): void {
		$entries   = $this->entries();
		$entries[] = array(
			'time'    => (int) call_user_func( $this->now ),
			'level'   => $level,
			'message' => $message,
		);
		update_option( Config::JOB_LOG, array_slice( $entries, -self::MAX ), false );
	}

	/**
	 * All messages, oldest first.
	 *
	 * @return array<int,array{time:int,level:string,message:string}>
	 */
	public function entries(): array {
		$entries = get_option( Config::JOB_LOG, array() );
		return is_array( $entries ) ? $entries : array();
	}
}
