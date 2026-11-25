<?php
/**
 * Tests of Schema.
 *
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * @package WpInaturalist
 */

namespace Evlist\WpInaturalist\Tests;

use Evlist\WpInaturalist\Config;
use Evlist\WpInaturalist\Schema;

require_once __DIR__ . '/TestCase.php';

/**
 * Creation and migration of the cache table.
 */
final class SchemaTest extends TestCase {

	public function test_a_site_without_version_gets_the_table_and_the_version() {
		Schema::maybeMigrate();
		$this->assertCount( 1, $GLOBALS['wpinat_test']['dbdelta'] );
		$this->assertStringStartsWith( 'CREATE TABLE wp_wpinat_observations (', $GLOBALS['wpinat_test']['dbdelta'][0] );
		$this->assertStringEndsWith( 'DEFAULT CHARACTER SET utf8mb4;', $GLOBALS['wpinat_test']['dbdelta'][0] );
		$this->assertSame( Schema::VERSION, get_option( Config::SCHEMA_VERSION ) );
	}

	public function test_an_up_to_date_site_is_left_alone() {
		update_option( Config::SCHEMA_VERSION, Schema::VERSION );
		Schema::maybeMigrate();
		$this->assertSame( array(), $GLOBALS['wpinat_test']['dbdelta'] );
	}

	public function test_an_older_version_is_migrated_without_activation() {
		update_option( Config::SCHEMA_VERSION, Schema::VERSION - 1 );
		Schema::maybeMigrate();
		$this->assertCount( 1, $GLOBALS['wpinat_test']['dbdelta'] );
		$this->assertSame( Schema::VERSION, get_option( Config::SCHEMA_VERSION ) );
	}

	public function test_the_sql_follows_the_dbdelta_rules_and_avoids_reserved_words() {
		$sql = Schema::createSql( 'wp_t', 'CHARSET' );
		$this->assertStringContainsString( 'PRIMARY KEY  (observation_id)', $sql, 'two spaces after PRIMARY KEY' );
		$this->assertStringContainsString( 'KEY user_date (user_login,observed_on)', $sql );
		$this->assertDoesNotMatchRegularExpression( '/^\s+rank /m', $sql, '"rank" is reserved in MySQL 8' );
		$this->assertStringContainsString( 'taxon_rank', $sql );
	}
}
