<?php
/**
 * Throttled GET shared by the clients of external services.
 *
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * @package WpInaturalist
 */

namespace Evlist\WpInaturalist;

// Exception messages are logged, never printed as is: the log screen escapes them where it displays them.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One throttled GET with typed errors, used by {@see INatClient} and {@see WikidataClient}.
 */
final class Http {

	/**
	 * Performs a GET. Transport errors, 429 and 5xx are raised; any other status is returned to the caller.
	 *
	 * @param Throttle $throttle Delay between requests to this service.
	 * @param callable $http     Function with the signature of wp_remote_get().
	 * @param string   $url      Full URL.
	 * @param string   $accept   Value of the Accept header.
	 * @return array{0:int,1:string} Status code and body.
	 * @throws INatException On a transport error, 429 or 5xx.
	 */
	public static function get( Throttle $throttle, callable $http, string $url, string $accept = 'application/json' ): array {
		$throttle->wait();
		$response = call_user_func(
			$http,
			$url,
			array(
				'timeout'    => 20,
				'user-agent' => 'wp-inaturalist/' . WPINAT_VERSION . ' (' . home_url() . ')',
				'headers'    => array( 'Accept' => $accept ),
			)
		);
		if ( is_wp_error( $response ) ) {
			throw new INatException( INatException::NETWORK, 'The request failed: ' . $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 429 === $code ) {
			$retry = wp_remote_retrieve_header( $response, 'retry-after' );
			throw new INatException( INatException::RATE_LIMITED, 'Too many requests.', is_numeric( $retry ) ? (int) $retry : null );
		}
		if ( $code >= 500 ) {
			throw new INatException( INatException::SERVER, 'Server error ' . $code . '.' );
		}
		return array( $code, (string) wp_remote_retrieve_body( $response ) );
	}
}
