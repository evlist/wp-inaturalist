<?php
/**
 * Tests of SyncState.
 *
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * @package WpInaturalist
 */

namespace Evlist\WpInaturalist\Tests;

use Evlist\WpInaturalist\Config;
use Evlist\WpInaturalist\SyncState;

require_once __DIR__ . '/TestCase.php';

/**
 * What the plugin remembers about loads and synchronisations.
 */
final class SyncStateTest extends TestCase {

	const T0 = 1764000000;

	public function test_defaults() {
		$this->assertSame(
			array(
				'login'           => '',
				'full_at'         => 0,
				'sync_at'         => 0,
				'reconcile_at'    => 0,
				'first_date'      => '',
				'probe'           => array(),
				'force_reconcile' => false,
			),
			SyncState::get()
		);
		$this->assertFalse( SyncState::isLoaded( 'me' ) );
	}

	public function test_a_corrupted_option_gives_the_defaults() {
		foreach ( array(
			'junk',
			5,
			null,
			array(
				'login'      => array( 1 ),
				'full_at'    => 'x',
				'first_date' => 'yesterday',
				'probe'      => 'x',
			),
		) as $bad ) {
			update_option( Config::SYNC, $bad );
			$state = SyncState::get();
			$this->assertSame( '', $state['login'] );
			$this->assertSame( 0, $state['full_at'] );
			$this->assertSame( '', $state['first_date'] );
			$this->assertSame( array(), $state['probe'] );
		}
	}

	public function test_the_option_is_not_autoloaded() {
		SyncState::syncDone( self::T0 );
		$this->assertFalse( $GLOBALS['wpinat_test']['autoload'][ Config::SYNC ] );
	}

	public function test_the_initial_load_is_proposed_only_with_a_login_and_without_a_full_load_for_it() {
		$this->assertFalse( SyncState::loadNeeded(), 'no login: nothing to propose' );
		update_option( Config::LOGIN, 'me' );
		$this->assertTrue( SyncState::loadNeeded() );
		SyncState::fullLoadDone( 'me', self::T0, '2019-02-01' );
		$this->assertFalse( SyncState::loadNeeded() );
		update_option( Config::LOGIN, 'someone-else' );
		$this->assertTrue( SyncState::loadNeeded(), 'a changed login proposes it again' );
		update_option( Config::LOGIN, 'me' );
		$this->assertFalse( SyncState::loadNeeded(), 'and changing back does not' );
	}

	public function test_a_full_load_records_login_times_and_first_date_and_closes_the_forced_reconciliation() {
		SyncState::forceReconcile();
		SyncState::fullLoadDone( 'me', self::T0, '2019-02-01' );
		$this->assertSame( array( 'me', self::T0, self::T0, self::T0, '2019-02-01', false ), array( SyncState::get()['login'], SyncState::get()['full_at'], SyncState::get()['sync_at'], SyncState::get()['reconcile_at'], SyncState::get()['first_date'], SyncState::get()['force_reconcile'] ) );
	}

	public function test_an_empty_account_closes_the_offer_without_a_first_date() {
		SyncState::fullLoadDone( 'me', self::T0, null );
		$this->assertTrue( SyncState::isLoaded( 'me' ) );
		$this->assertSame( '', SyncState::get()['first_date'] );
	}

	public function test_since_is_the_last_synchronisation_minus_the_margin_and_zero_before_a_full_load() {
		$this->assertSame( 0, SyncState::since( 'me' ) );
		SyncState::fullLoadDone( 'me', self::T0, '2019-02-01' );
		$this->assertSame( self::T0 - Config::SYNC_OVERLAP_SEC, SyncState::since( 'me' ) );
		SyncState::syncDone( self::T0 + 86400 );
		$this->assertSame( self::T0 + 86400 - Config::SYNC_OVERLAP_SEC, SyncState::since( 'me' ) );
		$this->assertSame( 0, SyncState::since( 'another' ), 'another login has no point to start from' );
	}

	public function test_times_never_go_backwards() {
		SyncState::fullLoadDone( 'me', self::T0, '2019-02-01' );
		SyncState::syncDone( self::T0 + 500 );
		SyncState::syncDone( self::T0 + 100 );
		$this->assertSame( self::T0 + 500, SyncState::get()['sync_at'] );
		SyncState::reconcileDone( self::T0 + 400 );
		SyncState::reconcileDone( self::T0 + 50 );
		$this->assertSame( self::T0 + 400, SyncState::get()['reconcile_at'] );
		$this->assertSame( self::T0 + 500, SyncState::get()['sync_at'], 'an old reconciliation does not move the synchronisation back' );
	}

	public function test_the_reconciliation_is_due_after_seven_days_or_when_forced() {
		$this->assertFalse( SyncState::reconcileDue( 'me', self::T0 + 99 * 86400 ), 'nothing to reconcile before a full load' );
		SyncState::fullLoadDone( 'me', self::T0, '2019-02-01' );
		$week = Config::RECONCILE_DAYS * 86400;
		$this->assertFalse( SyncState::reconcileDue( 'me', self::T0 + $week - 1 ) );
		$this->assertTrue( SyncState::reconcileDue( 'me', self::T0 + $week ) );
		SyncState::reconcileDone( self::T0 + $week );
		$this->assertFalse( SyncState::reconcileDue( 'me', self::T0 + $week + 86400 ) );
		SyncState::forceReconcile();
		$this->assertTrue( SyncState::reconcileDue( 'me', self::T0 + $week + 86400 ) );
		SyncState::reconcileDone( self::T0 + $week + 86400 );
		$this->assertFalse( SyncState::reconcileDue( 'me', self::T0 + $week + 86400 ), 'a reconciliation clears the request' );
		$this->assertFalse( SyncState::reconcileDue( 'another', self::T0 + 99 * $week ), 'not for another login' );
	}

	public function test_the_first_date_only_goes_back_and_only_for_real_dates() {
		SyncState::fullLoadDone( 'me', self::T0, '2019-02-01' );
		SyncState::widenFirstDate( '2020-01-01' );
		$this->assertSame( '2019-02-01', SyncState::get()['first_date'] );
		SyncState::widenFirstDate( '2018-05-05' );
		$this->assertSame( '2018-05-05', SyncState::get()['first_date'] );
		SyncState::widenFirstDate( 'garbage' );
		SyncState::widenFirstDate( '' );
		$this->assertSame( '2018-05-05', SyncState::get()['first_date'] );
	}

	public function test_a_probe_is_recorded_with_its_time() {
		SyncState::recordProbe( 'me', 861, '2019-02-01', self::T0 );
		$this->assertSame(
			array(
				'login' => 'me',
				'total' => 861,
				'first' => '2019-02-01',
				'at'    => self::T0,
			),
			SyncState::get()['probe']
		);
	}
}
