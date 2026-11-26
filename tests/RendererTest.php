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

	/**
	 * The gallery layout (the previous presentation, kept as an option).
	 *
	 * @param array $rows Rows.
	 * @param array $taxa Wikidata items by taxon id.
	 */
	private function gallery( array $rows, array $taxa = array() ): string {
		return Renderer::html( $rows, $taxa, Renderer::GALLERY );
	}

	public function test_nothing_to_show_gives_an_empty_string() {
		$this->assertSame( '', $this->gallery( array() ) );
		$this->assertSame( '', $this->gallery( array( $this->row( array( 'taxon_id' => null ) ) ) ), 'an observation without taxon is left out' );
		$this->assertSame(
			'',
			$this->gallery(
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
		$html = $this->gallery( $rows );
		$this->assertSame( 2, substr_count( $html, '<li class="wpinat-entry"' ) );
		$this->assertStringContainsString( '<span class="wpinat-count">×2</span>', $html );
		$this->assertSame( 1, substr_count( $html, 'wpinat-count' ) );
	}

	public function test_an_entry_has_photo_credit_name_and_latin_name() {
		$html = $this->gallery( array( $this->row() ) );
		$this->assertStringContainsString( 'We identified the following species with iNaturalist:', $html );
		$this->assertStringContainsString( 'data-taxon-id="14886"', $html );
		$this->assertStringContainsString( '<a href="https://www.inaturalist.org/observations/1"><img src="https://inaturalist-open-data.s3.amazonaws.com/photos/9/medium.jpg" alt="moqueur polyglotte" loading="lazy" /></a>', $html );
		$this->assertStringContainsString( '<figcaption class="wpinat-credit">(c) Jean, some rights reserved (CC BY-NC)</figcaption>', $html );
		$this->assertStringContainsString( '<a href="https://www.inaturalist.org/taxa/14886">moqueur polyglotte</a>', $html );
		$this->assertStringContainsString( '<em class="wpinat-latin">Mimus polyglottos</em>', $html );
		$this->assertStringNotContainsString( 'wpinat-count', $html, 'no count for one observation' );
	}

	public function test_without_a_common_name_the_latin_name_is_the_name_and_is_not_repeated() {
		$html = $this->gallery( array( $this->row( array( 'common_name' => null ) ) ) );
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
		$html = $this->gallery( $rows );
		$this->assertStringContainsString( 'href="https://www.inaturalist.org/observations/2"><img', $html );
	}

	public function test_an_entry_without_photo_is_still_shown() {
		$html = $this->gallery( array( $this->row( array( 'photos' => '[]' ) ) ) );
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
		$html  = $this->gallery( array( $this->row( array( 'photos' => $photo ) ) ) );
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
		$html  = $this->gallery(
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
			$this->assertStringNotContainsString( '<img', $this->gallery( array( $this->row( array( 'photos' => $photo ) ) ) ), $url );
		}
	}

	public function test_corrupted_photos_json_does_not_break_the_list() {
		foreach ( array( 'not json', '{"a":1}', '[[]]', null ) as $bad ) {
			$html = $this->gallery( array( $this->row( array( 'photos' => $bad ) ) ) );
			$this->assertStringContainsString( 'moqueur polyglotte', $html );
			$this->assertStringNotContainsString( '<img', $html );
		}
	}

	public function test_a_linked_media_library_photo_is_used_and_the_credit_stays() {
		$GLOBALS['wpinat_test']['attachments'][55] = 'https://blog.example.test/wp-content/uploads/pie.jpg';
		$html                                      = $this->gallery( array( $this->row( array( 'attachment_id' => 55 ) ) ) );
		$this->assertStringContainsString( '<a href="https://www.inaturalist.org/observations/1"><img src="https://blog.example.test/wp-content/uploads/pie.jpg" alt="moqueur polyglotte" data-size="medium" class="local" /></a>', $html );
		$this->assertStringNotContainsString( 'inaturalist-open-data', $html );
		$this->assertStringContainsString( '(c) Jean, some rights reserved (CC BY-NC)', $html );
	}

	public function test_a_linked_attachment_that_is_gone_falls_back_to_the_iNaturalist_photo() {
		$html = $this->gallery( array( $this->row( array( 'attachment_id' => 999 ) ) ) );
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
		$html                                      = $this->gallery( $rows );
		$this->assertStringContainsString( 'observations/2"><img src="https://blog.example.test/wp-content/uploads/second.jpg"', $html );
		$this->assertStringContainsString( '×3', $html );
	}

	public function test_an_attachment_id_without_any_iNaturalist_photo_shows_no_photo() {
		$GLOBALS['wpinat_test']['attachments'][55] = 'https://blog.example.test/wp-content/uploads/x.jpg';
		$html                                      = $this->gallery(
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
			$html = $this->gallery( array( $this->row( array( 'attachment_id' => $bad ) ) ) );
			$this->assertStringNotContainsString( 'class="local"', $html, var_export( $bad, true ) );
		}
	}

	public function test_turning_the_option_off_stops_using_the_linked_photos_at_once() {
		$GLOBALS['wpinat_test']['attachments'][55] = 'https://blog.example.test/wp-content/uploads/pie.jpg';
		update_option( \Evlist\WpInaturalist\Config::USE_MEDIA, 0 );
		$html = $this->gallery( array( $this->row( array( 'attachment_id' => 55 ) ) ) );
		$this->assertStringNotContainsString( 'class="local"', $html );
		$this->assertStringContainsString( 'inaturalist-open-data', $html );
	}

	public function test_an_entry_whose_taxon_has_a_wikidata_item_carries_the_index_mark() {
		$html = $this->gallery(
			array( $this->row() ),
			array(
				14886 => array(
					'qid'           => 'Q829683',
					'label'         => 'Moqueur polyglotte',
					'wikipedia_url' => 'https://fr.wikipedia.org/wiki/Moqueur_polyglotte',
				),
			)
		);
		$this->assertStringContainsString( ' data-index="{&quot;topic&quot;:&quot;wikidata:Q829683&quot;,&quot;kind&quot;:&quot;species&quot;,&quot;label&quot;:&quot;Moqueur polyglotte&quot;,&quot;source&quot;:&quot;inaturalist&quot;}"', $html );
		$this->assertStringNotContainsString( 'wikipedia.org', $html, 'the article address is stored, not shown (open question 6)' );
	}

	public function test_the_mark_decodes_to_what_the_index_module_reads() {
		$html = $this->gallery(
			array( $this->row() ),
			array(
				14886 => array(
					'qid'   => 'Q829683',
					'label' => 'Pinson des arbres',
				),
			)
		);
		preg_match( '/ data-index="([^"]*)"/', $html, $m );
		$this->assertSame(
			array(
				'topic'  => 'wikidata:Q829683',
				'kind'   => 'species',
				'label'  => 'Pinson des arbres',
				'source' => 'inaturalist',
			),
			json_decode( html_entity_decode( $m[1], ENT_QUOTES ), true )
		);
	}

	public function test_without_a_wikidata_label_the_mark_has_no_label_and_never_the_iNaturalist_name() {
		foreach ( array( null, '', '   ' ) as $label ) {
			$html = $this->gallery(
				array( $this->row() ),
				array(
					14886 => array(
						'qid'   => 'Q829683',
						'label' => $label,
					),
				)
			);
			preg_match( '/ data-index="([^"]*)"/', $html, $m );
			$mark = json_decode( html_entity_decode( $m[1], ENT_QUOTES ), true );
			$this->assertArrayNotHasKey( 'label', $mark, var_export( $label, true ) );
			$this->assertSame( 'wikidata:Q829683', $mark['topic'] );
		}
	}

	public function test_a_non_ascii_label_stays_readable() {
		$html = $this->gallery(
			array( $this->row() ),
			array(
				14886 => array(
					'qid'   => 'Q1',
					'label' => 'Sittelle torchepot « é »',
				),
			)
		);
		$this->assertStringContainsString( 'Sittelle torchepot « é »', $html );
		$this->assertStringNotContainsString( '\\u00', $html );
	}

	public function test_a_taxon_without_an_item_or_with_a_malformed_one_has_no_mark_and_the_output_is_unchanged() {
		$plain = $this->gallery( array( $this->row() ) );
		$this->assertStringNotContainsString( 'data-index', $plain );
		foreach ( array( array(), array( 14886 => array( 'qid' => null ) ), array( 14886 => array( 'qid' => 'Q0' ) ), array( 14886 => array( 'qid' => 'q5' ) ), array( 14886 => array( 'qid' => 'Q5"><script>' ) ), array( 14886 => array( 'qid' => 5 ) ), array( 14886 => array() ) ) as $taxa ) {
			$this->assertSame( $plain, $this->gallery( array( $this->row() ), $taxa ), json_encode( $taxa ) );
		}
	}

	public function test_a_hostile_label_cannot_break_out_of_the_attribute() {
		$html = $this->gallery(
			array( $this->row() ),
			array(
				14886 => array(
					'qid'   => 'Q5',
					'label' => '"><script>alert(1)</script>',
				),
			)
		);
		$this->assertStringNotContainsString( '<script>', $html );
		preg_match( '/ data-index="([^"]*)"/', $html, $m );
		$this->assertSame( '"><script>alert(1)</script>', json_decode( html_entity_decode( $m[1], ENT_QUOTES ), true )['label'], 'the text survives as data' );
	}

	public function test_the_mark_is_on_the_li_and_each_species_has_its_own() {
		$rows = array(
			$this->row(
				array(
					'observation_id'  => 1,
					'taxon_id'        => 5,
					'common_name'     => 'Pie',
					'scientific_name' => 'Pica pica',
				)
			),
			$this->row(
				array(
					'observation_id'  => 2,
					'taxon_id'        => 7,
					'common_name'     => 'Merle',
					'scientific_name' => 'Turdus merula',
				)
			),
		);
		$html = $this->gallery(
			$rows,
			array(
				5 => array( 'qid' => 'Q5' ),
				7 => array( 'qid' => 'Q7' ),
			)
		);
		$this->assertSame( 2, preg_match_all( '/<li class="wpinat-entry" data-taxon-id="\d+" data-index="[^"]+">/', $html ) );
		$this->assertStringContainsString( 'wikidata:Q5', $html );
		$this->assertStringContainsString( 'wikidata:Q7', $html );
	}

	/**
	 * Marks the observation of a card by the iNaturalist test address and gives the common cases.
	 *
	 * @param array $over Fields of the row.
	 * @param array $taxa Wikidata items.
	 */
	private function card( array $over = array(), array $taxa = array() ): string {
		return Renderer::html( array( $this->row( $over ) ), $taxa );
	}

	public function test_the_card_is_the_default_layout_and_the_wrapper_says_which() {
		$this->assertStringContainsString( 'class="wpinat-observations wpinat-layout-card"', Renderer::html( array( $this->row() ) ) );
		$this->assertStringContainsString( 'wpinat-layout-gallery', Renderer::html( array( $this->row() ), array(), Renderer::GALLERY ) );
		$this->assertStringContainsString( 'wpinat-layout-card', Renderer::html( array( $this->row() ), array(), 'something else' ), 'an unknown layout means card' );
	}

	public function test_a_card_has_a_thumbnail_the_names_and_no_visible_credit() {
		$html = $this->card();
		$this->assertStringContainsString( '<span class="wpinat-thumb"><img src="https://inaturalist-open-data.s3.amazonaws.com/photos/9/small.jpg" alt="moqueur polyglotte" title="(c) Jean, some rights reserved (CC BY-NC)" width="80" height="80" loading="lazy" /></span>', $html );
		$this->assertStringContainsString( '<span class="wpinat-info"><span class="wpinat-name">moqueur polyglotte</span><em class="wpinat-latin">Mimus polyglottos</em></span>', $html );
		$this->assertStringNotContainsString( 'figcaption', $html, 'the credit is not a visible line' );
		$this->assertStringNotContainsString( 'wpinat-credit', $html );
	}

	public function test_in_a_card_neither_the_photo_nor_the_name_is_a_link_and_there_are_at_most_two_links() {
		$html = $this->card(
			array(),
			array(
				14886 => array(
					'qid'           => 'Q1',
					'wikipedia_url' => 'https://fr.wikipedia.org/wiki/Moqueur_polyglotte',
				),
			)
		);
		$this->assertSame( 2, substr_count( $html, '<a ' ) );
		$this->assertStringNotContainsString( '<a href="https://www.inaturalist.org/taxa', $html, 'the name is not a link any more' );
		$this->assertDoesNotMatchRegularExpression( '#<a[^>]*><span class="wpinat-thumb"#', $html );
		$this->assertDoesNotMatchRegularExpression( '#<a[^>]*>[^<]*<img#', $html, 'the photo is not a link' );
	}

	public function test_the_links_are_the_observation_on_top_and_wikipedia_below_with_accessible_names() {
		update_option( \Evlist\WpInaturalist\Config::NAMES_LOCALE, 'fr' ); // The site language: the French article needs no language in its tooltip.
		$html = $this->card(
			array(),
			array(
				14886 => array(
					'qid'           => 'Q1',
					'wikipedia_url' => 'https://fr.wikipedia.org/wiki/Moqueur_polyglotte',
				),
			)
		);
		$this->assertStringContainsString( '<span class="wpinat-links wpinat-links-2">', $html );
		$obs  = strpos( $html, 'wpinat-link-observation' );
		$wiki = strpos( $html, 'wpinat-link-wikipedia' );
		$this->assertTrue( $obs > 0 && $wiki > $obs, 'observation first (top), Wikipedia second (bottom)' );
		$this->assertStringContainsString( 'href="https://www.inaturalist.org/observations/1" target="_blank" rel="noopener noreferrer" aria-label="View the observation on iNaturalist" title="View the observation on iNaturalist"', $html );
		$this->assertStringContainsString( 'href="https://fr.wikipedia.org/wiki/Moqueur_polyglotte" target="_blank" rel="noopener noreferrer" aria-label="Wikipedia article" title="Wikipedia article"', $html );
		$this->assertSame( 2, substr_count( $html, 'class="wpinat-ext" aria-hidden="true">↗</span>' ), 'the external-link mark, hidden from screen readers' );
		$this->assertStringContainsString( '<svg class="wpinat-icon"', $html );
		$this->assertStringContainsString( '<span class="wpinat-icon-w" aria-hidden="true">W</span>', $html );
	}

	public function test_without_any_wikipedia_article_there_is_one_centred_link() {
		$html = $this->card();
		$this->assertStringContainsString( 'wpinat-links wpinat-links-1', $html );
		$this->assertStringNotContainsString( 'wpinat-link-wikipedia', $html );
	}

	public function test_the_site_language_article_wins_over_the_english_one_and_is_not_labelled_with_a_language() {
		update_option( \Evlist\WpInaturalist\Config::NAMES_LOCALE, 'fr' );
		$html = $this->card(
			array( 'wikipedia_url' => 'http://en.wikipedia.org/wiki/Northern_mockingbird' ),
			array(
				14886 => array(
					'qid'           => 'Q1',
					'wikipedia_url' => 'https://fr.wikipedia.org/wiki/Moqueur_polyglotte',
				),
			)
		);
		$this->assertStringContainsString( 'fr.wikipedia.org/wiki/Moqueur_polyglotte', $html );
		$this->assertStringNotContainsString( 'en.wikipedia.org', $html );
	}

	public function test_without_an_article_in_the_site_language_the_english_one_is_used_over_https_and_named_in_its_tooltip() {
		update_option( \Evlist\WpInaturalist\Config::NAMES_LOCALE, 'fr' );
		$html = $this->card( array( 'wikipedia_url' => 'http://en.wikipedia.org/wiki/Northern_mockingbird' ) );
		$this->assertStringContainsString( 'href="https://en.wikipedia.org/wiki/Northern_mockingbird"', $html, 'http becomes https' );
		$this->assertStringContainsString( 'title="Wikipedia article (en)"', $html );
		update_option( \Evlist\WpInaturalist\Config::NAMES_LOCALE, 'en' );
		$this->assertStringContainsString( 'title="Wikipedia article"', $this->card( array( 'wikipedia_url' => 'http://en.wikipedia.org/wiki/Northern_mockingbird' ) ), 'the English site needs no language in the tooltip' );
	}

	/**
	 * @dataProvider badWikipedia
	 * @param mixed $address Address in the cache.
	 */
	public function test_only_a_well_formed_wikipedia_article_address_is_linked( $address ) {
		$html = $this->card(
			array( 'wikipedia_url' => $address ),
			array(
				14886 => array(
					'qid'           => 'Q1',
					'wikipedia_url' => $address,
				),
			)
		);
		$this->assertStringNotContainsString( 'wpinat-link-wikipedia', $html, var_export( $address, true ) );
	}

	public function badWikipedia(): array {
		return array(
			'javascript'      => array( 'javascript:alert(1)' ),
			'another host'    => array( 'https://evil.test/wiki/Pie' ),
			'a look-alike'    => array( 'https://en.wikipedia.org.evil.test/wiki/Pie' ),
			'not an article'  => array( 'https://en.wikipedia.org/w/index.php?title=Pie' ),
			'a long language' => array( 'https://english.wikipedia.org/wiki/Pie' ),
			'spaces'          => array( 'https://en.wikipedia.org/wiki/' ),
			'empty'           => array( '' ),
			'null'            => array( null ),
			'array'           => array( array( 'https://en.wikipedia.org/wiki/Pie' ) ),
		);
	}

	public function test_the_observation_link_goes_to_the_observation_of_the_shown_photo_else_the_first_one() {
		$rows = array(
			$this->row(
				array(
					'observation_id' => 1,
					'uri'            => 'https://www.inaturalist.org/observations/1',
					'photos'         => '[]',
				)
			),
			$this->row(
				array(
					'observation_id' => 2,
					'uri'            => 'https://www.inaturalist.org/observations/2',
				)
			),
		);
		$this->assertStringContainsString( 'href="https://www.inaturalist.org/observations/2"', Renderer::html( $rows ), 'the photo comes from observation 2' );
		$noPhoto = array(
			$this->row(
				array(
					'uri'    => 'https://www.inaturalist.org/observations/7',
					'photos' => '[]',
				)
			),
		);
		$html    = Renderer::html( $noPhoto );
		$this->assertStringContainsString( 'href="https://www.inaturalist.org/observations/7"', $html );
		$this->assertStringNotContainsString( 'wpinat-thumb', $html, 'no photo, no thumbnail' );
	}

	public function test_a_non_https_observation_address_is_not_linked() {
		foreach ( array( 'http://www.inaturalist.org/observations/1', 'javascript:alert(1)', '' ) as $uri ) {
			$html = $this->card( array( 'uri' => $uri ) );
			$this->assertStringNotContainsString( 'wpinat-link-observation', $html, $uri );
			$this->assertStringNotContainsString( 'wpinat-links', $html, 'no link at all, no empty container' );
		}
	}

	public function test_a_card_shows_the_count_next_to_the_name() {
		$rows = array( $this->row( array( 'observation_id' => 1 ) ), $this->row( array( 'observation_id' => 2 ) ) );
		$this->assertStringContainsString( '<span class="wpinat-name">moqueur polyglotte <span class="wpinat-count">×2</span></span>', Renderer::html( $rows ) );
	}

	public function test_a_card_uses_the_thumbnail_size_of_the_media_library_copy_with_the_credit_as_title() {
		$GLOBALS['wpinat_test']['attachments'][55] = 'https://blog.example.test/wp-content/uploads/pie.jpg';
		$html                                      = $this->card( array( 'attachment_id' => 55 ) );
		$this->assertStringContainsString( 'alt="moqueur polyglotte" title="(c) Jean, some rights reserved (CC BY-NC)" sizes="80px" data-size="thumbnail" class="local"', $html );
		$this->assertStringNotContainsString( 'inaturalist-open-data', $html );
		update_option( \Evlist\WpInaturalist\Config::USE_MEDIA, 0 );
		$this->assertStringContainsString( 'inaturalist-open-data', $this->card( array( 'attachment_id' => 55 ) ), 'option off: the iNaturalist photo' );
	}

	public function test_a_card_never_loads_a_non_https_photo_and_escapes_everything() {
		$photo = wp_json_encode(
			array(
				array(
					'id'          => 9,
					'url'         => 'http://x.test/9/square.jpg',
					'attribution' => '"><script>alert(1)</script>',
				),
			)
		);
		$html  = $this->card(
			array(
				'photos'          => $photo,
				'common_name'     => '<img src=x onerror=alert(2)>',
				'scientific_name' => '"><b>x</b>',
			)
		);
		$this->assertStringNotContainsString( '<img src="http', $html );
		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringNotContainsString( '<b>', $html );
		$this->assertStringNotContainsString( '<img src=x', $html );
		$hostile = wp_json_encode(
			array(
				array(
					'id'          => 9,
					'url'         => 'https://x.test/9/square.jpg',
					'attribution' => '" onmouseover="alert(1)',
				),
			)
		);
		$this->assertStringNotContainsString( 'onmouseover="alert', $this->card( array( 'photos' => $hostile ) ) );
	}

	public function test_the_index_mark_is_on_the_card_too() {
		$html = $this->card(
			array(),
			array(
				14886 => array(
					'qid'   => 'Q829683',
					'label' => 'Moqueur polyglotte',
				),
			)
		);
		$this->assertStringContainsString( '<li class="wpinat-entry" data-taxon-id="14886" data-index="{&quot;topic&quot;:&quot;wikidata:Q829683&quot;', $html );
	}

	public function test_the_gallery_keeps_its_previous_links_and_credit() {
		$html = $this->gallery( array( $this->row() ) );
		$this->assertStringContainsString( 'figcaption class="wpinat-credit"', $html );
		$this->assertStringContainsString( '<a href="https://www.inaturalist.org/taxa/14886">moqueur polyglotte</a>', $html );
		$this->assertStringNotContainsString( 'wpinat-links', $html );
	}
}
