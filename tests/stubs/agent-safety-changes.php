<?php
/**
 * Test-only stand-ins for Belayline Agent Governance 0.5's change-history API (read from
 * `plugin/src/Api/Changes.php` and core `src/Changes/Undo*.php`). Only the
 * shapes Specflux Site Agent consumes; no Belayline Agent Governance logic is reproduced.
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

if ( ! class_exists( 'SpecfluxSiteAgent_Test_Change', false ) ) {
	/** One RecordedChange. */
	final class SpecfluxSiteAgent_Test_Change {
		public function __construct(
			private int $id,
			private string $type = 'post',
			private string $object_id = '12',
			private array $diff = array(),
			private ?string $reason = null,
			private ?string $restored_by = null,
		) {}

		public function id(): int {
			return $this->id; }
		public function objectType(): string {
			return $this->type; }
		public function objectId(): string {
			return $this->object_id; }
		public function action(): string {
			return 'update'; }
		public function fieldDiff(): array {
			return $this->diff; }
		public function restorable(): bool {
			return null === $this->reason; }
		public function reason(): ?string {
			return $this->reason; }
		public function restoredByEvent(): ?string {
			return $this->restored_by; }
	}

	/** RecordedChanges. */
	final class SpecfluxSiteAgent_Test_Recorded {
		public function __construct( private array $changes, private object $scope ) {}

		public function all(): array {
			return $this->changes; }
		public function scope(): object {
			return $this->scope; }
	}

	/** `belayline()->changes()`: records every call. */
	final class SpecfluxSiteAgent_Test_Changes {
		/** @var array<string,list<SpecfluxSiteAgent_Test_Change>> */
		public array $by_correlation = array();
		public array $undo_calls     = array();
		public array $preview_calls  = array();
		public ?object $undo_result  = null;
		public array $preview_items  = array();

		public function forCorrelation( string $id ): SpecfluxSiteAgent_Test_Recorded {
			return new SpecfluxSiteAgent_Test_Recorded( $this->by_correlation[ $id ] ?? array(), \Belayline\Changes\UndoScope::correlation( $id ) );
		}

		public function preview( object $scope ): array {
			$this->preview_calls[] = $scope;
			return $this->preview_items;
		}

		public function undo( object $scope, int $user_id ): object {
			$this->undo_calls[] = array( $scope, $user_id );
			return $this->undo_result ?? new \Belayline\Changes\UndoResult( 'complete' );
		}
	}

	/** A container exposing changes(). */
	final class SpecfluxSiteAgent_Test_Container {
		public function __construct( public ?object $changes ) {}

		public function changes(): ?object {
			return $this->changes; }
	}
}
