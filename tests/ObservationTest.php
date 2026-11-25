<?php
/**
 * Tests of Observation.
 *
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * @package WpInaturalist
 */

namespace Evlist\WpInaturalist\Tests;

use Evlist\WpInaturalist\Observation;

require_once __DIR__ . '/TestCase.php';

/**
 * Mapping of an API result.
 */
final class ObservationTest extends TestCase {

	/**
	 * First result of the recorded answer.
	 */
	private function recorded(): array {
		$data = json_decode( file_get_contents( __DIR__ . '/fixtures/observations-page.json' ), true );
		return $data['results'][0];
	}

	public function test_a_recorded_observation_is_mapped() {
		$o = Observation::fromApi( $this->recorded() );
		$this->assertSame( 416889196, $o->id );
		$this->assertSame( '078b5038-1c3c-4e58-9f65-d82191179547', $o->uuid );
		$this->assertSame( 'https://www.inaturalist.org/observations/416889196', $o->uri );
		$this->assertSame( '2026-11-24', $o->observedOn );
		$this->assertSame( '2026-11-24T09:32:50-08:00', $o->timeObservedAt );
		$this->assertSame( 'America/Los_Angeles', $o->timeZone );
		$this->assertSame( 'research', $o->qualityGrade );
		$this->assertFalse( $o->obscured );
		$this->assertNull( $o->geoprivacy );
		$this->assertSame( 14886, $o->taxonId );
		$this->assertSame( 'Mimus polyglottos', $o->scientificName );
		$this->assertSame( 'moqueur polyglotte', $o->commonName );
		$this->assertSame( 'species', $o->rank );
		$this->assertSame( 'Aves', $o->iconicTaxon );
		$this->assertCount( 3, $o->photos );
		$this->assertSame( 767838489, $o->photos[0]['id'] );
		$this->assertSame( 'cc-by-nc', $o->photos[0]['license'] );
	}

	public function test_missing_fields_stay_null_and_are_not_invented() {
		$o = Observation::fromApi(
			array(
				'id'   => 1,
				'uuid' => 'u',
				'uri'  => 'https://example.test/1',
			)
		);
		$this->assertNull( $o->taxonId );
		$this->assertNull( $o->scientificName );
		$this->assertNull( $o->commonName );
		$this->assertNull( $o->observedOn );
		$this->assertSame( array(), $o->photos );
	}

	public function test_empty_common_name_is_null() {
		$row                                   = $this->recorded();
		$row['taxon']['preferred_common_name'] = '';
		$this->assertNull( Observation::fromApi( $row )->commonName );
	}

	public function test_a_photo_without_url_is_dropped() {
		$row           = $this->recorded();
		$row['photos'] = array( array( 'id' => 5 ), $row['photos'][0] );
		$this->assertCount( 1, Observation::fromApi( $row )->photos );
	}

	/**
	 * @dataProvider incomplete
	 * @param array $row Incomplete result.
	 */
	public function test_an_incomplete_observation_is_refused( array $row ) {
		$this->expectException( \InvalidArgumentException::class );
		Observation::fromApi( $row );
	}

	public function incomplete(): array {
		return array(
			'no id'          => array(
				array(
					'uuid' => 'u',
					'uri'  => 'x',
				),
			),
			'id is a string' => array(
				array(
					'id'   => '1',
					'uuid' => 'u',
					'uri'  => 'x',
				),
			),
			'no uuid'        => array(
				array(
					'id'  => 1,
					'uri' => 'x',
				),
			),
			'no uri'         => array(
				array(
					'id'   => 1,
					'uuid' => 'u',
				),
			),
		);
	}

	public function test_photo_url_changes_the_size() {
		$url = 'https://inaturalist-open-data.s3.amazonaws.com/photos/767838489/square.jpg';
		$this->assertSame( 'https://inaturalist-open-data.s3.amazonaws.com/photos/767838489/medium.jpg', Observation::photoUrl( $url ) );
		$this->assertSame( 'https://inaturalist-open-data.s3.amazonaws.com/photos/767838489/large.jpg', Observation::photoUrl( $url, 'large' ) );
		$this->assertSame( 'https://example.test/other.png', Observation::photoUrl( 'https://example.test/other.png' ), 'unknown shapes are left alone' );
	}
}
