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
}
