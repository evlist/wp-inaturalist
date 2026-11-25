<?php
/**
 * Tests of INatClient, with recorded answers and no network.
 *
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * @package WpInaturalist
 */

namespace Evlist\WpInaturalist\Tests;

use Evlist\WpInaturalist\INatClient;
use Evlist\WpInaturalist\INatException;
use Evlist\WpInaturalist\Throttle;

require_once __DIR__ . '/TestCase.php';

/**
 * Request building, pagination and error handling.
 */
final class INatClientTest extends TestCase {

	/**
	 * Requests seen by the fake HTTP function: list of array( url, args ).
	 *
	 * @var array
	 */
	private $requests = array();

	/**
	 * Number of times the throttle waited.
	 *
	 * @var int
	 */
	private $waits = 0;

	private function fixture( string $name ): string {
		return file_get_contents( __DIR__ . '/fixtures/' . $name );
	}

	private function ok( string $body ): array {
		return array(
			'response' => array( 'code' => 200 ),
			'body'     => $body,
			'headers'  => array(),
		);
	}

	/**
	 * Client answering with the given responses in order.
	 *
	 * @param array $responses Responses or WP_Error objects.
	 */
	private function client( array $responses ): INatClient {
		$throttle = new Throttle(
			1.0,
			static function () {
				return 1000.0;
			},
			function () {
				++$this->waits;
			}
		);
		$http     = function ( $url, $args ) use ( &$responses ) {
			$this->requests[] = array( $url, $args );
			return array_shift( $responses );
		};
		return new INatClient( $throttle, $http );
	}

	private function fetch( INatClient $client, array $options = array() ): array {
		return $client->fetchObservations( 'someone', new \DateTimeImmutable( '2026-11-20' ), new \DateTimeImmutable( '2026-11-24' ), $options );
	}

	/**
	 * Query parameters of the n-th request.
	 *
	 * @param int $n Index.
	 */
	private function query( int $n = 0 ): array {
		parse_str( (string) wp_parse_url( $this->requests[ $n ][0], PHP_URL_QUERY ), $q );
		return $q;
	}

	public function test_the_request_carries_the_filters_and_the_field_list() {
		$list = $this->fetch(
			$this->client( array( $this->ok( $this->fixture( 'observations-page.json' ) ) ) ),
			array(
				'locale'  => 'fr',
				'quality' => 'research',
			)
		);

		$this->assertCount( 1, $list );
		$this->assertCount( 1, $this->requests );
		$this->assertStringStartsWith( 'https://api.inaturalist.org/v2/observations?', $this->requests[0][0] );
		$q = $this->query();
		$this->assertSame( 'someone', $q['user_login'] );
		$this->assertSame( '2026-11-20', $q['d1'] );
		$this->assertSame( '2026-11-24', $q['d2'] );
		$this->assertSame( '200', $q['per_page'] );
		$this->assertSame( '1', $q['page'] );
		$this->assertSame( 'fr', $q['locale'] );
		$this->assertSame( 'research', $q['quality_grade'] );
		$this->assertSame( INatClient::FIELDS, $q['fields'] );
		$this->assertStringContainsString( 'wp-inaturalist/', $this->requests[0][1]['user-agent'] );
	}

	public function test_no_quality_filter_when_any_grade_is_wanted() {
		$this->fetch( $this->client( array( $this->ok( $this->fixture( 'empty.json' ) ) ) ), array( 'quality' => 'any' ) );
		$this->assertArrayNotHasKey( 'quality_grade', $this->query() );
		$this->assertArrayNotHasKey( 'locale', $this->query() );
	}

	public function test_an_empty_answer_gives_an_empty_list() {
		$this->assertSame( array(), $this->fetch( $this->client( array( $this->ok( $this->fixture( 'empty.json' ) ) ) ) ) );
	}

	public function test_pages_are_followed_until_the_total_is_reached() {
		$one                       = json_decode( $this->fixture( 'observations-page.json' ), true );
		$two                       = $one;
		$one['total_results']      = 2;
		$two['total_results']      = 2;
		$two['page']               = 2;
		$two['results'][0]['id']   = 2;
		$two['results'][0]['uuid'] = 'second';

		$list = $this->fetch( $this->client( array( $this->ok( json_encode( $one ) ), $this->ok( json_encode( $two ) ) ) ), array( 'per_page' => 1 ) );

		$this->assertSame( array( 416889196, 2 ), array( $list[0]->id, $list[1]->id ) );
		$this->assertCount( 2, $this->requests );
		$this->assertSame( '2', $this->query( 1 )['page'] );
		$this->assertSame( '1', $this->query( 1 )['per_page'] );
	}

	public function test_every_request_goes_through_the_throttle() {
		$one                  = json_decode( $this->fixture( 'observations-page.json' ), true );
		$one['total_results'] = 2;
		$client               = $this->client( array( $this->ok( json_encode( $one ) ), $this->ok( json_encode( $one ) ) ) );
		$this->fetch( $client, array( 'per_page' => 1 ) );
		$this->assertNotFalse( get_transient( Throttle::TRANSIENT ), 'the throttle recorded the requests' );
		$this->assertSame( 1, $this->waits, 'the second request had to wait: same clock reading as the first' );
	}

	public function test_a_partial_list_is_never_returned() {
		$one                  = json_decode( $this->fixture( 'observations-page.json' ), true );
		$one['total_results'] = 5;
		$this->expectExceptionObject( new INatException( INatException::TRUNCATED, 'More observations than the page limit allows.' ) );
		$this->fetch( $this->client( array( $this->ok( json_encode( $one ) ) ) ), array( 'max_pages' => 1 ) );
	}

