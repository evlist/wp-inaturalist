<?php
/**
 * The period of observations shown with a post.
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
 * Same rule as wp-whobird: the N units (day, week or month) that end on the post date, inclusive.
 */
final class Period {

	const UNITS       = array( 'day', 'week', 'month' );
	const MAX_NUMBER  = 100;
	const DEFAULT_NUM = 1;

	/**
	 * First and last day of the period.
	 *
	 * @param string $postDate Local date of the post (Y-m-d).
	 * @param mixed  $number   Number of units (clamped to 1..100).
	 * @param mixed  $unit     "day", "week" or "month" (anything else means "day").
	 * @return array{0:string,1:string}
	 * @throws \InvalidArgumentException When the date is not a Y-m-d date.
	 */
	public static function range( string $postDate, $number = self::DEFAULT_NUM, $unit = 'day' ): array {
		$last = \DateTimeImmutable::createFromFormat( '!Y-m-d', $postDate, new \DateTimeZone( 'UTC' ) );
		if ( false === $last || $last->format( 'Y-m-d' ) !== $postDate ) {
			throw new \InvalidArgumentException( 'Not a date: use Y-m-d.' );
		}
		$number = is_numeric( $number ) ? (int) $number : self::DEFAULT_NUM;
		$number = max( 1, min( self::MAX_NUMBER, $number ) );
		$unit   = in_array( $unit, self::UNITS, true ) ? $unit : 'day';
		// As wp-whobird: end = date + 1 day, start = end - N units.
		$start = $last->modify( '+1 day' )->modify( '-' . $number . ' ' . $unit );
		return array( $start->format( 'Y-m-d' ), $postDate );
	}

	/**
	 * The period covered by the blocks of a post, or null when it has none.
	 *
	 * With several blocks, the union (from the earliest start to the post date).
	 *
	 * @param string $content  Post content.
	 * @param string $postDate Local date of the post (Y-m-d).
	 * @return array{0:string,1:string}|null
	 * @throws \InvalidArgumentException When the date is not a Y-m-d date.
	 */
	public static function forContent( string $content, string $postDate ): ?array {
		$from = null;
		foreach ( self::blocks( parse_blocks( $content ) ) as $attrs ) {
			$range = self::range(
				$postDate,
				isset( $attrs['periodNumber'] ) ? $attrs['periodNumber'] : self::DEFAULT_NUM,
				isset( $attrs['periodUnit'] ) ? $attrs['periodUnit'] : 'day'
			);
			if ( null === $from || $range[0] < $from ) {
				$from = $range[0];
			}
		}
		return null === $from ? null : array( $from, $postDate );
	}

	/**
	 * Attributes of every block of ours, inner blocks included.
	 *
	 * @param array $blocks Result of parse_blocks().
	 * @return array<int,array>
	 */
	private static function blocks( array $blocks ): array {
		$found = array();
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			if ( isset( $block['blockName'] ) && Config::BLOCK_NAME === $block['blockName'] ) {
				$found[] = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
			}
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$found = array_merge( $found, self::blocks( $block['innerBlocks'] ) );
			}
		}
		return $found;
	}
}
