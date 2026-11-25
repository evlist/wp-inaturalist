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
define( 'ARRAY_A', 'ARRAY_A' );

/** Minimal WP_REST_Request. */
class WP_REST_Request {
	/** @var array */
	private $params;

	/** @param array $params Parameters. */
	public function __construct( $params = array() ) {
		$this->params = $params;
	}

	/** @param string $name Name. */
	public function get_param( $name ) {
		return isset( $this->params[ $name ] ) ? $this->params[ $name ] : null;
	}
}

/** Minimal WP_Error. */
class WP_Error {
	/** @var string */
	private $message;
	/** @var string */
	public $code;
	/** @var mixed */
	public $data;

	/**
	 * @param string $code    Error code.
	 * @param string $message Message.
	 */
	public function __construct( $code = '', $message = '', $data = null ) {
		$this->message = $message;
		$this->code    = $code;
		$this->data    = $data;
	}

	/** Message. */
	public function get_error_message() {
		return $this->message;
	}
}

/** In-memory stand-in for $wpdb: records queries and keeps the rows written by replace(). */
class WPINAT_Fake_Wpdb {
	/** @var string */
	public $prefix = 'wp_';
	/** @var string[] */
	public $queries = array();
	/** @var array<int,array> */
	public $rows = array();
	/** @var bool */
	public $fail_replace = false;
	/** @var array<int,array> Rows of the table of the taxa. */
	public $taxa_rows = array();
	/** @var array */
	public $last_update = array();
	/** @var string */
	public $posts = 'wp_posts';
	/** @var string */
	public $postmeta = 'wp_postmeta';
	/** @var array Scripted answers of get_results() for the media queries (oldest first). */
	public $scripted_results = array();
	/** @var array Scripted answers of get_var() for the manual-link query. */
	public $scripted_vars = array();

	public function esc_like( $text ) {
		return addcslashes( $text, '_%\\' );
	}

