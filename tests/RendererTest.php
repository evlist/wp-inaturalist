<?php
/**
 * Tests of Renderer.
 *
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * @package WpInaturalist
 */

namespace Evlist\WpInaturalist\Tests;

use Evlist\WpInaturalist\Renderer;

require_once __DIR__ . '/TestCase.php';

/**
 * Grouping and markup of the species list.
 */
final class RendererTest extends TestCase {

	/**
	 * A cache row.
	 *
	 * @param array $over Fields to override.
	 */
	private function row( array $over = array() ): array {
		return array_merge(
			array(
				'observation_id'  => 1,
				'uri'             => 'https://www.inaturalist.org/observations/1',
				'observed_on'     => '2026-11-20',
				'taxon_id'        => 14886,
				'scientific_name' => 'Mimus polyglottos',
				'common_name'     => 'moqueur polyglotte',
				'photos'          => wp_json_encode(
					array(
						array(
							'id'          => 9,
							'url'         => 'https://inaturalist-open-data.s3.amazonaws.com/photos/9/square.jpg',
							'attribution' => '(c) Jean, some rights reserved (CC BY-NC)',
							'license'     => 'cc-by-nc',
						),
					)
				),
			),
			$over
		);
	}

	public function test_nothing_to_show_gives_an_empty_string() {
		$this->assertSame( '', Renderer::html( array() ) );
		$this->assertSame( '', Renderer::html( array( $this->row( array( 'taxon_id' => null ) ) ) ), 'an observation without taxon is left out' );
		$this->assertSame(
			'',
			Renderer::html(
				array(
					$this->row(
						array(
							'scientific_name' => null,
							'common_name'     => null,
						)
					),
				)
			),
			'nothing to name it with'
		);
	}

	public function test_one_entry_per_taxon_in_order_of_first_observation_with_a_count() {
		$rows   = array(
			$this->row(
				array(
					'observation_id'  => 1,
					'taxon_id'        => 5,
					'common_name'     => 'Pie',
					'scientific_name' => 'Pica pica',
					'observed_on'     => '2026-11-18',
				)
			),
			$this->row(
				array(
					'observation_id'  => 2,
					'taxon_id'        => 7,
					'common_name'     => 'Merle',
					'scientific_name' => 'Turdus merula',
					'observed_on'     => '2026-11-19',
				)
			),
			$this->row(
				array(
					'observation_id'  => 3,
					'taxon_id'        => 5,
					'common_name'     => 'Pie',
					'scientific_name' => 'Pica pica',
					'observed_on'     => '2026-11-20',
				)
			),
		);
		$groups = Renderer::group( $rows );
		$this->assertSame( array( 5, 7 ), array_column( $groups, 'taxon_id' ) );
		$this->assertSame( array( 2, 1 ), array_column( $groups, 'count' ) );
		$html = Renderer::html( $rows );
		$this->assertSame( 2, substr_count( $html, '<li class="wpinat-entry"' ) );
		$this->assertStringContainsString( '<span class="wpinat-count">×2</span>', $html );
		$this->assertSame( 1, substr_count( $html, 'wpinat-count' ) );
	}

	public function test_an_entry_has_photo_credit_name_and_latin_name() {
		$html = Renderer::html( array( $this->row() ) );
		$this->assertStringContainsString( 'We observed the following species on iNaturalist:', $html );
		$this->assertStringContainsString( 'data-taxon-id="14886"', $html );
		$this->assertStringContainsString( '<a href="https://www.inaturalist.org/observations/1"><img src="https://inaturalist-open-data.s3.amazonaws.com/photos/9/medium.jpg" alt="moqueur polyglotte" loading="lazy" /></a>', $html );
		$this->assertStringContainsString( '<figcaption class="wpinat-credit">(c) Jean, some rights reserved (CC BY-NC)</figcaption>', $html );
		$this->assertStringContainsString( '<a href="https://www.inaturalist.org/taxa/14886">moqueur polyglotte</a>', $html );
		$this->assertStringContainsString( '<em class="wpinat-latin">Mimus polyglottos</em>', $html );
		$this->assertStringNotContainsString( 'wpinat-count', $html, 'no count for one observation' );
	}

	public function test_without_a_common_name_the_latin_name_is_the_name_and_is_not_repeated() {
		$html = Renderer::html( array( $this->row( array( 'common_name' => null ) ) ) );
		$this->assertStringContainsString( '>Mimus polyglottos</a>', $html );
		$this->assertStringNotContainsString( 'wpinat-latin', $html );
	}

	public function test_the_photo_comes_from_the_first_observation_that_has_one() {
		$rows = array(
			$this->row(
				array(
					'observation_id' => 1,
					'photos'         => '[]',
					'uri'            => 'https://www.inaturalist.org/observations/1',
				)
			),
			$this->row(
				array(
					'observation_id' => 2,
					'uri'            => 'https://www.inaturalist.org/observations/2',
				)
			),
		);
		$html = Renderer::html( $rows );
		$this->assertStringContainsString( 'href="https://www.inaturalist.org/observations/2"><img', $html );
	}

	public function test_an_entry_without_photo_is_still_shown() {
		$html = Renderer::html( array( $this->row( array( 'photos' => '[]' ) ) ) );
		$this->assertStringNotContainsString( '<figure', $html );
		$this->assertStringContainsString( 'moqueur polyglotte', $html );
	}

	public function test_a_photo_without_credit_has_no_caption() {
		$photo = wp_json_encode(
			array(
				array(
					'id'          => 9,
					'url'         => 'https://example.test/9/square.jpg',
					'attribution' => null,
					'license'     => null,
				),
			)
		);
		$html  = Renderer::html( array( $this->row( array( 'photos' => $photo ) ) ) );
		$this->assertStringContainsString( '<figure', $html );
		$this->assertStringNotContainsString( 'figcaption', $html );
	}

