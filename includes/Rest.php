<?php
/**
 * REST routes: refresh of a post and check of a login.
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
 * Both routes answer a status code, never free text; the scripts map codes to translated messages.
 *
 * With cookie authentication WordPress only recognises the user when the request carries the REST nonce (X-WP-Nonce);
 * without it the request is anonymous and the permission callbacks refuse it.
 */
final class Rest {

	/**
	 * Registers the routes (hook rest_api_init).
	 */
	public static function register(): void {
		register_rest_route(
			Config::REST_NS,
			'/refresh',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'refresh' ),
				'permission_callback' => array( self::class, 'canRefresh' ),
				'args'                => array(
					'post_id' => array(
						'type'     => 'integer',
						'required' => true,
					),
				),
			)
		);
		register_rest_route(
			Config::REST_NS,
			'/verify-login',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'verifyLogin' ),
				'permission_callback' => array( self::class, 'canVerify' ),
				'args'                => array(
					'login' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * Who may refresh a post: whoever may edit it.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function canRefresh( $request ): bool {
		$id = (int) $request->get_param( 'post_id' );
		return $id > 0 && current_user_can( 'edit_post', $id );
	}

	/**
	 * Who may check a login: whoever may change the settings.
	 */
	public static function canVerify(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Refreshes the observations of the period of a saved post. The period is computed here, from the post.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @param Job|null         $job     Job (tests).
	 * @return array|\WP_Error
	 */
	public static function refresh( $request, ?Job $job = null ) {
		$post = get_post( (int) $request->get_param( 'post_id' ) );
		if ( ! $post ) {
			return new \WP_Error( 'wpinat_no_post', 'No such post.', array( 'status' => 404 ) );
		}
		return array( 'status' => PostRefresh::refresh( $post, $job ) );
	}

	/**
	 * Tells whether an iNaturalist login exists.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @param object|null      $client  Client with fetchUser() (tests).
	 * @return array{status:string,login?:string}
	 */
	public static function verifyLogin( $request, $client = null ): array {
		$login = $request->get_param( 'login' );
		$login = is_string( $login ) ? trim( $login ) : '';
		if ( ! Config::isValidLogin( $login ) ) {
			return array( 'status' => 'invalid' );
		}
		$client = $client ? $client : new INatClient( new Throttle() );
		try {
			$user = $client->fetchUser( $login );
		} catch ( INatException $e ) {
			return array( 'status' => 'unavailable' );
		}
		return null === $user ? array( 'status' => 'not_found' ) : array(
			'status' => 'found',
			'login'  => $user['login'],
		);
	}
}
