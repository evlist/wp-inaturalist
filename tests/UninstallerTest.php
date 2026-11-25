<?php
/**
 * Tests of the uninstallation.
 *
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * @package WpInaturalist
 */

namespace Evlist\WpInaturalist\Tests;

use Evlist\WpInaturalist\Config;
use Evlist\WpInaturalist\Throttle;
use Evlist\WpInaturalist\Uninstaller;

require_once __DIR__ . '/TestCase.php';

/**
 * Uninstaller::run() and uninstall.php.
 */
final class UninstallerTest extends TestCase {

	public function test_run_removes_everything_the_plugin_stored() {
		foreach ( Config::allOptionNames() as $name ) {
			update_option( $name, 'x' );
		}
		update_option( 'blogname', 'Not ours' );
		set_transient( Throttle::TRANSIENT, 1.0 );

		Uninstaller::run();

		foreach ( Config::allOptionNames() as $name ) {
			$this->assertFalse( get_option( $name, false ), $name );
		}
		$this->assertSame( 'Not ours', get_option( 'blogname' ), 'options of others are kept' );
		$this->assertFalse( get_transient( Throttle::TRANSIENT ) );
		$this->assertSame( array( Config::CRON_HOOK, Config::STEP_HOOK ), $GLOBALS['wpinat_test']['cleared'] );
		$this->assertSame( array( 'DROP TABLE IF EXISTS `wp_wpinat_observations`' ), $GLOBALS['wpdb']->queries );
	}

	public function test_uninstall_file_does_the_work_when_wordpress_asks() {
		update_option( Config::LOGIN, 'someone' );
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- the constant is WordPress's own.
		define( 'WP_UNINSTALL_PLUGIN', 'wp-inaturalist/wp-inaturalist.php' );
		require dirname( __DIR__ ) . '/uninstall.php';
		$this->assertFalse( get_option( Config::LOGIN, false ) );
		$this->assertCount( 1, $GLOBALS['wpdb']->queries );
	}
}
