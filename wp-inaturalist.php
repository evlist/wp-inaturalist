<?php
/**
 * Plugin Name:       WP iNaturalist
 * Description:       Lists the species observed on iNaturalist for the period around a post's date.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Eric van der Vlist
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       wp-inaturalist
 * Domain Path:       /languages
 *
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * @package WpInaturalist
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WPINAT_VERSION', '0.1.0' );
define( 'WPINAT_FILE', __FILE__ );
define( 'WPINAT_DIR', plugin_dir_path( __FILE__ ) );

require_once WPINAT_DIR . 'includes/Config.php';
require_once WPINAT_DIR . 'includes/Throttle.php';
require_once WPINAT_DIR . 'includes/INatException.php';
require_once WPINAT_DIR . 'includes/Observation.php';
require_once WPINAT_DIR . 'includes/INatClient.php';
require_once WPINAT_DIR . 'includes/SettingsPage.php';
require_once WPINAT_DIR . 'includes/Setup.php';

// No activation hook on purpose: nothing is created at activation (tables are created and migrated from the schema version, slice 004).
\Evlist\WpInaturalist\Setup::register();
