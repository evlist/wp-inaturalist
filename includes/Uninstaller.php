<?php
/**
 * Removes everything the plugin stored.
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
 * Deletes options, the cache table, the throttle transient and the scheduled events.
 *
 * It never touches posts or the media library. Single site only for now: on a multisite network it cleans the site it runs on.
 */
final class Uninstaller {

	/**
	 * Runs the uninstallation.
	 */
	public static function run(): void {
		foreach ( Config::allOptionNames() as $name ) {
			delete_option( $name );
		}
		delete_transient( Throttle::TRANSIENT );
		delete_transient( Throttle::WIKIDATA_TRANSIENT );
		wp_clear_scheduled_hook( Config::CRON_HOOK );
		wp_clear_scheduled_hook( Config::STEP_HOOK );

		global $wpdb;
		$table = Config::tableName( $wpdb );
		// The table name is built from the site prefix and a constant, and there is nothing to prepare.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
		$taxa = Config::taxaTableName( $wpdb );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DROP TABLE IF EXISTS `{$taxa}`" );
	}
}
