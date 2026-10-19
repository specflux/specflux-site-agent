<?php
/**
 * The Runs screen's "set up a real cron" hint.
 *
 * Background runs ride on WP-Cron, which only fires when someone visits the
 * site. The hint is useful only when WP-Cron is in its default mode AND the
 * site has actually handed a run to the queue, so it needs no scan of the runs
 * table: the first successful queueing sets a per-site flag. It is dismissible
 * per user, per site.
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Background;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Condition and state for the real-cron hint.
 */
final class CronHint {

	/** Site option: set the first time a run was queued for the background. */
	public const USED_OPTION = 'specflux_site_agent_background_used';

	/** Per-site user option (stored with the blog prefix): the person dismissed the hint. */
	public const DISMISSED_META = 'specflux_site_agent_cron_hint_dismissed';

	/** Where the hint points. */
	public const HELP_URL = 'https://developer.wordpress.org/plugins/cron/hooking-wp-cron-into-the-system-task-scheduler/';

	/**
	 * Record that a run was handed to the background. Written once, not autoloaded.
	 */
	public static function markBackgroundUsed(): void {
		if ( ! get_option( self::USED_OPTION ) ) {
			update_option( self::USED_OPTION, 1, false );
		}
	}

	/**
	 * Whether to show the hint to this person now.
	 *
	 * @param int       $user_id       Viewer.
	 * @param bool|null $cron_disabled Test seam; null reads DISABLE_WP_CRON.
	 */
	public static function shouldShow( int $user_id, ?bool $cron_disabled = null ): bool {
		$cron_disabled ??= defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		if ( $cron_disabled || ! get_option( self::USED_OPTION ) ) {
			return false;
		}

		return ! self::dismissed( $user_id );
	}

	/**
	 * Hide the hint for one person on this site.
	 *
	 * @param int $user_id Viewer.
	 */
	public static function dismiss( int $user_id ): void {
		update_user_option( $user_id, self::DISMISSED_META, 1 );
	}

	private static function dismissed( int $user_id ): bool {
		global $wpdb;

		// Read the prefixed key directly: get_user_option() would also consult the bare network-wide key.
		$prefix = is_object( $wpdb ) && method_exists( $wpdb, 'get_blog_prefix' ) ? (string) $wpdb->get_blog_prefix() : '';

		return (bool) get_user_meta( $user_id, $prefix . self::DISMISSED_META, true );
	}
}
