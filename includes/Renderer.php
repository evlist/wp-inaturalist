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
	const CARD      = 'card';
	const GALLERY   = 'gallery';

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
					'taxon_id'  => $taxon,
					'name'      => '' !== $common ? $common : $latin,
					'latin'     => ( '' !== $common && '' !== $latin ) ? $latin : '',
					'count'     => 0,
					'photo'     => null,
					'uri'       => '',
					'media'     => 0,
					'score'     => 0,
					'first_uri' => isset( $row['uri'] ) ? (string) $row['uri'] : '',
					'wiki'      => '',
				);
			}
			++$groups[ $taxon ]['count'];
			if ( '' === $groups[ $taxon ]['wiki'] && isset( $row['wikipedia_url'] ) && is_string( $row['wikipedia_url'] ) ) {
				$groups[ $taxon ]['wiki'] = $row['wikipedia_url']; // The (usually English) article given by iNaturalist, a fallback.
			}
			// The photo shown is the first one that has a linked media library copy, else the first iNaturalist photo.
			$photos = isset( $row['photos'] ) ? json_decode( (string) $row['photos'], true ) : null;
			if ( is_array( $photos ) && isset( $photos[0]['url'] ) && is_string( $photos[0]['url'] ) ) {
				$media = isset( $row['attachment_id'] ) ? (int) $row['attachment_id'] : 0;
				$score = $media > 0 ? 2 : 1;
				if ( $score > $groups[ $taxon ]['score'] ) {
					$groups[ $taxon ]['score'] = $score;
					$groups[ $taxon ]['photo'] = $photos[0];
					$groups[ $taxon ]['uri']   = isset( $row['uri'] ) ? (string) $row['uri'] : '';
					$groups[ $taxon ]['media'] = $media;
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
	 * @param array<int,array<string,mixed>> $taxa Wikidata items by taxon id (see {@see Taxa::get()}), for the index mark and the Wikipedia link.
	 * @param string                         $layout "card" (default) or "gallery"; anything else means card.
	 */
	public static function html( array $rows, array $taxa = array(), string $layout = self::CARD ): string {
		$groups = self::group( $rows );
		if ( array() === $groups ) {
			return '';
		}
		$layout = self::GALLERY === $layout ? self::GALLERY : self::CARD;
		$items  = '';
		foreach ( $groups as $group ) {
			$item   = isset( $taxa[ $group['taxon_id'] ] ) ? $taxa[ $group['taxon_id'] ] : null;
			$items .= self::CARD === $layout ? self::card( $group, $item ) : self::gallery( $group, $item );
		}
		return '<div class="wpinat-observations wpinat-layout-' . $layout . '"><p class="wpinat-intro">'
			. esc_html__( 'We identified the following species with iNaturalist:', 'wp-inaturalist' )
			. '</p><ul class="wpinat-list">' . $items . '</ul></div>';
	}

	/**
	 * The `data-index` attribute read by the Index module of wp-otherguise, or nothing without a well-formed Wikidata item.
	 *
	 * Same format as wp-whobird (one JSON object, with a `source`). The label is the Wikidata label in the site language and is
	 * left out when there is none: never the iNaturalist common name, so that capitalisation is Wikidata's.
	 *
	 * @param array|null $item Wikidata item of the taxon: qid and label.
	 */
	private static function indexMark( ?array $item ): string {
		if ( null === $item || ! isset( $item['qid'] ) || ! is_string( $item['qid'] ) || 1 !== preg_match( '/^Q[1-9][0-9]*$/', $item['qid'] ) ) {
			return '';
		}
		$mark = array(
			'topic' => 'wikidata:' . $item['qid'],
			'kind'  => 'species',
		);
		if ( isset( $item['label'] ) && is_string( $item['label'] ) && '' !== trim( $item['label'] ) ) {
			$mark['label'] = trim( $item['label'] );
		}
		$mark['source'] = 'inaturalist';
		return ' data-index="' . esc_attr( (string) wp_json_encode( $mark, JSON_UNESCAPED_UNICODE ) ) . '"';
	}

	/**
	 * One species in the gallery layout (large photo, credit line, links on the photo and on the name).
	 *
	 * @param array<string,mixed> $group Entry of {@see Renderer::group()}.
	 * @param array|null          $item  Wikidata item of the taxon, when known.
	 */
	private static function gallery( array $group, ?array $item = null ): string {
		$html = '<li class="wpinat-entry" data-taxon-id="' . esc_attr( (string) $group['taxon_id'] ) . '"' . self::indexMark( $item ) . '>';

		$photo = $group['photo'];
		// The media library copy when one is linked and WordPress can still output it, else the iNaturalist photo.
		$local = ( is_array( $photo ) && $group['media'] > 0 && Config::getUseMediaLibrary() ) ? wp_get_attachment_image(
			(int) $group['media'],
			'medium',
			false,
			array(
				'alt'     => (string) $group['name'],
				'loading' => 'lazy',
			)
		) : '';
		if ( is_array( $photo ) && ( '' !== $local || 0 === strpos( (string) $photo['url'], 'https://' ) ) ) {
			$img   = '' !== $local ? $local : '<img src="' . esc_url( Observation::photoUrl( (string) $photo['url'], 'medium' ) ) . '" alt="' . esc_attr( (string) $group['name'] ) . '" loading="lazy" />';
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

	/**
	 * One species in the card layout, like wp-whobird: a small photo, the name, the Latin name and at most two links on the right.
	 *
	 * @param array<string,mixed> $group Entry of {@see Renderer::group()}.
	 * @param array|null          $item  Wikidata item of the taxon, when known.
	 */
	private static function card( array $group, ?array $item = null ): string {
		$html  = '<li class="wpinat-entry" data-taxon-id="' . esc_attr( (string) $group['taxon_id'] ) . '"' . self::indexMark( $item ) . '>';
		$thumb = self::thumbnail( $group );
		if ( '' !== $thumb ) {
			$html .= '<span class="wpinat-thumb">' . $thumb . '</span>';
		}
		$html .= '<span class="wpinat-info"><span class="wpinat-name">' . esc_html( (string) $group['name'] );
		if ( $group['count'] > 1 ) {
			$html .= ' <span class="wpinat-count">×' . (int) $group['count'] . '</span>';
		}
		$html .= '</span>';
		if ( '' !== $group['latin'] ) {
			$html .= '<em class="wpinat-latin">' . esc_html( (string) $group['latin'] ) . '</em>';
		}
		return $html . '</span>' . self::links( $group, $item ) . '</li>';
	}

	/**
	 * The 80 px thumbnail of a card: the media library copy when linked, else the small iNaturalist photo. The credit is the
	 * `title` (a tooltip), not a visible line.
	 *
	 * @param array<string,mixed> $group Entry of {@see Renderer::group()}.
	 */
	private static function thumbnail( array $group ): string {
		$photo = $group['photo'];
		if ( ! is_array( $photo ) ) {
			return '';
		}
		$title = ( ! empty( $photo['attribution'] ) && is_string( $photo['attribution'] ) ) ? $photo['attribution'] : '';
		$local = ( $group['media'] > 0 && Config::getUseMediaLibrary() ) ? wp_get_attachment_image(
			(int) $group['media'],
			'thumbnail',
			false,
			array_filter(
				array(
					'alt'     => (string) $group['name'],
					'title'   => $title,
					'loading' => 'lazy',
					'sizes'   => '80px',
				),
				'strlen'
			)
		) : '';
		if ( '' !== $local ) {
			return $local;
		}
		if ( 0 !== strpos( (string) $photo['url'], 'https://' ) ) {
			return '';
		}
		return '<img src="' . esc_url( Observation::photoUrl( (string) $photo['url'], 'small' ) ) . '" alt="' . esc_attr( (string) $group['name'] ) . '"'
			. ( '' !== $title ? ' title="' . esc_attr( $title ) . '"' : '' ) . ' width="80" height="80" loading="lazy" />';
	}

	/**
	 * The links of a card: the observation (top) and the Wikipedia article (bottom). At most two.
	 *
	 * @param array<string,mixed> $group Entry of {@see Renderer::group()}.
	 * @param array|null          $item  Wikidata item of the taxon, when known.
	 */
	private static function links( array $group, ?array $item ): string {
		$links       = '';
		$count       = 0;
		$observation = '' !== $group['uri'] ? (string) $group['uri'] : (string) $group['first_uri'];
		if ( 0 === strpos( $observation, 'https://' ) ) {
			$label  = __( 'View the observation on iNaturalist', 'wp-inaturalist' );
			$links .= self::link( 'observation', $observation, $label, Icons::svg( 'eye', 'wpinat-main' ) );
			++$count;
		}
		$wiki = self::wikipedia( $group, $item );
		if ( null !== $wiki ) {
			$label = Config::getNamesLocale() === $wiki['lang']
				? __( 'Wikipedia article', 'wp-inaturalist' )
				/* translators: %s: language code of the article, for example "en". */
				: sprintf( __( 'Wikipedia article (%s)', 'wp-inaturalist' ), $wiki['lang'] );
			$links .= self::link( 'wikipedia', $wiki['url'], $label, Icons::svg( 'wikipedia-w', 'wpinat-main' ) );
			++$count;
		}
		return 0 === $count ? '' : '<span class="wpinat-links wpinat-links-' . $count . '">' . $links . '</span>';
	}

	/**
	 * One link of a card.
	 *
	 * @param string $kind  "observation" or "wikipedia".
	 * @param string $url   Address (already checked).
	 * @param string $label Accessible name and tooltip.
	 * @param string $icon  Constant markup of the icon.
	 */
	private static function link( string $kind, string $url, string $label, string $icon ): string {
		return '<a class="wpinat-link wpinat-link-' . $kind . '" href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer" aria-label="' . esc_attr( $label ) . '" title="' . esc_attr( $label ) . '">'
			. $icon . Icons::svg( 'up-right-from-square', 'wpinat-ext' ) . '</a>';
	}

	/**
	 * The Wikipedia article to link: the one in the language of the site found through Wikidata, else the article iNaturalist gives
	 * (usually English; `http` is turned into `https`). Only a well-formed address of a Wikipedia article is accepted.
	 *
	 * @param array<string,mixed> $group Entry of {@see Renderer::group()}.
	 * @param array|null          $item  Wikidata item of the taxon, when known.
	 * @return array{url:string,lang:string}|null
	 */
	private static function wikipedia( array $group, ?array $item ): ?array {
		$candidates = array( isset( $item['wikipedia_url'] ) ? $item['wikipedia_url'] : null, $group['wiki'] );
		foreach ( $candidates as $address ) {
			if ( is_string( $address ) && 1 === preg_match( '#^https?://([a-z]{2,3})\.wikipedia\.org/wiki/(\S+)$#', $address, $m ) ) {
				return array(
					'url'  => 'https://' . $m[1] . '.wikipedia.org/wiki/' . $m[2],
					'lang' => $m[1],
				);
			}
		}
		return null;
	}
}
