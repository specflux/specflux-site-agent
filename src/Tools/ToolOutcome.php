<?php
/**
 * The outcome of one tool call, before any model turn.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tools;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Five terminal kinds (S5, plus 0.4 `hold`):
 *   - result            — the ability ran; carries its output array.
 *   - approval_required — Agent Safety demands a human; carries the
 *                         approval id (+ verb/tier from the gate's error data).
 *   - denied            — the gate refused; carries code + message. execute()
 *                         was NEVER called.
 *   - unknown_tool      — no such ability; never executed either.
 *   - hold              — 0.4 S5: SenroGate refused the call for a TRANSIENT
 *                         reason (`site_paused` or a `rate_limited_*` window).
 *                         Never executed; the run parks and retries the same
 *                         call later. `errorCode` carries the reason.
 */
final class ToolOutcome {

	/**
	 * @param array<string,mixed>|null $output Ability output (result kind).
	 */
	private function __construct(
		public readonly string $kind,
		public readonly ?array $output = null,
		public readonly ?string $approvalId = null,
		public readonly ?string $verb = null,
		public readonly ?string $tier = null,
		public readonly ?string $errorCode = null,
		public readonly ?string $errorMessage = null,
	) {
	}

	/**
	 * Successful execution.
	 *
	 * @param array<string,mixed> $output Ability output array.
	 */
	public static function result( array $output ): self {
		return new self( 'result', output: $output );
	}

	/**
	 * Agent Safety parked this call for human approval.
	 */
	public static function approvalRequired( string $approval_id, string $verb, ?string $tier ): self {
		return new self( 'approval_required', approvalId: $approval_id, verb: $verb, tier: $tier );
	}

	/**
	 * Gate refusal — never executed.
	 */
	public static function denied( string $code, string $message ): self {
		return new self( 'denied', errorCode: $code, errorMessage: $message );
	}

	/**
	 * A transient SenroGate refusal (pause / rate-limit window) — never
	 * executed; the call is retried when the hold lifts.
	 *
	 * @param string $reason  `site_paused` or `rate_limited_<rule>`.
	 * @param string $message SenroGate's own denial text (kept for audit).
	 */
	public static function hold( string $reason, string $message ): self {
		return new self( 'hold', errorCode: $reason, errorMessage: $message );
	}

	/**
	 * The transient reason inside a SenroGate `agent_safety_denied` error, or
	 * null when it is any other denial. SenroGate's error carries the reason
	 * only inside the message ("Blocked by SenroGate (<pack>): <reason>",
	 * Verdict::error()); the data holds just status/verb/tier.
	 */
	public static function transientReason( string $code, string $message ): ?string {
		if ( 'agent_safety_denied' !== $code ) {
			return null;
		}
		if ( 1 === preg_match( '/\):\s*(site_paused|rate_limited_[a-z_]+)\s*$/', $message, $m ) ) {
			return $m[1];
		}

		return null;
	}

	/**
	 * Unknown ability — never executed.
	 *
	 * @param list<string> $suggestions Exact names of the closest tools the run was given.
	 */
	public static function unknownTool( string $name, array $suggestions = array() ): self {
		$message = sprintf(
			/* translators: %s: the tool name the model called. */
			__( 'Unknown tool "%s". Call one of the tools you were given, by its exact name.', 'senroflux' ),
			$name
		);
		if ( array() !== $suggestions ) {
			$message .= ' ' . sprintf(
				/* translators: %s: comma-separated exact tool names. */
				__( 'Closest matches: %s.', 'senroflux' ),
				implode( ', ', $suggestions )
			);
		}

		return new self( 'unknown_tool', errorMessage: $message );
	}

	/**
	 * The ability itself failed.
	 */
	public static function executionError( string $message ): self {
		return new self( 'error', errorMessage: $message );
	}
}
