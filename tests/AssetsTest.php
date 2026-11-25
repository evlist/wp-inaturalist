<?php
/**
 * Tests of Assets.
 *
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * @package WpInaturalist
 */

namespace Evlist\WpInaturalist\Tests;

use Evlist\WpInaturalist\Assets;

require_once __DIR__ . '/TestCase.php';

/**
 * Scripts are loaded where they are needed, with their texts as data.
 */
final class AssetsTest extends TestCase {

	public function test_the_settings_script_is_only_loaded_on_the_settings_page() {
		Assets::enqueueSettings( 'tools_page_wpinat-tools' );
		Assets::enqueueSettings( 'index.php' );
		$this->assertSame( array(), $GLOBALS['wpinat_test']['scripts'] );

		Assets::enqueueSettings( 'settings_page_wp-inaturalist' );
		$script = $GLOBALS['wpinat_test']['scripts']['wpinat-settings'];
		$this->assertStringEndsWith( '/js/settings.js', $script[0] );
		$this->assertSame( array( 'wp-api-fetch' ), $script[1] );
		$this->assertTrue( $script[3], 'in the footer' );
		$this->assertFileExists( WPINAT_DIR . 'js/settings.js' );
	}

	public function test_the_settings_script_gets_a_message_for_every_status_the_route_can_answer() {
		Assets::enqueueSettings( 'settings_page_wp-inaturalist' );
		$inline = $GLOBALS['wpinat_test']['inline']['wpinat-settings'];
		$this->assertSame( 'before', $inline[1] );
		$json = json_decode( substr( $inline[0], strlen( 'window.wpinatSettings = ' ), -1 ), true );
		foreach ( array( 'checking', 'found', 'not_found', 'invalid', 'unavailable', 'error' ) as $key ) {
			$this->assertArrayHasKey( $key, $json['strings'] );
		}
		$this->assertStringContainsString( '%s', $json['strings']['found'] );
	}

	public function test_the_editor_script_depends_on_the_editor_packages() {
		Assets::enqueueEditor();
		$script = $GLOBALS['wpinat_test']['scripts']['wpinat-editor'];
		$this->assertStringEndsWith( '/js/editor.js', $script[0] );
		foreach ( array( 'wp-plugins', 'wp-element', 'wp-data', 'wp-api-fetch' ) as $dep ) {
			$this->assertContains( $dep, $script[1] );
		}
		$this->assertFileExists( WPINAT_DIR . 'js/editor.js' );
	}

	public function test_the_editor_script_gets_a_message_for_every_status_the_route_can_answer() {
		Assets::enqueueEditor();
		$inline = $GLOBALS['wpinat_test']['inline']['wpinat-editor'];
		$json   = json_decode( substr( $inline[0], strlen( 'window.wpinatEditor = ' ), -1 ), true );
		foreach ( array( 'title', 'button', 'saving', 'started', 'queued', 'no_block', 'no_login', 'bad_range', 'error' ) as $key ) {
			$this->assertArrayHasKey( $key, $json['strings'] );
		}
	}
}
