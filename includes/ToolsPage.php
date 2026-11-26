<?php
/**
 * Tools > iNaturalist: status of the refresh job, its log and its controls.
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
 * The screen and the four actions (start, stop, resume, empty the cache).
 *
 * Each action is an admin-post handler in two parts: `handleX()` checks the capability and the nonce, does the work and returns
 * a notice code; `onX()` redirects with that code. The notice shown is chosen from a fixed list: no free text travels in the URL.
 */
final class ToolsPage {

	const PAGE       = 'wpinat-tools';
	const CAPABILITY = 'manage_options';

	const ACTION_START  = 'wpinat_job_start';
	const ACTION_STOP   = 'wpinat_job_stop';
	const ACTION_RESUME = 'wpinat_job_resume';
	const ACTION_CLEAR  = 'wpinat_cache_clear';
	const ACTION_LOAD   = 'wpinat_load_all';

	/**
	 * Adds the page under Tools.
	 */
	public static function addMenu(): void {
		add_management_page( __( 'iNaturalist', 'wp-inaturalist' ), __( 'iNaturalist', 'wp-inaturalist' ), self::CAPABILITY, self::PAGE, array( self::class, 'render' ) );
	}

	/**
	 * Messages of the notices, by code.
	 *
	 * @return array<string,array{0:string,1:string}> Code => (type, text).
	 */
	public static function notices(): array {
		return array(
			'started'       => array( 'success', __( 'The refresh was started.', 'wp-inaturalist' ) ),
			'loading_all'   => array( 'success', __( 'Loading all your observations has started. It runs in the background: reload this page to follow it.', 'wp-inaturalist' ) ),
			'nothing_found' => array( 'warning', __( 'iNaturalist has no public observation for this login. Check the login in the settings.', 'wp-inaturalist' ) ),
			'too_many'      => array( 'warning', __( 'This account has a very large number of observations: nothing was started. Choose a first day below and refresh those days instead.', 'wp-inaturalist' ) ),
			'unavailable'   => array( 'error', __( 'iNaturalist did not answer: try again later.', 'wp-inaturalist' ) ),
			'queued'        => array( 'success', __( 'A refresh is running: this one will start when it ends.', 'wp-inaturalist' ) ),
			'busy'          => array( 'warning', __( 'A refresh is already running.', 'wp-inaturalist' ) ),
			'no_login'      => array( 'error', __( 'Set the iNaturalist login in the settings first.', 'wp-inaturalist' ) ),
			'bad_range'     => array( 'error', __( 'The dates are not valid: use real days, the first not after the last, at most about ten years apart.', 'wp-inaturalist' ) ),
			'stopped'       => array( 'success', __( 'The refresh was stopped.', 'wp-inaturalist' ) ),
			'not_running'   => array( 'warning', __( 'No refresh is running.', 'wp-inaturalist' ) ),
			'resumed'       => array( 'success', __( 'The refresh was resumed.', 'wp-inaturalist' ) ),
			'not_resumable' => array( 'warning', __( 'There is no stopped or failed refresh to resume.', 'wp-inaturalist' ) ),
			'cleared'       => array( 'success', __( 'The cache was emptied.', 'wp-inaturalist' ) ),
		);
	}

	/**
	 * Outputs the page.
	 */
	public static function render(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to use this page.', 'wp-inaturalist' ), '', array( 'response' => 403 ) );
		}
		$job   = Scheduler::job();
		$state = $job->state();
		$log   = new JobLog();

		echo '<div class="wrap"><h1>' . esc_html__( 'iNaturalist', 'wp-inaturalist' ) . '</h1>';

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only selects a fixed message, changes nothing.
		$code    = isset( $_GET['wpinat_notice'] ) ? sanitize_key( wp_unslash( $_GET['wpinat_notice'] ) ) : '';
		$notices = self::notices();
		if ( isset( $notices[ $code ] ) ) {
			printf( '<div class="notice notice-%1$s"><p>%2$s</p></div>', esc_attr( $notices[ $code ][0] ), esc_html( $notices[ $code ][1] ) );
		}

