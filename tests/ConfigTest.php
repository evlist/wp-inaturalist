<?php
/**
 * Tests of Config.
 *
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * @package WpInaturalist
 */

namespace Evlist\WpInaturalist\Tests;

use Evlist\WpInaturalist\Config;

require_once __DIR__ . '/TestCase.php';

/**
 * Defaults and validation of the settings.
 */
final class ConfigTest extends TestCase {

	public function test_defaults() {
		$this->assertSame( '', Config::getLogin() );
		$this->assertSame( 'research', Config::getQuality() );
		$this->assertSame( 3, Config::getRefreshHour() );
		$this->assertSame( 'en', Config::getNamesLocale() );
	}

	public function test_names_locale_follows_the_site_locale_then_the_setting() {
		$GLOBALS['wpinat_test']['locale'] = 'fr_FR';
		$this->assertSame( 'fr', Config::getNamesLocale() );
		update_option( Config::NAMES_LOCALE, 'de' );
		$this->assertSame( 'de', Config::getNamesLocale() );
		update_option( Config::NAMES_LOCALE, 'not a locale' );
		$this->assertSame( 'fr', Config::getNamesLocale() );
	}

	public function test_login_is_validated() {
		update_option( Config::LOGIN, 'jean-dupont_42' );
		$this->assertSame( 'jean-dupont_42', Config::getLogin() );
		foreach ( array( 'a b', 'a/b', '', '../x', str_repeat( 'a', 41 ), 12, array( 'x' ) ) as $bad ) {
			update_option( Config::LOGIN, $bad );
			$this->assertSame( '', Config::getLogin(), 'rejected: ' . wp_json_encode( $bad ) );
		}
	}

	public function test_quality_only_accepts_known_values() {
		update_option( Config::QUALITY, 'any' );
		$this->assertSame( 'any', Config::getQuality() );
		update_option( Config::QUALITY, 'casual' );
		$this->assertSame( 'research', Config::getQuality() );
	}

	public function test_refresh_hour_is_bounded() {
		update_option( Config::REFRESH_HOUR, '23' );
		$this->assertSame( 23, Config::getRefreshHour() );
		foreach ( array( 24, -1, 'x', null ) as $bad ) {
			update_option( Config::REFRESH_HOUR, $bad );
			$this->assertSame( 3, Config::getRefreshHour() );
		}
	}

	public function test_every_stored_option_is_listed_for_the_uninstaller() {
		$names = Config::allOptionNames();
		foreach ( array_keys( Config::settings() ) as $setting ) {
			$this->assertContains( $setting, $names );
		}
		$this->assertContains( Config::SCHEMA_VERSION, $names );
		$this->assertContains( Config::JOB_STATE, $names );
		$this->assertContains( Config::JOB_LOG, $names );
		$this->assertContains( Config::JOB_LOCK, $names );
		$this->assertContains( Config::JOB_QUEUE, $names );
	}

	public function test_table_name_uses_the_site_prefix() {
		$this->assertSame( 'wp_wpinat_observations', Config::tableName( $GLOBALS['wpdb'] ) );
	}
}
