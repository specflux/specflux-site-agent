<?php
/**
 * Atomic run claim (schema 11): the claim lives on the runs row, so of two
 * claims on one run exactly one wins, a stale token cannot release or extend
 * someone else's claim, and a lapsed claim can be taken over.
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Tests\Run;

use PHPUnit\Framework\TestCase;
use Specflux\SiteAgent\Run\Budget;
use Specflux\SiteAgent\Run\Clock;
use Specflux\SiteAgent\Run\WpdbRunStore;
use wpdb;

final class RunClaimTest extends TestCase {

	private WpdbRunStore $store;

	private int $run_id;

	protected function setUp(): void {
		$this->store  = new WpdbRunStore( new wpdb() );
		$this->run_id = $this->store->createRun( 1, 'test-consumer', 'Goal', array( 'x/*' ), Budget::defaults() );
		Clock::useFixed( 1_800_000_000 );
	}

	protected function tearDown(): void {
		Clock::reset();
	}

	public function test_two_claims_on_the_same_run_exactly_one_wins(): void {
		$first  = $this->store->claimRun( $this->run_id, 'token-a', 300 );
		$second = $this->store->claimRun( $this->run_id, 'token-b', 300 );

		$this->assertTrue( $first );
		$this->assertFalse( $second );
		$this->assertSame( 'token-a', $this->store->getRun( $this->run_id )?->lockToken );
	}

	public function test_claims_on_different_runs_do_not_collide(): void {
		$other = $this->store->createRun( 1, 'test-consumer', 'Other', array( 'x/*' ), Budget::defaults() );

		$this->assertTrue( $this->store->claimRun( $this->run_id, 'token-a', 300 ) );
		$this->assertTrue( $this->store->claimRun( $other, 'token-b', 300 ) );
	}

	public function test_release_with_the_wrong_token_does_not_release(): void {
		$this->store->claimRun( $this->run_id, 'token-a', 300 );

		$this->assertFalse( $this->store->releaseClaim( $this->run_id, 'token-b' ) );
		$this->assertSame( 'token-a', $this->store->getRun( $this->run_id )?->lockToken );
		$this->assertFalse( $this->store->claimRun( $this->run_id, 'token-c', 300 ), 'the claim is still live' );
	}

	public function test_release_with_the_right_token_frees_the_run(): void {
		$this->store->claimRun( $this->run_id, 'token-a', 300 );

		$this->assertTrue( $this->store->releaseClaim( $this->run_id, 'token-a' ) );
		$this->assertNull( $this->store->getRun( $this->run_id )?->lockUntilUtc );
		$this->assertTrue( $this->store->claimRun( $this->run_id, 'token-b', 300 ) );
	}

	public function test_an_expired_claim_can_be_taken_over(): void {
		$this->store->claimRun( $this->run_id, 'token-a', 300 );

		Clock::useFixed( 1_800_000_000 + 299 );
		$this->assertFalse( $this->store->claimRun( $this->run_id, 'token-b', 300 ), 'still inside lock_until' );

		Clock::useFixed( 1_800_000_000 + 301 );
		$this->assertTrue( $this->store->claimRun( $this->run_id, 'token-b', 300 ) );
		$this->assertSame( 'token-b', $this->store->getRun( $this->run_id )?->lockToken );
	}

	public function test_the_displaced_owner_can_neither_extend_nor_release(): void {
		$this->store->claimRun( $this->run_id, 'token-a', 300 );
		Clock::useFixed( 1_800_000_000 + 301 );
		$this->store->claimRun( $this->run_id, 'token-b', 300 );

		$this->assertFalse( $this->store->extendClaim( $this->run_id, 'token-a', 300 ) );
		$this->assertFalse( $this->store->releaseClaim( $this->run_id, 'token-a' ) );
		$this->assertSame( 'token-b', $this->store->getRun( $this->run_id )?->lockToken );
	}

	public function test_extending_pushes_lock_until_out(): void {
		$this->store->claimRun( $this->run_id, 'token-a', 300 );
		$before = $this->store->getRun( $this->run_id )?->lockUntilUtc;

		Clock::useFixed( 1_800_000_000 + 200 );
		$this->assertTrue( $this->store->extendClaim( $this->run_id, 'token-a', 300 ) );

		$this->assertGreaterThan( $before, $this->store->getRun( $this->run_id )?->lockUntilUtc );
	}
}
