<?php
/**
 * Scripts of the plugin: the settings page and the block editor.
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
 * Plain JavaScript files loaded as they are (no build step). Their texts are translated here, in PHP, and passed as data.
 */
final class Assets {

	const SETTINGS_HANDLE = 'wpinat-settings';
	const EDITOR_HANDLE   = 'wpinat-editor';

	/**
	 * Settings page script (hook admin_enqueue_scripts).
	 *
	 * @param string $hook Suffix of the current admin page.
	 */
	public static function enqueueSettings( $hook ): void {
		if ( 'settings_page_' . SettingsPage::PAGE !== $hook ) {
			return;
		}
		wp_enqueue_script( self::SETTINGS_HANDLE, plugins_url( 'js/settings.js', WPINAT_FILE ), array( 'wp-api-fetch' ), WPINAT_VERSION, true );
		wp_add_inline_script( self::SETTINGS_HANDLE, 'window.wpinatSettings = ' . wp_json_encode( array( 'strings' => self::settingsStrings() ) ) . ';', 'before' );
	}

	/**
	 * Block editor script (hook enqueue_block_editor_assets).
	 */
	public static function enqueueEditor(): void {
		wp_enqueue_script( self::EDITOR_HANDLE, plugins_url( 'js/editor.js', WPINAT_FILE ), array( 'wp-plugins', 'wp-element', 'wp-data', 'wp-api-fetch', 'wp-editor', 'wp-edit-post' ), WPINAT_VERSION, true );
		wp_add_inline_script( self::EDITOR_HANDLE, 'window.wpinatEditor = ' . wp_json_encode( array( 'strings' => self::editorStrings() ) ) . ';', 'before' );
	}

	/**
	 * Texts of the settings script, by status code.
	 *
	 * @return array<string,string>
	 */
	public static function settingsStrings(): array {
		return array(
			'checking'    => __( 'Checking…', 'wp-inaturalist' ),
			/* translators: %s: iNaturalist login. */
			'found'       => __( 'Account found: %s', 'wp-inaturalist' ),
			'not_found'   => __( 'No iNaturalist account has this login.', 'wp-inaturalist' ),
			'invalid'     => __( 'This is not a valid login: use letters, digits, "-" and "_".', 'wp-inaturalist' ),
			'unavailable' => __( 'iNaturalist did not answer: try again later.', 'wp-inaturalist' ),
			'error'       => __( 'The check could not be done.', 'wp-inaturalist' ),
		);
	}

	/**
	 * Texts of the editor script, by status code.
	 *
	 * @return array<string,string>
	 */
	public static function editorStrings(): array {
		return array(
			'title'     => __( 'iNaturalist', 'wp-inaturalist' ),
			'button'    => __( 'Refresh observations', 'wp-inaturalist' ),
			'saving'    => __( 'Saving the post first…', 'wp-inaturalist' ),
			'started'   => __( 'The refresh of the observations of this post has started.', 'wp-inaturalist' ),
			'queued'    => __( 'A refresh is running: this one will start when it ends.', 'wp-inaturalist' ),
			'no_block'  => __( 'This post has no iNaturalist block yet: add one and save the post.', 'wp-inaturalist' ),
			'no_login'  => __( 'Set the iNaturalist login in the settings first.', 'wp-inaturalist' ),
			'bad_range' => __( 'The date of this post cannot be used.', 'wp-inaturalist' ),
			'error'     => __( 'The refresh could not be requested.', 'wp-inaturalist' ),
		);
	}
}
