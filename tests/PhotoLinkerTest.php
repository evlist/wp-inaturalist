<?php
/**
 * Tests of PhotoLinker.
 *
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * @package WpInaturalist
 */

namespace Evlist\WpInaturalist\Tests;

use Evlist\WpInaturalist\Cache;
use Evlist\WpInaturalist\Config;
use Evlist\WpInaturalist\PhotoLinker;

require_once __DIR__ . '/TestCase.php';

/**
 * Choice of the media library photo of each observation.
 */
final class PhotoLinkerTest extends TestCase {

	/**
	 * Fake media library index: manual links and candidates by time.
	 *
	 * @var object
	 */
	private $index;

	protected function setUp(): void {
		parent::setUp();
		$this->index = new class() {
			public $manual     = array();
			public $candidates = array();
			public $asked      = array();
			public function manual( $id ) {
				$this->asked[] = array( 'manual', $id );
				return isset( $this->manual[ $id ] ) ? $this->manual[ $id ] : null;
			}
			public function candidates( $time, $tolerance ) {
				$this->asked[] = array( 'candidates', $time, $tolerance );
				return isset( $this->candidates[ $time ] ) ? $this->candidates[ $time ] : array();
			}
		};
	}

	private function linker(): PhotoLinker {
		return new PhotoLinker( new Cache( $GLOBALS['wpdb'] ), $this->index );
	}

	/**
	 * Stores an observation with a time of day in the cache.
	 *
	 * @param int         $id   Observation id.
	 * @param string|null $time time_observed_at.
	 */
	private function store( int $id, ?string $time, string $day = '2026-11-20' ): void {
		$o                 = $this->observation( $id, $day );
		$o->timeObservedAt = $time;
		( new Cache( $GLOBALS['wpdb'] ) )->replaceRange( 'me', '2026-11-01', '2026-11-30', array_merge( $this->current(), array( $o ) ), 'fr', 1 );
	}

	/**
	 * Observations already stored (rebuilt from the fake table so that replaceRange keeps them).
	 *
	 * @return array
	 */
	private function current(): array {
		$out = array();
		foreach ( $GLOBALS['wpdb']->rows as $row ) {
			$o                 = $this->observation( (int) $row['observation_id'], $row['observed_on'] );
			$o->timeObservedAt = $row['time_observed_at'];
			$out[]             = $o;
		}
		return $out;
	}

	/**
	 * @dataProvider wallClocks
	 * @param mixed    $iso      time_observed_at.
	 * @param int|null $expected Timestamp read as UTC.
	 */
	public function test_wall_clock( $iso, ?int $expected ) {
		$this->assertSame( $expected, PhotoLinker::wallClock( $iso ) );
	}

	public function wallClocks(): array {
		return array(
			'with a negative offset' => array( '2026-11-24T09:32:50-08:00', gmmktime( 9, 32, 50, 11, 24, 2026 ) ),
			'with a positive offset' => array( '2026-11-24T09:32:50+10:00', gmmktime( 9, 32, 50, 11, 24, 2026 ) ),
			'zulu'                   => array( '2026-11-24T09:32:50Z', gmmktime( 9, 32, 50, 11, 24, 2026 ) ),
			'a space instead of T'   => array( '2026-11-24 09:32:50', gmmktime( 9, 32, 50, 11, 24, 2026 ) ),
			'a date only'            => array( '2026-11-24', null ),
			'null'                   => array( null, null ),
			'garbage'                => array( 'yesterday', null ),
			'an impossible date'     => array( '2026-13-45T25:61:61+00:00', null ),
			'not a string'           => array( array( 'x' ), null ),
		);
	}

	public function test_a_manual_link_wins_over_the_time_match() {
		$this->index->manual[5]                                       = 900;
		$this->index->candidates[ gmmktime( 9, 0, 0, 11, 20, 2026 ) ] = array(
			array(
				'id'   => 100,
				'diff' => 0,
			),
		);
		$choice = $this->linker()->choose(
			array(
				'observation_id'   => 5,
				'time_observed_at' => '2026-11-20T09:00:00+01:00',
			)
		);
		$this->assertSame(
			array(
				'id'  => 900,
				'how' => 'manual',
			),
			$choice
		);
		$this->assertCount( 1, $this->index->asked, 'the time is not even looked up' );
	}

	public function test_the_closest_photo_is_chosen_within_the_tolerance() {
		$this->index->candidates[ gmmktime( 9, 0, 0, 11, 20, 2026 ) ] = array(
			array(
				'id'   => 100,
				'diff' => 0,
			),
			array(
				'id'   => 101,
				'diff' => 1,
			),
		);
		$choice = $this->linker()->choose(
			array(
				'observation_id'   => 5,
				'time_observed_at' => '2026-11-20T09:00:00+01:00',
			)
		);
		$this->assertSame(
			array(
				'id'  => 100,
				'how' => 'auto',
			),
			$choice
		);
		$this->assertSame( array( 'candidates', gmmktime( 9, 0, 0, 11, 20, 2026 ), Config::PHOTO_TOLERANCE_SEC ), $this->index->asked[1] );
	}

