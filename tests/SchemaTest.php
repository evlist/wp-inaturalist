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

	public function test_a_site_without_version_gets_both_tables_and_the_version() {
		Schema::maybeMigrate();
		$this->assertCount( 1, $GLOBALS['wpinat_test']['dbdelta'] );
		$statements = $GLOBALS['wpinat_test']['dbdelta'][0];
		$this->assertCount( 2, $statements );
		$this->assertStringStartsWith( 'CREATE TABLE wp_wpinat_observations (', $statements[0] );
		$this->assertStringStartsWith( 'CREATE TABLE wp_wpinat_taxa (', $statements[1] );
		$this->assertStringEndsWith( 'DEFAULT CHARACTER SET utf8mb4;', $statements[0] );
		$this->assertSame( Schema::VERSION, get_option( Config::SCHEMA_VERSION ) );
	}

	public function test_an_up_to_date_site_is_left_alone() {
		update_option( Config::SCHEMA_VERSION, Schema::VERSION );
		Schema::maybeMigrate();
		$this->assertSame( array(), $GLOBALS['wpinat_test']['dbdelta'] );
	}

	public function test_an_older_version_is_migrated_without_activation() {
		update_option( Config::SCHEMA_VERSION, 2 ); // The version before the table of the taxa.
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
		$this->assertStringContainsString( 'attachment_id bigint(20) unsigned DEFAULT NULL,', $sql, 'added in version 2' );
		$this->assertGreaterThanOrEqual( 3, Schema::VERSION );
	}

	public function test_the_taxa_table_follows_the_dbdelta_rules() {
		$sql = Schema::createTaxaSql( 'wp_t', 'CHARSET' );
		$this->assertStringContainsString( 'PRIMARY KEY  (taxon_id)', $sql );
		foreach ( array( 'taxon_id bigint(20) unsigned NOT NULL', 'qid varchar(32) DEFAULT NULL', 'label varchar(255) DEFAULT NULL', 'wikipedia_url varchar(500) DEFAULT NULL', 'checked_at datetime NOT NULL' ) as $column ) {
			$this->assertStringContainsString( $column, $sql );
		}
	}
}
