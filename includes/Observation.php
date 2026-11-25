<?php
/**
 * One observation, as returned by the iNaturalist API.
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
 * Plain value object. A field absent from the answer is null (or an empty list), never invented.
 * Properties are public and meant to be read only.
 */
final class Observation {

	/**
	 * Observation id.
	 *
	 * @var int
	 */
	public $id = 0;

	/**
	 * Observation uuid.
	 *
	 * @var string
	 */
	public $uuid = '';

	/**
	 * Page of the observation on iNaturalist.
	 *
	 * @var string
	 */
	public $uri = '';

	/**
	 * Local date of the observation (Y-m-d).
	 *
	 * @var string|null
	 */
	public $observedOn;

	/**
	 * Date and time with offset (ISO 8601), null when the observer gave no time.
	 *
	 * @var string|null
	 */
	public $timeObservedAt;

	/**
	 * IANA time zone of the observation.
	 *
	 * @var string|null
	 */
	public $timeZone;

	/**
	 * "research", "needs_id" or "casual".
	 *
	 * @var string
	 */
	public $qualityGrade = '';

	/**
	 * Whether the coordinates are obscured. The plugin never asks for coordinates.
	 *
	 * @var bool
	 */
	public $obscured = false;

	/**
	 * Privacy chosen by the observer ("obscured", "private") or null.
	 *
	 * @var string|null
	 */
	public $geoprivacy;

	/**
	 * Taxon id, null when the observation has no identification.
	 *
	 * @var int|null
	 */
	public $taxonId;

	/**
	 * Latin name.
	 *
	 * @var string|null
	 */
	public $scientificName;

	/**
	 * Common name in the requested locale, null when the taxon has none in that language.
	 *
	 * @var string|null
	 */
	public $commonName;

	/**
	 * Rank (species, genus, ...).
	 *
	 * @var string|null
	 */
	public $rank;

	/**
	 * Iconic taxon (Aves, Insecta, ...).
	 *
	 * @var string|null
	 */
	public $iconicTaxon;

	/**
	 * Wikipedia page of the taxon (as given by iNaturalist, usually the English one).
	 *
	 * @var string|null
	 */
	public $wikipediaUrl;

	/**
	 * Photos of the observation: list of arrays with id, url, attribution, license.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	public $photos = array();

	/**
	 * Builds an observation from one element of "results".
	 *
	 * @param array $row Decoded JSON.
	 * @throws \InvalidArgumentException When the id, uuid or uri is missing.
	 */
	public static function fromApi( array $row ): self {
		if ( ! isset( $row['id'], $row['uuid'], $row['uri'] ) || ! is_int( $row['id'] ) || ! is_string( $row['uuid'] ) || ! is_string( $row['uri'] ) ) {
			throw new \InvalidArgumentException( 'An observation needs an integer id, a uuid and a uri.' );
		}
		$taxon = ( isset( $row['taxon'] ) && is_array( $row['taxon'] ) ) ? $row['taxon'] : array();

		$o                 = new self();
		$o->id             = $row['id'];
		$o->uuid           = $row['uuid'];
		$o->uri            = $row['uri'];
		$o->observedOn     = self::string( $row, 'observed_on' );
		$o->timeObservedAt = self::string( $row, 'time_observed_at' );
		$o->timeZone       = self::string( $row, 'observed_time_zone' );
		$o->qualityGrade   = (string) self::string( $row, 'quality_grade' );
		$o->obscured       = ! empty( $row['obscured'] );
		$o->geoprivacy     = self::string( $row, 'geoprivacy' );
		$o->taxonId        = ( isset( $taxon['id'] ) && is_int( $taxon['id'] ) ) ? $taxon['id'] : null;
		$o->scientificName = self::string( $taxon, 'name' );
		$o->commonName     = self::string( $taxon, 'preferred_common_name' );
		$o->rank           = self::string( $taxon, 'rank' );
		$o->iconicTaxon    = self::string( $taxon, 'iconic_taxon_name' );
		$o->wikipediaUrl   = self::string( $taxon, 'wikipedia_url' );

		if ( isset( $row['photos'] ) && is_array( $row['photos'] ) ) {
			foreach ( $row['photos'] as $photo ) {
				if ( is_array( $photo ) && isset( $photo['id'], $photo['url'] ) && is_int( $photo['id'] ) && is_string( $photo['url'] ) ) {
					$o->photos[] = array(
						'id'          => $photo['id'],
						'url'         => $photo['url'],
						'attribution' => self::string( $photo, 'attribution' ),
						'license'     => self::string( $photo, 'license_code' ),
					);
				}
			}
		}
		return $o;
	}

	/**
	 * URL of a photo in another size.
	 *
	 * The API gives the "square" URL; the same path with "medium", "large" or "original" exists (checked once on 2026-11-25
	 * for one photo; the extension is kept as given).
	 *
	 * @param string $url  URL given by the API.
	 * @param string $size square, small, medium, large or original.
	 */
	public static function photoUrl( string $url, string $size = 'medium' ): string {
		return preg_replace( '#/square(\.[A-Za-z0-9]+)$#', '/' . $size . '$1', $url );
	}

	/**
	 * A non-empty string value of an array, or null.
	 *
	 * @param array  $data Array to read.
	 * @param string $key  Key to read.
	 */
	private static function string( array $data, string $key ): ?string {
		return ( isset( $data[ $key ] ) && is_string( $data[ $key ] ) && '' !== $data[ $key ] ) ? $data[ $key ] : null;
	}
}
