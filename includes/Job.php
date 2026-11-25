<?php
/**
 * The refresh job: fetches observations by chunks of dates and stores them in the cache.
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
 * A background job driven by WP-Cron, with a persistent state, stop and resume, retries and a log.
 *
 * The state is the option {@see Config::JOB_STATE}. The only code that calls the API is {@see Job::step()}.
 */
final class Job {

	const IDLE    = 'idle';
	const RUNNING = 'running';
	const STOPPED = 'stopped';
	const DONE    = 'done';
	const FAILED  = 'failed';

	const BUDGET_SECONDS = 20;
	const LOCK_TTL       = 600;
	const MAX_ATTEMPTS   = 5;
	const MAX_BACKOFF    = 900;

	/**
	 * Cache.
	 *
	 * @var Cache
	 */
	private $cache;

	/**
	 * Builds the API client.
	 *
	 * @var callable
	 */
	private $clientFactory;

	/**
	 * Log.
	 *
	 * @var JobLog
	 */
	private $log;

	/**
	 * Returns the current timestamp.
	 *
	 * @var callable
	 */
	private $now;

	/**
	 * Constructor.
	 *
	 * @param Cache         $cache         Cache.
	 * @param callable      $clientFactory Returns an {@see INatClient}.
	 * @param JobLog        $log           Log.
	 * @param callable|null $now           Replaceable clock (tests).
	 */
	public function __construct( Cache $cache, callable $clientFactory, JobLog $log, ?callable $now = null ) {
		$this->cache         = $cache;
		$this->clientFactory = $clientFactory;
		$this->log           = $log;
		$this->now           = $now ? $now : 'time';
	}

	/**
	 * Splits a date range in chunks of at most $days days.
	 *
	 * @param string $from First day (Y-m-d).
	 * @param string $to   Last day (Y-m-d).
	 * @param int    $days Maximal length of a chunk.
	 * @return array<int,array{0:string,1:string}>
	 * @throws \InvalidArgumentException On an invalid or reversed range, or a range longer than the maximum.
	 */
	public static function chunks( string $from, string $to, int $days = Config::CHUNK_DAYS ): array {
		$start = self::date( $from );
		$end   = self::date( $to );
		if ( $start > $end ) {
			throw new \InvalidArgumentException( 'The first day is after the last day.' );
		}
		if ( $start->diff( $end )->days >= Config::MAX_RANGE_DAYS ) {
			throw new \InvalidArgumentException( 'The range is too long.' );
		}
		$out = array();
		while ( $start <= $end ) {
			$last  = $start->modify( '+' . ( $days - 1 ) . ' days' );
			$last  = $last > $end ? $end : $last;
			$out[] = array( $start->format( 'Y-m-d' ), $last->format( 'Y-m-d' ) );
			$start = $last->modify( '+1 day' );
		}
		return $out;
	}

