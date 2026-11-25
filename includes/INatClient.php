<?php
/**
 * Client for the iNaturalist web API (v2).
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
 * Reads the public observations of one user over a date range.
 *
 * Only to be called from a cron job, an admin action or a REST call of a user who may do it, never from a page view.
 * No token, no cookie: public data only.
 */
final class INatClient {

	const ENDPOINT = 'https://api.inaturalist.org/v2/observations';

	/**
	 * Fields asked from the API (rison syntax). The v2 API returns nothing but what is listed, which keeps answers small
	 * (a v1 answer is about 130 KB per observation).
	 */
	const FIELDS = '(id:!t,uuid:!t,uri:!t,observed_on:!t,time_observed_at:!t,observed_time_zone:!t,quality_grade:!t,geoprivacy:!t,obscured:!t,taxon:(id:!t,name:!t,rank:!t,preferred_common_name:!t,wikipedia_url:!t,iconic_taxon_name:!t),photos:(id:!t,url:!t,attribution:!t,license_code:!t))';

	const USERS = 'https://api.inaturalist.org/v1/users/';

	const PER_PAGE_MAX = 200;

	/**
	 * Throttle.
	 *
	 * @var Throttle
	 */
	private $throttle;

	/**
	 * HTTP function with the signature of wp_remote_get().
	 *
	 * @var callable
	 */
	private $http;

	/**
	 * Constructor.
	 *
	 * @param Throttle      $throttle Delay between requests.
	 * @param callable|null $http     Replaceable wp_remote_get() (tests).
	 */
	public function __construct( Throttle $throttle, ?callable $http = null ) {
		$this->throttle = $throttle;
		$this->http     = $http ? $http : 'wp_remote_get';
	}

	/**
	 * Observations of a user between two dates (inclusive), oldest first.
	 *
	 * Options: "locale" (language of the common names), "quality" ("research" or "any"), "per_page" (up to 200) and
	 * "max_pages" (default 20; reaching it with results left raises a TRUNCATED error instead of returning a partial list).
	 *
	 * @param string             $login   iNaturalist login.
	 * @param \DateTimeInterface $from    First day.
	 * @param \DateTimeInterface $to      Last day.
	 * @param array              $options See above.
	 * @return Observation[]
	 * @throws INatException On any failure.
	 * @throws \InvalidArgumentException When the login is not valid.
	 */
	public function fetchObservations( string $login, \DateTimeInterface $from, \DateTimeInterface $to, array $options = array() ): array {
		if ( ! Config::isValidLogin( $login ) ) {
			throw new \InvalidArgumentException( 'Invalid iNaturalist login.' );
		}
		$per_page  = isset( $options['per_page'] ) ? max( 1, min( self::PER_PAGE_MAX, (int) $options['per_page'] ) ) : self::PER_PAGE_MAX;
		$max_pages = isset( $options['max_pages'] ) ? max( 1, (int) $options['max_pages'] ) : 20;

		$params = array(
			'user_login' => $login,
			'd1'         => $from->format( 'Y-m-d' ),
			'd2'         => $to->format( 'Y-m-d' ),
			'per_page'   => $per_page,
			'order_by'   => 'observed_on',
			'order'      => 'asc',
			'fields'     => self::FIELDS,
		);
		if ( ! empty( $options['locale'] ) ) {
			$params['locale'] = (string) $options['locale'];
		}
		if ( isset( $options['quality'] ) && Config::QUALITY_RESEARCH === $options['quality'] ) {
			$params['quality_grade'] = 'research';
		}

		$observations = array();
		for ( $page = 1; $page <= $max_pages; $page++ ) {
			$params['page'] = $page;
			$body           = $this->request( $params );
			foreach ( $body['results'] as $row ) {
				try {
					$observations[] = Observation::fromApi( is_array( $row ) ? $row : array() );
				} catch ( \InvalidArgumentException $e ) {
					throw new INatException( INatException::MALFORMED, 'An observation of the answer is incomplete.' );
				}
			}
			if ( array() === $body['results'] || count( $observations ) >= $body['total_results'] ) {
				return $observations;
			}
		}
		throw new INatException( INatException::TRUNCATED, 'More observations than the page limit allows.' );
	}

	/**
	 * The public profile of a user, or null when no such user exists.
	 *
	 * @param string $login iNaturalist login.
	 * @return array{id:int,login:string,created_at:?string}|null
	 * @throws INatException On any failure other than "no such user".
	 * @throws \InvalidArgumentException When the login is not valid.
	 */
	public function fetchUser( string $login ): ?array {
		if ( ! Config::isValidLogin( $login ) ) {
			throw new \InvalidArgumentException( 'Invalid iNaturalist login.' );
		}
		list( $code, $body ) = $this->get( self::USERS . rawurlencode( $login ) );
		if ( 404 === $code ) {
			return null;
		}
		if ( 200 !== $code ) {
			throw new INatException( INatException::HTTP, 'Unexpected answer ' . $code . '.' );
		}
		$data = json_decode( $body, true );
		$user = ( is_array( $data ) && isset( $data['results'][0] ) && is_array( $data['results'][0] ) ) ? $data['results'][0] : null;
		if ( null === $user || ! isset( $user['id'], $user['login'] ) || ! is_int( $user['id'] ) || ! is_string( $user['login'] ) ) {
			throw new INatException( INatException::MALFORMED, 'The answer is not a user.' );
		}
		return array(
			'id'         => $user['id'],
			'login'      => $user['login'],
			'created_at' => ( isset( $user['created_at'] ) && is_string( $user['created_at'] ) ) ? $user['created_at'] : null,
		);
	}

	/**
	 * One request for a list of observations, checked and decoded.
	 *
	 * @param array $params Query parameters.
	 * @return array{total_results:int,results:array}
	 * @throws INatException On any failure.
	 */
	private function request( array $params ): array {
		list( $code, $body ) = $this->get( self::ENDPOINT . '?' . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 ) );
		if ( 200 !== $code ) {
			throw new INatException( INatException::HTTP, 'Unexpected answer ' . $code . '.' );
		}
		$data = json_decode( $body, true );
		if ( ! is_array( $data ) || ! isset( $data['results'], $data['total_results'] ) || ! is_array( $data['results'] ) || ! is_int( $data['total_results'] ) ) {
			throw new INatException( INatException::MALFORMED, 'The answer is not a list of observations.' );
		}
		return $data;
	}

	/**
	 * One throttled GET. Transport errors, 429 and 5xx are raised; any other status is returned to the caller.
	 *
	 * @param string $url Full URL.
	 * @return array{0:int,1:string} Status code and body.
	 * @throws INatException On a transport error, 429 or 5xx.
	 */
	private function get( string $url ): array {
		$this->throttle->wait();
		$response = call_user_func(
			$this->http,
			$url,
			array(
				'timeout'    => 20,
				'user-agent' => 'wp-inaturalist/' . WPINAT_VERSION . ' (' . home_url() . ')',
				'headers'    => array( 'Accept' => 'application/json' ),
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