	public function update( $table, $data, $where, $format = null, $where_format = null ) {
		$this->queries[]   = 'UPDATE ' . $table;
		$this->last_update = array( $data, $where, $format, $where_format );
		if ( isset( $this->rows[ $where['observation_id'] ] ) ) {
			$this->rows[ $where['observation_id'] ] = array_merge( $this->rows[ $where['observation_id'] ], $data );
		}
		return 1;
	}

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
		if ( 'wp_wpinat_taxa' === $table ) {
			$this->taxa_rows[ $data['taxon_id'] ] = $data;
			return 1;
		}
		$this->rows[ $data['observation_id'] ] = $data;
		return 1;
	}

	public function prepare( $sql, ...$args ) {
		$i = 0;
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		return preg_replace_callback(
			'/%[sd]/',
			function ( $m ) use ( &$i, $args ) {
				$v = $args[ $i++ ];
				return '%d' === $m[0] ? (string) (int) $v : "'" . addslashes( (string) $v ) . "'";
			},
			$sql
		);
	}

	public function get_results( $sql, $output = 'OBJECT' ) {
		$this->queries[] = $sql;
		if ( false !== strpos( $sql, 'FROM wp_postmeta pm' ) ) {
			return array_shift( $this->scripted_results );
		}
		if ( preg_match( '/^SELECT (.*?) FROM wp_wpinat_taxa WHERE (qid IS NOT NULL AND )?taxon_id IN \(([\d,]+)\)$/', $sql, $m ) ) {
			$ids = explode( ',', $m[3] );
			$out = array();
			foreach ( $this->taxa_rows as $id => $row ) {
				if ( in_array( (string) $id, $ids, true ) && ( '' === $m[2] || null !== $row['qid'] ) ) {
					$out[] = $row;
				}
			}
			return $out;
		}
		if ( preg_match( '/^SELECT \* FROM \S+ WHERE user_login = \'(.*?)\' ORDER BY observed_on DESC, observation_id DESC LIMIT (\d+)$/', $sql, $m ) ) {
			$out = array_values(
				array_filter(
					$this->rows,
					static function ( $r ) use ( $m ) {
						return $r['user_login'] === $m[1];
					}
				)
			);
			usort(
				$out,
				static function ( $a, $b ) {
					return array( $b['observed_on'], $b['observation_id'] ) <=> array( $a['observed_on'], $a['observation_id'] );
				}
			);
			return array_slice( $out, 0, (int) $m[2] );
		}
		if ( ! preg_match( "/WHERE user_login = '(.*?)' AND observed_on BETWEEN '(.*?)' AND '(.*?)' ORDER BY observed_on ASC, observation_id ASC$/", $sql, $m ) ) {
			return null;
		}
		$out = array();
		foreach ( $this->rows as $row ) {
			if ( $row['user_login'] === $m[1] && $row['observed_on'] >= $m[2] && $row['observed_on'] <= $m[3] ) {
				$out[] = $row;
			}
		}
		usort(
			$out,
			static function ( $a, $b ) {
				return array( $a['observed_on'], $a['observation_id'] ) <=> array( $b['observed_on'], $b['observation_id'] );
			}
		);
		return $out;
	}

	public function get_var( $sql ) {
		if ( false !== strpos( $sql, 'FROM wp_wpinat_taxa' ) ) {
			return count(
				array_filter(
					$this->taxa_rows,
					static function ( $r ) {
						return null !== $r['qid'];
					}
				)
			);
		}
		if ( false !== strpos( $sql, 'FROM wp_postmeta pm' ) ) {
			$this->queries[] = $sql;
			return array_shift( $this->scripted_vars );
		}
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
		'options'      => array(),
		'transients'   => array(),
		'actions'      => array(),
		'filters'      => array(),
		'can'          => true,
		'dies'         => array(),
		'errors'       => array(),
		'settings'     => array(),
		'fields'       => array(),
		'menu'         => array(),
		'scheduled'    => array(),
		'dbdelta'      => array(),
		'redirects'    => array(),
		'nonces'       => array(),
		'nonce_ok'     => true,
		'autoload'     => array(),
		'posts'        => array(),
		'routes'       => array(),
		'scripts'      => array(),
		'inline'       => array(),
		'blocks'       => null,
		'cap_ids'      => array(),
		'registered'   => array(),
		'block_types'  => array(),
		'current_post' => null,
		'cleared'      => array(),
		'textdomain'   => array(),
		'locale'       => 'en_US',
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
	return gmdate( $format, null === $timestamp ? time() : (int) $timestamp );
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

function current_user_can( $capability, ...$args ) {
	$GLOBALS['wpinat_test']['cap_ids'][] = array_merge( array( $capability ), $args );
	return $GLOBALS['wpinat_test']['can'];
}

function maybe_unserialize( $value ) {
	return is_string( $value ) ? @unserialize( $value ) : $value; // phpcs:ignore WordPress.PHP.NoSilencedErrors
}

function get_post_meta( $id, $key, $single = false ) {
	return isset( $GLOBALS['wpinat_test']['postmeta'][ $id ][ $key ] ) ? $GLOBALS['wpinat_test']['postmeta'][ $id ][ $key ] : '';
}

function update_post_meta( $id, $key, $value ) {
	$GLOBALS['wpinat_test']['postmeta'][ $id ][ $key ] = $value;
	return true;
}

function delete_post_meta( $id, $key ) {
	unset( $GLOBALS['wpinat_test']['postmeta'][ $id ][ $key ] );
	return true;
}

function wp_get_attachment_image( $id, $size = 'thumbnail', $icon = false, $attr = '' ) {
	if ( ! isset( $GLOBALS['wpinat_test']['attachments'][ $id ] ) ) {
		return '';
	}
	$alt = is_array( $attr ) && isset( $attr['alt'] ) ? esc_attr( $attr['alt'] ) : '';
	return '<img src="' . esc_url( $GLOBALS['wpinat_test']['attachments'][ $id ] ) . '" alt="' . $alt . '" class="local" />';
}

function get_edit_post_link( $id ) {
	return 'https://blog.example.test/wp-admin/post.php?post=' . $id . '&action=edit';
}

function get_post( $id = null ) {
	if ( null === $id ) {
		return isset( $GLOBALS['wpinat_test']['current_post'] ) ? $GLOBALS['wpinat_test']['current_post'] : null;
	}
	return isset( $GLOBALS['wpinat_test']['posts'][ $id ] ) ? $GLOBALS['wpinat_test']['posts'][ $id ] : null;
}

function wp_register_script( $handle, $src = '', $deps = array(), $ver = false, $in_footer = false ) {
	$GLOBALS['wpinat_test']['registered'][ $handle ] = array( $src, $deps, $ver, $in_footer );
	return true;
}

function register_block_type( $path, $args = array() ) {
	$GLOBALS['wpinat_test']['block_types'][] = array( $path, $args );
	return true;
}

function parse_blocks( $content ) {
	if ( null !== $GLOBALS['wpinat_test']['blocks'] ) {
		return $GLOBALS['wpinat_test']['blocks'];
	}
	$out = array();
	preg_match_all( '/<!-- wp:([a-z0-9\/-]+)( \{.*?\})? \/?-->/', $content, $m, PREG_SET_ORDER );
	foreach ( $m as $row ) {
		$out[] = array(
			'blockName'   => $row[1],
			'attrs'       => isset( $row[2] ) && '' !== $row[2] ? json_decode( $row[2], true ) : array(),
			'innerBlocks' => array(),
		);
	}
	return $out;
}

function register_rest_route( $namespace, $route, $args ) {
	$GLOBALS['wpinat_test']['routes'][ $namespace . $route ] = $args;
}

function wp_enqueue_script( $handle, $src = '', $deps = array(), $ver = false, $in_footer = false ) {
	$GLOBALS['wpinat_test']['scripts'][ $handle ] = array( $src, $deps, $ver, $in_footer );
}

function wp_add_inline_script( $handle, $data, $position = 'after' ) {
	$GLOBALS['wpinat_test']['inline'][ $handle ] = array( $data, $position );
}

function plugins_url( $path = '', $plugin = '' ) {
	return 'https://blog.example.test/wp-content/plugins/wp-inaturalist/' . $path;
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

function wp_json_encode( $value, $flags = 0, $depth = 512 ) {
	return json_encode( $value, $flags, $depth );
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
require_once WPINAT_DIR . 'includes/Http.php';
require_once WPINAT_DIR . 'includes/Taxa.php';
require_once WPINAT_DIR . 'includes/WikidataClient.php';
require_once WPINAT_DIR . 'includes/TaxonResolver.php';
require_once WPINAT_DIR . 'includes/AttachmentIndex.php';
require_once WPINAT_DIR . 'includes/MediaFields.php';
require_once WPINAT_DIR . 'includes/PhotoLinker.php';
require_once WPINAT_DIR . 'includes/Scheduler.php';
require_once WPINAT_DIR . 'includes/Period.php';
require_once WPINAT_DIR . 'includes/PostRefresh.php';
require_once WPINAT_DIR . 'includes/Rest.php';
require_once WPINAT_DIR . 'includes/Assets.php';
require_once WPINAT_DIR . 'includes/Renderer.php';
require_once WPINAT_DIR . 'includes/Block.php';
require_once WPINAT_DIR . 'includes/ToolsPage.php';
require_once WPINAT_DIR . 'includes/SettingsPage.php';
require_once WPINAT_DIR . 'includes/Setup.php';
require_once WPINAT_DIR . 'includes/Uninstaller.php';
