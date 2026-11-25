<?php
/**
 * Option names, defaults and getters: the single source of truth for settings.
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
 * Names, defaults and getters of every option of the plugin.
 *
 * The settings page (slice 003) registers exactly the options of {@see Config::settings()}.
 */
final class Config {

	const LOGIN          = 'wpinat_login';
	const NAMES_LOCALE   = 'wpinat_names_locale';
	const QUALITY        = 'wpinat_quality';
	const REFRESH_HOUR   = 'wpinat_refresh_hour';
	const SCHEMA_VERSION = 'wpinat_schema_version';
	const JOB_STATE      = 'wpinat_job_state';
	const JOB_LOG        = 'wpinat_job_log';

	const CRON_HOOK    = 'wpinat_refresh';
	const TABLE_SUFFIX = 'wpinat_observations';

	const QUALITY_RESEARCH = 'research';
	const QUALITY_ANY      = 'any';

	/**
	 * Options edited on the settings page, with their defaults.
	 *
	 * An empty names locale means "the site locale". The quality default is provisional (open question 2).
	 *
	 * @return array<string,mixed>
	 */
	public static function settings(): array {
		return array(
			self::LOGIN        => '',
			self::NAMES_LOCALE => '',
			self::QUALITY      => self::QUALITY_RESEARCH,
			self::REFRESH_HOUR => 3,
		);
	}

	/**
	 * Every option the plugin stores, for the uninstaller.
	 *
	 * @return string[]
	 */
	public static function allOptionNames(): array {
		return array_merge(
			array_keys( self::settings() ),
			array( self::SCHEMA_VERSION, self::JOB_STATE, self::JOB_LOG )
		);
	}

	/**
	 * Stored value of a setting, or its default.
	 *
	 * @param string $name One of the keys of {@see Config::settings()}.
	 * @return mixed
	 */
	private static function raw( string $name ) {
		$defaults = self::settings();
		return get_option( $name, $defaults[ $name ] );
	}

	/**
	 * Whether a login is a valid iNaturalist login (letters, digits, '-' and '_').
	 *
	 * @param mixed $login Value to check.
	 */
	public static function isValidLogin( $login ): bool {
		return is_string( $login ) && 1 === preg_match( '/^[A-Za-z0-9_-]{1,40}$/', $login );
	}

	/**
	 * The iNaturalist login, or an empty string when unset or invalid.
	 */
	public static function getLogin(): string {
		$login = self::raw( self::LOGIN );
		return self::isValidLogin( $login ) ? $login : '';
	}

	/**
	 * Language code sent to the API for common names (for example "fr").
	 *
	 * Falls back to the language of the site locale.
	 */
	public static function getNamesLocale(): string {
		$value = self::raw( self::NAMES_LOCALE );
		if ( ! is_string( $value ) || 1 !== preg_match( '/^[a-z]{2,3}$/', $value ) ) {
			$value = strtolower( substr( (string) get_locale(), 0, 2 ) );
		}
		return $value;
	}

	/**
	 * Quality filter: "research" or "any".
	 */
	public static function getQuality(): string {
		$value = self::raw( self::QUALITY );
		return in_array( $value, array( self::QUALITY_RESEARCH, self::QUALITY_ANY ), true ) ? $value : self::QUALITY_RESEARCH;
	}

	/**
	 * Hour of the day (0 to 23, site time) of the daily refresh.
	 */
	public static function getRefreshHour(): int {
		$value = self::raw( self::REFRESH_HOUR );
		if ( is_numeric( $value ) && (int) $value >= 0 && (int) $value <= 23 ) {
			return (int) $value;
		}
		$defaults = self::settings();
		return $defaults[ self::REFRESH_HOUR ];
	}

	/**
	 * Name of the cache table (created by slice 004).
	 *
	 * @param \wpdb $wpdb WordPress database object.
	 */
	public static function tableName( $wpdb ): string {
		return $wpdb->prefix . self::TABLE_SUFFIX;
	}
}
