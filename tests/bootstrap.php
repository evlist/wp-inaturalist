<?php
/**
 * Test bootstrap: stand-ins for the WordPress functions the plugin uses.
 *
 * Nothing here talks to a database, to WordPress or to the network.
 *
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * @package WpInaturalist
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'WPINAT_VERSION', 'test' );
define( 'WPINAT_FILE', dirname( __DIR__ ) . '/wp-inaturalist.php' );
define( 'WPINAT_DIR', dirname( __DIR__ ) . '/' );

/** Minimal WP_Error. */
class WP_Error {
 // phpcs:ignore Generic.Files.OneObjectStructurePerFile
	/** @var string */
	private $message;

	/**
	 * @param string $code    Error code.
	 * @param string $message Message.
	 */
	public function __construct( $code = '', $message = '' ) {
		$this->message = $message;
	}

	/** Message. */
	public function get_error_message() {
		return $this->message;
	}
}

/** In-memory stand-in for $wpdb: records queries and keeps the rows written by replace(). */
class WPINAT_Fake_Wpdb {
 // phpcs:ignore Generic.Files.OneObjectStructurePerFile
	/** @var string */
	public $prefix = 'wp_';
	/** @var string[] */
	public $queries = array();
	/** @var array<int,array> */
	public $rows = array();
	/** @var bool */
	public $fail_replace = false;

	/** @param string $sql Query. */
	public function query( $sql ) {
		$this->queries[] = $sql;
		if ( preg_match( "/^DELETE FROM \S+ WHERE user_login = '(.*?)' AND observed_on BETWEEN '(.*?)' AND '(.*?)'(?: AND observation_id NOT IN \(([\d,]+)\))?$/", $sql, $m ) ) {
			$keep = isset( $m[4] ) ? explode( ',', $m[4] ) : array();
			foreach ( $this->rows as $id => $row ) {
				if ( $row['user_login'] === $m[1] && $row['observed_on'] >= $m[2] && $row['observed_on'] <= $m[3] && ! in_array( (string) $id, $keep, true ) ) {
					unset( $this->rows[ $id ] );
				}
			}
		} elseif ( preg_match( '/^DELETE FROM \S+$/', $sql ) ) {
			$this->rows = array();
		}
		return 0;
	}

	public function replace( $table, $data, $formats ) {
		if ( $this->fail_replace ) {
			return false;
		}
		$this->rows[ $data['observation_id'] ] = $data;
		return 1;
	}

	public function prepare( $sql, ...$args ) {
		$i = 0;
		return preg_replace_callback(
			'/%[sd]/',
			function ( $m ) use ( &$i, $args ) {
				$v = $args[ $i++ ];
				return '%d' === $m[0] ? (string) (int) $v : "'" . addslashes( (string) $v ) . "'";
			},
			$sql
		);
	}

	public function get_var( $sql ) {
		return count( $this->rows );
	}

	public function get_charset_collate() {
		return 'DEFAULT CHARACTER SET utf8mb4';
	}
}

/**
 * Resets the state shared by the stand-ins. Call it from setUp().
 */
function wpinat_test_reset() {
	$GLOBALS['wpinat_test'] = array(
		'options'    => array(),
		'transients' => array(),
		'actions'    => array(),
		'filters'    => array(),
		'can'        => true,
		'dies'       => array(),
		'errors'     => array(),
		'settings'   => array(),
		'fields'     => array(),
		'menu'       => array(),
		'scheduled'  => array(),
		'dbdelta'    => array(),
		'redirects'  => array(),
		'nonces'     => array(),
		'nonce_ok'   => true,
		'autoload'   => array(),
		'cleared'    => array(),
		'textdomain' => array(),
		'locale'     => 'en_US',
	);
	$GLOBALS['wpdb']        = new WPINAT_Fake_Wpdb();
}

wpinat_test_reset();

function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['wpinat_test']['options'] ) ? $GLOBALS['wpinat_test']['options'][ $name ] : $default;
}

