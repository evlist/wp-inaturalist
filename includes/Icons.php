<?php
/**
 * Inline SVG icons.
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
 * The icons of the cards: the Wikipedia "W", the external-link mark and an eye (the outline one: as light as the Wikipedia "W"), from Font Awesome Free 6.7.2 (the version
 * wp-whobird uses, so that the two blocks look the same). Only the names of the list can be asked, and only constant data
 * (a view box and a path) ever reaches the markup.
 */
final class Icons {

	const NAMES = array( 'wikipedia-w', 'up-right-from-square', 'eye' );

	/**
	 * Icon data, loaded once.
	 *
	 * @var array|null
	 */
	private static $data = null;

	/**
	 * The markup of an icon, or an empty string for an unknown name.
	 *
	 * @param string $name  One of {@see Icons::NAMES}.
	 * @param string $cssClass Extra CSS class (letters, digits and hyphens only).
	 */
	public static function svg( string $name, string $cssClass = '' ): string {
		if ( ! in_array( $name, self::NAMES, true ) ) {
			return '';
		}
		if ( null === self::$data ) {
			self::$data = require WPINAT_DIR . 'includes/IconsData.php';
		}
		if ( ! isset( self::$data[ $name ] ) || 1 !== preg_match( '/^0 0 \d+ \d+$/', self::$data[ $name ]['viewBox'] ) || 1 !== preg_match( '/^[0-9A-Za-z\s.,\-]+$/', self::$data[ $name ]['path'] ) ) {
			return '';
		}
		$extra = 1 === preg_match( '/^[A-Za-z0-9-]+$/', $cssClass ) ? ' ' . $cssClass : '';
		return '<svg class="wpinat-fa wpinat-fa-' . $name . $extra . '" viewBox="' . self::$data[ $name ]['viewBox'] . '" fill="currentColor" aria-hidden="true" focusable="false"><path d="' . self::$data[ $name ]['path'] . '"/></svg>';
	}
}
