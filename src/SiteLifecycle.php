<?php
/**
 * Multisite lifecycle: how each site of a network gets (and loses) its own install.
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Every site is an independent install: its own tables ($wpdb->prefix), options,
 * crons, Action Scheduler queue and approvals. There is no network-wide view.
 *
 *  - Network activation installs the CURRENT site only. Every other site installs
 *    lazily: boot() runs {@see Schema::maybe_upgrade()} on each request (it
 *    creates the tables whenever the site's db-version option is missing), and
 *    the `init` self-heal ({@see self::ensureCronsScheduled()}) schedules every
 *    cron on that site's first admin or cron request. No synchronous loop over
 *    the network, which could time out on a large one.
 *  - New sites (`wp_initialize_site`) are installed at once when the plugin is
 *    network-active.
 *  - Deleted sites (`wp_uninitialize_site`) are purged when the plugin was active there.
 *  - Network deactivation unschedules on every site in batches of 100, capped at
 *    {@see self::SITE_CAP}. Sites beyond the cap keep their cron entries; with the
 *    plugin inactive no callback is registered for them, so they fire into nothing
 *    and WordPress drops them on the next run. They are inert, not harmful.
 */
final class SiteLifecycle {

	/** Sites fetched per batch. */
	public const BATCH = 100;

	/** Hard stop for the deactivation walk. */
	public const SITE_CAP = 10000;

	/** Hook wiring. Multisite only. */
	public static function register(): void {
		if ( ! function_exists( 'is_multisite' ) || ! is_multisite() ) {
			return;
		}
		// Priority 11: core's own initialisation (priority 10) has created the site's tables.
		add_action( 'wp_initialize_site', array( self::class, 'onInitializeSite' ), 11 );
		add_action( 'wp_uninitialize_site', array( self::class, 'onUninitializeSite' ), 11 );
	}

	/** Basename WordPress records this plugin under. */
	public static function basename(): string {
		return plugin_basename( dirname( __DIR__ ) . '/specflux-site-agent.php' );
	}

	/** Is the plugin network-activated? */
	public static function networkActive(): bool {
		$active = get_site_option( 'active_sitewide_plugins', array() );

		return is_array( $active ) && isset( $active[ self::basename() ] );
	}

	/** Is the plugin active on the CURRENT site, by either route? */
	public static function activeOnCurrentSite(): bool {
		if ( self::networkActive() ) {
			return true;
		}
		$active = get_option( 'active_plugins', array() );

		return is_array( $active ) && in_array( self::basename(), $active, true );
	}

	/**
	 * Create this site's tables and schedule its crons. Idempotent.
	 *
	 * @return void
	 */
	public static function installCurrentSite(): void {
		global $wpdb;
		if ( isset( $wpdb ) ) {
			Schema::maybe_upgrade( $wpdb );
		}
		self::ensureCronsScheduled();
	}

	/**
	 * Schedule every WP-Cron event of this site if missing. Used at activation
	 * and as the lazy path for sites a network activation did not touch.
	 *
	 * @return void
	 */
	public static function ensureCronsScheduled(): void {
		if ( class_exists( Connector\ActivityLog::class ) ) {
			Connector\ActivityLog::schedule();
		}
		if ( class_exists( Run\RunLifecycle::class ) ) {
			Run\RunLifecycle::schedule();
		}
		if ( class_exists( Background\BackgroundDriver::class ) ) {
			Background\BackgroundDriver::ensureSweepScheduled();
		}
	}

	/**
	 * Remove this site's cron events and queued actions.
	 *
	 * @return void
	 */
	public static function unscheduleCurrentSite(): void {
		if ( class_exists( Connector\ActivityLog::class ) ) {
			Connector\ActivityLog::unschedule();
		}
		if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( Connector\OAuth\OAuth::GC_HOOK );
		}
		if ( class_exists( Run\RunLifecycle::class ) ) {
			Run\RunLifecycle::unschedule();
		}
		if ( class_exists( Background\BackgroundDriver::class ) ) {
			Background\BackgroundDriver::unscheduleAll();
		}
	}

	/**
	 * Network deactivation: unschedule on every site, batched.
	 *
	 * @return void
	 */
	public static function unscheduleNetwork(): void {
		self::eachSite( array( self::class, 'unscheduleCurrentSite' ) );
	}

	/**
	 * `wp_initialize_site`: a new site of a network-active plugin installs at once.
	 *
	 * @param mixed $site The new WP_Site.
	 * @return void
	 */
	public static function onInitializeSite( $site ): void {
		$blog_id = is_object( $site ) && isset( $site->blog_id ) ? (int) $site->blog_id : 0;
		if ( $blog_id <= 0 || ! self::networkActive() ) {
			return;
		}
		switch_to_blog( $blog_id );
		try {
			self::installCurrentSite();
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * `wp_uninitialize_site`: the site is being deleted. If the plugin was active
	 * there, drop its tables and options and unschedule, reusing the uninstall manifest.
	 *
	 * @param mixed $site The WP_Site being deleted.
	 * @return void
	 */
	public static function onUninitializeSite( $site ): void {
		$blog_id = is_object( $site ) && isset( $site->blog_id ) ? (int) $site->blog_id : 0;
		if ( $blog_id <= 0 ) {
			return;
		}
		switch_to_blog( $blog_id );
		try {
			if ( self::activeOnCurrentSite() ) {
				global $wpdb;
				Uninstaller::unschedule();
				if ( isset( $wpdb ) ) {
					Uninstaller::purge( $wpdb );
				}
			}
		} finally {
			restore_current_blog();
		}
	}

	/**
	 * Run a callback on every site, 100 at a time, up to {@see self::SITE_CAP}.
	 *
	 * @param callable():void $callback Per-site work, run inside switch_to_blog().
	 * @return void
	 */
	private static function eachSite( callable $callback ): void {
		for ( $offset = 0; $offset < self::SITE_CAP; $offset += self::BATCH ) {
			$ids = get_sites(
				array(
					'number' => self::BATCH,
					'offset' => $offset,
					'fields' => 'ids',
				)
			);
			foreach ( $ids as $blog_id ) {
				switch_to_blog( (int) $blog_id );
				try {
					$callback();
				} finally {
					restore_current_blog();
				}
			}
			if ( count( $ids ) < self::BATCH ) {
				return;
			}
		}
	}
}
