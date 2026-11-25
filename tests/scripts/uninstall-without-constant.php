<?php
/**
 * Runs uninstall.php without WP_UNINSTALL_PLUGIN and reports what was touched.
 *
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * @package WpInaturalist
 */

require dirname( __DIR__ ) . '/bootstrap.php';

update_option( \Evlist\WpInaturalist\Config::LOGIN, 'someone' );

register_shutdown_function(
	static function () {
		echo ( 'someone' === get_option( \Evlist\WpInaturalist\Config::LOGIN ) ? 'option kept' : 'option deleted' ) . "\n";
		echo ( array() === $GLOBALS['wpdb']->queries ? 'no query' : 'query sent' ) . "\n";
	}
);

require dirname( __DIR__, 2 ) . '/uninstall.php';
