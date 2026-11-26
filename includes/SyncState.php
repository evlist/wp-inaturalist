<?php
/**
 * What is known about the last loads and synchronisations of the cache.
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
 * The option {@see Config::SYNC}: the login of the last full load, the times of the last full load, synchronisation and
 * reconciliation, the date of the first observation and the result of the last account check. Not autoloaded.
 */
final class SyncState {

	/**
	 * The state with defaults.
	 *
	 * @return array{login:string,full_at:int,sync_at:int,reconcile_at:int,first_date:string,probe:array,force_reconcile:bool}
	 */
	public static function get(): array {
		$state = get_option( Config::SYNC, array() );
		$state = is_array( $state ) ? $state : array();
		return array(
			'login'           => isset( $state['login'] ) && is_string( $state['login'] ) ? $state['login'] : '',
			'full_at'         => isset( $state['full_at'] ) ? (int) $state['full_at'] : 0,
			'sync_at'         => isset( $state['sync_at'] ) ? (int) $state['sync_at'] : 0,
			'reconcile_at'    => isset( $state['reconcile_at'] ) ? (int) $state['reconcile_at'] : 0,
			'first_date'      => isset( $state['first_date'] ) && is_string( $state['first_date'] ) && 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $state['first_date'] ) ? $state['first_date'] : '',
			'probe'           => isset( $state['probe'] ) && is_array( $state['probe'] ) ? $state['probe'] : array(),
			'force_reconcile' => ! empty( $state['force_reconcile'] ),
		);
	}

	/**
	 * Saves a state.
	 *
	 * @param array $state State.
	 */
	private static function save( array $state ): void {
		update_option( Config::SYNC, $state, false );
	}

	/**
	 * Whether a full load was done for this login.
	 *
	 * @param string $login iNaturalist login.
	 */
	public static function isLoaded( string $login ): bool {
		$state = self::get();
		return '' !== $login && $state['login'] === $login && $state['full_at'] > 0;
	}

	/**
	 * Whether the initial load should be proposed: a login is set and no full load was done for it.
	 */
	public static function loadNeeded(): bool {
		$login = Config::getLogin();
		return '' !== $login && ! self::isLoaded( $login );
	}

	/**
	 * Whether the weekly reconciliation is due (a full load exists for the login and the last reconciliation is old enough).
	 *
	 * @param string $login iNaturalist login.
	 * @param int    $now   Current timestamp.
	 */
	public static function reconcileDue( string $login, int $now ): bool {
		$state = self::get();
		if ( ! self::isLoaded( $login ) ) {
			return false;
		}
		return $state['force_reconcile'] || $now - max( $state['reconcile_at'], $state['full_at'] ) >= Config::RECONCILE_DAYS * 86400;
	}

	/**
	 * The moment from which the next synchronisation asks for changes: the last one minus a margin, or the full load.
	 *
	 * @param string $login iNaturalist login.
	 */
	public static function since( string $login ): int {
		$state = self::get();
		if ( ! self::isLoaded( $login ) ) {
			return 0;
		}
		return max( 0, max( $state['sync_at'], $state['full_at'] ) - Config::SYNC_OVERLAP_SEC );
	}

	/**
	 * Records the result of an account check.
	 *
	 * @param string      $login iNaturalist login.
	 * @param int         $total Number of observations.
	 * @param string|null $first Date of the oldest.
	 * @param int         $now   Current timestamp.
	 */
	public static function recordProbe( string $login, int $total, ?string $first, int $now ): void {
		$state          = self::get();
		$state['probe'] = array(
			'login' => $login,
			'total' => $total,
			'first' => $first,
			'at'    => $now,
		);
		self::save( $state );
	}

	/**
	 * Records the end of a full load (or of an empty account): the offer closes. The time is that of the START of the job, so
	 * that what changed while it ran is caught by the next synchronisation.
	 *
	 * @param string      $login   iNaturalist login.
	 * @param int         $startAt Start of the job.
	 * @param string|null $first   Date of the oldest observation.
	 */
	public static function fullLoadDone( string $login, int $startAt, ?string $first ): void {
		$state                    = self::get();
		$state['login']           = $login;
		$state['full_at']         = $startAt;
		$state['sync_at']         = $startAt;
		$state['reconcile_at']    = $startAt;
		$state['force_reconcile'] = false;
		if ( null !== $first && '' !== $first ) {
			$state['first_date'] = $first;
		}
		self::save( $state );
	}

	/**
	 * Records the end of a synchronisation (start time of the job).
	 *
	 * @param int $startAt Start of the job.
	 */
	public static function syncDone( int $startAt ): void {
		$state            = self::get();
		$state['sync_at'] = max( $state['sync_at'], $startAt );
		self::save( $state );
	}

	/**
	 * Records the end of a reconciliation (start time of the job).
	 *
	 * @param int $startAt Start of the job.
	 */
	public static function reconcileDone( int $startAt ): void {
		$state                    = self::get();
		$state['reconcile_at']    = max( $state['reconcile_at'], $startAt );
		$state['sync_at']         = max( $state['sync_at'], $startAt );
		$state['force_reconcile'] = false;
		self::save( $state );
	}

	/**
	 * Asks for a reconciliation at the next daily run (too many observations changed for a synchronisation).
	 */
	public static function forceReconcile(): void {
		$state                    = self::get();
		$state['force_reconcile'] = true;
		self::save( $state );
	}

	/**
	 * Lowers the date of the first observation when an older one appears (a backdated observation).
	 *
	 * @param string $date Date (Y-m-d).
	 */
	public static function widenFirstDate( string $date ): void {
		$state = self::get();
		if ( 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) && ( '' === $state['first_date'] || $date < $state['first_date'] ) ) {
			$state['first_date'] = $date;
			self::save( $state );
		}
	}
}
