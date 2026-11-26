<?php
/**
 * The proposal to load all the observations.
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
 * An admin notice, shown to those who can manage options on a few screens, while a login is set and no full load was done for it
 * (a new install, or a changed login). It only proposes: the button posts to `admin-post.php`. No API call is made when it is shown.
 */
final class Offer {

	const SCREENS = array( 'dashboard', 'plugins', 'settings_page_wp-inaturalist', 'tools_page_wpinat-tools' );

	/**
	 * Whether the notice is to be shown on a screen.
	 *
	 * @param string $screenId Id of the current admin screen.
	 */
	public static function visible( string $screenId ): bool {
		if ( ! current_user_can( 'manage_options' ) || ! in_array( $screenId, self::SCREENS, true ) || ! SyncState::loadNeeded() ) {
			return false;
		}
		$state = Scheduler::job()->state();
		return ! ( Job::RUNNING === $state['status'] && 'initial' === $state['trigger'] ); // Already loading: nothing to propose.
	}

	/**
	 * Outputs the notice (hook admin_notices).
	 */
	public static function render(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! is_object( $screen ) || ! isset( $screen->id ) || ! self::visible( (string) $screen->id ) ) {
			return;
		}
		echo '<div class="notice notice-info"><p><strong>' . esc_html__( 'iNaturalist observations', 'wp-inaturalist' ) . '</strong> — '
			. esc_html__( 'No observation has been loaded for this iNaturalist login yet. Load them all so that every post can show them; it runs in the background.', 'wp-inaturalist' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="' . esc_attr( ToolsPage::ACTION_LOAD ) . '" />';
		wp_nonce_field( ToolsPage::ACTION_LOAD );
		echo '<p>';
		submit_button( __( 'Load all my observations', 'wp-inaturalist' ), 'primary', 'submit', false );
		echo '</p></form></div>';
	}
}
