<?php
/**
 * Hook registration: every hook of the plugin is set up here, with an explicit priority.
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
 * Registers the hooks.
 *
 * Coexistence: the plugin only adds actions of its own on core hooks (no shared filter is answered), so priority 10 is safe.
 */
final class Setup {

	const TEXT_DOMAIN = 'wp-inaturalist';

	/**
	 * Registers the hooks.
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'loadTextDomain' ), 10 );
		add_action( 'admin_menu', array( SettingsPage::class, 'addMenu' ), 10 );
		add_action( 'admin_init', array( SettingsPage::class, 'registerSettings' ), 10 );
		// Only the capability filter of our own option group: nothing shared with other plugins.
		add_filter( 'option_page_capability_' . SettingsPage::GROUP, array( SettingsPage::class, 'capability' ), 10 );
	}

	/**
	 * Loads the translations.
	 */
	public static function loadTextDomain(): void {
		load_plugin_textdomain( self::TEXT_DOMAIN, false, dirname( plugin_basename( WPINAT_FILE ) ) . '/languages' );
	}
}
