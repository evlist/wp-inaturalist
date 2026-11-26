<?php
/**
 * Links observations to photos of the media library.
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
 * For each cached observation of a range: a manual link first, then the photo taken at the same time.
 */
final class PhotoLinker {

	/**
	 * Cache.
	 *
	 * @var Cache
	 */
	private $cache;

	/**
	 * Index of the media library.
	 *
	 * @var AttachmentIndex
	 */
	private $index;

	/**
	 * Constructor.
	 *
	 * @param Cache           $cache Cache.
	 * @param AttachmentIndex $index Index of the media library (any object with candidates() and manual()).
	 */
	public function __construct( Cache $cache, $index ) {
		$this->cache = $cache;
		$this->index = $index;
	}

	/**
	 * Wall-clock time of an observation as a timestamp read as UTC (as WordPress stores the EXIF time), or null without time of day.
	 *
	 * @param mixed $iso Value of `time_observed_at`, for example "2026-11-24T09:32:50-08:00".
	 */
	public static function wallClock( $iso ): ?int {
		if ( ! is_string( $iso ) || 1 !== preg_match( '/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2}:\d{2})/', $iso, $m ) ) {
			return null;
		}
		$text = $m[1] . ' ' . $m[2];
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $text, new \DateTimeZone( 'UTC' ) );
		// PHP rolls an impossible date over (month 13, second 61): only a date that reads back unchanged is real.
		return ( false === $date || $date->format( 'Y-m-d H:i:s' ) !== $text ) ? null : $date->getTimestamp();
	}

	/**
	 * The attachment to show for one cached row, and how it was found.
	 *
	 * @param array $row Row of the cache.
	 * @return array{id:?int,how:string} "how" is "manual", "auto", "no_time" or "no_match".
	 */
	public function choose( array $row ): array {
		$manual = $this->index->manual( (int) $row['observation_id'] );
		if ( null !== $manual ) {
			return array(
				'id'  => $manual,
				'how' => 'manual',
			);
		}
		$time = self::wallClock( isset( $row['time_observed_at'] ) ? $row['time_observed_at'] : null );
		if ( null === $time ) {
			return array(
				'id'  => null,
				'how' => 'no_time',
			);
		}
		$candidates = $this->index->candidates( $time, Config::PHOTO_TOLERANCE_SEC );
		return array() === $candidates ? array(
			'id'  => null,
			'how' => 'no_match',
		) : array(
			'id'  => $candidates[0]['id'],
			'how' => 'auto',
		);
	}

	/**
	 * Links the cached observations of a range that will be shown (a taxon and a photo). Does nothing when the setting is off.
	 *
	 * @param string $login iNaturalist login.
	 * @param string $from  First day (Y-m-d).
	 * @param string $to    Last day (Y-m-d).
	 * @return int Number of observations linked.
	 */
	public function linkRange( string $login, string $from, string $to ): int {
		return $this->linkRows( $this->cache->getBetween( $login, $from, $to ) );
	}

	/**
	 * Links the given cached rows that will be shown (a taxon and a photo). Does nothing when the setting is off.
	 *
	 * @param array<int,array<string,mixed>> $rows Rows of the cache.
	 * @return int Number of observations linked.
	 */
	public function linkRows( array $rows ): int {
		if ( ! Config::getUseMediaLibrary() ) {
			return 0;
		}
		$linked = 0;
		foreach ( $rows as $row ) {
			if ( empty( $row['taxon_id'] ) || ! self::hasPhoto( $row ) ) {
				continue;
			}
			$choice = $this->choose( $row );
			if ( null !== $choice['id'] ) {
				$this->cache->setAttachment( (int) $row['observation_id'], $choice['id'] );
				++$linked;
			}
		}
		return $linked;
	}

	/**
	 * Whether a cached row has at least one iNaturalist photo.
	 *
	 * @param array $row Row of the cache.
	 */
	public static function hasPhoto( array $row ): bool {
		$photos = isset( $row['photos'] ) ? json_decode( (string) $row['photos'], true ) : null;
		return is_array( $photos ) && isset( $photos[0]['url'] );
	}
}
