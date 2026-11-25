<?php
/**
 * Settings > iNaturalist.
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
 * The settings page, built with the Settings API.
 *
 * Saving goes through wp-admin/options.php, which checks the nonce of the option group ("wpinat_settings-options") and the
 * capability returned by the filter {@see SettingsPage::capability()}; the sanitize callbacks below then validate each value.
 * Reading goes through {@see Config} only: the options registered here are exactly those of {@see Config::settings()}.
 */
final class SettingsPage {

	const GROUP      = 'wpinat_settings';
	const PAGE       = 'wp-inaturalist';
	const SECTION    = 'wpinat_main';
	const CAPABILITY = 'manage_options';

	/**
	 * Adds the page under Settings.
	 */
	public static function addMenu(): void {
		add_options_page(
			__( 'iNaturalist', 'wp-inaturalist' ),
			__( 'iNaturalist', 'wp-inaturalist' ),
			self::CAPABILITY,
			self::PAGE,
			array( self::class, 'render' )
		);
	}

	/**
	 * Capability required to save the options of the group (filter option_page_capability_{group}).
	 */
	public static function capability(): string {
		return self::CAPABILITY;
	}

	/**
	 * Registers the options, the section and the fields.
	 */
	public static function registerSettings(): void {
		$defaults = Config::settings();
		$sanitize = array(
			Config::LOGIN        => 'sanitizeLogin',
			Config::NAMES_LOCALE => 'sanitizeNamesLocale',
			Config::QUALITY      => 'sanitizeQuality',
			Config::REFRESH_HOUR => 'sanitizeRefreshHour',
		);
		foreach ( $sanitize as $name => $method ) {
			register_setting(
				self::GROUP,
				$name,
				array(
					'type'              => is_int( $defaults[ $name ] ) ? 'integer' : 'string',
					'default'           => $defaults[ $name ],
					'sanitize_callback' => array( self::class, $method ),
				)
			);
		}

		add_settings_section( self::SECTION, __( 'Observations', 'wp-inaturalist' ), '__return_false', self::PAGE );
		add_settings_field( Config::LOGIN, __( 'iNaturalist login', 'wp-inaturalist' ), array( self::class, 'fieldLogin' ), self::PAGE, self::SECTION, array( 'label_for' => Config::LOGIN ) );
		add_settings_field( Config::NAMES_LOCALE, __( 'Language of the names', 'wp-inaturalist' ), array( self::class, 'fieldNamesLocale' ), self::PAGE, self::SECTION, array( 'label_for' => Config::NAMES_LOCALE ) );
		add_settings_field( Config::QUALITY, __( 'Observations shown', 'wp-inaturalist' ), array( self::class, 'fieldQuality' ), self::PAGE, self::SECTION, array( 'label_for' => Config::QUALITY ) );
		add_settings_field( Config::REFRESH_HOUR, __( 'Hour of the daily refresh', 'wp-inaturalist' ), array( self::class, 'fieldRefreshHour' ), self::PAGE, self::SECTION, array( 'label_for' => Config::REFRESH_HOUR ) );
	}

