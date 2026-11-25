<?php
/**
 * Client for the Wikidata SPARQL endpoint: Wikidata items of iNaturalist taxa.
 *
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * @package WpInaturalist
 */

namespace Evlist\WpInaturalist;

// Exception messages are logged, never printed as is: the log screen escapes them where it displays them.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Asks Wikidata which item has a given iNaturalist taxon id (property P3151), with its label and Wikipedia article in one language.
 *
 * Only to be called from the refresh job, never from a page view. Only integers and a validated language code reach the query.
 */
final class WikidataClient {

	const ENDPOINT = 'https://query.wikidata.org/sparql';

	/**
	 * Throttle (its own transient: Wikidata has its own limits).
	 *
	 * @var Throttle
	 */
	private $throttle;

	/**
	 * HTTP function with the signature of wp_remote_get().
	 *
	 * @var callable
	 */
	private $http;

	/**
	 * Constructor.
	 *
	 * @param Throttle      $throttle Delay between requests.
	 * @param callable|null $http     Replaceable wp_remote_get() (tests).
	 */
	public function __construct( Throttle $throttle, ?callable $http = null ) {
		$this->throttle = $throttle;
		$this->http     = $http ? $http : 'wp_remote_get';
	}

	/**
	 * The SPARQL query for a list of taxon ids and a language.
	 *
	 * @param int[]  $ids  Taxon ids (positive integers).
	 * @param string $lang Language code of two or three letters.
	 * @return string
	 */
	public static function query( array $ids, string $lang ): string {
		$values = implode(
			' ',
			array_map(
				static function ( $id ) {
					return '"' . (int) $id . '"';
				},
				$ids
			)
		);
		return 'SELECT ?id ?item ?label ?article WHERE { VALUES ?id { ' . $values . ' } ?item wdt:P3151 ?id . '
			. 'OPTIONAL { ?item rdfs:label ?label . FILTER( LANG(?label) = "' . $lang . '" ) } '
			. 'OPTIONAL { ?article schema:about ?item ; schema:isPartOf <https://' . $lang . '.wikipedia.org/> . } }';
	}

	/**
	 * Items of a list of taxa. A taxon Wikidata does not know is simply absent from the answer.
	 *
	 * @param int[]  $taxonIds Taxon ids (at most {@see Config::TAXA_BATCH}).
	 * @param string $lang     Language of the label and of the Wikipedia (two or three lower case letters).
	 * @return array<int,array{qid:string,label:?string,wikipedia_url:?string}> By taxon id.
	 * @throws INatException On any failure.
	 * @throws \InvalidArgumentException On an invalid language or too many ids.
	 */
	public function lookup( array $taxonIds, string $lang ): array {
		$ids = array_values(
			array_unique(
				array_filter(
					array_map( 'intval', $taxonIds ),
					static function ( $id ) {
						return $id > 0;
					}
				)
			)
		);
		if ( 1 !== preg_match( '/^[a-z]{2,3}$/', $lang ) || count( $ids ) > Config::TAXA_BATCH ) {
			throw new \InvalidArgumentException( 'Invalid language or too many taxa.' );
		}
		if ( array() === $ids ) {
			return array();
		}
		$url                 = self::ENDPOINT . '?' . http_build_query(
			array(
				'query'  => self::query( $ids, $lang ),
				'format' => 'json',
			),
			'',
			'&',
			PHP_QUERY_RFC3986
		);
		list( $code, $body ) = Http::get( $this->throttle, $this->http, $url, 'application/sparql-results+json' );
		if ( 200 !== $code ) {
			throw new INatException( INatException::HTTP, 'Unexpected answer ' . $code . ' from Wikidata.' );
		}
		$data = json_decode( $body, true );
		if ( ! is_array( $data ) || ! isset( $data['results']['bindings'] ) || ! is_array( $data['results']['bindings'] ) ) {
			throw new INatException( INatException::MALFORMED, 'The answer of Wikidata is not a list of results.' );
		}
		return self::parse( $data['results']['bindings'], $ids, $lang );
	}

	/**
	 * Reads the bindings: only well-formed items and addresses are kept; with several items for one id the lowest number wins.
	 *
	 * @param array  $bindings Bindings of the answer.
	 * @param int[]  $asked    Taxon ids that were asked (an answer about another id is ignored).
	 * @param string $lang     Language asked.
	 * @return array<int,array{qid:string,label:?string,wikipedia_url:?string}>
	 */
	private static function parse( array $bindings, array $asked, string $lang ): array {
		$byId = array();
		foreach ( $bindings as $row ) {
			$id   = isset( $row['id']['value'] ) && is_string( $row['id']['value'] ) && ctype_digit( $row['id']['value'] ) ? (int) $row['id']['value'] : 0;
			$item = isset( $row['item']['value'] ) && is_string( $row['item']['value'] ) ? $row['item']['value'] : '';
			if ( ! in_array( $id, $asked, true ) || 1 !== preg_match( '#^http://www\.wikidata\.org/entity/(Q[1-9][0-9]{0,15})$#', $item, $m ) ) {
				continue;
			}
			$label   = isset( $row['label']['value'] ) && is_string( $row['label']['value'] ) && '' !== trim( $row['label']['value'] ) ? trim( $row['label']['value'] ) : null;
			$article = isset( $row['article']['value'] ) && is_string( $row['article']['value'] ) && 1 === preg_match( '#^https://' . preg_quote( $lang, '#' ) . '\.wikipedia\.org/wiki/\S+$#', $row['article']['value'] ) ? $row['article']['value'] : null;
			// The rows of one item are merged: an item may come back in several rows (OPTIONAL parts).
			$known                = isset( $byId[ $id ][ $m[1] ] ) ? $byId[ $id ][ $m[1] ] : array(
				'qid'           => $m[1],
				'label'         => null,
				'wikipedia_url' => null,
			);
			$byId[ $id ][ $m[1] ] = array(
				'qid'           => $m[1],
				'label'         => null === $known['label'] ? $label : $known['label'],
				'wikipedia_url' => null === $known['wikipedia_url'] ? $article : $known['wikipedia_url'],
			);
		}
		$out = array();
		foreach ( $byId as $id => $items ) {
			uasort(
				$items,
				static function ( $a, $b ) {
					return (int) substr( $a['qid'], 1 ) <=> (int) substr( $b['qid'], 1 );
				}
			);
			$out[ $id ] = reset( $items );
		}
		return $out;
	}
}
