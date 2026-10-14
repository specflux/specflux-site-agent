<?php
/**
 * Belayline Agent Governance's ability-review status, behind one small seam (0.4 S6).
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Passthrough;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Asks Belayline Agent Governance (Agent Safety) whether an ability from another plugin has
 * been reviewed. The contract Belayline Agent Governance will implement:
 *
 *     belayline()->abilityReviews()->status( string $ability_name )
 *         : 'reviewed' | 'unreviewed' | 'blocked'
 *
 * FAIL CLOSED: the only answer that lets an ability through is the exact
 * string 'reviewed'. A missing locator, a missing method, an exception, a
 * non-string or any other value reads as 'unreviewed'. This is the only place
 * that knows the contract's shape.
 */
final class AbilityReviewProbe {

	public const REVIEWED   = 'reviewed';
	public const UNREVIEWED = 'unreviewed';
	public const BLOCKED    = 'blocked';

	/**
	 * Does the installed Belayline Agent Governance expose the review service at all?
	 */
	public static function available(): bool {
		return null !== self::service();
	}

	/**
	 * The review status of one ability; 'unreviewed' whenever it cannot be
	 * established.
	 *
	 * @param string $ability_name Full ability name, e.g. `acme/do-thing`.
	 * @return string One of the REVIEWED / UNREVIEWED / BLOCKED constants.
	 */
	public static function status( string $ability_name ): string {
		$service = self::service();
		if ( null === $service ) {
			return self::UNREVIEWED;
		}

		$callback = array( $service, 'status' );
		if ( ! is_callable( $callback ) ) {
			return self::UNREVIEWED;
		}

		try {
			$status = $callback( $ability_name );
		} catch ( \Throwable $e ) {
			return self::UNREVIEWED;
		}

		return self::REVIEWED === $status || self::BLOCKED === $status ? $status : self::UNREVIEWED;
	}

	/**
	 * Is this ability reviewed (and so allowed through in Belayline Agent Governance mode)?
	 */
	public static function isReviewed( string $ability_name ): bool {
		return self::REVIEWED === self::status( $ability_name );
	}

	/**
	 * The review service object, or null when the contract is not met.
	 */
	private static function service(): ?object {
		if ( ! function_exists( 'belayline' ) ) {
			return null;
		}

		$container = belayline();
		if ( ! is_object( $container ) || ! method_exists( $container, 'abilityReviews' ) ) {
			return null;
		}

		$service = $container->abilityReviews();

		return is_object( $service ) && method_exists( $service, 'status' ) ? $service : null;
	}
}