	/**
	 * Parses a strict Y-m-d date.
	 *
	 * @param string $value Date.
	 * @throws \InvalidArgumentException When it is not a real date.
	 */
	private static function date( string $value ): \DateTimeImmutable {
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value, new \DateTimeZone( 'UTC' ) );
		if ( false === $date || $date->format( 'Y-m-d' ) !== $value ) {
			throw new \InvalidArgumentException( 'Not a date: use Y-m-d.' );
		}
		return $date;
	}

	/**
	 * Adds a message to the log of the job.
	 *
	 * @param string $level   "info", "warning" or "error".
	 * @param string $message Plain English text.
	 */
	public function log( string $level, string $message ): void {
		$this->log->add( $level, $message );
	}

	/**
	 * The state of the job, with defaults.
	 *
	 * @return array<string,mixed>
	 */
	public function state(): array {
		$state = get_option( Config::JOB_STATE, array() );
		return array_merge(
			array(
				'status'     => self::IDLE,
				'trigger'    => '',
				'ranges'     => array(),
				'next'       => 0,
				'fetched'    => 0,
				'attempt'    => 0,
				'error'      => '',
				'started_at' => 0,
				'updated_at' => 0,
			),
			is_array( $state ) ? $state : array()
		);
	}

	/**
	 * Saves the state.
	 *
	 * @param array $state State.
	 */
	private function save( array $state ): void {
		$state['updated_at'] = (int) call_user_func( $this->now );
		update_option( Config::JOB_STATE, $state, false );
	}

	/**
	 * Starts a job over a date range.
	 *
	 * @param string $from    First day (Y-m-d).
	 * @param string $to      Last day (Y-m-d).
	 * @param string $trigger "cron" or "manual".
	 * @return string "started", "busy", "no_login" or "bad_range".
	 */
	public function start( string $from, string $to, string $trigger ): string {
		if ( self::RUNNING === $this->state()['status'] ) {
			$this->log->add( 'info', 'A refresh was requested while one is running: ignored.' );
			return 'busy';
		}
		if ( '' === Config::getLogin() ) {
			$this->log->add( 'warning', 'No iNaturalist login is configured: nothing to refresh.' );
			return 'no_login';
		}
		try {
			$ranges = self::chunks( $from, $to );
		} catch ( \InvalidArgumentException $e ) {
			return 'bad_range';
		}
		$now = (int) call_user_func( $this->now );
		$this->save(
			array_merge(
				$this->state(),
				array(
					'status'     => self::RUNNING,
					'trigger'    => $trigger,
					'ranges'     => $ranges,
					'next'       => 0,
					'fetched'    => 0,
					'attempt'    => 0,
					'error'      => '',
					'started_at' => $now,
				)
			)
		);
		$this->log->add( 'info', sprintf( 'Refresh started (%s) from %s to %s in %d step(s).', $trigger, $from, $to, count( $ranges ) ) );
		wp_schedule_single_event( $now, Config::STEP_HOOK );
		return 'started';
	}

	/**
	 * Starts a job over a date range, or queues the range when a job is running.
	 *
	 * Used by callers that must not lose a request (daily refresh, publication of a post, editor button). A queued range is
	 * started when the running job ends.
	 *
	 * @param string $from    First day (Y-m-d).
	 * @param string $to      Last day (Y-m-d).
	 * @param string $trigger "cron", "post" or "manual".
	 * @return string "started", "queued", "no_login" or "bad_range".
	 */
	public function request( string $from, string $to, string $trigger ): string {
		if ( self::RUNNING !== $this->state()['status'] ) {
			return $this->start( $from, $to, $trigger );
		}
		if ( '' === Config::getLogin() ) {
			$this->log->add( 'warning', 'No iNaturalist login is configured: nothing to refresh.' );
			return 'no_login';
		}
		try {
			self::chunks( $from, $to );
		} catch ( \InvalidArgumentException $e ) {
			return 'bad_range';
		}
		$queue = $this->queue();
		foreach ( $queue as $waiting ) {
			if ( $waiting[0] === $from && $waiting[1] === $to ) {
				return 'queued'; // The same range is already waiting.
			}
		}
		if ( count( $queue ) >= Config::MAX_QUEUE ) {
			$this->log->add( 'warning', 'The queue of refreshes is full: the request for ' . $from . ' to ' . $to . ' was dropped.' );
			return 'queued';
		}
		$queue[] = array( $from, $to, $trigger );
		update_option( Config::JOB_QUEUE, $queue, false );
		$this->log->add( 'info', sprintf( 'A refresh is running: %s to %s (%s) is queued.', $from, $to, $trigger ) );
		return 'queued';
	}

	/**
	 * Ranges waiting for the running job to end.
	 *
	 * @return array<int,array{0:string,1:string,2:string}>
	 */
	public function queue(): array {
		$queue = get_option( Config::JOB_QUEUE, array() );
		return is_array( $queue ) ? array_values( $queue ) : array();
	}

	/**
	 * Starts the next queued range, if any, once the job is done.
	 */
	private function startQueued(): void {
		$queue = $this->queue();
		if ( array() === $queue ) {
			return;
		}
		$next = array_shift( $queue );
		update_option( Config::JOB_QUEUE, $queue, false );
		$this->start( $next[0], $next[1], $next[2] );
	}

	/**
	 * Starts the daily refresh: the last days up to today in the time of the site.
	 */
	public function startRolling(): string {
		$today = ( new \DateTimeImmutable( '@' . (int) call_user_func( $this->now ) ) )->setTimezone( wp_timezone() );
		$from  = $today->modify( '-' . ( Config::DAILY_WINDOW_DAYS - 1 ) . ' days' );
		return $this->request( $from->format( 'Y-m-d' ), $today->format( 'Y-m-d' ), 'cron' );
	}

	/**
	 * Stops the job. The chunk being fetched, if any, is finished but its progress is not recorded.
	 *
	 * @return string "stopped" or "not_running".
	 */
	public function stop(): string {
		$state = $this->state();
		if ( self::RUNNING !== $state['status'] ) {
			return 'not_running';
		}
		$state['status'] = self::STOPPED;
		$this->save( $state );
		wp_clear_scheduled_hook( Config::STEP_HOOK );
		$this->log->add( 'info', sprintf( 'Refresh stopped at step %d of %d.', $state['next'], count( $state['ranges'] ) ) );
		return 'stopped';
	}

	/**
	 * Resumes a stopped or failed job from the chunk where it stopped.
	 *
	 * @return string "resumed" or "not_resumable".
	 */
	public function resume(): string {
		$state = $this->state();
		if ( ! in_array( $state['status'], array( self::STOPPED, self::FAILED ), true ) || $state['next'] >= count( $state['ranges'] ) ) {
			return 'not_resumable';
		}
		$state['status']  = self::RUNNING;
		$state['attempt'] = 0;
		$state['error']   = '';
		$this->save( $state );
		$this->log->add( 'info', sprintf( 'Refresh resumed at step %d of %d.', $state['next'] + 1, count( $state['ranges'] ) ) );
		wp_schedule_single_event( (int) call_user_func( $this->now ), Config::STEP_HOOK );
		return 'resumed';
	}

	/**
	 * Runs steps for a limited time. Called by the cron event {@see Config::STEP_HOOK}.
	 */
	public function step(): void {
		if ( ! $this->acquireLock() ) {
			return;
		}
		try {
			$this->runChunks();
		} finally {
			delete_option( Config::JOB_LOCK );
		}
	}

	/**
	 * Takes the lock: an option added atomically, taken over when older than the time to live.
	 */
	private function acquireLock(): bool {
		$now = (int) call_user_func( $this->now );
		if ( add_option( Config::JOB_LOCK, $now, '', 'no' ) ) {
			return true;
		}
		$held = (int) get_option( Config::JOB_LOCK, 0 );
		if ( $now - $held > self::LOCK_TTL ) {
			update_option( Config::JOB_LOCK, $now, false );
			$this->log->add( 'warning', 'A stale lock was taken over.' );
			return true;
		}
		return false;
	}

	/**
	 * Fetches and stores chunks until the time budget is spent, the job ends, fails or is stopped.
	 *
	 * Everything thrown inside is caught and turned into a retry or a failure.
	 *
	 * @throws \InvalidArgumentException Never: caught below, listed because the code throws it for a missing login.
	 */
	private function runChunks(): void {
		$started = (int) call_user_func( $this->now );
		while ( true ) {
			$state = $this->state();
			if ( self::RUNNING !== $state['status'] ) {
				return;
			}
			$total = count( $state['ranges'] );
			if ( $state['next'] >= $total ) {
				$this->finish( $state );
				return;
			}
			list( $from, $to ) = $state['ranges'][ $state['next'] ];
			$login             = Config::getLogin();
			$locale            = Config::getNamesLocale();

			try {
				if ( '' === $login ) {
					throw new \InvalidArgumentException( 'No iNaturalist login is configured.' );
				}
				$client       = call_user_func( $this->clientFactory );
				$observations = $client->fetchObservations(
					$login,
					self::date( $from ),
					self::date( $to ),
					array(
						'locale'  => $locale,
						'quality' => Config::getQuality(),
					)
				);
				$this->cache->replaceRange( $login, $from, $to, $observations, $locale, (int) call_user_func( $this->now ) );
			} catch ( INatException $e ) {
				$this->handleApiError( $e );
				return;
			} catch ( \InvalidArgumentException | \RuntimeException $e ) {
				$this->fail( $this->state(), $e->getMessage() );
				return;
			}

			$fresh = $this->state();
			if ( self::RUNNING !== $fresh['status'] ) {
				return;
			}
			$fresh['next']    = $state['next'] + 1;
			$fresh['fetched'] = $state['fetched'] + count( $observations );
			$fresh['attempt'] = 0;
			$this->save( $fresh );
			$this->log->add( 'info', sprintf( 'Step %d of %d (%s to %s): %d observation(s).', $fresh['next'], $total, $from, $to, count( $observations ) ) );

			if ( $fresh['next'] >= $total ) {
				$this->finish( $fresh );
				return;
			}
			if ( (int) call_user_func( $this->now ) - $started >= self::BUDGET_SECONDS ) {
				wp_schedule_single_event( (int) call_user_func( $this->now ) + 1, Config::STEP_HOOK );
				return;
			}
		}
	}

	/**
	 * Ends the job successfully.
	 *
	 * @param array $state Current state.
	 */
	private function finish( array $state ): void {
		$state['status'] = self::DONE;
		$this->save( $state );
		$this->log->add( 'info', sprintf( 'Refresh done: %d observation(s) fetched, %d in the cache.', $state['fetched'], $this->cache->count() ) );
		$this->startQueued();
	}

	/**
	 * Marks the job as failed; it can be resumed.
	 *
	 * @param array  $state   Current state.
	 * @param string $message Reason.
	 */
	private function fail( array $state, string $message ): void {
		if ( self::RUNNING !== $this->state()['status'] ) {
			return; // Stopped meanwhile: the stop wins.
		}
		$state['status'] = self::FAILED;
		$state['error']  = $message;
		$this->save( $state );
		$this->log->add( 'error', 'Refresh failed: ' . $message );
	}

	/**
	 * Retries a retryable error later, up to the maximal number of attempts, otherwise fails.
	 *
	 * @param INatException $e Error of the client.
	 */
	private function handleApiError( INatException $e ): void {
		$state = $this->state();
		if ( self::RUNNING !== $state['status'] ) {
			return;
		}
		if ( ! $e->isRetryable() ) {
			$this->fail( $state, $e->getMessage() . ' (' . $e->kind . ')' );
			return;
		}
		$state['attempt'] = $state['attempt'] + 1;
		if ( $state['attempt'] >= self::MAX_ATTEMPTS ) {
			$this->fail( $state, $e->getMessage() . ' (' . $e->kind . ', ' . self::MAX_ATTEMPTS . ' attempts)' );
			return;
		}
		$delay = null !== $e->retryAfter ? max( 1, $e->retryAfter ) : min( self::MAX_BACKOFF, 60 * ( 2 ** $state['attempt'] ) );
		$this->save( $state );
		$this->log->add( 'warning', sprintf( '%s (%s): attempt %d of %d, next try in %d s.', $e->getMessage(), $e->kind, $state['attempt'], self::MAX_ATTEMPTS, $delay ) );
		wp_schedule_single_event( (int) call_user_func( $this->now ) + $delay, Config::STEP_HOOK );
	}
}
