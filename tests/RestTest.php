<?php
/**
 * Tests of the REST routes.
 *
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * @package WpInaturalist
 */

namespace Evlist\WpInaturalist\Tests;

use Evlist\WpInaturalist\Config;
use Evlist\WpInaturalist\INatException;
use Evlist\WpInaturalist\Rest;
use Evlist\WpInaturalist\Scheduler;

require_once __DIR__ . '/TestCase.php';

/**
 * Permissions, answers and the rule that the browser sends no dates.
 *
 * The REST nonce handling is done by WordPress itself and was never tried here.
 */
final class RestTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		update_option( Config::LOGIN, 'me' );
	}

	public function test_both_routes_are_registered_as_post_with_a_permission_callback() {
		Rest::register();
		$routes = $GLOBALS['wpinat_test']['routes'];
		$this->assertSame( array( 'wpinat/v1/refresh', 'wpinat/v1/verify-login' ), array_keys( $routes ) );
		foreach ( $routes as $route ) {
			$this->assertSame( 'POST', $route['methods'] );
			$this->assertIsCallable( $route['permission_callback'] );
			$this->assertIsCallable( $route['callback'] );
		}
		$this->assertTrue( $routes['wpinat/v1/refresh']['args']['post_id']['required'] );
		$this->assertSame( array( 'post_id' ), array_keys( $routes['wpinat/v1/refresh']['args'] ), 'the only argument is the post id' );
		$this->assertArrayNotHasKey( 'from', $routes['wpinat/v1/refresh']['args'], 'no dates from the browser' );
		$this->assertArrayNotHasKey( 'to', $routes['wpinat/v1/refresh']['args'] );
	}

	public function test_refresh_needs_the_right_to_edit_that_post() {
		$this->assertTrue( Rest::canRefresh( new \WP_REST_Request( array( 'post_id' => 42 ) ) ) );
		$this->assertSame( array( 'edit_post', 42 ), end( $GLOBALS['wpinat_test']['cap_ids'] ) );
		$GLOBALS['wpinat_test']['can'] = false;
		$this->assertFalse( Rest::canRefresh( new \WP_REST_Request( array( 'post_id' => 42 ) ) ) );
	}

	/**
	 * @dataProvider badIds
	 * @param mixed $id Submitted post id.
	 */
	public function test_refresh_refuses_a_missing_or_invalid_post_id( $id ) {
		$this->assertFalse( Rest::canRefresh( new \WP_REST_Request( array( 'post_id' => $id ) ) ) );
		$this->assertSame( array(), $GLOBALS['wpinat_test']['cap_ids'], 'no capability question for a nonsense id' );
	}

	public function badIds(): array {
		return array(
			'absent'   => array( null ),
			'zero'     => array( 0 ),
			'negative' => array( -3 ),
			'text'     => array( 'abc' ),
		);
	}

	public function test_refresh_of_an_unknown_post_is_a_404() {
		$answer = Rest::refresh( new \WP_REST_Request( array( 'post_id' => 999 ) ) );
		$this->assertInstanceOf( \WP_Error::class, $answer );
		$this->assertSame( 404, $answer->data['status'] );
	}

	public function test_refresh_computes_the_period_from_the_saved_post_and_ignores_submitted_dates() {
		$GLOBALS['wpinat_test']['posts'][42] = (object) array(
			'ID'           => 42,
			'post_date'    => '2026-11-20 10:00:00',
			'post_content' => '<!-- wp:wpinat/wp-inaturalist {"periodNumber":2} /-->',
			'post_type'    => 'post',
		);
		$answer                              = Rest::refresh(
			new \WP_REST_Request(
				array(
					'post_id' => 42,
					'from'    => '2000-01-01',
					'to'      => '2000-12-31',
				)
			)
		);
		$this->assertSame( array( 'status' => 'started' ), $answer );
		$this->assertSame( array( array( '2026-11-19', '2026-11-20' ) ), Scheduler::job()->state()['ranges'] );
	}

	public function test_verify_needs_manage_options() {
		$this->assertTrue( Rest::canVerify() );
		$this->assertSame( array( 'manage_options' ), end( $GLOBALS['wpinat_test']['cap_ids'] ) );
		$GLOBALS['wpinat_test']['can'] = false;
		$this->assertFalse( Rest::canVerify() );
	}

	private function client( $result ) {
		return new class( $result ) {
			public $asked = array();
			private $result;
			public function __construct( $result ) {
				$this->result = $result;
			}
			public function fetchUser( $login ) {
				$this->asked[] = $login;
				if ( $this->result instanceof \Throwable ) {
					throw $this->result;
				}
				return $this->result;
			}
		};
	}

	public function test_verify_finds_an_account_and_returns_its_exact_login() {
		$client = $this->client(
			array(
				'id'         => 5,
				'login'      => 'Jean',
				'created_at' => null,
			)
		);
		$this->assertSame(
			array(
				'status' => 'found',
				'login'  => 'Jean',
			),
			Rest::verifyLogin( new \WP_REST_Request( array( 'login' => ' jean ' ) ), $client )
		);
		$this->assertSame( array( 'jean' ), $client->asked, 'the value is trimmed' );
	}

	public function test_verify_reports_an_unknown_account() {
		$this->assertSame( array( 'status' => 'not_found' ), Rest::verifyLogin( new \WP_REST_Request( array( 'login' => 'nobody' ) ), $this->client( null ) ) );
	}

	public function test_verify_reports_an_outage_without_blocking() {
		$client = $this->client( new INatException( INatException::NETWORK, 'timeout' ) );
		$this->assertSame( array( 'status' => 'unavailable' ), Rest::verifyLogin( new \WP_REST_Request( array( 'login' => 'me' ) ), $client ) );
		$client = $this->client( new INatException( INatException::RATE_LIMITED, 'slow down' ) );
		$this->assertSame( array( 'status' => 'unavailable' ), Rest::verifyLogin( new \WP_REST_Request( array( 'login' => 'me' ) ), $client ) );
	}

	/**
	 * @dataProvider invalidLogins
	 * @param mixed $login Submitted value.
	 */
	public function test_verify_does_not_ask_the_service_about_an_invalid_login( $login ) {
		$client = $this->client( null );
		$this->assertSame( array( 'status' => 'invalid' ), Rest::verifyLogin( new \WP_REST_Request( array( 'login' => $login ) ), $client ) );
		$this->assertSame( array(), $client->asked );
	}

	public function invalidLogins(): array {
		return array(
			'empty'         => array( '' ),
			'spaces inside' => array( 'a b' ),
			'path'          => array( '../x' ),
			'array'         => array( array( 'x' ) ),
			'absent'        => array( null ),
		);
	}
}
