<?php
/**
 * Tests that the translation files follow the strings of the code.
 *
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * @package WpInaturalist
 */

namespace Evlist\WpInaturalist\Tests;

require_once __DIR__ . '/TestCase.php';

/**
 * A string added to the code without regenerating the .pot (with WP-CLI) fails here.
 */
final class TranslationsTest extends TestCase {

	/**
	 * Message ids of a .po or .pot file, with their translation.
	 *
	 * @param string $file File.
	 * @return array<string,string>
	 */
	private function entries( string $file ): array {
		preg_match_all( '/^msgid "(.*)"\nmsgstr "(.*)"$/m', (string) file_get_contents( $file ), $m, PREG_SET_ORDER );
		$out = array();
		foreach ( $m as $row ) {
			if ( '' !== $row[1] ) {
				$out[ stripcslashes( $row[1] ) ] = stripcslashes( $row[2] );
			}
		}
		return $out;
	}

	public function test_every_string_of_the_code_is_in_the_pot() {
		$pot = $this->entries( WPINAT_DIR . 'languages/wp-inaturalist.pot' );
		$this->assertNotEmpty( $pot );
		foreach ( glob( WPINAT_DIR . 'includes/*.php' ) as $file ) {
			preg_match_all( '/\b(?:__|esc_html__|esc_attr__|_e|esc_html_e)\(\s*(\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*")\s*,\s*\'wp-inaturalist\'/', (string) file_get_contents( $file ), $m );
			foreach ( $m[1] as $literal ) {
				$text = stripcslashes( substr( $literal, 1, -1 ) );
				$this->assertArrayHasKey( $text, $pot, basename( $file ) . ': ' . $text );
			}
		}
	}

	public function test_the_french_translation_is_complete() {
		$pot = $this->entries( WPINAT_DIR . 'languages/wp-inaturalist.pot' );
		$fr  = $this->entries( WPINAT_DIR . 'languages/wp-inaturalist-fr_FR.po' );
		$this->assertSame( array_keys( $pot ), array_keys( $fr ) );
		foreach ( $fr as $id => $translation ) {
			$this->assertNotSame( '', $translation, $id );
		}
	}
}
