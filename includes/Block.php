<?php
/**
 * The block wpinat/wp-inaturalist.
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
 * Registration and server-side rendering. The block only reads the cache: no API call is made from here.
 */
final class Block {

	const EDITOR_HANDLE = 'wpinat-block-editor';

	/**
	 * Registers the editor script and the block (hook init).
	 */
	public static function register(): void {
		wp_register_script(
			self::EDITOR_HANDLE,
			plugins_url( 'blocks/wp-inaturalist/index.js', WPINAT_FILE ),
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-server-side-render', 'wp-data' ),
			WPINAT_VERSION,
			true
		);
		wp_add_inline_script( self::EDITOR_HANDLE, 'window.wpinatBlock = ' . wp_json_encode( array( 'strings' => self::editorStrings() ) ) . ';', 'before' );
		register_block_type( WPINAT_DIR . 'blocks/wp-inaturalist', array( 'render_callback' => array( self::class, 'render' ) ) );
	}

	/**
	 * Texts of the editor script.
	 *
	 * @return array<string,string>
	 */
	public static function editorStrings(): array {
		return array(
			'period'     => __( 'Period', 'wp-inaturalist' ),
			'number'     => __( 'Number of units', 'wp-inaturalist' ),
			'unit'       => __( 'Unit', 'wp-inaturalist' ),
			'day'        => __( 'Days', 'wp-inaturalist' ),
			'week'       => __( 'Weeks', 'wp-inaturalist' ),
			'month'      => __( 'Months', 'wp-inaturalist' ),
			'help'       => __( 'The period ends on the date of the post.', 'wp-inaturalist' ),
			'empty_html' => __( 'Nothing to show yet.', 'wp-inaturalist' ),
		);
	}

	/**
	 * Render callback.
	 *
	 * @param array  $attributes Block attributes.
	 * @param string $content    Inner content (none).
	 * @param mixed  $block      Block instance.
	 */
	public static function render( $attributes = array(), $content = '', $block = null ): string {
		unset( $content, $block );
		$editing = defined( 'REST_REQUEST' ) && REST_REQUEST;
		$login   = Config::getLogin();
		if ( '' === $login ) {
			return $editing ? self::hint( __( 'Set the iNaturalist login in the settings first.', 'wp-inaturalist' ) ) : '';
		}
		$post = get_post();
		if ( ! $post ) {
			return '';
		}
		$attributes = is_array( $attributes ) ? $attributes : array();
		try {
			$range = Period::range(
				PostRefresh::dateOf( $post ),
				isset( $attributes['periodNumber'] ) ? $attributes['periodNumber'] : Period::DEFAULT_NUM,
				isset( $attributes['periodUnit'] ) ? $attributes['periodUnit'] : 'day'
			);
		} catch ( \InvalidArgumentException $e ) {
			return '';
		}
		global $wpdb;
		$rows = ( new Cache( $wpdb ) )->getBetween( $login, $range[0], $range[1] );
		$html = Renderer::html( $rows, ( new Taxa( $wpdb ) )->get( array_column( Renderer::group( $rows ), 'taxon_id' ) ) );
		if ( '' === $html && $editing ) {
			return self::hint( __( 'No observation in the cache for this period. Save the post, then use "Refresh observations" in the sidebar.', 'wp-inaturalist' ) );
		}
		return $html;
	}

	/**
	 * A hint shown in the editor preview only.
	 *
	 * @param string $text Text.
	 */
	private static function hint( string $text ): string {
		return '<p class="wpinat-hint">' . esc_html( $text ) . '</p>';
	}
}
