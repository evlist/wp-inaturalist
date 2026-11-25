<?php
/**
 * Test that uninstall.php does nothing when WordPress did not ask for it.
 *
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * @package WpInaturalist
 */

namespace Evlist\WpInaturalist\Tests;

require_once __DIR__ . '/TestCase.php';

/**
 * The file exits when WP_UNINSTALL_PLUGIN is not defined, so it runs in its own PHP process.
 */
final class UninstallDirectAccessTest extends TestCase {

	public function test_nothing_is_deleted_without_the_wordpress_constant() {
		$output = array();
		exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/scripts/uninstall-without-constant.php' ), $output );
		$this->assertSame( array( 'option kept', 'no query' ), $output );
	}
}
