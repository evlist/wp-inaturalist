<?php
/**
 * Tests of the settings page.
 *
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * @package WpInaturalist
 */

namespace Evlist\WpInaturalist\Tests;

use Evlist\WpInaturalist\Config;
use Evlist\WpInaturalist\SettingsPage;

require_once __DIR__ . '/TestCase.php';

/**
 * Registration, rendering, capability and sanitizing.
 *
 * What these tests cannot cover: wp-admin/options.php itself, which checks the nonce and the capability filter before it
 * calls the sanitize callbacks. That was never tried in a real WordPress.
 */
final class SettingsPageTest extends TestCase {

	public function test_the_menu_entry_requires_manage_options() {
		SettingsPage::addMenu();
		$this->assertSame( array( 'manage_options', 'wp-inaturalist', array( SettingsPage::class, 'render' ) ), $GLOBALS['wpinat_test']['menu'] );
	}

	public function test_the_capability_filter_answers_manage_options() {
		$this->assertSame( 'manage_options', SettingsPage::capability() );
	}

	public function test_registered_options_are_exactly_those_of_config() {
		SettingsPage::registerSettings();
		$registered = $GLOBALS['wpinat_test']['settings'];
		$this->assertEqualsCanonicalizing( array_keys( Config::settings() ), array_keys( $registered ) );
		foreach ( Config::settings() as $name => $default ) {
			$this->assertSame( 'wpinat_settings', $registered[ $name ][0] );
			$this->assertSame( $default, $registered[ $name ][1]['default'] );
			$this->assertIsCallable( $registered[ $name ][1]['sanitize_callback'], $name );
		}
		$this->assertEqualsCanonicalizing( array_keys( Config::settings() ), array_keys( $GLOBALS['wpinat_test']['fields'] ) );
	}

	public function test_render_refuses_a_user_who_may_not_manage_options() {
		$GLOBALS['wpinat_test']['can'] = false;
		ob_start();
		try {
			SettingsPage::render();
			$this->fail( 'expected wp_die' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( '', ob_get_clean(), 'nothing of the form is output' );
		}
		$this->assertSame( 403, $GLOBALS['wpinat_test']['dies'][0][1]['response'] );
	}

	public function test_render_outputs_a_form_posting_to_options_php_with_the_settings_fields() {
		ob_start();
		SettingsPage::render();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'action="options.php"', $html );
		$this->assertStringContainsString( '<!--settings_fields wpinat_settings-->', $html );
		$this->assertStringContainsString( '<!--sections wp-inaturalist-->', $html );
	}

	public function test_fields_show_the_current_values_escaped() {
		update_option( Config::LOGIN, 'someone' );
		update_option( Config::QUALITY, 'any' );
		update_option( Config::REFRESH_HOUR, 7 );
		update_option( Config::NAMES_LOCALE, '"><script>x</script>' );
		ob_start();
		SettingsPage::fieldLogin();
		SettingsPage::fieldQuality();
		SettingsPage::fieldRefreshHour();
		SettingsPage::fieldNamesLocale();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'name="wpinat_login" value="someone"', $html );
		$this->assertStringContainsString( '<option value="any" selected="selected">', $html );
		$this->assertStringContainsString( '<option value="research">', $html );
		$this->assertStringContainsString( 'value="7"', $html );
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( '&quot;&gt;&lt;script&gt;', $html );
	}

	public function test_the_login_field_has_a_check_button_that_is_hidden_without_javascript() {
		ob_start();
		SettingsPage::fieldLogin();
		$html = ob_get_clean();
		$this->assertMatchesRegularExpression( '/<button type="button" class="button" id="wpinat-verify" hidden>Check<\/button>/', $html );
		$this->assertStringContainsString( 'id="wpinat-verify-result" role="status"', $html );
	}

	/**
	 * @dataProvider logins
	 * @param mixed  $input    Submitted value.
	 * @param string $expected Result.
	 * @param bool   $error    Whether an error is reported.
	 */
	public function test_sanitize_login( $input, string $expected, bool $error ) {
		update_option( Config::LOGIN, 'previous' );
		$this->assertSame( $expected, SettingsPage::sanitizeLogin( $input ) );
		$this->assertSame( $error ? array( 'wpinat_bad_login' ) : array(), $GLOBALS['wpinat_test']['errors'] );
	}

	public function logins(): array {
		return array(
			'valid'              => array( 'jean-dupont_42', 'jean-dupont_42', false ),
			'trimmed'            => array( '  jean  ', 'jean', false ),
			'empty clears it'    => array( '', '', false ),
			'invalid keeps old'  => array( 'a b', 'previous', true ),
			'path is refused'    => array( '../x', 'previous', true ),
			'script is refused'  => array( '<script>', 'previous', true ),
			'array is not valid' => array( array( 'x' ), '', false ),
		);
	}

	/**
	 * @dataProvider locales
	 * @param mixed  $input    Submitted value.
	 * @param string $expected Result.
	 * @param bool   $error    Whether an error is reported.
	 */
	public function test_sanitize_names_locale( $input, string $expected, bool $error ) {
		update_option( Config::NAMES_LOCALE, 'de' );
		$this->assertSame( $expected, SettingsPage::sanitizeNamesLocale( $input ) );
		$this->assertSame( $error ? array( 'wpinat_bad_locale' ) : array(), $GLOBALS['wpinat_test']['errors'] );
	}

	public function locales(): array {
		return array(
			'code'            => array( 'fr', 'fr', false ),
			'upper case'      => array( 'FR', 'fr', false ),
			'empty'           => array( '', '', false ),
			'region refused'  => array( 'fr_FR', 'de', true ),
			'garbage refused' => array( '"><x', 'de', true ),
		);
	}

	public function test_sanitize_quality() {
		$this->assertSame( 'any', SettingsPage::sanitizeQuality( 'any' ) );
		$this->assertSame( array(), $GLOBALS['wpinat_test']['errors'] );
		update_option( Config::QUALITY, 'any' );
		$this->assertSame( 'any', SettingsPage::sanitizeQuality( 'casual' ), 'unknown value keeps the current one' );
		$this->assertSame( array( 'wpinat_bad_quality' ), $GLOBALS['wpinat_test']['errors'] );
	}

	/**
	 * @dataProvider hours
	 * @param mixed $input    Submitted value.
	 * @param int   $expected Result.
	 * @param bool  $error    Whether an error is reported.
	 */
	public function test_sanitize_refresh_hour( $input, int $expected, bool $error ) {
		update_option( Config::REFRESH_HOUR, 5 );
		$this->assertSame( $expected, SettingsPage::sanitizeRefreshHour( $input ) );
		$this->assertSame( $error ? array( 'wpinat_bad_hour' ) : array(), $GLOBALS['wpinat_test']['errors'] );
	}

	public function hours(): array {
		return array(
			'string number' => array( '23', 23, false ),
			'zero'          => array( '0', 0, false ),
			'too big'       => array( '24', 5, true ),
			'negative'      => array( '-1', 5, true ),
			'decimal'       => array( '3.5', 5, true ),
			'text'          => array( 'abc', 5, true ),
			'array'         => array( array( 3 ), 5, true ),
		);
	}
}