	public function test_a_422_is_a_permanent_http_error() {
		$e = $this->failure(
			$this->client(
				array(
					array(
						'response' => array( 'code' => 422 ),
						'body'     => $this->fixture( 'error-422.json' ),
					),
				)
			)
		);
		$this->assertSame( INatException::HTTP, $e->kind );
		$this->assertFalse( $e->isRetryable() );
	}

	public function test_a_429_reports_the_retry_delay() {
		$e = $this->failure(
			$this->client(
				array(
					array(
						'response' => array( 'code' => 429 ),
						'body'     => '',
						'headers'  => array( 'retry-after' => '120' ),
					),
				)
			)
		);
		$this->assertSame( INatException::RATE_LIMITED, $e->kind );
		$this->assertSame( 120, $e->retryAfter );
		$this->assertTrue( $e->isRetryable() );
	}

	public function test_a_429_without_header_has_no_delay() {
		$e = $this->failure(
			$this->client(
				array(
					array(
						'response' => array( 'code' => 429 ),
						'body'     => '',
					),
				)
			)
		);
		$this->assertNull( $e->retryAfter );
	}

	public function test_a_503_is_retryable() {
		$e = $this->failure(
			$this->client(
				array(
					array(
						'response' => array( 'code' => 503 ),
						'body'     => 'down',
					),
				)
			)
		);
		$this->assertSame( INatException::SERVER, $e->kind );
		$this->assertTrue( $e->isRetryable() );
	}

	public function test_a_transport_error_is_retryable() {
		$e = $this->failure( $this->client( array( new \WP_Error( 'http_request_failed', 'timeout' ) ) ) );
		$this->assertSame( INatException::NETWORK, $e->kind );
		$this->assertTrue( $e->isRetryable() );
	}

	/**
	 * @dataProvider malformed
	 * @param string $body Body of a 200 answer.
	 */
	public function test_a_malformed_answer_is_an_error_not_an_empty_result( string $body ) {
		$e = $this->failure( $this->client( array( $this->ok( $body ) ) ) );
		$this->assertSame( INatException::MALFORMED, $e->kind );
	}

	public function malformed(): array {
		return array(
			'not json'            => array( '<html>' ),
			'no results'          => array( '{"total_results":1}' ),
			'no total'            => array( '{"results":[]}' ),
			'incomplete result'   => array( '{"total_results":1,"results":[{"id":1}]}' ),
			'result is not array' => array( '{"total_results":1,"results":[5]}' ),
		);
	}

	public function test_an_invalid_login_is_refused_before_any_request() {
		$client = $this->client( array() );
		try {
			$client->fetchObservations( '../x', new \DateTimeImmutable( 'today' ), new \DateTimeImmutable( 'today' ) );
			$this->fail( 'expected an exception' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertSame( array(), $this->requests );
		}
	}

	private function userAnswer( int $code, string $body ): array {
		return array(
			'response' => array( 'code' => $code ),
			'body'     => $body,
		);
	}

	public function test_a_known_user_is_returned_with_the_exact_login() {
		$client = $this->client( array( $this->userAnswer( 200, '{"total_results":1,"results":[{"id":2014634,"login":"zonata","created_at":"2019-07-19T00:36:12+00:00","spam":false}]}' ) ) );
		$this->assertSame(
			array(
				'id'         => 2014634,
				'login'      => 'zonata',
				'created_at' => '2019-07-19T00:36:12+00:00',
			),
			$client->fetchUser( 'zonata' )
		);
		$this->assertSame( 'https://api.inaturalist.org/v1/users/zonata', $this->requests[0][0] );
	}

	public function test_an_unknown_user_is_null_not_an_error() {
		$client = $this->client( array( $this->userAnswer( 404, '{"error":"User does not exist","status":404}' ) ) );
		$this->assertNull( $client->fetchUser( 'zz_no_such_user_zz' ) );
	}

	public function test_user_errors_are_typed_like_the_others() {
		foreach ( array(
			429 => INatException::RATE_LIMITED,
			503 => INatException::SERVER,
			403 => INatException::HTTP,
		) as $code => $kind ) {
			try {
				$this->client( array( $this->userAnswer( $code, '' ) ) )->fetchUser( 'me' );
				$this->fail( 'expected an exception for ' . $code );
			} catch ( INatException $e ) {
				$this->assertSame( $kind, $e->kind, (string) $code );
			}
		}
		foreach ( array( '<html>', '{"results":[]}', '{"results":[{"id":"x","login":"a"}]}' ) as $body ) {
			try {
				$this->client( array( $this->userAnswer( 200, $body ) ) )->fetchUser( 'me' );
				$this->fail( 'expected MALFORMED for ' . $body );
			} catch ( INatException $e ) {
				$this->assertSame( INatException::MALFORMED, $e->kind, $body );
			}
		}
	}

	public function test_the_login_cannot_change_the_path_of_the_user_request() {
		$client = $this->client( array() );
		foreach ( array( '../observations', 'a/b', 'a?b=1', '' ) as $login ) {
			try {
				$client->fetchUser( $login );
				$this->fail( 'expected an exception for ' . $login );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertSame( array(), $this->requests );
			}
		}
	}

	private function failure( INatClient $client ): INatException {
		try {
			$this->fetch( $client );
		} catch ( INatException $e ) {
			return $e;
		}
		$this->fail( 'expected an INatException' );
	}
}
