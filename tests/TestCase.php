<?php
/**
 * Base class: resets the stand-ins before each test.
 *
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * @package WpInaturalist
 */

namespace Evlist\WpInaturalist\Tests;

use PHPUnit\Framework\TestCase as PhpUnitTestCase;

/**
 * Base class of the tests.
 */
abstract class TestCase extends PhpUnitTestCase {

	/**
	 * Resets options, transients and recorded calls.
	 */
	protected function setUp(): void {
		wpinat_test_reset();
	}

	/**
	 * An observation as the client would return it.
	 *
	 * @param int    $id   Id.
	 * @param string $date Day (Y-m-d).
	 */
	protected function observation( int $id, string $date = '2026-11-20' ): \Evlist\WpInaturalist\Observation {
		return \Evlist\WpInaturalist\Observation::fromApi(
			array(
				'id'            => $id,
				'uuid'          => 'uuid-' . $id,
				'uri'           => 'https://www.inaturalist.org/observations/' . $id,
				'observed_on'   => $date,
				'quality_grade' => 'research',
				'taxon'         => array(
					'id'                    => 1000 + $id,
					'name'                  => 'Species ' . $id,
					'rank'                  => 'species',
					'preferred_common_name' => 'espèce ' . $id,
				),
				'photos'        => array(
					array(
						'id'           => 5000 + $id,
						'url'          => 'https://example.test/' . $id . '/square.jpg',
						'attribution'  => '(c) x',
						'license_code' => 'cc-by',
					),
				),
			)
		);
	}
}
