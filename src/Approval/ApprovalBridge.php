<?php
/**
 * Bridge to Agent Safety's programmatic approvals API.
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Approval;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * S6: the inline approve is a second caller of the SAME code path Agent
 * Safety's Pending Actions page uses. Feature-detects belayline()->approvals()
 * (Agent Safety >= 0.3 with AS-10); when absent — an older Agent Safety, or a
 * broken autoloader there — approve() reports failure and the consumer falls
 * back to the review URL: the human approves on wp-admin, and the next tick's
 * permission re-check finds the grant by reference.
 */
class ApprovalBridge {

	/**
	 * Try to grant one pending approval as the given user.
	 *
	 * @param string $approval_id Agent Safety approval id.
	 * @param int    $user_id     Acting user.
	 * @return bool True when THIS call granted it; false when the API is
	 *              unavailable, the caller lacks capability, or the row was
	 *              already resolved (the by-reference retry still works then).
	 */
	public function approve( string $approval_id, int $user_id ): bool {
		if ( ! function_exists( 'belayline' ) ) {
			return false;
		}

		$container = belayline();
		if ( null === $container || null === $container->approvals() ) {
			return false;
		}

		return $container->approvals()->approve( $approval_id, $user_id );
	}

	/**
	 * Is the programmatic approvals API present at all?
	 */
	public function isAvailable(): bool {
		return function_exists( 'belayline' )
			&& null !== belayline()
			&& null !== belayline()->approvals();
	}
}
