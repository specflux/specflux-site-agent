<?php
/**
 * Bridge to SenroGate's change-history and undo API (0.4 S3.2).
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Approval;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

use Specflux\SenroFlux\Present\Links;
use Specflux\SenroFlux\Present\WpLinks;
use Specflux\SenroFlux\Run\GateMode;
use Specflux\SenroFlux\Run\Run;
use WP_Error;

/**
 * The one place SenroFlux talks to `agent_safety()->changes()`.
 *
 * Feature-detected end to end: an absent or older SenroGate (no `changes()`,
 * no `UndoScope`) means no undo, and the report falls back to WordPress's own
 * revisions links plus one "Undo needs SenroGate" line.
 *
 * Nothing here is reachable by the model: it feeds the Runs screen's read
 * path and one human-click REST route, and is never named in a prompt or
 * registered as a tool. The undo itself re-checks the actor inside SenroGate
 * (current logged-in user, capability, not an agent credential).
 *
 * The SenroGate symbols are referenced by name, as in {@see GrantBridge}: that
 * plugin is not a composer dependency of this one.
 */
class ChangesBridge {

	private const UNDO_SCOPE = 'Specflux\\AgentSafety\\Changes\\UndoScope';

	/** Longest before/after value shown, in characters. */
	private const VALUE_LIMIT = 300;

	/**
	 * Constructor.
	 *
	 * @param GrantBridge|null $grants Source of the run's correlation id.
	 * @param Links|null       $links  Local-read seam for object titles.
	 */
	public function __construct(
		private readonly ?GrantBridge $grants = null,
		private readonly ?Links $links = null
	) {}

	/**
	 * The capability a human needs to see and undo this run's changes.
	 *
	 * @param Run $run The run.
	 */
	public function capabilityFor( Run $run ): string {
		return 'commerce' === $run->pack
			? \Specflux\SenroFlux\Packs\Commerce\Platforms\StorePlatforms::resolve( $run->storePlatform )->restoreCapability()
			: 'manage_options';
	}

	/**
	 * Is the undo API reachable (SenroGate 0.5 or later)?
	 */
	public function isAvailable(): bool {
		return null !== $this->api();
	}

	/**
	 * What the report's Changes area needs, or null for "nothing to show".
	 *
	 * Terminal runs only. With the API: the recorded changes (shown only to a
	 * holder of the run's capability, since they can include customer fields).
	 * Without it: Revisions links for changed posts and pages, and the one
	 * "Undo needs SenroGate" note.
	 *
	 * @param Run $run The run.
	 * @return array<string,mixed>|null
	 */
	public function section( Run $run ): ?array {
		if ( ! $run->status->isTerminal() ) {
			return null;
		}

		$api = GateMode::AgentSafety === $run->gateMode ? $this->api() : null;
		if ( null === $api ) {
			return $this->fallback( $run );
		}

		if ( ! current_user_can( $this->capabilityFor( $run ) ) ) {
			return null;
		}

		$recorded = $api->forCorrelation( $this->correlation( $run ) );
		$rows     = array();
		foreach ( $recorded->all() as $change ) {
			$reason = $change->reason();
			$rows[] = array(
				'id'           => $change->id(),
				'object_type'  => $change->objectType(),
				'object_id'    => $change->objectId(),
				'action'       => $change->action(),
				'label'        => $this->labelFor( $change->objectType(), $change->objectId() ),
				'diff'         => $this->diffRows( $change->fieldDiff() ),
				'restorable'   => $change->restorable(),
				'reason'       => $reason,
				'reason_label' => null === $reason ? '' : self::reasonLabel( $reason ),
				'restored'     => null !== $change->restoredByEvent(),
			);
		}

		return array(
			'mode'    => 'undo',
			'nonce'   => wp_create_nonce( self::nonceAction( $run->id ) ),
			'changes' => $rows,
		);
	}

	/**
	 * What an undo would do now, writing nothing.
	 *
	 * @param Run      $run       The run.
	 * @param int|null $change_id One change of the run, or null for the whole run.
	 * @return array<string,mixed>|WP_Error
	 */
	public function preview( Run $run, ?int $change_id ): array|WP_Error {
		$ready = $this->ready( $run );
		if ( $ready instanceof WP_Error ) {
			return $ready;
		}
		$scope = $this->scope( $ready, $run, $change_id );
		if ( $scope instanceof WP_Error ) {
			return $scope;
		}

		return array( 'items' => $this->itemRows( (array) $ready->preview( $scope ) ) );
	}

