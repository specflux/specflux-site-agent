<?php
/**
 * Can background runs happen on this site right now?
 *
 * Background running rides on Action Scheduler (bundled, but a newer or older
 * copy from another plugin may win the version arbitration). The browser
 * driver works either way; this only answers whether the queue is a usable
 * second driver, and why not when it isn't.
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Background;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Background-run availability and its reason.
 */
final class Availability {

	/** The oldest Action Scheduler that has `$unique` on as_schedule_single_action(). */
	public const MIN_VERSION = '3.6.0';

	public const REASON_DISABLED = 'disabled';
	public const REASON_MISSING  = 'missing';
	public const REASON_TOO_OLD  = 'too_old';

	/**
	 * Why background running is unavailable: 'disabled' | 'missing' | 'too_old',
	 * or '' when it is available.
	 */
	public static function reason(): string {
		/**
		 * Kill switch for background runs (default true). Returning false leaves
		 * only the browser driver.
		 *
		 * @param bool $enabled Whether background runs may be scheduled.
		 */
		if ( true !== apply_filters( 'specflux_site_agent_background_runs', true ) ) {
			return self::REASON_DISABLED;
		}

		if ( ! function_exists( 'as_schedule_single_action' ) || ! class_exists( 'ActionScheduler_Versions', false ) ) {
			return self::REASON_MISSING;
		}

		$version = \ActionScheduler_Versions::instance()->latest_version();
		if ( ! is_string( $version ) || '' === $version ) {
			return self::REASON_MISSING; // Nothing registered a version: the queue never initialised.
		}
		if ( version_compare( $version, self::MIN_VERSION, '<' ) ) {
			return self::REASON_TOO_OLD;
		}

		return '';
	}

	/** Is the queue usable as a driver? */
	public static function available(): bool {
		return '' === self::reason();
	}

	/**
	 * The shape the Runs screen receives.
	 *
	 * @return array{available:bool,reason:string}
	 */
	public static function status(): array {
		$reason = self::reason();

		return array(
			'available' => '' === $reason,
			'reason'    => $reason,
		);
	}
}