	/**
	 * Outputs the page. Does nothing but refuse when the user may not manage options.
	 */
	public static function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to change these settings.', 'wp-inaturalist' ), '', array( 'response' => 403 ) );
		}
		echo '<div class="wrap"><h1>' . esc_html__( 'iNaturalist', 'wp-inaturalist' ) . '</h1>';
		echo '<form method="post" action="options.php">';
		settings_fields( self::GROUP );
		do_settings_sections( self::PAGE );
		submit_button();
		echo '</form></div>';
	}

	/**
	 * Login field.
	 */
	public static function fieldLogin(): void {
		printf(
			'<input type="text" id="%1$s" name="%1$s" value="%2$s" class="regular-text" maxlength="40" autocomplete="off" />',
			esc_attr( Config::LOGIN ),
			esc_attr( Config::getLogin() )
		);
		echo '<p class="description">' . esc_html__( 'Only public observations of this user are read. No password or token is needed or stored.', 'wp-inaturalist' ) . '</p>';
	}

	/**
	 * Names language field.
	 */
	public static function fieldNamesLocale(): void {
		$stored = get_option( Config::NAMES_LOCALE, '' );
		printf(
			'<input type="text" id="%1$s" name="%1$s" value="%2$s" class="small-text" maxlength="3" />',
			esc_attr( Config::NAMES_LOCALE ),
			esc_attr( is_string( $stored ) ? $stored : '' )
		);
		echo '<p class="description">' . esc_html__( 'Language code of the common names, for example "fr". Leave empty to use the language of the site.', 'wp-inaturalist' ) . '</p>';
	}

	/**
	 * Quality field.
	 */
	public static function fieldQuality(): void {
		$current = Config::getQuality();
		$choices = array(
			Config::QUALITY_RESEARCH => __( 'Research grade only', 'wp-inaturalist' ),
			Config::QUALITY_ANY      => __( 'All observations', 'wp-inaturalist' ),
		);
		printf( '<select id="%1$s" name="%1$s">', esc_attr( Config::QUALITY ) );
		foreach ( $choices as $value => $label ) {
			printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $value ), $value === $current ? ' selected="selected"' : '', esc_html( $label ) );
		}
		echo '</select>';
	}

	/**
	 * Refresh hour field.
	 */
	public static function fieldRefreshHour(): void {
		printf(
			'<input type="number" id="%1$s" name="%1$s" value="%2$d" min="0" max="23" step="1" class="small-text" />',
			esc_attr( Config::REFRESH_HOUR ),
			absint( Config::getRefreshHour() )
		);
		echo '<p class="description">' . esc_html__( 'From 0 to 23, in the time of the site.', 'wp-inaturalist' ) . '</p>';
	}

	/**
	 * Sanitizes the login: empty or valid, otherwise the previous value is kept and an error is shown.
	 *
	 * @param mixed $value Submitted value.
	 */
	public static function sanitizeLogin( $value ): string {
		$value = is_string( $value ) ? trim( $value ) : '';
		if ( '' === $value || Config::isValidLogin( $value ) ) {
			return $value;
		}
		add_settings_error( self::GROUP, 'wpinat_bad_login', __( 'The iNaturalist login may only contain letters, digits, "-" and "_".', 'wp-inaturalist' ) );
		return Config::getLogin();
	}

	/**
	 * Sanitizes the names language: empty (site language) or a two or three letter code.
	 *
	 * @param mixed $value Submitted value.
	 */
	public static function sanitizeNamesLocale( $value ): string {
		$value = is_string( $value ) ? strtolower( trim( $value ) ) : '';
		if ( '' === $value || 1 === preg_match( '/^[a-z]{2,3}$/', $value ) ) {
			return $value;
		}
		add_settings_error( self::GROUP, 'wpinat_bad_locale', __( 'The language must be a code of two or three letters, such as "fr", or empty.', 'wp-inaturalist' ) );
		$stored = get_option( Config::NAMES_LOCALE, '' );
		return ( is_string( $stored ) && 1 === preg_match( '/^[a-z]{2,3}$/', $stored ) ) ? $stored : '';
	}

	/**
	 * Sanitizes the quality: a known value, otherwise the default.
	 *
	 * @param mixed $value Submitted value.
	 */
	public static function sanitizeQuality( $value ): string {
		if ( in_array( $value, array( Config::QUALITY_RESEARCH, Config::QUALITY_ANY ), true ) ) {
			return $value;
		}
		add_settings_error( self::GROUP, 'wpinat_bad_quality', __( 'Unknown choice of observations.', 'wp-inaturalist' ) );
		return Config::getQuality();
	}

	/**
	 * Sanitizes the refresh hour: an integer from 0 to 23, otherwise the previous value.
	 *
	 * @param mixed $value Submitted value.
	 */
	public static function sanitizeRefreshHour( $value ): int {
		if ( is_numeric( $value ) && trim( (string) $value ) === (string) (int) $value && (int) $value >= 0 && (int) $value <= 23 ) {
			return (int) $value;
		}
		add_settings_error( self::GROUP, 'wpinat_bad_hour', __( 'The hour must be a whole number from 0 to 23.', 'wp-inaturalist' ) );
		return Config::getRefreshHour();
	}
}