function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['wpinat_test']['options'][ $name ]  = $value;
	$GLOBALS['wpinat_test']['autoload'][ $name ] = $autoload;
	return true;
}

function add_option( $name, $value = '', $deprecated = '', $autoload = 'yes' ) {
	if ( array_key_exists( $name, $GLOBALS['wpinat_test']['options'] ) ) {
		return false;
	}
	$GLOBALS['wpinat_test']['options'][ $name ] = $value;
	return true;
}

function delete_option( $name ) {
	unset( $GLOBALS['wpinat_test']['options'][ $name ] );
	return true;
}

function get_transient( $name ) {
	return array_key_exists( $name, $GLOBALS['wpinat_test']['transients'] ) ? $GLOBALS['wpinat_test']['transients'][ $name ] : false;
}

function set_transient( $name, $value, $expiration = 0 ) {
	$GLOBALS['wpinat_test']['transients'][ $name ] = $value;
	return true;
}

function delete_transient( $name ) {
	unset( $GLOBALS['wpinat_test']['transients'][ $name ] );
	return true;
}

function wp_clear_scheduled_hook( $hook ) {
	$GLOBALS['wpinat_test']['cleared'][] = $hook;
	$GLOBALS['wpinat_test']['scheduled'] = array_values(
		array_filter(
			$GLOBALS['wpinat_test']['scheduled'],
			static function ( $e ) use ( $hook ) {
				return $e['hook'] !== $hook;
			}
		)
	);
	return 0;
}

function wp_schedule_single_event( $timestamp, $hook, $args = array() ) {
	$GLOBALS['wpinat_test']['scheduled'][] = array(
		'hook'       => $hook,
		'time'       => $timestamp,
		'recurrence' => false,
	);
	return true;
}

function wp_schedule_event( $timestamp, $recurrence, $hook, $args = array() ) {
	$GLOBALS['wpinat_test']['scheduled'][] = array(
		'hook'       => $hook,
		'time'       => $timestamp,
		'recurrence' => $recurrence,
	);
	return true;
}

function wp_next_scheduled( $hook, $args = array() ) {
	foreach ( $GLOBALS['wpinat_test']['scheduled'] as $e ) {
		if ( $e['hook'] === $hook ) {
			return $e['time'];
		}
	}
	return false;
}

function dbDelta( $sql ) {
	$GLOBALS['wpinat_test']['dbdelta'][] = $sql;
	return array();
}

function wp_timezone() {
	return new \DateTimeZone( 'Europe/Paris' );
}

function wp_date( $format, $timestamp = null ) {
	return gmdate( $format, (int) $timestamp );
}

function admin_url( $path = '' ) {
	return 'https://blog.example.test/wp-admin/' . $path;
}

function add_query_arg( $args, $url ) {
	return $url . '?' . http_build_query( $args );
}

function wp_safe_redirect( $url ) {
	$GLOBALS['wpinat_test']['redirects'][] = $url;
}

function check_admin_referer( $action ) {
	$GLOBALS['wpinat_test']['nonces'][] = $action;
	if ( ! $GLOBALS['wpinat_test']['nonce_ok'] ) {
		wp_die( 'bad nonce', '', array( 'response' => 403 ) );
	}
	return 1;
}

function wp_nonce_field( $action ) {
	echo '<input type="hidden" name="_wpnonce" value="nonce-' . $action . '" />';
}

function add_management_page( $page_title, $menu_title, $capability, $slug, $callback ) {
	$GLOBALS['wpinat_test']['menu'] = array( $capability, $slug, $callback );
}

function esc_url( $url ) {
	return htmlspecialchars( (string) $url, ENT_QUOTES );
}

function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}

function sanitize_text_field( $text ) {
	return trim( strip_tags( (string) $text ) );
}

function wp_unslash( $value ) {
	return $value;
}

function register_deactivation_hook( $file, $callback ) {
	$GLOBALS['wpinat_test']['deactivation'] = array( $file, $callback );
}

