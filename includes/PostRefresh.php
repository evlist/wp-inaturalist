<?php
/**
 * Refresh of the observations of a post.
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
 * Computes the period of a saved post and asks the job to refresh it. Shared by the publication hook and the REST route.
 */
final class PostRefresh {

	/**
	 * Local date of a post (Y-m-d), today for a post that has none yet.
	 *
	 * @param object $post Post.
	 */
	public static function dateOf( $post ): string {
		$date = substr( (string) $post->post_date, 0, 10 );
		return ( '0000-00-00' === $date || '' === $date ) ? wp_date( 'Y-m-d' ) : $date;
	}

	/**
	 * Asks for the refresh of the period of a post.
	 *
	 * @param object   $post Post (post_date and post_content are read).
	 * @param Job|null $job  Job (tests).
	 * @return string "started", "queued", "no_block", "no_login" or "bad_range".
	 */
	public static function refresh( $post, ?Job $job = null ): string {
		$date = self::dateOf( $post );
		try {
			$range = Period::forContent( (string) $post->post_content, $date );
		} catch ( \InvalidArgumentException $e ) {
			return 'bad_range';
		}
		if ( null === $range ) {
			return 'no_block';
		}
		$job = $job ? $job : Scheduler::job();
		$job->log( 'info', 'Refresh requested for post ' . (int) $post->ID . '.' );
		return $job->request( $range[0], $range[1], 'post' );
	}

	/**
	 * Hook callback of transition_post_status: refreshes a post that has just been published.
	 *
	 * Updating a post that was already published does nothing, and neither do revisions.
	 *
	 * @param string $newStatus New status.
	 * @param string $oldStatus Old status.
	 * @param object $post      Post.
	 */
	public static function onTransition( $newStatus, $oldStatus, $post ): void {
		if ( 'publish' !== $newStatus || 'publish' === $oldStatus || ! is_object( $post ) || 'revision' === $post->post_type ) {
			return;
		}
		self::refresh( $post );
	}
}
