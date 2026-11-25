<?php
/**
 * Tests of Taxa and TaxonResolver.
 *
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * @package WpInaturalist
 */

namespace Evlist\WpInaturalist\Tests;

use Evlist\WpInaturalist\Config;
use Evlist\WpInaturalist\INatException;
use Evlist\WpInaturalist\Taxa;
use Evlist\WpInaturalist\TaxonResolver;

require_once __DIR__ . '/TestCase.php';

/**
 * Which taxa are asked of Wikidata, and what is stored.
 */
final class TaxaTest extends TestCase {

	/**
	 * Current time of the tests.
	 */
	const NOW = 1764000000;

	private function taxa(): Taxa {
		return new Taxa( $GLOBALS['wpdb'] );
	}

	/**
	 * A fake client with scripted answers.
	 *
	 * @param array $answers Answers by call: arrays of items by id, or exceptions.
	 */
	private function client( array $answers ) {
		return new class( $answers ) {
			public $asked = array();
			private $answers;
			public function __construct( $answers ) {
				$this->answers = $answers;
			}
			public function lookup( $ids, $lang ) {
				$this->asked[] = array( $ids, $lang );
				$next          = array_shift( $this->answers );
				if ( $next instanceof \Throwable ) {
					throw $next;
				}
				return $next ? $next : array();
			}
		};
	}

	public function test_get_returns_only_taxa_with_an_item_cleaned() {
		$this->taxa()->save( 1, 'fr', 'Q10', 'Pie', 'https://fr.wikipedia.org/wiki/Pie', self::NOW );
		$this->taxa()->save( 2, 'fr', null, null, null, self::NOW );
		$this->taxa()->save( 3, 'fr', 'Q30', '', '', self::NOW );
		$this->assertSame(
			array(
				1 => array(
					'qid'           => 'Q10',
					'label'         => 'Pie',
					'wikipedia_url' => 'https://fr.wikipedia.org/wiki/Pie',
				),
				3 => array(
					'qid'           => 'Q30',
					'label'         => null,
					'wikipedia_url' => null,
				),
			),
			$this->taxa()->get( array( 1, 2, 3, 4 ) )
		);
		$this->assertSame( array(), $this->taxa()->get( array() ) );
		$this->assertSame( 2, $this->taxa()->countFound() );
	}

	public function test_ids_reach_the_query_only_as_integers() {
		$this->taxa()->get( array( '5 OR 1=1', 7, -1, 'x' ) );
		$this->assertStringContainsString( 'taxon_id IN (5,7)', end( $GLOBALS['wpdb']->queries ) );
	}

	public function test_unknown_or_other_language_taxa_need_a_check() {
		$this->taxa()->save( 1, 'fr', 'Q10', null, null, self::NOW );
		$this->taxa()->save( 2, 'en', 'Q20', null, null, self::NOW );
		$this->assertSame( array( 2, 3 ), $this->taxa()->needingCheck( array( 1, 2, 3 ), 'fr', self::NOW + 10 ) );
	}

	public function test_a_found_taxon_is_checked_again_after_90_days_and_an_absent_one_after_30() {
		$this->taxa()->save( 1, 'fr', 'Q10', null, null, self::NOW );
		$this->taxa()->save( 2, 'fr', null, null, null, self::NOW );
		$this->assertSame( array(), $this->taxa()->needingCheck( array( 1, 2 ), 'fr', self::NOW + Config::TAXA_ABSENT_TTL - 1 ) );
		$this->assertSame( array( 2 ), $this->taxa()->needingCheck( array( 1, 2 ), 'fr', self::NOW + Config::TAXA_ABSENT_TTL ) );
		$this->assertSame( array( 1, 2 ), $this->taxa()->needingCheck( array( 1, 2 ), 'fr', self::NOW + Config::TAXA_FOUND_TTL ) );
	}

	public function test_a_refused_write_is_an_error() {
		$GLOBALS['wpdb']->fail_replace = true;
		$this->expectException( \RuntimeException::class );
		$this->taxa()->save( 1, 'fr', 'Q10', null, null, self::NOW );
	}

	public function test_the_resolver_asks_only_for_what_is_needed_and_stores_absences() {
		$this->taxa()->save( 1, 'fr', 'Q10', 'Pie', null, self::NOW );
		$client   = $this->client(
			array(
				array(
					2 => array(
						'qid'           => 'Q20',
						'label'         => 'Merle',
						'wikipedia_url' => 'https://fr.wikipedia.org/wiki/Merle',
					),
				),
			)
		);
		$resolver = new TaxonResolver( $this->taxa(), $client );

		$stats = $resolver->resolve( array( 1, 2, 3 ), 'fr', self::NOW + 5 );

		$this->assertSame( array( array( array( 2, 3 ), 'fr' ) ), $client->asked );
		$this->assertSame(
			array(
				'found'  => 1,
				'absent' => 1,
			),
			$stats
		);
		$this->assertSame( 'Q20', $GLOBALS['wpdb']->taxa_rows[2]['qid'] );
		$this->assertArrayHasKey( 3, $GLOBALS['wpdb']->taxa_rows );
		$this->assertNull( $GLOBALS['wpdb']->taxa_rows[3]['qid'], 'a taxon with no item is recorded, not asked again' );

		$again = $this->client( array() );
		( new TaxonResolver( $this->taxa(), $again ) )->resolve( array( 1, 2, 3 ), 'fr', self::NOW + 6 );
		$this->assertSame( array(), $again->asked, 'nothing is asked twice' );
	}

	public function test_a_change_of_language_asks_again() {
		$this->taxa()->save( 1, 'fr', 'Q10', 'Pie', null, self::NOW );
		$client = $this->client(
			array(
				array(
					1 => array(
						'qid'           => 'Q10',
						'label'         => 'Magpie',
						'wikipedia_url' => null,
					),
				),
			)
		);
		( new TaxonResolver( $this->taxa(), $client ) )->resolve( array( 1 ), 'en', self::NOW + 1 );
		$this->assertSame( array( array( array( 1 ), 'en' ) ), $client->asked );
		$this->assertSame( 'en', $GLOBALS['wpdb']->taxa_rows[1]['lang'] );
	}

	public function test_taxa_are_asked_in_batches_and_answers_before_a_failure_are_kept() {
		$ids   = range( 1, Config::TAXA_BATCH + 10 );
		$first = array();
		foreach ( range( 1, Config::TAXA_BATCH ) as $id ) {
			$first[ $id ] = array(
				'qid'           => 'Q' . $id,
				'label'         => null,
				'wikipedia_url' => null,
			);
		}
		$client = $this->client( array( $first, new INatException( INatException::SERVER, 'down' ) ) );
		try {
			( new TaxonResolver( $this->taxa(), $client ) )->resolve( $ids, 'fr', self::NOW );
			$this->fail( 'expected the failure to come out' );
		} catch ( INatException $e ) {
			$this->assertSame( INatException::SERVER, $e->kind );
		}
		$this->assertSame( array( Config::TAXA_BATCH, 10 ), array_map( 'count', array_column( $client->asked, 0 ) ) );
		$this->assertCount( Config::TAXA_BATCH, $GLOBALS['wpdb']->taxa_rows, 'the first batch is stored' );
		$this->assertArrayNotHasKey( Config::TAXA_BATCH + 1, $GLOBALS['wpdb']->taxa_rows, 'the failed batch is not marked as checked' );
	}
}
