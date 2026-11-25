<?php
/**
 * Runs when the plugin is deleted from the Plugins screen.
 *
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * @package WpInaturalist
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/Config.php';
require_once __DIR__ . '/includes/Throttle.php';
require_once __DIR__ . '/includes/Uninstaller.php';

\Evlist\WpInaturalist\Uninstaller::run();
