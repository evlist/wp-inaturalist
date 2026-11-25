<?php
/**
 * The Wikidata items of the taxa.
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
 * Reads and writes the table of the Wikidata items found for iNaturalist taxon ids. Only the refresh job writes to it.
 */
final class Taxa {

	/**
	 * WordPress database object.
	 *
	 * @var \wpdb
	 */
	private $db;

	/**
	 * Table name.
	 *
	 * @var string
	 */
	private $table;

	/**
	 * Constructor.
	 *
	 * @param \wpdb $db WordPress database object.
	 */
	public function __construct( $db ) {
		$this->db    = $db;
		$this->table = Config::taxaTableName( $db );
	}

	/**
	 * The comma-separated list of positive integers of a list (anything else is dropped), for an IN clause.
	 *
	 * @param array $ids Taxon ids.
	 * @return int[]
	 */
	private static function ints( array $ids ): array {
		return array_values(
			array_unique(
				array_filter(
					array_map( 'intval', $ids ),
					static function ( $id ) {
						return $id > 0;
					}
				)
			)
		);
	}

	/**
	 * The Wikidata item, label and Wikipedia address of the taxa that have an item.
	 *
	 * @param int[] $ids Taxon ids.
	 * @return array<int,array{qid:string,label:?string,wikipedia_url:?string}> By taxon id.
	 */
	public function get( array $ids ): array {
		$ids = self::ints( $ids );
		if ( array() === $ids ) {
			return array();
		}
		// The table name is built from the site prefix and a constant; the ids are integers.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $this->db->get_results( 'SELECT taxon_id, qid, label, wikipedia_url FROM ' . $this->table . ' WHERE qid IS NOT NULL AND taxon_id IN (' . implode( ',', $ids ) . ')', ARRAY_A );
		$out  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[ (int) $row['taxon_id'] ] = array(
				'qid'           => (string) $row['qid'],
				'label'         => isset( $row['label'] ) && '' !== $row['label'] ? (string) $row['label'] : null,
				'wikipedia_url' => isset( $row['wikipedia_url'] ) && '' !== $row['wikipedia_url'] ? (string) $row['wikipedia_url'] : null,
			);
		}
		return $out;
	}

	/**
	 * The taxa that have to be asked of Wikidata: unknown, known in another language, or checked too long ago.
	 *
	 * A taxon found is checked again after 90 days (an article or a label may have appeared), one with no item after 30 days.
	 *
	 * @param int[]  $ids  Taxon ids.
	 * @param string $lang Language of the labels and of the Wikipedia.
	 * @param int    $now  Current timestamp.
	 * @return int[]
	 */
	public function needingCheck( array $ids, string $lang, int $now ): array {
		$ids = self::ints( $ids );
		if ( array() === $ids ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows  = $this->db->get_results( 'SELECT taxon_id, lang, qid, checked_at FROM ' . $this->table . ' WHERE taxon_id IN (' . implode( ',', $ids ) . ')', ARRAY_A );
		$known = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$known[ (int) $row['taxon_id'] ] = $row;
		}
		$todo = array();
		foreach ( $ids as $id ) {
			if ( ! isset( $known[ $id ] ) || $known[ $id ]['lang'] !== $lang ) {
				$todo[] = $id;
				continue;
			}
			$ttl     = empty( $known[ $id ]['qid'] ) ? Config::TAXA_ABSENT_TTL : Config::TAXA_FOUND_TTL;
			$checked = strtotime( (string) $known[ $id ]['checked_at'] . ' UTC' );
			if ( false === $checked || $now - $checked >= $ttl ) {
				$todo[] = $id;
			}
		}
		return $todo;
	}

	/**
	 * Stores the answer for a taxon: the item found, or null when Wikidata has none.
	 *
	 * @param int         $taxonId Taxon id.
	 * @param string      $lang    Language asked.
	 * @param string|null $qid     Item, for example "Q829683".
	 * @param string|null $label   Label of the item in that language.
	 * @param string|null $url     Address of the Wikipedia article in that language.
	 * @param int         $now     Current timestamp.
	 * @throws \RuntimeException When the database refuses the write.
	 */
	public function save( int $taxonId, string $lang, ?string $qid, ?string $label, ?string $url, int $now ): void {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$ok = $this->db->replace(
			$this->table,
			array(
				'taxon_id'      => $taxonId,
				'lang'          => $lang,
				'qid'           => $qid,
				'label'         => $label,
				'wikipedia_url' => $url,
				'checked_at'    => gmdate( 'Y-m-d H:i:s', $now ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s' )
		);
		if ( false === $ok ) {
			throw new \RuntimeException( 'The database refused to store a taxon.' );
		}
	}

	/**
	 * Number of taxa with a Wikidata item.
	 */
	public function countFound(): int {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $this->db->get_var( 'SELECT COUNT(*) FROM ' . $this->table . ' WHERE qid IS NOT NULL' );
	}
}
