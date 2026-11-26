<?php
/**
 * Tests of Icons.
 *
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * @package WpInaturalist
 */

namespace Evlist\WpInaturalist\Tests;

use Evlist\WpInaturalist\Icons;

require_once __DIR__ . '/TestCase.php';

/**
 * The inline icons, from constant data.
 */
final class IconsTest extends TestCase {

	/**
	 * @dataProvider names
	 * @param string $name Icon name.
	 */
	public function test_each_icon_is_a_hidden_svg_with_a_view_box_and_a_path( string $name ) {
		$svg = Icons::svg( $name );
		$this->assertMatchesRegularExpression( '#^<svg class="wpinat-fa wpinat-fa-' . preg_quote( $name, '#' ) . '" viewBox="0 0 \d+ \d+" fill="currentColor" aria-hidden="true" focusable="false"><path d="[0-9A-Za-z\s.,\-]+"/></svg>$#', $svg );
	}

	public function names(): array {
		return array_map(
			static function ( $name ) {
				return array( $name );
			},
			Icons::NAMES
		);
	}

	public function test_an_extra_class_is_added_only_when_it_is_safe() {
		$this->assertStringContainsString( 'class="wpinat-fa wpinat-fa-eye wpinat-main"', Icons::svg( 'eye', 'wpinat-main' ) );
		foreach ( array( '"><script>', 'a b', '', 'x"y' ) as $bad ) {
			$this->assertStringContainsString( 'class="wpinat-fa wpinat-fa-eye"', Icons::svg( 'eye', $bad ), $bad );
		}
	}

	public function test_an_unknown_name_gives_nothing() {
		foreach ( array( '', 'eye"><script>', '../etc/passwd', 'EYE', 'binoculars' ) as $bad ) {
			$this->assertSame( '', Icons::svg( $bad ), $bad );
		}
	}

	public function test_the_data_file_carries_the_licence_of_the_icons_and_only_data() {
		$source = file_get_contents( WPINAT_DIR . 'includes/IconsData.php' );
		$this->assertStringContainsString( 'SPDX-License-Identifier: CC-BY-4.0', $source );
		$this->assertStringContainsString( 'Fonticons, Inc.', $source );
		$this->assertFileExists( WPINAT_DIR . 'LICENSES/CC-BY-4.0.txt' );
		$data = require WPINAT_DIR . 'includes/IconsData.php';
		$this->assertSame( Icons::NAMES, array_keys( $data ) );
	}
}
