<?php
/**
 * Finds media library photos by the time they were taken.
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
 * Looks in the metadata WordPress keeps for every image (`_wp_attachment_metadata`, key `image_meta.created_timestamp`).
 *
 * That timestamp is the wall-clock time of the shot read from the EXIF data, stored as if it were UTC. The metadata is a
 * serialized array, so one query narrows the candidates with a LIKE on the first digits of the timestamp, and the exact
 * comparison is done in PHP. Nothing is indexed or copied: no backfill is needed.
 */
final class AttachmentIndex {

	/**
	 * WordPress database object.
	 *
	 * @var \wpdb
	 */
	private $db;

	/**
	 * Constructor.
	 *
	 * @param \wpdb $db WordPress database object.
	 */
	public function __construct( $db ) {
		$this->db = $db;
	}

	/**
	 * Image attachments taken within $tolerance seconds of a wall-clock time, closest first (then lowest id).
	 *
	 * @param int $timestamp Wall-clock time of the observation, as a timestamp read as UTC.
	 * @param int $tolerance Tolerance in seconds.
	 * @return array<int,array{id:int,diff:int}>
	 */
	public function candidates( int $timestamp, int $tolerance ): array {
		if ( $timestamp < 100000 ) {
			return array();
		}
		// A 5-digit prefix covers a window of 100000 s: two prefixes when the tolerance straddles a boundary.
		$prefixes = array_unique( array( substr( (string) ( $timestamp - $tolerance ), 0, 5 ), substr( (string) ( $timestamp + $tolerance ), 0, 5 ) ) );
		$likes    = array();
		$args     = array( '_wp_attachment_metadata', 'attachment', 'image/%' );
		foreach ( $prefixes as $prefix ) {
			$likes[] = 'pm.meta_value LIKE %s';
			$args[]  = '%created_timestamp";%' . $this->db->esc_like( $prefix ) . '%';
		}
		// Table names come from $wpdb; every value is prepared.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $this->db->get_results( $this->db->prepare( "SELECT pm.post_id, pm.meta_value FROM {$this->db->postmeta} pm INNER JOIN {$this->db->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND p.post_type = %s AND p.post_mime_type LIKE %s AND (" . implode( ' OR ', $likes ) . ')', ...$args ) );

		$found = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$meta = maybe_unserialize( $row->meta_value );
			if ( ! is_array( $meta ) || ! isset( $meta['image_meta']['created_timestamp'] ) || ! is_numeric( $meta['image_meta']['created_timestamp'] ) ) {
				continue;
			}
			$diff = abs( (int) $meta['image_meta']['created_timestamp'] - $timestamp );
			if ( $diff <= $tolerance ) {
				$found[] = array(
					'id'   => (int) $row->post_id,
					'diff' => $diff,
				);
			}
		}
		usort(
			$found,
			static function ( $a, $b ) {
				return array( $a['diff'], $a['id'] ) <=> array( $b['diff'], $b['id'] );
			}
		);
		return $found;
	}

	/**
	 * The image attachment a user linked by hand to an observation, or null.
	 *
	 * @param int $observationId Observation id.
	 */
	public function manual( int $observationId ): ?int {
		// Table names come from $wpdb; every value is prepared.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$id = $this->db->get_var( $this->db->prepare( "SELECT pm.post_id FROM {$this->db->postmeta} pm INNER JOIN {$this->db->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND pm.meta_value = %s AND p.post_type = %s AND p.post_mime_type LIKE %s ORDER BY pm.post_id ASC LIMIT 1", Config::MANUAL_LINK_META, (string) $observationId, 'attachment', 'image/%' ) );
		return $id ? (int) $id : null;
	}
}
