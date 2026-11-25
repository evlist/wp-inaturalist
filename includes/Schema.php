<?php
/**
 * Cache table creation and migration.
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
 * The table is created and migrated from the schema version stored in an option, checked on every load of the plugin:
 * deploying a new version over the old one needs no deactivation and re-activation.
 */
final class Schema {

	const VERSION = 2;

	/**
	 * Creates or updates the table when the stored version is older than {@see Schema::VERSION}.
	 */
	public static function maybeMigrate(): void {
		if ( (int) get_option( Config::SCHEMA_VERSION, 0 ) >= self::VERSION ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( self::createSql( Config::tableName( $wpdb ), $wpdb->get_charset_collate() ) );
		update_option( Config::SCHEMA_VERSION, self::VERSION );
	}

	/**
	 * The statement given to dbDelta() (its formatting rules: two spaces after PRIMARY KEY, one field per line).
	 *
	 * @param string $table   Table name.
	 * @param string $charset Charset and collation clause.
	 */
	public static function createSql( string $table, string $charset ): string {
		return "CREATE TABLE {$table} (
  observation_id bigint(20) unsigned NOT NULL,
  user_login varchar(40) NOT NULL,
  uuid varchar(36) NOT NULL,
  uri varchar(255) NOT NULL,
  observed_on date DEFAULT NULL,
  time_observed_at varchar(32) DEFAULT NULL,
  time_zone varchar(64) DEFAULT NULL,
  quality_grade varchar(16) NOT NULL DEFAULT '',
  taxon_id bigint(20) unsigned DEFAULT NULL,
  scientific_name varchar(255) DEFAULT NULL,
  common_name varchar(255) DEFAULT NULL,
  names_locale varchar(8) NOT NULL DEFAULT '',
  taxon_rank varchar(32) DEFAULT NULL,
  iconic_taxon varchar(64) DEFAULT NULL,
  wikipedia_url varchar(255) DEFAULT NULL,
  photos longtext NOT NULL,
  fetched_at datetime NOT NULL,
  attachment_id bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY  (observation_id),
  KEY user_date (user_login,observed_on),
  KEY taxon (taxon_id)
) {$charset};";
	}
}