function add_action( $hook, $callback, $priority = 10, $args = 1 ) {
	$GLOBALS['wpinat_test']['actions'][] = array( $hook, $callback, $priority );
	return true;
}

function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {
	$GLOBALS['wpinat_test']['filters'][] = array( $hook, $callback, $priority );
	return true;
}

function __( $text, $domain = 'default' ) {
	return $text;
}

function esc_html__( $text, $domain = 'default' ) {
	return htmlspecialchars( $text, ENT_QUOTES );
}

function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}

function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}

function absint( $value ) {
	return abs( (int) $value );
}

function current_user_can( $capability ) {
	return $GLOBALS['wpinat_test']['can'];
}

function wp_die( $message = '', $title = '', $args = array() ) {
	$GLOBALS['wpinat_test']['dies'][] = array( $message, $args );
	throw new \RuntimeException( 'wp_die' );
}

function add_settings_error( $setting, $code, $message, $type = 'error' ) {
	$GLOBALS['wpinat_test']['errors'][] = $code;
}

function register_setting( $group, $name, $args = array() ) {
	$GLOBALS['wpinat_test']['settings'][ $name ] = array( $group, $args );
}

function add_settings_section( $id, $title, $callback, $page ) {
	$GLOBALS['wpinat_test']['sections'][] = array( $id, $page );
}

function add_settings_field( $id, $title, $callback, $page, $section, $args = array() ) {
	$GLOBALS['wpinat_test']['fields'][ $id ] = array( $callback, $page, $section );
}

function add_options_page( $page_title, $menu_title, $capability, $slug, $callback ) {
	$GLOBALS['wpinat_test']['menu'] = array( $capability, $slug, $callback );
}

function settings_fields( $group ) {
	echo '<!--settings_fields ' . $group . '-->';
}

function do_settings_sections( $page ) {
	echo '<!--sections ' . $page . '-->';
}

function submit_button( $text = null, $type = 'primary', $name = 'submit', $wrap = true, $other = array() ) {
	echo '<!--submit ' . $text . ( isset( $other['disabled'] ) ? ' disabled' : '' ) . '-->';
}

function load_plugin_textdomain( $domain, $deprecated = false, $path = false ) {
	$GLOBALS['wpinat_test']['textdomain'] = array( $domain, $path );
	return true;
}

function plugin_basename( $file ) {
	return basename( dirname( $file ) ) . '/' . basename( $file );
}

function get_locale() {
	return $GLOBALS['wpinat_test']['locale'];
}

function home_url() {
	return 'https://blog.example.test';
}

function wp_json_encode( $value ) {
	return json_encode( $value );
}

function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

function wp_remote_retrieve_response_code( $response ) {
	return isset( $response['response']['code'] ) ? $response['response']['code'] : '';
}

function wp_remote_retrieve_body( $response ) {
	return isset( $response['body'] ) ? $response['body'] : '';
}

function wp_remote_retrieve_header( $response, $header ) {
	return isset( $response['headers'][ $header ] ) ? $response['headers'][ $header ] : '';
}

require_once WPINAT_DIR . 'includes/Config.php';
require_once WPINAT_DIR . 'includes/Throttle.php';
require_once WPINAT_DIR . 'includes/INatException.php';
require_once WPINAT_DIR . 'includes/Observation.php';
require_once WPINAT_DIR . 'includes/INatClient.php';
require_once WPINAT_DIR . 'includes/Schema.php';
require_once WPINAT_DIR . 'includes/Cache.php';
require_once WPINAT_DIR . 'includes/JobLog.php';
require_once WPINAT_DIR . 'includes/Job.php';
require_once WPINAT_DIR . 'includes/Scheduler.php';
require_once WPINAT_DIR . 'includes/ToolsPage.php';
require_once WPINAT_DIR . 'includes/SettingsPage.php';
require_once WPINAT_DIR . 'includes/Setup.php';
require_once WPINAT_DIR . 'includes/Uninstaller.php';
