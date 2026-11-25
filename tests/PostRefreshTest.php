<?php
/**
 * Tests of PostRefresh.
 *
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * @package WpInaturalist
 */

namespace Evlist\WpInaturalist\Tests;

use Evlist\WpInaturalist\Config;
use Evlist\WpInaturalist\Job;
use Evlist\WpInaturalist\JobLog;
use Evlist\WpInaturalist\PostRefresh;
use Evlist\WpInaturalist\Scheduler;

require_once __DIR__ . '/TestCase.php';

/**
 * Refresh of a post's period, on publication and on request.
 */
final class PostRefreshTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		update_option( Config::LOGIN, 'me' );
	}

	private function post( string $content = '<!-- wp:wpinat/wp-inaturalist {"periodNumber":3} /-->', string $date = '2026-11-20 14:05:00', string $type = 'post', int $id = 42 ): object {
		return (object) array(
			'ID'           => $id,
			'post_date'    => $date,
			'post_content' => $content,
			'post_type'    => $type,
		);
	}

	public function test_a_post_with_the_block_starts_a_refresh_of_its_period() {
		$this->assertSame( 'started', PostRefresh::refresh( $this->post() ) );
		$state = Scheduler::job()->state();
		$this->assertSame( array( array( '2026-11-18', '2026-11-20' ) ), $state['ranges'] );
		$this->assertSame( 'post', $state['trigger'] );
		$this->assertContains( 'Refresh requested for post 42.', array_column( ( new JobLog() )->entries(), 'message' ) );
	}

	public function test_the_local_date_of_the_post_is_used_not_the_time() {
		PostRefresh::refresh( $this->post( '<!-- wp:wpinat/wp-inaturalist /-->', '2026-11-20 23:59:59' ) );
		$this->assertSame( array( array( '2026-11-20', '2026-11-20' ) ), Scheduler::job()->state()['ranges'] );
	}

	public function test_a_post_without_the_block_does_nothing() {
		$this->assertSame( 'no_block', PostRefresh::refresh( $this->post( '<p>Hello</p>' ) ) );
		$this->assertFalse( get_option( Config::JOB_STATE, false ) );
	}

	public function test_a_post_without_date_uses_today() {
		$this->assertSame( 'started', PostRefresh::refresh( $this->post( '<!-- wp:wpinat/wp-inaturalist /-->', '0000-00-00 00:00:00' ) ) );
		$this->assertSame( gmdate( 'Y-m-d' ), Scheduler::job()->state()['ranges'][0][0] );
	}

	public function test_a_garbage_date_is_a_bad_range() {
		$this->assertSame( 'bad_range', PostRefresh::refresh( $this->post( '<!-- wp:wpinat/wp-inaturalist /-->', 'yesterday' ) ) );
	}

	public function test_without_a_login_nothing_starts() {
		update_option( Config::LOGIN, '' );
		$this->assertSame( 'no_login', PostRefresh::refresh( $this->post() ) );
	}

	public function test_a_running_job_queues_the_request() {
		Scheduler::job()->start( '2026-11-01', '2026-11-05', 'cron' );
		$this->assertSame( 'queued', PostRefresh::refresh( $this->post() ) );
		$this->assertSame( array( array( '2026-11-18', '2026-11-20', 'post' ) ), Scheduler::job()->queue() );
	}

	public function test_publication_triggers_the_refresh() {
		PostRefresh::onTransition( 'publish', 'draft', $this->post() );
		$this->assertSame( 'running', Scheduler::job()->state()['status'] );
	}

	public function test_a_scheduled_post_that_gets_published_triggers_it_too() {
		PostRefresh::onTransition( 'publish', 'future', $this->post() );
		$this->assertSame( 'running', Scheduler::job()->state()['status'] );
	}

	/**
	 * @dataProvider noTrigger
	 * @param string $newStatus New status.
	 * @param string $oldStatus Old status.
	 * @param string $type Post type.
	 */
	public function test_other_transitions_do_nothing( string $newStatus, string $oldStatus, string $type ) {
		PostRefresh::onTransition( $newStatus, $oldStatus, $this->post( '<!-- wp:wpinat/wp-inaturalist /-->', '2026-11-20 10:00:00', $type ) );
		$this->assertFalse( get_option( Config::JOB_STATE, false ) );
	}

	public function noTrigger(): array {
		return array(
			'update of a published post' => array( 'publish', 'publish', 'post' ),
			'draft saved'                => array( 'draft', 'draft', 'post' ),
			'unpublished'                => array( 'draft', 'publish', 'post' ),
			'trashed'                    => array( 'trash', 'publish', 'post' ),
			'scheduled'                  => array( 'future', 'draft', 'post' ),
			'revision'                   => array( 'publish', 'new', 'revision' ),
		);
	}
}