	/**
	 * Undo one change or the whole run, as the current human.
	 *
	 * Capability and the "is this a human" question are enforced again inside
	 * SenroGate; the checks here are the harness's own, for a clear error and
	 * so a change from another run can never be named.
	 *
	 * @param Run      $run       The run.
	 * @param int|null $change_id One change of the run, or null for the whole run.
	 * @param int      $user_id   The acting user (always the current user).
	 * @return array<string,mixed>|WP_Error
	 */
	public function undo( Run $run, ?int $change_id, int $user_id ): array|WP_Error {
		$ready = $this->ready( $run );
		if ( $ready instanceof WP_Error ) {
			return $ready;
		}
		$scope = $this->scope( $ready, $run, $change_id );
		if ( $scope instanceof WP_Error ) {
			return $scope;
		}

		$result = $ready->undo( $scope, $user_id );

		return array(
			'status'  => (string) $result->status,
			'reason'  => null === $result->reason ? null : (string) $result->reason,
			'message' => null === $result->reason ? '' : self::reasonLabel( (string) $result->reason ),
			'items'   => $this->itemRows( (array) $result->items ),
			'section' => $this->section( $run ),
		);
	}

	/**
	 * The nonce action for one run's undo.
	 *
	 * @param int $run_id The run.
	 */
	public static function nonceAction( int $run_id ): string {
		return 'senroflux_undo_' . $run_id;
	}

	/**
	 * A short human sentence for a SenroGate reason code.
	 *
	 * @param string $reason Machine code (a change refusal, an item reason or a forbidden reason).
	 */
	public static function reasonLabel( string $reason ): string {
		switch ( $reason ) {
			case 'irreversible':
				return __( "This kind of change can't be undone.", 'senroflux' );
			case 'truncated':
				return __( 'Too large to restore. Only part of it was recorded.', 'senroflux' );
			case 'already_restored':
				return __( 'Already restored.', 'senroflux' );
			case 'drift':
				return __( 'Changed since the run. Restoring would overwrite newer work.', 'senroflux' );
			case 'object_missing':
				return __( 'The item no longer exists.', 'senroflux' );
			case 'restore_failed':
				return __( 'WordPress refused to restore it.', 'senroflux' );
			case 'no_adapter':
				return __( "SenroGate can't restore this kind of item.", 'senroflux' );
			case 'capability':
				return __( 'You do not have permission to undo this.', 'senroflux' );
			case 'user_mismatch':
				return __( 'The undo must come from the signed-in user.', 'senroflux' );
			case 'agent_context':
				return __( 'An undo must come from a person, not an agent.', 'senroflux' );
			case 'inside_governed_call':
				return __( 'An undo cannot run inside an agent call.', 'senroflux' );
			default:
				return __( "This change can't be undone.", 'senroflux' );
		}
	}

	/**
	 * The API object when SenroGate 0.5 or later is reachable.
	 */
	private function api(): ?object {
		if ( ! function_exists( 'agent_safety' ) || ! class_exists( self::UNDO_SCOPE ) ) {
			return null;
		}

		$container = agent_safety();
		if ( ! is_object( $container ) || ! method_exists( $container, 'changes' ) ) {
			return null;
		}

		$api = $container->changes();
		if ( ! is_object( $api ) ) {
			return null;
		}
		foreach ( array( 'forCorrelation', 'preview', 'undo' ) as $method ) {
			if ( ! method_exists( $api, $method ) ) {
				return null;
			}
		}

		return $api;
	}

	/**
	 * Shared preconditions: terminal run, gate mode, API present, capability.
	 *
	 * @param Run $run The run.
	 * @return object|WP_Error
	 */
	private function ready( Run $run ): mixed {
		$api = $this->api();
		if ( null === $api || GateMode::AgentSafety !== $run->gateMode ) {
			return new WP_Error( 'senroflux_undo_unavailable', __( 'Undo needs SenroGate.', 'senroflux' ), array( 'status' => 409 ) );
		}
		if ( ! $run->status->isTerminal() ) {
			return new WP_Error( 'senroflux_undo_run_active', __( 'Wait for the run to finish before undoing it.', 'senroflux' ), array( 'status' => 409 ) );
		}
		if ( ! current_user_can( $this->capabilityFor( $run ) ) ) {
			return new WP_Error( 'senroflux_forbidden', __( 'Insufficient permissions.', 'senroflux' ), array( 'status' => 403 ) );
		}

		return $api;
	}

	/**
	 * The undo scope: the whole run, or one of its own changes.
	 *
	 * @param object   $api       The changes API.
	 * @param Run      $run       The run.
	 * @param int|null $change_id Change id, or null for the whole run.
	 */
	private function scope( object $api, Run $run, ?int $change_id ): mixed {
		$recorded = $api->forCorrelation( $this->correlation( $run ) );
		if ( null === $change_id ) {
			return $recorded->scope();
		}
		foreach ( $recorded->all() as $change ) {
			if ( $change->id() === $change_id ) {
				return ( self::UNDO_SCOPE )::change( $change_id );
			}
		}

		return new WP_Error( 'senroflux_change_not_found', __( 'That change does not belong to this run.', 'senroflux' ), array( 'status' => 404 ) );
	}

