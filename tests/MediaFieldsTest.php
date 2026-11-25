<?php
/**
 * Tests of MediaFields.
 *
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * @package WpInaturalist
 */

namespace Evlist\WpInaturalist\Tests;

use Evlist\WpInaturalist\Config;
use Evlist\WpInaturalist\MediaFields;

require_once __DIR__ . '/TestCase.php';

/**
 * The manual link field of the attachment edit screen.
 *
 * The real media screen and modal, and WordPress's own nonce check on saving, were never tried.
 */
final class MediaFieldsTest extends TestCase {

	/**
	 * @dataProvider values
	 * @param mixed    $value    Typed value.
	 * @param int|null $expected Observation id.
	 */
	public function test_parse( $value, ?int $expected ) {
		$this->assertSame( $expected, MediaFields::parse( $value ) );
	}

	public function values(): array {
		return array(
			'a number'                => array( '416889196', 416889196 ),
			'spaces around'           => array( '  416889196 ', 416889196 ),
			'a url'                   => array( 'https://www.inaturalist.org/observations/416889196', 416889196 ),
			'a url without www'       => array( 'https://inaturalist.org/observations/416889196', 416889196 ),
			'a url with a trailing /' => array( 'https://www.inaturalist.org/observations/416889196/', 416889196 ),
			'a url with a query'      => array( 'https://www.inaturalist.org/observations/416889196?locale=fr', 416889196 ),
			'zero'                    => array( '0', null ),
			'a leading zero'          => array( '0123', null ),
			'negative'                => array( '-5', null ),
			'a decimal'               => array( '1.5', null ),
			'another site'            => array( 'https://example.test/observations/5', null ),
			'a look-alike host'       => array( 'https://www.inaturalist.org.evil.test/observations/5', null ),
			'http'                    => array( 'http://www.inaturalist.org/observations/5', null ),
			'a script'                => array( '<script>', null ),
			'too long'                => array( '12345678901234567', null ),
			'an array'                => array( array( '5' ), null ),
			'null'                    => array( null, null ),
		);
	}

	public function test_the_field_is_offered_for_images_only_with_the_current_value() {
		update_post_meta( 7, Config::MANUAL_LINK_META, '123' );
		$fields = MediaFields::addField(
			array( 'url' => array() ),
			(object) array(
				'ID'             => 7,
				'post_mime_type' => 'image/jpeg',
			)
		);
		$this->assertSame( 'text', $fields['wpinat_observation']['input'] );
		$this->assertSame( '123', $fields['wpinat_observation']['value'] );
		$this->assertArrayHasKey( 'url', $fields, 'other fields are kept' );

		$pdf = MediaFields::addField(
			array( 'url' => array() ),
			(object) array(
				'ID'             => 8,
				'post_mime_type' => 'application/pdf',
			)
		);
		$this->assertSame( array( 'url' => array() ), $pdf );
		$this->assertSame(
			'junk',
			MediaFields::addField(
				'junk',
				(object) array(
					'ID'             => 9,
					'post_mime_type' => 'image/png',
				)
			)
		);
	}

	public function test_a_valid_value_is_stored_as_the_observation_id() {
		MediaFields::saveField( array( 'ID' => 7 ), array( 'wpinat_observation' => 'https://www.inaturalist.org/observations/555' ) );
		$this->assertSame( '555', get_post_meta( 7, Config::MANUAL_LINK_META, true ) );
	}

	public function test_an_empty_value_removes_the_link() {
		update_post_meta( 7, Config::MANUAL_LINK_META, '555' );
		MediaFields::saveField( array( 'ID' => 7 ), array( 'wpinat_observation' => '  ' ) );
		$this->assertSame( '', get_post_meta( 7, Config::MANUAL_LINK_META, true ) );
	}

	public function test_an_invalid_value_changes_nothing() {
		update_post_meta( 7, Config::MANUAL_LINK_META, '555' );
		MediaFields::saveField( array( 'ID' => 7 ), array( 'wpinat_observation' => 'not a number' ) );
		$this->assertSame( '555', get_post_meta( 7, Config::MANUAL_LINK_META, true ) );
	}

	public function test_without_the_capability_nothing_is_saved() {
		$GLOBALS['wpinat_test']['can'] = false;
		MediaFields::saveField( array( 'ID' => 7 ), array( 'wpinat_observation' => '555' ) );
		$this->assertSame( '', get_post_meta( 7, Config::MANUAL_LINK_META, true ) );
		$this->assertSame( array( 'edit_post', 7 ), end( $GLOBALS['wpinat_test']['cap_ids'] ), 'the capability of that attachment is asked' );
	}

	public function test_a_form_without_the_field_is_left_alone() {
		update_post_meta( 7, Config::MANUAL_LINK_META, '555' );
		$post = array( 'ID' => 7 );
		$this->assertSame( $post, MediaFields::saveField( $post, array( 'other' => 'x' ) ) );
		$this->assertSame( '555', get_post_meta( 7, Config::MANUAL_LINK_META, true ) );
	}
}