	public function test_hostile_text_and_urls_from_the_cache_are_neutralised() {
		$photo = wp_json_encode(
			array(
				array(
					'id'          => 9,
					'url'         => 'https://x.test/"onerror="alert(1)/square.jpg',
					'attribution' => '<script>alert(2)</script>',
				),
			)
		);
		$html  = Renderer::html(
			array(
				$this->row(
					array(
						'photos'          => $photo,
						'common_name'     => '<img src=x onerror=alert(3)>',
						'scientific_name' => '"><b>x</b>',
						'uri'             => 'javascript:alert(4)',
					)
				),
			)
		);
		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringNotContainsString( '<img src=x', $html );
		$this->assertStringNotContainsString( '<b>', $html );
		$this->assertStringNotContainsString( 'javascript:', $html );
		$this->assertStringNotContainsString( '"onerror=', $html );
	}

	public function test_a_non_https_photo_is_not_loaded() {
		foreach ( array( 'http://x.test/9/square.jpg', 'javascript:alert(1)', 'data:image/png;base64,AAAA' ) as $url ) {
			$photo = wp_json_encode(
				array(
					array(
						'id'  => 9,
						'url' => $url,
					),
				)
			);
			$this->assertStringNotContainsString( '<img', Renderer::html( array( $this->row( array( 'photos' => $photo ) ) ) ), $url );
		}
	}

	public function test_corrupted_photos_json_does_not_break_the_list() {
		foreach ( array( 'not json', '{"a":1}', '[[]]', null ) as $bad ) {
			$html = Renderer::html( array( $this->row( array( 'photos' => $bad ) ) ) );
			$this->assertStringContainsString( 'moqueur polyglotte', $html );
			$this->assertStringNotContainsString( '<img', $html );
		}
	}

	public function test_a_linked_media_library_photo_is_used_and_the_credit_stays() {
		$GLOBALS['wpinat_test']['attachments'][55] = 'https://blog.example.test/wp-content/uploads/pie.jpg';
		$html                                      = Renderer::html( array( $this->row( array( 'attachment_id' => 55 ) ) ) );
		$this->assertStringContainsString( '<a href="https://www.inaturalist.org/observations/1"><img src="https://blog.example.test/wp-content/uploads/pie.jpg" alt="moqueur polyglotte" class="local" /></a>', $html );
		$this->assertStringNotContainsString( 'inaturalist-open-data', $html );
		$this->assertStringContainsString( '(c) Jean, some rights reserved (CC BY-NC)', $html );
	}

	public function test_a_linked_attachment_that_is_gone_falls_back_to_the_iNaturalist_photo() {
		$html = Renderer::html( array( $this->row( array( 'attachment_id' => 999 ) ) ) );
		$this->assertStringContainsString( 'inaturalist-open-data.s3.amazonaws.com/photos/9/medium.jpg', $html );
		$this->assertStringNotContainsString( 'class="local"', $html );
	}

	public function test_the_first_observation_with_a_linked_photo_provides_the_photo_of_the_species() {
		$GLOBALS['wpinat_test']['attachments'][55] = 'https://blog.example.test/wp-content/uploads/second.jpg';
		$rows                                      = array(
			$this->row(
				array(
					'observation_id' => 1,
					'uri'            => 'https://www.inaturalist.org/observations/1',
					'attachment_id'  => null,
				)
			),
			$this->row(
				array(
					'observation_id' => 2,
					'uri'            => 'https://www.inaturalist.org/observations/2',
					'attachment_id'  => 55,
				)
			),
			$this->row(
				array(
					'observation_id' => 3,
					'uri'            => 'https://www.inaturalist.org/observations/3',
					'attachment_id'  => 56,
				)
			),
		);
		$html                                      = Renderer::html( $rows );
		$this->assertStringContainsString( 'observations/2"><img src="https://blog.example.test/wp-content/uploads/second.jpg"', $html );
		$this->assertStringContainsString( '×3', $html );
	}

	public function test_an_attachment_id_without_any_iNaturalist_photo_shows_no_photo() {
		$GLOBALS['wpinat_test']['attachments'][55] = 'https://blog.example.test/wp-content/uploads/x.jpg';
		$html                                      = Renderer::html(
			array(
				$this->row(
					array(
						'attachment_id' => 55,
						'photos'        => '[]',
					)
				),
			)
		);
		$this->assertStringNotContainsString( '<figure', $html, 'the credit comes with an iNaturalist photo' );
	}

	public function test_a_hostile_attachment_id_is_only_ever_an_integer() {
		$GLOBALS['wpinat_test']['attachments'][0] = 'https://blog.example.test/zero.jpg';
		foreach ( array( '0', '-3', 'abc', null ) as $bad ) {
			$html = Renderer::html( array( $this->row( array( 'attachment_id' => $bad ) ) ) );
			$this->assertStringNotContainsString( 'class="local"', $html, var_export( $bad, true ) );
		}
	}

	public function test_turning_the_option_off_stops_using_the_linked_photos_at_once() {
		$GLOBALS['wpinat_test']['attachments'][55] = 'https://blog.example.test/wp-content/uploads/pie.jpg';
		update_option( \Evlist\WpInaturalist\Config::USE_MEDIA, 0 );
		$html = Renderer::html( array( $this->row( array( 'attachment_id' => 55 ) ) ) );
		$this->assertStringNotContainsString( 'class="local"', $html );
		$this->assertStringContainsString( 'inaturalist-open-data', $html );
	}
}