	/**
	 * The run's SenroGate correlation id.
	 *
	 * @param Run $run The run.
	 */
	private function correlation( Run $run ): string {
		return ( $this->grants ?? new GrantBridge() )->correlationFor( $run->id );
	}

	/**
	 * Built-in mode or SenroGate below 0.5: Revisions links and one line.
	 *
	 * @param Run $run The run.
	 * @return array<string,mixed>
	 */
	private function fallback( Run $run ): array {
		$links = array();
		$rows  = is_array( $run->result ) && is_array( $run->result['changes'] ?? null ) ? $run->result['changes'] : array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! in_array( $row['object_type'] ?? '', array( 'post', 'page' ), true ) ) {
				continue;
			}
			$id = (string) ( $row['object_id'] ?? '' );
			if ( ! ctype_digit( $id ) || (int) $id < 1 || ! current_user_can( 'edit_post', (int) $id ) ) {
				continue;
			}
			$url = $this->revisionUrl( (int) $id );
			if ( null !== $url ) {
				$links[ $id ] = $url;
			}
		}

		return array(
			'mode'      => 'revisions',
			'revisions' => (object) $links,
			'note'      => __( 'Undo needs SenroGate', 'senroflux' ),
		);
	}

	/**
	 * The revisions screen for a post's newest revision, or null when none.
	 *
	 * @param int $post_id Post id.
	 */
	private function revisionUrl( int $post_id ): ?string {
		if ( ! function_exists( 'wp_get_post_revisions' ) ) {
			return null;
		}
		$revisions = wp_get_post_revisions(
			$post_id,
			array(
				'order'          => 'DESC',
				'orderby'        => 'date ID',
				'posts_per_page' => 1,
			)
		);
		if ( ! is_array( $revisions ) || array() === $revisions ) {
			return null;
		}
		$latest = reset( $revisions );
		$id     = is_object( $latest ) ? (int) $latest->ID : 0;

		return $id > 0 ? admin_url( 'revision.php?revision=' . $id ) : null;
	}

	/**
	 * The object's own title, for a post-like object; '' otherwise.
	 *
	 * @param string $type Object type.
	 * @param string $id   Object id.
	 */
	private function labelFor( string $type, string $id ): string {
		if ( 'post' !== $type || ! ctype_digit( $id ) ) {
			return '';
		}

		return ( $this->links ?? new WpLinks() )->title( (int) $id );
	}

	/**
	 * Field diff rows with display strings (never raw structures).
	 *
	 * @param array<int,mixed> $diff SenroGate's `fieldDiff()`.
	 * @return list<array{field:string,before:string,after:string}>
	 */
	private function diffRows( array $diff ): array {
		$rows = array();
		foreach ( $diff as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$rows[] = array(
				'field'  => (string) ( $entry['field'] ?? '' ),
				'before' => self::display( $entry['before'] ?? null ),
				'after'  => self::display( $entry['after'] ?? null ),
			);
		}

		return $rows;
	}

	/**
	 * Undo items as plain rows.
	 *
	 * @param array<int,mixed> $items `UndoItem` objects (SenroGate's, not installed here).
	 * @return list<array<string,mixed>>
	 */
	private function itemRows( array $items ): array {
		$rows = array();
		foreach ( $items as $item ) {
			if ( ! is_object( $item ) ) {
				continue;
			}
			/** @var object{changeId:int,objectType:string,objectId:string,status:string,reason:?string,drift:array<int,mixed>} $item */
			$drift = array();
			foreach ( $item->drift as $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}
				$drift[] = array(
					'field'   => (string) ( $entry['field'] ?? '' ),
					'before'  => self::display( $entry['before'] ?? null ),
					'after'   => self::display( $entry['after'] ?? null ),
					'current' => self::display( $entry['current'] ?? null ),
				);
			}
			$reason = $item->reason;
			$rows[] = array(
				'change_id'    => (int) $item->changeId,
				'object_type'  => (string) $item->objectType,
				'object_id'    => (string) $item->objectId,
				'label'        => $this->labelFor( (string) $item->objectType, (string) $item->objectId ),
				'status'       => (string) $item->status,
				'reason'       => $reason,
				'reason_label' => null === $reason ? '' : self::reasonLabel( $reason ),
				'drift'        => $drift,
			);
		}

		return $rows;
	}

	/**
	 * One recorded value as a bounded display string.
	 *
	 * @param mixed $value Any recorded value.
	 */
	private static function display( mixed $value ): string {
		if ( null === $value ) {
			return '';
		}
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}
		$text = is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value );
		if ( function_exists( 'mb_strlen' ) && mb_strlen( $text ) > self::VALUE_LIMIT ) {
			return mb_substr( $text, 0, self::VALUE_LIMIT ) . '…';
		}

		return $text;
	}
}
