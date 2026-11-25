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

use Evlist\WpInaturalist\Schema;
use Evlist\WpInaturalist\Scheduler;
use Evlist\WpInaturalist\SettingsPage;
use Evlist\WpInaturalist\ToolsPage;
use Evlist\WpInaturalist\Setup;

require_once __DIR__ . '/TestCase.php';

/**
 * Every hook is registered in one place with an explicit priority.
 */
final class SetupTest extends TestCase {

	public function test_register_adds_every_hook_with_an_explicit_priority() {
		Setup::register();
		$actions = array();
		foreach ( $GLOBALS['wpinat_test']['actions'] as $a ) {
			$actions[ $a[0] . ' -> ' . $a[1][0] . '::' . $a[1][1] ] = $a[2];
		}
		$this->assertSame(
			array(
				'init -> ' . Setup::class . '::loadTextDomain'                     => 10,
				'plugins_loaded -> ' . Schema::class . '::maybeMigrate'             => 10,
				'init -> ' . Scheduler::class . '::ensureScheduled'                 => 20,
				'update_option_wpinat_refresh_hour -> ' . Scheduler::class . '::reschedule' => 10,
				'wpinat_refresh -> ' . Scheduler::class . '::runDaily'              => 10,
				'wpinat_refresh_step -> ' . Scheduler::class . '::runStep'          => 10,
				'admin_menu -> ' . ToolsPage::class . '::addMenu'                   => 10,
				'admin_post_wpinat_job_start -> ' . ToolsPage::class . '::onStart'  => 10,
				'admin_post_wpinat_job_stop -> ' . ToolsPage::class . '::onStop'    => 10,
				'admin_post_wpinat_job_resume -> ' . ToolsPage::class . '::onResume' => 10,
				'admin_post_wpinat_cache_clear -> ' . ToolsPage::class . '::onClear' => 10,
				'admin_menu -> ' . SettingsPage::class . '::addMenu'                => 10,
				'admin_init -> ' . SettingsPage::class . '::registerSettings'       => 10,
			),
			$actions
		);
		$this->assertSame(
			array( array( 'option_page_capability_wpinat_settings', array( SettingsPage::class, 'capability' ), 10 ) ),
			$GLOBALS['wpinat_test']['filters']
		);
	}

	public function test_every_registered_callback_exists() {
		Setup::register();
		foreach ( $GLOBALS['wpinat_test']['actions'] as $a ) {
			$this->assertIsCallable( $a[1], $a[0] );
		}
	}

	public function test_text_domain_is_loaded_from_the_languages_directory() {
		Setup::loadTextDomain();
		$this->assertSame( array( 'wp-inaturalist', 'wp-inaturalist/languages' ), $GLOBALS['wpinat_test']['textdomain'] );
	}
}
