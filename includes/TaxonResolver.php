<?php
/**
 * Finds and stores the Wikidata items of the taxa of the observations.
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
 * Asks Wikidata, by batches, only for the taxa that need it, and records the answers (including "no item").
 */
final class TaxonResolver {

	/**
	 * Table of the taxa.
	 *
	 * @var Taxa
	 */
	private $taxa;

	/**
	 * Client with lookup() (see {@see WikidataClient}).
	 *
	 * @var object
	 */
	private $client;

	/**
	 * Constructor.
	 *
	 * @param Taxa   $taxa   Table of the taxa.
	 * @param object $client Object with lookup( array $ids, string $lang ).
	 */
	public function __construct( Taxa $taxa, $client ) {
		$this->taxa   = $taxa;
		$this->client = $client;
	}

	/**
	 * Resolves the taxa that need it. Answers already stored stay if a later batch fails.
	 *
	 * @param int[]  $taxonIds Taxon ids.
	 * @param string $lang     Language of the labels and of the Wikipedia.
	 * @param int    $now      Current timestamp.
	 * @return array{found:int,absent:int} Counts for this call.
	 * @throws INatException When Wikidata fails.
	 */
	public function resolve( array $taxonIds, string $lang, int $now ): array {
		$stats = array(
			'found'  => 0,
			'absent' => 0,
		);
		$todo  = $this->taxa->needingCheck( $taxonIds, $lang, $now );
		foreach ( array_chunk( $todo, Config::TAXA_BATCH ) as $batch ) {
			$answers = $this->client->lookup( $batch, $lang );
			foreach ( $batch as $id ) {
				if ( isset( $answers[ $id ] ) ) {
					$this->taxa->save( $id, $lang, $answers[ $id ]['qid'], $answers[ $id ]['label'], $answers[ $id ]['wikipedia_url'], $now );
					++$stats['found'];
				} else {
					$this->taxa->save( $id, $lang, null, null, null, $now );
					++$stats['absent'];
				}
			}
		}
		return $stats;
	}
}
