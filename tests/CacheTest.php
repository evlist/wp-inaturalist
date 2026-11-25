<?php
/**
 * Tests of Cache, against an in-memory stand-in of $wpdb.
 *
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * @package WpInaturalist
 */

namespace Evlist\WpInaturalist\Tests;

use Evlist\WpInaturalist\Cache;

require_once __DIR__ . '/TestCase.php';

/**
 * Upsert and replacement of a date range.
 *
 * The stand-in understands the one DELETE statement the cache sends; real MySQL was never tried.
 */
final class CacheTest extends TestCase {

	private function cache(): Cache {
		return new Cache( $GLOBALS['wpdb'] );
	}

	public function test_rows_are_stored_with_all_the_fields() {
		$this->cache()->replaceRange( 'me', '2026-11-01', '2026-11-30', array( $this->observation( 7, '2026-11-20' ) ), 'fr', 1700000000 );
		$row = $GLOBALS['wpdb']->rows[7];
		$this->assertSame( 'me', $row['user_login'] );
		$this->assertSame( 'uuid-7', $row['uuid'] );
		$this->assertSame( '2026-11-20', $row['observed_on'] );
		$this->assertSame( 'Species 7', $row['scientific_name'] );
		$this->assertSame( 'espèce 7', $row['common_name'] );
		$this->assertSame( 'fr', $row['names_locale'] );
		$this->assertSame( 'species', $row['taxon_rank'] );
		$this->assertSame( '2023-11-14 22:13:20', $row['fetched_at'] );
		$this->assertSame( 5007, json_decode( $row['photos'], true )[0]['id'] );
		$this->assertSame( 1, $this->cache()->count() );
	}

	public function test_a_second_fetch_updates_the_same_row() {
		$this->cache()->replaceRange( 'me', '2026-11-01', '2026-11-30', array( $this->observation( 7 ) ), 'fr', 1 );
		$changed             = $this->observation( 7 );
		$changed->commonName = 'autre nom';
		$this->cache()->replaceRange( 'me', '2026-11-01', '2026-11-30', array( $changed ), 'fr', 2 );
		$this->assertSame( 1, $this->cache()->count() );
		$this->assertSame( 'autre nom', $GLOBALS['wpdb']->rows[7]['common_name'] );
	}

	public function test_rows_of_the_range_no_longer_returned_are_removed() {
		$this->cache()->replaceRange( 'me', '2026-11-01', '2026-11-30', array( $this->observation( 1 ), $this->observation( 2 ) ), 'fr', 1 );
		$this->cache()->replaceRange( 'me', '2026-11-01', '2026-11-30', array( $this->observation( 2 ) ), 'fr', 2 );
		$this->assertSame( array( 2 ), array_keys( $GLOBALS['wpdb']->rows ) );
	}

	public function test_an_empty_answer_empties_the_range() {
		$this->cache()->replaceRange( 'me', '2026-11-01', '2026-11-30', array( $this->observation( 1 ) ), 'fr', 1 );
		$this->cache()->replaceRange( 'me', '2026-11-01', '2026-11-30', array(), 'fr', 2 );
		$this->assertSame( 0, $this->cache()->count() );
	}

	public function test_other_dates_and_other_users_are_not_touched() {
		$GLOBALS['wpdb']->rows = array(
			10 => array(
				'user_login'  => 'me',
				'observed_on' => '2026-10-31',
			),
			11 => array(
				'user_login'  => 'me',
				'observed_on' => '2026-12-01',
			),
			12 => array(
				'user_login'  => 'other',
				'observed_on' => '2026-11-15',
			),
		);
		$this->cache()->replaceRange( 'me', '2026-11-01', '2026-11-30', array(), 'fr', 1 );
		$this->assertSame( array( 10, 11, 12 ), array_keys( $GLOBALS['wpdb']->rows ) );
	}

	public function test_the_login_reaches_sql_only_through_prepare() {
		$this->cache()->replaceRange( "x' OR '1'='1", '2026-11-01', '2026-11-30', array(), 'fr', 1 );
		$this->assertStringContainsString( "user_login = 'x\\' OR \\'1\\'=\\'1'", $GLOBALS['wpdb']->queries[0] );
	}

	public function test_a_refused_write_is_an_error() {
		$GLOBALS['wpdb']->fail_replace = true;
		$this->expectException( \RuntimeException::class );
		$this->cache()->replaceRange( 'me', '2026-11-01', '2026-11-30', array( $this->observation( 1 ) ), 'fr', 1 );
	}

	public function test_clear_empties_the_table() {
		$this->cache()->replaceRange( 'me', '2026-11-01', '2026-11-30', array( $this->observation( 1 ) ), 'fr', 1 );
		$this->cache()->clear();
		$this->assertSame( 0, $this->cache()->count() );
	}

	public function test_get_between_returns_the_rows_of_the_login_and_period_oldest_first() {
		$c = $this->cache();
		$c->replaceRange( 'me', '2026-11-01', '2026-11-30', array( $this->observation( 3, '2026-11-20' ), $this->observation( 1, '2026-11-05' ), $this->observation( 2, '2026-11-20' ) ), 'fr', 1 );
		$c->replaceRange( 'other', '2026-11-01', '2026-11-30', array( $this->observation( 9, '2026-11-10' ) ), 'fr', 1 );
		$c->replaceRange( 'me', '2026-10-01', '2026-10-31', array( $this->observation( 8, '2026-10-31' ) ), 'fr', 1 );

		$rows = $c->getBetween( 'me', '2026-11-01', '2026-11-30' );
		$this->assertSame( array( 1, 2, 3 ), array_map( 'intval', array_column( $rows, 'observation_id' ) ) );
		$this->assertSame( array(), $c->getBetween( 'nobody', '2026-11-01', '2026-11-30' ) );
	}

	public function test_get_between_prepares_its_values() {
		$this->cache()->getBetween( "x' OR '1'='1", '2026-11-01', '2026-11-30' );
		$this->assertStringContainsString( "user_login = 'x\\' OR \\'1\\'=\\'1'", end( $GLOBALS['wpdb']->queries ) );
	}

	public function test_an_attachment_can_be_linked_and_unlinked() {
		$c = $this->cache();
		$c->replaceRange( 'me', '2026-11-01', '2026-11-30', array( $this->observation( 1, '2026-11-05' ) ), 'fr', 1 );
		$c->setAttachment( 1, 77 );
		$this->assertSame( 77, $GLOBALS['wpdb']->rows[1]['attachment_id'] );
		$c->setAttachment( 1, null );
		$this->assertNull( $GLOBALS['wpdb']->rows[1]['attachment_id'] );
		$this->assertSame( array( array( 'attachment_id' => null ), array( 'observation_id' => 1 ), array( '%d' ), array( '%d' ) ), $GLOBALS['wpdb']->last_update, 'the ids are integers for $wpdb' );
	}

	public function test_latest_returns_the_newest_rows_of_the_login_first() {
		$c = $this->cache();
		$c->replaceRange( 'me', '2026-11-01', '2026-11-30', array( $this->observation( 1, '2026-11-05' ), $this->observation( 2, '2026-11-20' ), $this->observation( 3, '2026-11-10' ) ), 'fr', 1 );
		$c->replaceRange( 'other', '2026-11-01', '2026-11-30', array( $this->observation( 9, '2026-11-25' ) ), 'fr', 1 );
		$this->assertSame( array( 2, 3 ), array_map( 'intval', array_column( $c->latest( 'me', 2 ), 'observation_id' ) ) );
	}
}
