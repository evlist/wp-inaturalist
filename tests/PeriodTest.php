<?php
/**
 * Tests of Period.
 *
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * @package WpInaturalist
 */

namespace Evlist\WpInaturalist\Tests;

use Evlist\WpInaturalist\Period;

require_once __DIR__ . '/TestCase.php';

/**
 * Same rule as wp-whobird: N units ending on the post date.
 */
final class PeriodTest extends TestCase {

	/**
	 * @dataProvider ranges
	 * @param string $date     Post date.
	 * @param mixed  $number   Number of units.
	 * @param mixed  $unit     Unit.
	 * @param string $expected First day.
	 */
	public function test_range( string $date, $number, $unit, string $expected ) {
		$this->assertSame( array( $expected, $date ), Period::range( $date, $number, $unit ) );
	}

	public function ranges(): array {
		return array(
			'one day is the post date'   => array( '2026-11-20', 1, 'day', '2026-11-20' ),
			'three days'                 => array( '2026-11-20', 3, 'day', '2026-11-18' ),
			'one week is seven days'     => array( '2026-11-20', 1, 'week', '2026-11-14' ),
			'two weeks'                  => array( '2026-11-20', 2, 'week', '2026-11-07' ),
			'one month, same day + 1'    => array( '2026-11-20', 1, 'month', '2026-10-21' ),
			'month across a year'        => array( '2026-01-10', 1, 'month', '2025-12-11' ),
			'month from the 31st'        => array( '2026-03-31', 1, 'month', '2026-03-01' ),
			'number as a numeric string' => array( '2026-11-20', '2', 'day', '2026-11-19' ),
			'unknown unit means day'     => array( '2026-11-20', 2, 'year', '2026-11-19' ),
			'zero is clamped to one'     => array( '2026-11-20', 0, 'day', '2026-11-20' ),
			'negative is clamped to one' => array( '2026-11-20', -5, 'day', '2026-11-20' ),
			'huge is clamped to 100'     => array( '2026-11-20', 100000, 'day', '2026-08-13' ),
			'garbage number means one'   => array( '2026-11-20', 'x', 'day', '2026-11-20' ),
			'array unit means day'       => array( '2026-11-20', 1, array( 'week' ), '2026-11-20' ),
		);
	}

	public function test_a_bad_date_is_refused() {
		$this->expectException( \InvalidArgumentException::class );
		Period::range( '20/11/2026' );
	}

	public function test_content_without_the_block_has_no_period() {
		$this->assertNull( Period::forContent( '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->', '2026-11-20' ) );
		$this->assertNull( Period::forContent( '', '2026-11-20' ) );
	}

	public function test_the_block_attributes_give_the_period() {
		$content = '<!-- wp:wpinat/wp-inaturalist {"periodNumber":3,"periodUnit":"day"} /-->';
		$this->assertSame( array( '2026-11-18', '2026-11-20' ), Period::forContent( $content, '2026-11-20' ) );
	}

	public function test_a_block_without_attributes_uses_the_defaults() {
		$this->assertSame( array( '2026-11-20', '2026-11-20' ), Period::forContent( '<!-- wp:wpinat/wp-inaturalist /-->', '2026-11-20' ) );
	}

	public function test_several_blocks_give_the_union() {
		$content = '<!-- wp:wpinat/wp-inaturalist {"periodNumber":2} /--><!-- wp:wpinat/wp-inaturalist {"periodNumber":1,"periodUnit":"week"} /-->';
		$this->assertSame( array( '2026-11-14', '2026-11-20' ), Period::forContent( $content, '2026-11-20' ) );
	}

	public function test_blocks_inside_other_blocks_are_found() {
		$GLOBALS['wpinat_test']['blocks'] = array(
			array(
				'blockName'   => 'core/group',
				'attrs'       => array(),
				'innerBlocks' => array(
					array(
						'blockName'   => 'wpinat/wp-inaturalist',
						'attrs'       => array( 'periodNumber' => 5 ),
						'innerBlocks' => array(),
					),
				),
			),
		);
		$this->assertSame( array( '2026-11-16', '2026-11-20' ), Period::forContent( 'ignored', '2026-11-20' ) );
	}

	public function test_other_blocks_do_not_count() {
		$this->assertNull( Period::forContent( '<!-- wp:wpwbd/wp-whobird {"periodNumber":3} /-->', '2026-11-20' ) );
	}
}
