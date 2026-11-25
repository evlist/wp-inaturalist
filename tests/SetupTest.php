<?php
/**
 * Tests of Setup (hook registration).
 *
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * @package WpInaturalist
 */

namespace Evlist\WpInaturalist\Tests;

use Evlist\WpInaturalist\Setup;

require_once __DIR__ . '/TestCase.php';

/**
 * Every hook is registered in one place with an explicit priority.
 */
final class SetupTest extends TestCase {

	public function test_register_adds_the_init_hook_with_an_explicit_priority() {
		Setup::register();
		$this->assertSame(
			array( array( 'init', array( Setup::class, 'loadTextDomain' ), 10 ) ),
			$GLOBALS['wpinat_test']['actions']
		);
	}

	public function test_text_domain_is_loaded_from_the_languages_directory() {
		Setup::loadTextDomain();
		$this->assertSame( array( 'wp-inaturalist', 'wp-inaturalist/languages' ), $GLOBALS['wpinat_test']['textdomain'] );
	}
}
