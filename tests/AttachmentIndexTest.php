<?php
/**
 * Tests of AttachmentIndex.
 *
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * @package WpInaturalist
 */

namespace Evlist\WpInaturalist\Tests;

use Evlist\WpInaturalist\AttachmentIndex;
use Evlist\WpInaturalist\Config;

require_once __DIR__ . '/TestCase.php';

/**
 * Search of attachments by the time of the shot, with scripted answers of the database.
 *
 * The real query was run on MariaDB with both serialisations of the timestamp (see the slice), not on MySQL 8 nor through WordPress.
 */
final class AttachmentIndexTest extends TestCase {

	/**
	 * A row of _wp_attachment_metadata as the database returns it.
	 *
	 * @param int   $id        Attachment id.
	 * @param mixed $timestamp created_timestamp.
	 */
	private function meta( int $id, $timestamp ): object {
		return (object) array(
			'post_id'    => $id,
			'meta_value' => serialize(
				array(
					'width'      => 4000,
					'image_meta' => array(
						'created_timestamp' => $timestamp,
						'camera'            => 'x',
					),
				)
			),
		);
	}

	private function index(): AttachmentIndex {
		return new AttachmentIndex( $GLOBALS['wpdb'] );
	}

	public function test_candidates_within_the_tolerance_closest_first_then_lowest_id() {
		$t                                   = 1764000000;
		$GLOBALS['wpdb']->scripted_results[] = array(
			$this->meta( 10, (string) ( $t + 2 ) ),
			$this->meta( 30, (string) $t ),
			$this->meta( 20, $t ),
			$this->meta( 5, (string) ( $t - 1 ) ),
			$this->meta( 40, (string) ( $t - 3 ) ),
			$this->meta( 50, (string) ( $t - 2 ) ),
		);
		$found                               = $this->index()->candidates( $t, 2 );
		$this->assertSame( array( 20, 30, 5, 10, 50 ), array_column( $found, 'id' ), 'closest first, then lowest id; ints and strings are both read; 3 s away is out' );
		$this->assertSame( array( 0, 0, 1, 2, 2 ), array_column( $found, 'diff' ) );
	}

	public function test_unusable_metadata_is_skipped() {
		$GLOBALS['wpdb']->scripted_results[] = array(
			(object) array(
				'post_id'    => 1,
				'meta_value' => 'garbage',
			),
			(object) array(
				'post_id'    => 2,
				'meta_value' => serialize( array( 'width' => 1 ) ),
			),
			(object) array(
				'post_id'    => 3,
				'meta_value' => serialize( array( 'image_meta' => array( 'created_timestamp' => 'abc' ) ) ),
			),
			(object) array(
				'post_id'    => 4,
				'meta_value' => serialize( array( 'image_meta' => array( 'created_timestamp' => '0' ) ) ),
			),
		);
		$this->assertSame( array(), $this->index()->candidates( 1764000000, 2 ) );
	}

	public function test_a_null_answer_means_no_candidate() {
		$GLOBALS['wpdb']->scripted_results[] = null;
		$this->assertSame( array(), $this->index()->candidates( 1764000000, 2 ) );
	}

	public function test_a_nonsense_time_asks_nothing() {
		$this->assertSame( array(), $this->index()->candidates( 0, 2 ) );
		$this->assertSame( array(), $GLOBALS['wpdb']->queries );
	}

	public function test_the_query_only_looks_at_image_attachment_metadata_and_narrows_by_prefix() {
		$GLOBALS['wpdb']->scripted_results[] = array();
		$this->index()->candidates( 1764050000, 2 );
		$sql = $GLOBALS['wpdb']->queries[0];
		$this->assertStringContainsString( "pm.meta_key = '_wp_attachment_metadata'", $sql );
		$this->assertStringContainsString( "p.post_type = 'attachment'", $sql );
		$this->assertStringContainsString( "p.post_mime_type LIKE 'image/%'", $sql );
		$this->assertStringContainsString( "pm.meta_value LIKE '%created_timestamp\\\";%17640%'", $sql );
		$this->assertSame( 1, substr_count( $sql, 'meta_value LIKE' ), 'one prefix when the window does not straddle a boundary' );
	}

	public function test_two_prefixes_when_the_window_straddles_a_boundary() {
		$GLOBALS['wpdb']->scripted_results[] = array();
		$this->index()->candidates( 1764100000, 2 ); // 1764099998 .. 1764100002.
		$sql = $GLOBALS['wpdb']->queries[0];
		$this->assertStringContainsString( '%17640%', $sql );
		$this->assertStringContainsString( '%17641%', $sql );
		$this->assertStringContainsString( ' OR ', $sql );
	}

	public function test_the_manual_link_is_looked_up_by_observation_id() {
		$GLOBALS['wpdb']->scripted_vars = array( '77', null );
		$this->assertSame( 77, $this->index()->manual( 416889196 ) );
		$this->assertStringContainsString( "pm.meta_key = '" . Config::MANUAL_LINK_META . "' AND pm.meta_value = '416889196'", $GLOBALS['wpdb']->queries[0] );
		$this->assertNull( $this->index()->manual( 5 ) );
	}
}
