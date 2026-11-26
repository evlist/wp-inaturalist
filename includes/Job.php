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

	/**
	 * Marker in place of the first day of a range: the chunk asks for the observations updated since a moment (the second value).
	 */
	const UPDATED = '@updated';

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
	 * Links the observations of a range to photos of the media library (optional).
	 *
	 * @var PhotoLinker|null
	 */
	private $linker;

	/**
	 * Resolves the Wikidata items of the taxa (optional).
	 *
	 * @var object|null
	 */
	private $resolver;

	/**
	 * Constructor.
	 *
	 * @param Cache         $cache         Cache.
	 * @param callable      $clientFactory Returns an {@see INatClient}.
	 * @param JobLog        $log           Log.
	 * @param callable|null $now           Replaceable clock (tests).
	 * @param object|null   $linker        Object with linkRange() (see {@see PhotoLinker}).
	 * @param object|null   $resolver      Object with resolve() (see {@see TaxonResolver}).
	 */
	public function __construct( Cache $cache, callable $clientFactory, JobLog $log, ?callable $now = null, $linker = null, $resolver = null ) {
		$this->cache         = $cache;
		$this->clientFactory = $clientFactory;
		$this->log           = $log;
		$this->now           = $now ? $now : 'time';
		$this->linker        = $linker;
		$this->resolver      = $resolver;
	}

	/**
	 * Splits a date range in chunks of at most $days days.
	 *
	 * @param string $from First day (Y-m-d).
	 * @param string $to   Last day (Y-m-d).
	 * @param int    $days Maximal length of a chunk.
	 * @param int    $maxDays Longest range accepted.
	 * @return array<int,array{0:string,1:string}>
	 * @throws \InvalidArgumentException On an invalid or reversed range, or a range longer than the maximum.
	 */
	public static function chunks( string $from, string $to, int $days = Config::CHUNK_DAYS, int $maxDays = Config::MAX_RANGE_DAYS ): array {
		$start = self::date( $from );
		$end   = self::date( $to );
		if ( $start > $end ) {
			throw new \InvalidArgumentException( 'The first day is after the last day.' );
		}
		if ( $start->diff( $end )->days >= $maxDays ) {
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
	 * The chunks of a request: a range cut in chunks (92 days for a full load, 31 otherwise), or the one chunk "updated since".
	 *
	 * @param string $from    First day (Y-m-d), or {@see Job::UPDATED}.
	 * @param string $to      Last day (Y-m-d), or the moment (ISO 8601 UTC, "+00:00") with {@see Job::UPDATED}.
	 * @param string $trigger Who asks.
	 * @return array<int,array{0:string,1:string}>
	 * @throws \InvalidArgumentException On an invalid range or moment.
	 */
	private static function rangesFor( string $from, string $to, string $trigger ): array {
		if ( self::UPDATED === $from ) {
			if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', $to ) || false === strtotime( $to ) ) {
				throw new \InvalidArgumentException( 'Not a moment: use ISO 8601 in UTC.' );
			}
			return array( array( self::UPDATED, $to ) );
		}
		$full = in_array( $trigger, array( 'initial', 'reconcile' ), true );
		return self::chunks( $from, $to, $full ? Config::CHUNK_DAYS_FULL : Config::CHUNK_DAYS, $full ? Config::MAX_RANGE_FULL : Config::MAX_RANGE_DAYS );
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
			$ranges = self::rangesFor( $from, $to, $trigger );
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
					'login'      => Config::getLogin(),
					'ranges'     => $ranges,
					'next'       => 0,
					'fetched'    => 0,
					'attempt'    => 0,
					'error'      => '',
					'started_at' => $now,
				)
			)
		);
		$this->log->add(
			'info',
			self::UPDATED === $from
				? sprintf( 'Synchronisation started (%s): observations updated since %s.', $trigger, $to )
				: sprintf( 'Refresh started (%s) from %s to %s in %d step(s).', $trigger, $from, $to, count( $ranges ) )
		);
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
			self::rangesFor( $from, $to, $trigger );
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
	 * What the daily event does: the 14-day window as long as no full load was accepted for this login; then a weekly
	 * reconciliation (the full load again, which also removes what was deleted on iNaturalist) and, on the other days, a
	 * synchronisation of what changed since the last one.
	 */
	public function startDaily(): string {
		$login = Config::getLogin();
		$now   = (int) call_user_func( $this->now );
		if ( '' === $login || ! SyncState::isLoaded( $login ) ) {
			return $this->startRolling();
		}
		$first = SyncState::get()['first_date'];
		if ( '' !== $first && SyncState::reconcileDue( $login, $now ) ) {
			$today = ( new \DateTimeImmutable( '@' . $now ) )->setTimezone( wp_timezone() );
			return $this->request( $first, $today->format( 'Y-m-d' ), 'reconcile' );
		}
		return $this->request( self::UPDATED, gmdate( 'Y-m-d\TH:i:s', SyncState::since( $login ) ) . '+00:00', 'sync' );
	}

	/**
	 * Starts the 14-day window: the last days up to today in the time of the site.
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
				$client = call_user_func( $this->clientFactory );
				if ( self::UPDATED === $from ) {
					$observations = $client->fetchUpdated( $login, new \DateTimeImmutable( $to ), array( 'locale' => $locale ) );
					$rows         = $this->storeUpdated( $login, $observations, $locale );
					$this->linkPhotos( $login, $from, $to, $rows );
					$this->resolveTaxa( $login, $from, $to, $locale, $rows );
				} else {
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
					$this->linkPhotos( $login, $from, $to );
					$this->resolveTaxa( $login, $from, $to, $locale );
				}
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
			$this->log->add(
				'info',
				self::UPDATED === $from
					? sprintf( 'Step %d of %d (updated since %s): %d observation(s).', $fresh['next'], $total, $to, count( $observations ) )
					: sprintf( 'Step %d of %d (%s to %s): %d observation(s).', $fresh['next'], $total, $from, $to, count( $observations ) )
			);

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
	 * Stores observations updated on iNaturalist, in place: the ones that pass the quality filter of the setting are inserted or
	 * updated, the others are removed from the cache (they stopped being "research grade"). Lowers the recorded first date when
	 * an older observation appears.
	 *
	 * @param string        $login        iNaturalist login.
	 * @param Observation[] $observations Observations returned by the service (not filtered).
	 * @param string        $locale       Language of the names.
	 * @return array<int,array<string,mixed>> The cache rows of the observations that were kept.
	 */
	private function storeUpdated( string $login, array $observations, string $locale ): array {
		$research = Config::QUALITY_RESEARCH === Config::getQuality();
		$keep     = array();
		$drop     = array();
		foreach ( $observations as $o ) {
			if ( $research && 'research' !== $o->qualityGrade ) {
				$drop[] = $o->id;
			} else {
				$keep[] = $o;
			}
		}
		$this->cache->upsertMany( $login, $keep, $locale, (int) call_user_func( $this->now ) );
		$this->cache->deleteIds( $drop );
		foreach ( $keep as $o ) {
			if ( null !== $o->observedOn ) {
				SyncState::widenFirstDate( $o->observedOn );
			}
		}
		return $this->cache->getByIds(
			array_map(
				static function ( $o ) {
					return $o->id;
				},
				$keep
			)
		);
	}

	/**
	 * Links the photos of the media library to the observations just stored. A failure here is logged and never fails the job.
	 *
	 * @param string     $login iNaturalist login.
	 * @param string     $from  First day (Y-m-d), or the marker of a synchronisation.
	 * @param string     $to    Last day (Y-m-d), or its moment.
	 * @param array|null $rows  Cache rows of the observations to link (a synchronisation); null for the whole range.
	 */
	private function linkPhotos( string $login, string $from, string $to, ?array $rows = null ): void {
		if ( null === $this->linker ) {
			return;
		}
		try {
			if ( null !== $rows && method_exists( $this->linker, 'linkRows' ) ) {
				$this->linker->linkRows( $rows );
			} elseif ( null === $rows ) {
				$this->linker->linkRange( $login, $from, $to );
			}
		} catch ( \Throwable $e ) {
			$this->log->add( 'warning', 'Linking the photos of the media library failed: ' . $e->getMessage() );
		}
	}

	/**
	 * Finds the Wikidata items of the taxa just stored. A failure here is logged and never fails the job: the next refresh tries again.
	 *
	 * @param string     $login  iNaturalist login.
	 * @param string     $from   First day (Y-m-d), or the marker of a synchronisation.
	 * @param string     $to     Last day (Y-m-d), or its moment.
	 * @param string     $locale Language of the names, also used for Wikidata labels and the Wikipedia.
	 * @param array|null $rows   Cache rows of the observations to resolve (a synchronisation); null for the whole range.
	 */
	private function resolveTaxa( string $login, string $from, string $to, string $locale, ?array $rows = null ): void {
		if ( null === $this->resolver ) {
			return;
		}
		try {
			$ids = array();
			foreach ( null === $rows ? $this->cache->getBetween( $login, $from, $to ) : $rows as $row ) {
				if ( ! empty( $row['taxon_id'] ) ) {
					$ids[] = (int) $row['taxon_id'];
				}
			}
			$stats = $this->resolver->resolve( array_values( array_unique( $ids ) ), $locale, (int) call_user_func( $this->now ) );
			if ( $stats['found'] + $stats['absent'] > 0 ) {
				$this->log->add( 'info', sprintf( 'Wikidata: %d taxon/taxa with an item, %d without.', $stats['found'], $stats['absent'] ) );
			}
		} catch ( \Throwable $e ) {
			$this->log->add( 'warning', 'Resolving the taxa with Wikidata failed: ' . $e->getMessage() );
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
		$this->recordEnd( $state );
		$this->startQueued();
	}

	/**
	 * Records in the synchronisation state what a job that ended successfully has done. The time recorded is the start of the job, so
	 * that what changed while it ran is caught next time. Nothing is recorded if the login changed while it ran.
	 *
	 * @param array $state State of the job that ended.
	 */
	private function recordEnd( array $state ): void {
		$login = Config::getLogin();
		if ( '' === $login || ( isset( $state['login'] ) && $state['login'] !== $login ) ) {
			return;
		}
		$start = (int) $state['started_at'];
		if ( 'initial' === $state['trigger'] ) {
			SyncState::fullLoadDone( $login, $start, isset( $state['ranges'][0][0] ) && self::UPDATED !== $state['ranges'][0][0] ? $state['ranges'][0][0] : null );
		} elseif ( 'reconcile' === $state['trigger'] ) {
			SyncState::reconcileDone( $start );
		} elseif ( 'sync' === $state['trigger'] ) {
			SyncState::syncDone( $start );
		}
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
			if ( INatException::TRUNCATED === $e->kind && 'sync' === $state['trigger'] ) {
				// Too many observations changed for one synchronisation: the next daily run reloads everything instead.
				SyncState::forceReconcile();
				$this->log->add( 'warning', 'Too many observations changed: a full reconciliation is planned for the next daily run.' );
			}
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
