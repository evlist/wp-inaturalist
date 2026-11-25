<?php
/**
 * Tests of WikidataClient, with a recorded real answer and no network.
 *
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * @package WpInaturalist
 */

namespace Evlist\WpInaturalist\Tests;

use Evlist\WpInaturalist\INatException;
use Evlist\WpInaturalist\Throttle;
use Evlist\WpInaturalist\WikidataClient;

require_once __DIR__ . '/TestCase.php';

/**
 * Query, parsing and errors.
 */
final class WikidataClientTest extends TestCase {

	/**
	 * Requests seen by the fake HTTP function.
	 *
	 * @var array
	 */
	private $requests = array();

	private function client( array $responses ): WikidataClient {
		$throttle = new Throttle(
			0.0,
			static function () {
				return 1.0;
			},
			static function () {},
			Throttle::WIKIDATA_TRANSIENT
		);
		return new WikidataClient(
			$throttle,
			function ( $url, $args ) use ( &$responses ) {
				$this->requests[] = array( $url, $args );
				return array_shift( $responses );
			}
		);
	}

	private function ok( $body ): array {
		return array(
			'response' => array( 'code' => 200 ),
			'body'     => is_array( $body ) ? json_encode( $body ) : $body,
		);
	}

	/**
	 * A SPARQL answer with these bindings.
	 *
	 * @param array $rows List of arrays id, item, label, article (values or null).
	 */
	private function answer( array $rows ): array {
		$bindings = array();
		foreach ( $rows as $r ) {
			$b = array(
				'id'   => array(
					'type'  => 'literal',
					'value' => (string) $r[0],
				),
				'item' => array(
					'type'  => 'uri',
					'value' => $r[1],
				),
			);
			if ( isset( $r[2] ) ) {
				$b['label'] = array(
					'type'  => 'literal',
					'value' => $r[2],
				);
			}
			if ( isset( $r[3] ) ) {
				$b['article'] = array(
					'type'  => 'uri',
					'value' => $r[3],
				);
			}
			$bindings[] = $b;
		}
		return array( 'results' => array( 'bindings' => $bindings ) );
	}

	public function test_the_recorded_real_answer_is_read() {
		$found = $this->client( array( $this->ok( file_get_contents( __DIR__ . '/fixtures/wikidata-taxa.json' ) ) ) )->lookup( array( 14886, 9083, 999999999 ), 'fr' );
		$this->assertSame(
			array(
				9083  => array(
					'qid'           => 'Q726389',
					'label'         => 'Cardinal rouge',
					'wikipedia_url' => 'https://fr.wikipedia.org/wiki/Cardinal_rouge',
				),
				14886 => array(
					'qid'           => 'Q829683',
					'label'         => 'Moqueur polyglotte',
					'wikipedia_url' => 'https://fr.wikipedia.org/wiki/Moqueur_polyglotte',
				),
			),
			$found,
			'a taxon Wikidata does not know is absent'
		);
	}

	public function test_the_request_carries_the_query_the_headers_and_nothing_else() {
		$this->client( array( $this->ok( array( 'results' => array( 'bindings' => array() ) ) ) ) )->lookup( array( 14886, 9083 ), 'fr' );
		list( $url, $args ) = $this->requests[0];
		$this->assertStringStartsWith( 'https://query.wikidata.org/sparql?', $url );
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
		$this->assertSame( 'json', $q['format'] );
		$this->assertStringContainsString( 'VALUES ?id { "14886" "9083" }', $q['query'] );
		$this->assertStringContainsString( '?item wdt:P3151 ?id', $q['query'] );
		$this->assertStringContainsString( 'LANG(?label) = "fr"', $q['query'] );
		$this->assertStringContainsString( 'schema:isPartOf <https://fr.wikipedia.org/>', $q['query'] );
		$this->assertSame( 'application/sparql-results+json', $args['headers']['Accept'] );
		$this->assertStringContainsString( 'wp-inaturalist/', $args['user-agent'] );
	}

	public function test_only_integers_and_a_language_code_can_reach_the_query() {
		$this->client( array( $this->ok( array( 'results' => array( 'bindings' => array() ) ) ) ) )->lookup( array( '5"} INJECT {"', 7, -3, 0, 'x', '12abc' ), 'fr' );
		parse_str( (string) wp_parse_url( $this->requests[0][0], PHP_URL_QUERY ), $q );
		$this->assertStringContainsString( 'VALUES ?id { "5" "7" "12" }', $q['query'] );
		$this->assertStringNotContainsString( 'INJECT', $q['query'] );
	}

