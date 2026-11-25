<?php
/**
 * The local copy of the observations.
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
 * Reads and writes the cache table. Only the refresh job writes to it.
 */
final class Cache {

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
		$this->table = Config::tableName( $db );
	}

	/**
	 * Stores what the API returned for a date range and removes the rows of that range it no longer returns.
	 *
	 * @param string        $login        iNaturalist login.
	 * @param string        $from         First day (Y-m-d).
	 * @param string        $to           Last day (Y-m-d).
	 * @param Observation[] $observations Observations returned for the range.
	 * @param string        $locale       Language of the common names.
	 * @param int           $now          Timestamp of the fetch.
	 * @throws \RuntimeException When the database refuses a write.
	 */
	public function replaceRange( string $login, string $from, string $to, array $observations, string $locale, int $now ): void {
		$ids = array();
		foreach ( $observations as $o ) {
			$ids[] = $o->id;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$ok = $this->db->replace(
				$this->table,
				array(
					'observation_id'   => $o->id,
					'user_login'       => $login,
					'uuid'             => $o->uuid,
					'uri'              => $o->uri,
					'observed_on'      => $o->observedOn,
					'time_observed_at' => $o->timeObservedAt,
					'time_zone'        => $o->timeZone,
					'quality_grade'    => $o->qualityGrade,
					'taxon_id'         => $o->taxonId,
					'scientific_name'  => $o->scientificName,
					'common_name'      => $o->commonName,
					'names_locale'     => $locale,
					'taxon_rank'       => $o->rank,
					'iconic_taxon'     => $o->iconicTaxon,
					'wikipedia_url'    => $o->wikipediaUrl,
					'photos'           => wp_json_encode( $o->photos ),
					'fetched_at'       => gmdate( 'Y-m-d H:i:s', $now ),
				),
				array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
			);
			if ( false === $ok ) {
				throw new \RuntimeException( 'The database refused to store an observation.' );
			}
		}

		$keep = '';
		if ( array() !== $ids ) {
			$keep = ' AND observation_id NOT IN (' . implode( ',', array_map( 'intval', $ids ) ) . ')';
		}
		// The table name is built from the site prefix and a constant; the ids are integers.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$ok = $this->db->query( $this->db->prepare( "DELETE FROM {$this->table} WHERE user_login = %s AND observed_on BETWEEN %s AND %s{$keep}", $login, $from, $to ) );
		if ( false === $ok ) {
			throw new \RuntimeException( 'The database refused to remove old observations.' );
		}
	}

	/**
	 * Cached observations of a user over a date range, oldest first.
	 *
	 * @param string $login iNaturalist login.
	 * @param string $from  First day (Y-m-d).
	 * @param string $to    Last day (Y-m-d).
	 * @return array<int,array<string,mixed>> Rows of the table.
	 */
	public function getBetween( string $login, string $from, string $to ): array {
		// The table name is built from the site prefix and a constant; the values are prepared.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $this->db->get_results( $this->db->prepare( "SELECT * FROM {$this->table} WHERE user_login = %s AND observed_on BETWEEN %s AND %s ORDER BY observed_on ASC, observation_id ASC", $login, $from, $to ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Records which media library attachment shows an observation (null for none).
	 *
	 * @param int      $observationId Observation id.
	 * @param int|null $attachmentId  Attachment id.
	 */
	public function setAttachment( int $observationId, ?int $attachmentId ): void {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->db->update( $this->table, array( 'attachment_id' => $attachmentId ), array( 'observation_id' => $observationId ), array( '%d' ), array( '%d' ) );
	}

	/**
	 * The latest cached observations of a user, newest first.
	 *
	 * @param string $login iNaturalist login.
	 * @param int    $limit Maximal number of rows.
	 * @return array<int,array<string,mixed>> Rows of the table.
	 */
	public function latest( string $login, int $limit ): array {
		// The table name is built from the site prefix and a constant; the values are prepared.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $this->db->get_results( $this->db->prepare( "SELECT * FROM {$this->table} WHERE user_login = %s ORDER BY observed_on DESC, observation_id DESC LIMIT %d", $login, $limit ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Number of cached observations.
	 */
	public function count(): int {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $this->db->get_var( "SELECT COUNT(*) FROM {$this->table}" );
	}

	/**
	 * Empties the cache.
	 */
	public function clear(): void {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->db->query( "DELETE FROM {$this->table}" );
	}
}