		self::renderStatus( $state );
		self::renderSync();
		self::renderPhotos();
		self::renderControls( $state );
		self::renderLog( $log->entries() );
		echo '</div>';
	}

	/**
	 * Status table.
	 *
	 * @param array $state State of the job.
	 */
	private static function renderStatus( array $state ): void {
		$cache = new Cache( $GLOBALS['wpdb'] );
		$next  = wp_next_scheduled( Config::CRON_HOOK );
		$rows  = array(
			__( 'Status', 'wp-inaturalist' )               => $state['status'],
			__( 'Progress', 'wp-inaturalist' )             => sprintf( '%d / %d', min( $state['next'], count( $state['ranges'] ) ), count( $state['ranges'] ) ),
			__( 'Observations fetched', 'wp-inaturalist' ) => (string) $state['fetched'],
			__( 'Observations in the cache', 'wp-inaturalist' ) => (string) $cache->count(),
			__( 'Started by', 'wp-inaturalist' )           => (string) $state['trigger'],
			__( 'Last change', 'wp-inaturalist' )          => $state['updated_at'] ? wp_date( 'Y-m-d H:i:s', $state['updated_at'] ) : '',
			__( 'Next daily refresh', 'wp-inaturalist' )   => $next ? wp_date( 'Y-m-d H:i:s', $next ) : '',
			__( 'Last error', 'wp-inaturalist' )           => (string) $state['error'],
		);
		echo '<h2>' . esc_html__( 'Refresh', 'wp-inaturalist' ) . '</h2><table class="widefat striped"><tbody>';
		foreach ( $rows as $label => $value ) {
			printf( '<tr><th scope="row">%1$s</th><td>%2$s</td></tr>', esc_html( $label ), esc_html( $value ) );
		}
		echo '</tbody></table>';
	}

	/**
	 * Forms.
	 *
	 * @param array $state State of the job.
	 */
	private static function renderControls( array $state ): void {
		$today = ( new \DateTimeImmutable( '@' . time() ) )->setTimezone( wp_timezone() );
		$from  = $today->modify( '-' . ( Config::DAILY_WINDOW_DAYS - 1 ) . ' days' )->format( 'Y-m-d' );

		echo '<h2>' . esc_html__( 'Actions', 'wp-inaturalist' ) . '</h2>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_START ) . '" />';
		wp_nonce_field( self::ACTION_START );
		printf(
			'<p><label>%1$s <input type="date" name="from" value="%2$s" required /></label> <label>%3$s <input type="date" name="to" value="%4$s" required /></label> ',
			esc_html__( 'From', 'wp-inaturalist' ),
			esc_attr( $from ),
			esc_html__( 'to', 'wp-inaturalist' ),
			esc_attr( $today->format( 'Y-m-d' ) )
		);
		submit_button( __( 'Refresh these days', 'wp-inaturalist' ), 'primary', 'submit', false );
		echo '</p></form>';

		$buttons = array(
			self::ACTION_STOP   => array( __( 'Stop', 'wp-inaturalist' ), Job::RUNNING === $state['status'] ),
			self::ACTION_RESUME => array( __( 'Resume', 'wp-inaturalist' ), in_array( $state['status'], array( Job::STOPPED, Job::FAILED ), true ) ),
			self::ACTION_CLEAR  => array( __( 'Empty the cache', 'wp-inaturalist' ), true ),
		);
		echo '<p>';
		foreach ( $buttons as $action => $button ) {
			echo '<form method="post" style="display:inline" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '" />';
			wp_nonce_field( $action );
			submit_button( $button[0], 'secondary', 'submit', false, $button[1] ? array() : array( 'disabled' => 'disabled' ) );
			echo '</form> ';
		}
		echo '</p>';
	}

	/**
	 * What is known about the account and the last loads and synchronisations, and the button to load everything.
	 */
	private static function renderSync(): void {
		$sync  = SyncState::get();
		$probe = $sync['probe'];
		$when  = static function ( $ts ) {
			return $ts ? wp_date( 'Y-m-d H:i:s', (int) $ts ) : __( 'never', 'wp-inaturalist' );
		};
		$rows  = array(
			__( 'Account check', 'wp-inaturalist' )        => isset( $probe['total'] ) ? sprintf( /* translators: 1: number of observations, 2: date of the first one, 3: time of the check. */ __( '%1$d observations, from %2$s (checked %3$s)', 'wp-inaturalist' ), (int) $probe['total'], isset( $probe['first'] ) && $probe['first'] ? $probe['first'] : '-', $when( isset( $probe['at'] ) ? $probe['at'] : 0 ) ) : __( 'not done yet', 'wp-inaturalist' ),
			__( 'Full load', 'wp-inaturalist' )            => SyncState::isLoaded( Config::getLogin() ) ? $when( $sync['full_at'] ) : __( 'not done for this login', 'wp-inaturalist' ),
			__( 'Last synchronisation', 'wp-inaturalist' ) => $when( $sync['sync_at'] ),
			__( 'Last reconciliation', 'wp-inaturalist' )  => $when( $sync['reconcile_at'] ),
		);
		echo '<h2>' . esc_html__( 'Loading and synchronisation', 'wp-inaturalist' ) . '</h2><table class="widefat striped"><tbody>';
		foreach ( $rows as $label => $value ) {
			printf( '<tr><th scope="row">%1$s</th><td>%2$s</td></tr>', esc_html( $label ), esc_html( $value ) );
		}
		echo '</tbody></table>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION_LOAD ) . '" />';
		wp_nonce_field( self::ACTION_LOAD );
		echo '<p>';
		submit_button( __( 'Load all my observations', 'wp-inaturalist' ), 'secondary', 'submit', false );
		echo ' ' . esc_html__( 'Asks iNaturalist for the number of observations, then loads them in the background.', 'wp-inaturalist' ) . '</p></form>';
	}

	/**
	 * Report on the photos of the media library: what is linked among the latest cached observations. Read-only.
	 */
	private static function renderPhotos(): void {
		global $wpdb;
		$cache  = new Cache( $wpdb );
		$linker = new PhotoLinker( $cache, new AttachmentIndex( $wpdb ) );
		$rows   = Config::getLogin() ? $cache->latest( Config::getLogin(), 30 ) : array();
		echo '<h2>' . esc_html__( 'Photos of the media library', 'wp-inaturalist' ) . '</h2>';
		if ( ! Config::getUseMediaLibrary() ) {
			echo '<p>' . esc_html__( 'This option is off in the settings: the photos of iNaturalist are used.', 'wp-inaturalist' ) . '</p>';
			return;
		}
		$rows = array_values( array_filter( $rows, array( PhotoLinker::class, 'hasPhoto' ) ) );
		if ( array() === $rows ) {
			echo '<p>' . esc_html__( 'No cached observation with a photo yet.', 'wp-inaturalist' ) . '</p>';
			return;
		}
		$labels = array(
			'manual'   => __( 'Linked by hand', 'wp-inaturalist' ),
			'auto'     => __( 'Matched by the time of the shot', 'wp-inaturalist' ),
			'no_time'  => __( 'No time of day in the observation', 'wp-inaturalist' ),
			'no_match' => __( 'No photo of the media library taken at that time', 'wp-inaturalist' ),
		);
		$body   = '';
		$linked = 0;
		foreach ( $rows as $row ) {
			$choice  = $linker->choose( $row );
			$linked += null === $choice['id'] ? 0 : 1;
			$name    = '' !== (string) $row['common_name'] ? $row['common_name'] : $row['scientific_name'];
			$photo   = null === $choice['id'] ? '' : wp_get_attachment_image( $choice['id'], array( 48, 48 ) );
			$edit    = null === $choice['id'] ? '' : get_edit_post_link( $choice['id'] );
			$body   .= sprintf(
				'<tr><td>%1$s</td><td>%2$s</td><td>%3$s</td><td>%4$s</td></tr>',
				esc_html( (string) ( isset( $row['time_observed_at'] ) ? $row['time_observed_at'] : $row['observed_on'] ) ),
				esc_html( (string) $name ),
				$edit ? '<a href="' . esc_url( $edit ) . '">' . $photo . '</a>' : $photo,
				esc_html( $labels[ $choice['how'] ] )
			);
		}
		echo '<p>' . esc_html( sprintf( /* translators: 1: number linked, 2: number of observations. */ __( '%1$d of the %2$d latest observations are linked to a photo of the media library.', 'wp-inaturalist' ), $linked, count( $rows ) ) ) . '</p>';
		echo '<table class="widefat striped"><tbody>' . $body . '</tbody></table>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every cell is escaped above.
	}

	/**
	 * The log, newest first.
	 *
	 * @param array $entries Log entries, oldest first.
	 */
	private static function renderLog( array $entries ): void {
		echo '<h2>' . esc_html__( 'Log', 'wp-inaturalist' ) . '</h2>';
		if ( array() === $entries ) {
			echo '<p>' . esc_html__( 'Nothing yet.', 'wp-inaturalist' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped"><tbody>';
		foreach ( array_slice( array_reverse( $entries ), 0, 50 ) as $entry ) {
			printf(
				'<tr><td>%1$s</td><td>%2$s</td><td>%3$s</td></tr>',
				esc_html( wp_date( 'Y-m-d H:i:s', (int) $entry['time'] ) ),
				esc_html( (string) $entry['level'] ),
				esc_html( (string) $entry['message'] )
			);
		}
		echo '</tbody></table>';
	}

	/**
	 * Checks the capability and the nonce of an action; dies (403) when either is wrong.
	 *
	 * @param string $action Action and nonce name.
	 */
	private static function guard( string $action ): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'wp-inaturalist' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $action );
	}

	/**
	 * Starts a refresh over the submitted days.
	 *
	 * @param array    $post Submitted fields (already unslashed).
	 * @param Job|null $job  Job (tests).
	 * @return string Notice code.
	 */
	public static function handleStart( array $post, ?Job $job = null ): string {
		self::guard( self::ACTION_START );
		$from = isset( $post['from'] ) && is_string( $post['from'] ) ? sanitize_text_field( $post['from'] ) : '';
		$to   = isset( $post['to'] ) && is_string( $post['to'] ) ? sanitize_text_field( $post['to'] ) : '';
		return ( $job ? $job : Scheduler::job() )->start( $from, $to, 'manual' );
	}

	/**
	 * Stops the refresh.
	 *
	 * @param Job|null $job Job (tests).
	 * @return string Notice code.
	 */
	public static function handleStop( ?Job $job = null ): string {
		self::guard( self::ACTION_STOP );
		return ( $job ? $job : Scheduler::job() )->stop();
	}

	/**
	 * Resumes the refresh.
	 *
	 * @param Job|null $job Job (tests).
	 * @return string Notice code.
	 */
	public static function handleResume( ?Job $job = null ): string {
		self::guard( self::ACTION_RESUME );
		return ( $job ? $job : Scheduler::job() )->resume();
	}

	/**
	 * Empties the cache.
	 *
	 * @param Cache|null $cache Cache (tests).
	 * @return string Notice code.
	 */
	public static function handleClear( ?Cache $cache = null ): string {
		self::guard( self::ACTION_CLEAR );
		( $cache ? $cache : new Cache( $GLOBALS['wpdb'] ) )->clear();
		( new JobLog() )->add( 'info', 'The cache was emptied.' );
		return 'cleared';
	}

	/**
	 * Checks the account (number of observations, first day) and starts loading all of them, from the first one to today.
	 *
	 * More than {@see Config::MAX_AUTO_LOAD} observations: nothing is started. Another account's observations are removed first.
	 *
	 * @param object|null $client Client with summary() (tests).
	 * @param Job|null    $job    Job (tests).
	 * @param Cache|null  $cache  Cache (tests).
	 * @return string Notice code.
	 */
	public static function handleLoadAll( $client = null, ?Job $job = null, ?Cache $cache = null ): string {
		self::guard( self::ACTION_LOAD );
		global $wpdb;
		$login = Config::getLogin();
		if ( '' === $login ) {
			return 'no_login';
		}
		$client = $client ? $client : new INatClient( new Throttle() );
		$now    = time();
		try {
			$summary = $client->summary( $login, Config::getQuality() );
		} catch ( INatException $e ) {
			return 'unavailable';
		}
		SyncState::recordProbe( $login, $summary['total'], $summary['first'], $now );
		if ( 0 === $summary['total'] || null === $summary['first'] ) {
			SyncState::fullLoadDone( $login, $now, null ); // Nothing to load: the offer is closed; the daily synchronisation catches new observations.
			return 'nothing_found';
		}
		if ( $summary['total'] > Config::MAX_AUTO_LOAD ) {
			return 'too_many';
		}
		if ( SyncState::get()['login'] !== $login ) {
			try {
				( $cache ? $cache : new Cache( $wpdb ) )->deleteOtherAccounts( $login );
			} catch ( \RuntimeException $e ) {
				return 'unavailable';
			}
		}
		( new JobLog() )->add( 'info', sprintf( 'Loading all observations: %d found on iNaturalist, from %s.', $summary['total'], $summary['first'] ) );
		return ( $job ? $job : Scheduler::job() )->request( $summary['first'], wp_date( 'Y-m-d' ), 'initial' );
	}

	/**
	 * Redirects to the page with a notice code, then ends the request.
	 *
	 * @param string $code Notice code.
	 */
	private static function redirect( string $code ): void {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'          => self::PAGE,
					'wpinat_notice' => $code,
				),
				admin_url( 'tools.php' )
			)
		);
		exit;
	}

	/**
	 * Hook callback of admin-post.php?action=wpinat_job_start.
	 */
	public static function onStart(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the nonce is checked by guard() in handleStart().
		self::redirect( self::handleStart( wp_unslash( $_POST ) ) );
	}

	/**
	 * Hook callback of admin-post.php?action=wpinat_job_stop.
	 */
	public static function onStop(): void {
		self::redirect( self::handleStop() );
	}

	/**
	 * Hook callback of admin-post.php?action=wpinat_job_resume.
	 */
	public static function onResume(): void {
		self::redirect( self::handleResume() );
	}

	/**
	 * Hook callback of admin-post.php?action=wpinat_load_all.
	 */
	public static function onLoadAll(): void {
		self::redirect( self::handleLoadAll() );
	}

	/**
	 * Hook callback of admin-post.php?action=wpinat_cache_clear.
	 */
	public static function onClear(): void {
		self::redirect( self::handleClear() );
	}
}
