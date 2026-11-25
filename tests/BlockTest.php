<?php
/**
 * Tests of Block.
 *
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * @package WpInaturalist
 */

namespace Evlist\WpInaturalist\Tests;

use Evlist\WpInaturalist\Block;
use Evlist\WpInaturalist\Cache;
use Evlist\WpInaturalist\Config;

require_once __DIR__ . '/TestCase.php';

/**
 * Registration and rendering of the block.
 *
 * Block registration from block.json, the render callback in a real page and the editor preview were never tried in WordPress.
 */
final class BlockTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		update_option( Config::LOGIN, 'me' );
		$GLOBALS['wpinat_test']['current_post'] = (object) array(
			'ID'           => 42,
			'post_date'    => '2026-11-20 14:05:00',
			'post_content' => '',
			'post_type'    => 'post',
		);
		( new Cache( $GLOBALS['wpdb'] ) )->replaceRange(
			'me',
			'2026-11-01',
			'2026-11-30',
			array( $this->observation( 1, '2026-11-12' ), $this->observation( 2, '2026-11-19' ), $this->observation( 3, '2026-11-20' ), $this->observation( 4, '2026-11-21' ) ),
			'fr',
			1
		);
	}

	public function test_register_registers_the_editor_script_and_the_block_with_its_render_callback() {
		Block::register();
		$script = $GLOBALS['wpinat_test']['registered']['wpinat-block-editor'];
		$this->assertStringEndsWith( '/blocks/wp-inaturalist/index.js', $script[0] );
		foreach ( array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-server-side-render' ) as $dep ) {
			$this->assertContains( $dep, $script[1] );
		}
		$this->assertFileExists( WPINAT_DIR . 'blocks/wp-inaturalist/index.js' );
		$this->assertSame( WPINAT_DIR . 'blocks/wp-inaturalist', $GLOBALS['wpinat_test']['block_types'][0][0] );
		$this->assertSame( array( Block::class, 'render' ), $GLOBALS['wpinat_test']['block_types'][0][1]['render_callback'] );
		$this->assertArrayHasKey( 'wpinat-block-editor', $GLOBALS['wpinat_test']['inline'] );
	}

	public function test_block_json_is_what_the_other_parts_expect() {
		$json = json_decode( file_get_contents( WPINAT_DIR . 'blocks/wp-inaturalist/block.json' ), true );
		$this->assertSame( Config::BLOCK_NAME, $json['name'], 'the name Period looks for' );
		$this->assertSame( 'wpinat-block-editor', $json['editorScript'], 'the handle Block registers' );
		$this->assertSame( 'wp-inaturalist', $json['textdomain'] );
		$this->assertSame( 1, $json['attributes']['periodNumber']['default'], 'same defaults as Period' );
		$this->assertSame( 'day', $json['attributes']['periodUnit']['default'] );
		$this->assertArrayNotHasKey( 'render', $json, 'rendering is the PHP callback' );
		$this->assertFileExists( WPINAT_DIR . 'blocks/wp-inaturalist/style.css' );
	}

	public function test_the_block_shows_the_observations_of_the_period_ending_on_the_post_date() {
		$html = Block::render(
			array(
				'periodNumber' => 2,
				'periodUnit'   => 'day',
			)
		);
		$this->assertStringContainsString( 'Species 2', $html );
		$this->assertStringContainsString( 'Species 3', $html );
		$this->assertStringNotContainsString( 'Species 1', $html, 'older than the period' );
		$this->assertStringNotContainsString( 'Species 4', $html, 'after the post date' );
	}

	public function test_the_default_period_is_the_day_of_the_post() {
		$html = Block::render();
		$this->assertStringContainsString( 'Species 3', $html );
		$this->assertStringNotContainsString( 'Species 2', $html );
	}

	public function test_a_week_and_a_month_reach_further_back() {
		$this->assertStringContainsString(
			'Species 2',
			Block::render(
				array(
					'periodNumber' => 1,
					'periodUnit'   => 'week',
				)
			)
		);
		$this->assertStringNotContainsString(
			'Species 1',
			Block::render(
				array(
					'periodNumber' => 1,
					'periodUnit'   => 'week',
				)
			)
		);
		$this->assertStringContainsString(
			'Species 1',
			Block::render(
				array(
					'periodNumber' => 1,
					'periodUnit'   => 'month',
				)
			)
		);
	}

	public function test_the_block_never_calls_the_api() {
		Block::render();
		$this->assertSame( array(), $GLOBALS['wpinat_test']['scheduled'], 'no refresh is scheduled by a page view' );
		$this->assertFalse( get_option( Config::JOB_STATE, false ) );
	}

	public function test_observations_of_another_account_are_not_shown() {
		update_option( Config::LOGIN, 'someone-else' );
		$this->assertSame( '', Block::render() );
	}

	public function test_on_the_site_nothing_is_printed_when_there_is_nothing_to_show() {
		$GLOBALS['wpinat_test']['current_post']->post_date = '2026-01-01 10:00:00';
		$this->assertSame( '', Block::render() );
		update_option( Config::LOGIN, '' );
		$this->assertSame( '', Block::render() );
	}

	public function test_no_post_no_output() {
		$GLOBALS['wpinat_test']['current_post'] = null;
		$this->assertSame( '', Block::render() );
	}

	public function test_garbage_attributes_fall_back_to_the_defaults() {
		$html = Block::render(
			array(
				'periodNumber' => 'abc',
				'periodUnit'   => '<script>',
			)
		);
		$this->assertStringContainsString( 'Species 3', $html );
		$this->assertStringNotContainsString( 'Species 2', $html );
		$this->assertSame( Block::render(), Block::render( 'not an array' ), 'a non-array means no attributes' );
	}

	/**
	 * REST_REQUEST is a constant: this test defines it in its own PHP process.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_in_the_editor_an_empty_period_explains_what_to_do() {
		define( 'REST_REQUEST', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WordPress's own constant.
		$GLOBALS['wpinat_test']['current_post']->post_date = '2026-01-01 10:00:00';
		$this->assertStringContainsString( 'Refresh observations', Block::render() );
		update_option( Config::LOGIN, '' );
		$this->assertStringContainsString( 'Set the iNaturalist login in the settings first.', Block::render() );
		update_option( Config::LOGIN, 'me' );
		$GLOBALS['wpinat_test']['current_post']->post_date = '2026-11-20 10:00:00';
		$this->assertStringNotContainsString( 'wpinat-hint', Block::render(), 'no hint when there is something to show' );
	}

	public function test_the_block_puts_the_index_mark_from_the_taxa_table() {
		$GLOBALS['wpdb']->taxa_rows[1003] = array(
			'taxon_id'      => 1003,
			'lang'          => 'fr',
			'qid'           => 'Q42',
			'label'         => 'Espèce trois',
			'wikipedia_url' => null,
			'checked_at'    => '2026-11-25 10:00:00',
		);
		$html                             = Block::render();
		$this->assertStringContainsString( 'wikidata:Q42', $html );
		$this->assertStringContainsString( 'Espèce trois', $html );
		$this->assertSame( 1, substr_count( $html, 'data-index=' ) );
	}

	public function test_the_block_never_asks_wikidata() {
		Block::render();
		$this->assertSame( array(), $GLOBALS['wpinat_test']['scheduled'] );
		foreach ( $GLOBALS['wpdb']->queries as $sql ) {
			$this->assertStringNotContainsString( 'INSERT', $sql );
		}
	}
}
