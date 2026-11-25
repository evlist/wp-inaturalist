<?php
/**
 * Manual link between an attachment and an iNaturalist observation.
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
 * A field on the attachment edit screen and in the media modal. Saving goes through WordPress's own attachment handlers,
 * which check the nonce; the capability is checked again here.
 */
final class MediaFields {

	const FIELD = 'wpinat_observation';

	/**
	 * Observation id from a number or an observation URL, or null when it is neither.
	 *
	 * @param mixed $value Typed value.
	 */
	public static function parse( $value ): ?int {
		if ( ! is_string( $value ) ) {
			return null;
		}
		$value = trim( $value );
		if ( 1 === preg_match( '/^[1-9][0-9]{0,15}$/', $value ) ) {
			return (int) $value;
		}
		if ( 1 === preg_match( '#^https://(?:www\.)?inaturalist\.org/observations/([1-9][0-9]{0,15})/?(?:[?\#].*)?$#', $value, $m ) ) {
			return (int) $m[1];
		}
		return null;
	}

	/**
	 * Adds the field (filter attachment_fields_to_edit).
	 *
	 * @param array  $fields Fields of the form.
	 * @param object $post   Attachment.
	 * @return array
	 */
	public static function addField( $fields, $post ) {
		if ( ! is_array( $fields ) || ! is_object( $post ) || 0 !== strpos( (string) $post->post_mime_type, 'image/' ) ) {
			return $fields;
		}
		$fields[ self::FIELD ] = array(
			'label' => __( 'iNaturalist observation', 'wp-inaturalist' ),
			'input' => 'text',
			'value' => (string) get_post_meta( $post->ID, Config::MANUAL_LINK_META, true ),
			'helps' => __( 'Number or address of the observation: this photo is then the one shown for it. Applied at the next refresh of its period.', 'wp-inaturalist' ),
		);
		return $fields;
	}

	/**
	 * Saves the field (filter attachment_fields_to_save). An empty value removes the link; an invalid one changes nothing.
	 *
	 * @param array $post       Attachment data being saved.
	 * @param array $attachment Submitted fields.
	 * @return array
	 */
	public static function saveField( $post, $attachment ) {
		if ( ! is_array( $post ) || ! is_array( $attachment ) || ! isset( $attachment[ self::FIELD ] ) || empty( $post['ID'] ) ) {
			return $post;
		}
		$id = (int) $post['ID'];
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return $post;
		}
		$value = is_string( $attachment[ self::FIELD ] ) ? trim( $attachment[ self::FIELD ] ) : '';
		if ( '' === $value ) {
			delete_post_meta( $id, Config::MANUAL_LINK_META );
			return $post;
		}
		$observation = self::parse( $value );
		if ( null !== $observation ) {
			update_post_meta( $id, Config::MANUAL_LINK_META, (string) $observation );
		}
		return $post;
	}
}
