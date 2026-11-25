<?php
/**
 * HTML of the list of species.
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
 * Turns cached rows into markup. Names, URLs and credits come from the API through the cache: everything is escaped here.
 */
final class Renderer {

	const TAXON_URL = 'https://www.inaturalist.org/taxa/';

	/**
	 * Groups the cached observations by taxon, in order of first observation.
	 *
	 * An observation without taxon cannot be named and is left out.
	 *
	 * @param array<int,array<string,mixed>> $rows Rows of the cache, oldest first.
	 * @return array<int,array<string,mixed>> One entry per taxon.
	 */
	public static function group( array $rows ): array {
		$groups = array();
		foreach ( $rows as $row ) {
			$taxon = isset( $row['taxon_id'] ) ? (int) $row['taxon_id'] : 0;
			if ( $taxon <= 0 ) {
				continue;
			}
			if ( ! isset( $groups[ $taxon ] ) ) {
				$latin            = isset( $row['scientific_name'] ) ? trim( (string) $row['scientific_name'] ) : '';
				$common           = isset( $row['common_name'] ) ? trim( (string) $row['common_name'] ) : '';
				$groups[ $taxon ] = array(
					'taxon_id' => $taxon,
					'name'     => '' !== $common ? $common : $latin,
					'latin'    => ( '' !== $common && '' !== $latin ) ? $latin : '',
					'count'    => 0,
					'photo'    => null,
					'uri'      => '',
				);
			}
			++$groups[ $taxon ]['count'];
			if ( null === $groups[ $taxon ]['photo'] ) {
				$photos = isset( $row['photos'] ) ? json_decode( (string) $row['photos'], true ) : null;
				if ( is_array( $photos ) && isset( $photos[0]['url'] ) && is_string( $photos[0]['url'] ) ) {
					$groups[ $taxon ]['photo'] = $photos[0];
					$groups[ $taxon ]['uri']   = isset( $row['uri'] ) ? (string) $row['uri'] : '';
				}
			}
		}
		return array_values(
			array_filter(
				$groups,
				static function ( $group ) {
					return '' !== $group['name'];
				}
			)
		);
	}

	/**
	 * The list, or an empty string when there is nothing to show.
	 *
	 * @param array<int,array<string,mixed>> $rows Rows of the cache, oldest first.
	 */
	public static function html( array $rows ): string {
		$groups = self::group( $rows );
		if ( array() === $groups ) {
			return '';
		}
		$items = '';
		foreach ( $groups as $group ) {
			$items .= self::entry( $group );
		}
		return '<div class="wpinat-observations"><p class="wpinat-intro">'
			. esc_html__( 'We observed the following species on iNaturalist:', 'wp-inaturalist' )
			. '</p><ul class="wpinat-list">' . $items . '</ul></div>';
	}

	/**
	 * One species.
	 *
	 * @param array<string,mixed> $group Entry of {@see Renderer::group()}.
	 */
	private static function entry( array $group ): string {
		$html = '<li class="wpinat-entry" data-taxon-id="' . esc_attr( (string) $group['taxon_id'] ) . '">';

		$photo = $group['photo'];
		if ( is_array( $photo ) && 0 === strpos( (string) $photo['url'], 'https://' ) ) {
			$img   = '<img src="' . esc_url( Observation::photoUrl( (string) $photo['url'], 'medium' ) ) . '" alt="' . esc_attr( (string) $group['name'] ) . '" loading="lazy" />';
			$html .= '<figure class="wpinat-photo">';
			$html .= ( '' !== $group['uri'] && 0 === strpos( (string) $group['uri'], 'https://' ) ) ? '<a href="' . esc_url( (string) $group['uri'] ) . '">' . $img . '</a>' : $img;
			if ( ! empty( $photo['attribution'] ) && is_string( $photo['attribution'] ) ) {
				$html .= '<figcaption class="wpinat-credit">' . esc_html( $photo['attribution'] ) . '</figcaption>';
			}
			$html .= '</figure>';
		}

		$html .= '<span class="wpinat-name"><a href="' . esc_url( self::TAXON_URL . (int) $group['taxon_id'] ) . '">' . esc_html( (string) $group['name'] ) . '</a>';
		if ( '' !== $group['latin'] ) {
			$html .= ' <em class="wpinat-latin">' . esc_html( (string) $group['latin'] ) . '</em>';
		}
		if ( $group['count'] > 1 ) {
			$html .= ' <span class="wpinat-count">×' . (int) $group['count'] . '</span>';
		}
		return $html . '</span></li>';
	}
}