	public function test_without_a_time_of_day_nothing_is_guessed() {
		$choice = $this->linker()->choose(
			array(
				'observation_id'   => 5,
				'time_observed_at' => null,
			)
		);
		$this->assertSame(
			array(
				'id'  => null,
				'how' => 'no_time',
			),
			$choice
		);
		$this->assertSame( array( array( 'manual', 5 ) ), $this->index->asked, 'no query on the media library by time' );
	}

	public function test_no_photo_at_that_time() {
		$choice = $this->linker()->choose(
			array(
				'observation_id'   => 5,
				'time_observed_at' => '2026-11-20T09:00:00+01:00',
			)
		);
		$this->assertSame(
			array(
				'id'  => null,
				'how' => 'no_match',
			),
			$choice
		);
	}

	public function test_link_range_stores_the_attachment_of_each_matched_observation() {
		$this->store( 1, '2026-11-20T09:00:00+01:00' );
		$this->store( 2, '2026-11-20T10:00:00+01:00' );
		$this->store( 3, null );
		$this->index->candidates[ gmmktime( 9, 0, 0, 11, 20, 2026 ) ] = array(
			array(
				'id'   => 100,
				'diff' => 0,
			),
		);
		$this->index->manual[2]                                       = 200;

		$this->assertSame( 2, $this->linker()->linkRange( 'me', '2026-11-01', '2026-11-30' ) );
		$this->assertSame( 100, $GLOBALS['wpdb']->rows[1]['attachment_id'] );
		$this->assertSame( 200, $GLOBALS['wpdb']->rows[2]['attachment_id'] );
		$this->assertArrayNotHasKey( 'attachment_id', $GLOBALS['wpdb']->rows[3] );
	}

	public function test_observations_that_will_not_be_shown_are_not_linked() {
		$this->store( 1, '2026-11-20T09:00:00+01:00' );
		$this->store( 2, '2026-11-20T10:00:00+01:00' );
		$GLOBALS['wpdb']->rows[1]['taxon_id']                          = null;
		$GLOBALS['wpdb']->rows[2]['photos']                            = '[]';
		$this->index->candidates[ gmmktime( 9, 0, 0, 11, 20, 2026 ) ]  = array(
			array(
				'id'   => 100,
				'diff' => 0,
			),
		);
		$this->index->candidates[ gmmktime( 10, 0, 0, 11, 20, 2026 ) ] = array(
			array(
				'id'   => 101,
				'diff' => 0,
			),
		);
		$this->assertSame( 0, $this->linker()->linkRange( 'me', '2026-11-01', '2026-11-30' ) );
		$this->assertSame( array(), $this->index->asked, 'and the media library is not even asked' );
	}

	public function test_nothing_is_done_when_the_option_is_off() {
		update_option( Config::USE_MEDIA, 0 );
		$this->store( 1, '2026-11-20T09:00:00+01:00' );
		$this->index->manual[1] = 200;
		$this->assertSame( 0, $this->linker()->linkRange( 'me', '2026-11-01', '2026-11-30' ) );
		$this->assertSame( array(), $this->index->asked );
	}

	public function test_has_photo() {
		$this->assertTrue( PhotoLinker::hasPhoto( array( 'photos' => '[{"id":1,"url":"https://x/square.jpg"}]' ) ) );
		foreach ( array( '[]', 'junk', null, '[{"id":1}]' ) as $bad ) {
			$this->assertFalse( PhotoLinker::hasPhoto( array( 'photos' => $bad ) ) );
		}
		$this->assertFalse( PhotoLinker::hasPhoto( array() ) );
	}

	public function test_link_rows_links_only_the_given_rows() {
		$this->store( 1, '2026-11-20T09:00:00+01:00' );
		$this->store( 2, '2026-11-20T10:00:00+01:00' );
		$this->index->candidates[ gmmktime( 9, 0, 0, 11, 20, 2026 ) ]  = array(
			array(
				'id'   => 100,
				'diff' => 0,
			),
		);
		$this->index->candidates[ gmmktime( 10, 0, 0, 11, 20, 2026 ) ] = array(
			array(
				'id'   => 101,
				'diff' => 0,
			),
		);
		$cache = new Cache( $GLOBALS['wpdb'] );
		$this->assertSame( 1, $this->linker()->linkRows( $cache->getByIds( array( 2 ) ) ) );
		$this->assertArrayNotHasKey( 'attachment_id', $GLOBALS['wpdb']->rows[1] );
		$this->assertSame( 101, $GLOBALS['wpdb']->rows[2]['attachment_id'] );
	}

	public function test_link_rows_does_nothing_when_the_option_is_off() {
		update_option( Config::USE_MEDIA, 0 );
		$this->store( 1, '2026-11-20T09:00:00+01:00' );
		$this->assertSame( 0, $this->linker()->linkRows( ( new Cache( $GLOBALS['wpdb'] ) )->getByIds( array( 1 ) ) ) );
		$this->assertSame( array(), $this->index->asked );
	}
}