	/**
	 * @dataProvider badLanguages
	 * @param string $lang Language.
	 */
	public function test_an_invalid_language_is_refused_before_any_request( string $lang ) {
		try {
			$this->client( array() )->lookup( array( 1 ), $lang );
			$this->fail( 'expected an exception' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertSame( array(), $this->requests );
		}
	}

	public function badLanguages(): array {
		return array(
			'empty'      => array( '' ),
			'upper case' => array( 'FR' ),
			'region'     => array( 'fr_FR' ),
			'quote'      => array( 'fr" ) } #' ),
			'host'       => array( 'fr.evil.test/x' ),
		);
	}

	public function test_too_many_ids_are_refused() {
		$this->expectException( \InvalidArgumentException::class );
		$this->client( array() )->lookup( range( 1, 51 ), 'fr' );
	}

	public function test_no_id_means_no_request() {
		$this->assertSame( array(), $this->client( array() )->lookup( array(), 'fr' ) );
		$this->assertSame( array(), $this->requests );
	}

	public function test_with_several_items_for_one_taxon_the_lowest_number_wins_and_rows_of_an_item_are_merged() {
		$answer = $this->answer(
			array(
				array( 5, 'http://www.wikidata.org/entity/Q900', 'Autre' ),
				array( 5, 'http://www.wikidata.org/entity/Q100', 'Premier', null ),
				array( 5, 'http://www.wikidata.org/entity/Q100', null, 'https://fr.wikipedia.org/wiki/Premier' ),
			)
		);
		$found  = $this->client( array( $this->ok( $answer ) ) )->lookup( array( 5 ), 'fr' );
		$this->assertSame(
			array(
				5 => array(
					'qid'           => 'Q100',
					'label'         => 'Premier',
					'wikipedia_url' => 'https://fr.wikipedia.org/wiki/Premier',
				),
			),
			$found
		);
	}

	public function test_malformed_items_labels_and_addresses_are_not_trusted() {
		$answer = $this->answer(
			array(
				array( 1, 'http://www.wikidata.org/entity/Q0' ),
				array( 2, 'http://www.wikidata.org/entity/Qabc' ),
				array( 3, 'https://evil.test/entity/Q5' ),
				array( 4, 'http://www.wikidata.org/entity/Q5/../x' ),
				array( 6, 'http://www.wikidata.org/entity/Q7', '   ', 'https://en.wikipedia.org/wiki/Wrong_language' ),
				array( 7, 'http://www.wikidata.org/entity/Q8', 'Ok', 'javascript:alert(1)' ),
				array( 99, 'http://www.wikidata.org/entity/Q9' ),
			)
		);
		$found  = $this->client( array( $this->ok( $answer ) ) )->lookup( array( 1, 2, 3, 4, 6, 7 ), 'fr' );
		$this->assertSame(
			array(
				6 => array(
					'qid'           => 'Q7',
					'label'         => null,
					'wikipedia_url' => null,
				),
				7 => array(
					'qid'           => 'Q8',
					'label'         => 'Ok',
					'wikipedia_url' => null,
				),
			),
			$found,
			'an answer about a taxon that was not asked (99) is ignored too'
		);
	}

	public function test_errors_are_typed_like_those_of_inaturalist() {
		foreach ( array(
			429 => INatException::RATE_LIMITED,
			503 => INatException::SERVER,
			400 => INatException::HTTP,
		) as $code => $kind ) {
			try {
				$this->client(
					array(
						array(
							'response' => array( 'code' => $code ),
							'body'     => 'x',
							'headers'  => array( 'retry-after' => '30' ),
						),
					)
				)->lookup( array( 1 ), 'fr' );
				$this->fail( 'expected an exception for ' . $code );
			} catch ( INatException $e ) {
				$this->assertSame( $kind, $e->kind, (string) $code );
			}
		}
		try {
			$this->client( array( new \WP_Error( 'http_request_failed', 'timeout' ) ) )->lookup( array( 1 ), 'fr' );
			$this->fail( 'expected an exception' );
		} catch ( INatException $e ) {
			$this->assertSame( INatException::NETWORK, $e->kind );
		}
	}

	/**
	 * @dataProvider malformed
	 * @param string $body Body of a 200 answer.
	 */
	public function test_a_malformed_answer_is_an_error_not_an_empty_result( string $body ) {
		try {
			$this->client( array( $this->ok( $body ) ) )->lookup( array( 1 ), 'fr' );
			$this->fail( 'expected an exception' );
		} catch ( INatException $e ) {
			$this->assertSame( INatException::MALFORMED, $e->kind );
		}
	}

	public function malformed(): array {
		return array(
			'not json'            => array( '<html>' ),
			'no results'          => array( '{"head":{}}' ),
			'bindings not a list' => array( '{"results":{"bindings":"x"}}' ),
		);
	}

	public function test_the_query_builder_casts_its_ids_even_when_called_directly() {
		$query = WikidataClient::query( array( '5"} INJECT {"', 7 ), 'fr' );
		$this->assertStringContainsString( 'VALUES ?id { "5" "7" }', $query );
		$this->assertStringNotContainsString( 'INJECT', $query );
	}
}
