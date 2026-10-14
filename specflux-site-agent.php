<?php
/**
 * Plugin Name:       Specflux Site Agent
 * Plugin URI:        https://github.com/specflux/specflux-site-agent
 * Description:       Resumable, browser-driven multi-step agent runs inside the logged-in WordPress session — Abilities as tools, governed by Belayline Agent Governance when active or a built-in approval gate otherwise.
 * Version:           0.4.0
 * Author:            Stephen Paul Samynathan
 * Author URI:        https://www.specflux.com/author/stephen/
 * Requires at least: 7.0
 * Requires PHP:      8.1
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       specflux-site-agent
 *
 * Harness for agent RUNS: one goal pursued by one user through many model
 * turns and tool calls, pausing for human approval whenever a write needs
 * one. Specflux Site Agent owns runs/steps state and nothing else — the chat UI
 * belongs to consumers (first consumer: marketing-analytics-chat).
 *
 * GATE MODES (0.3 S3): if Agent Safety is active, every governed call goes
 * through its gate (packs, tiers, approvals) and audit trail. Without it,
 * Specflux Site Agent falls back to a built-in minimal gate: every call that changes
 * the site still stops for a person to approve it on Specflux Site Agent's own Runs
 * screen before it runs. A run's mode is pinned at start and never changes
 * underneath it. Multisite is refused outright (specflux_site_agent_ungoverned is
 * reserved for "no WordPress session" and third-party HTTP consumers
 * starting/ticking a built-in-mode run).
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent;

use Specflux\SiteAgent\MultisiteGuard;
use Specflux\SiteAgent\Plugin;

// Bail on direct access (must precede any other runtime code so static
// analysers and the plugin-check detector both recognize it).
defined( 'ABSPATH' ) || exit;

if ( ! defined( 'SPECFLUX_SITE_AGENT_URL' ) ) {
	define( 'SPECFLUX_SITE_AGENT_URL', plugin_dir_url( __FILE__ ) );
}

if ( ! defined( 'SPECFLUX_SITE_AGENT_PATH' ) ) {
	// 0.3 S10/S17: the React Runs screen's build assets are read from disk
	// (its `index.asset.php` dependency/version manifest) before being
	// enqueued by URL via `SPECFLUX_SITE_AGENT_URL` above.
	define( 'SPECFLUX_SITE_AGENT_PATH', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'SPECFLUX_SITE_AGENT_API_VERSION' ) ) {
	// S23: the semver of the DECLARED @api surface (Pack, LayoutVocabulary,
	// the specflux_site_agent_packs/specflux_site_agent_run_skills/specflux_site_agent_default_budget/
	// specflux_site_agent_result_presenters filters) — independent of the plugin Version header above. A break
	// (removal or signature change) needs a major bump; an addition needs a
	// minor bump. tests/Api/PublicSurfaceTest.php enforces this against
	// tests/Api/public-surface.json. Owner decision 2026-09-19: "everything
	// in specflux-site-agent uses 0.3" — this starts at 0.3.0, not 0.1.0, even though
	// no @api surface existed before this stage.
	define( 'SPECFLUX_SITE_AGENT_API_VERSION', '0.4.0' );
}


// Registered UNCONDITIONALLY (not inside any class guard): WordPress calls
// activation hooks by re-including this file and checking what got registered
// THAT load, so the callbacks below must exist even when the autoloader is
// broken — they guard internally instead (fail safe, not silent).
register_activation_hook( __FILE__, __NAMESPACE__ . '\specflux_site_agent_activate' );
register_deactivation_hook( __FILE__, __NAMESPACE__ . '\specflux_site_agent_deactivate' );

/**
 * Create the runs/steps tables.
 *
 * @return void
 */
function specflux_site_agent_activate(): void {
	// Multisite is refused (0.3 S2). Checked inline as well as through the
	// guard class so a broken autoloader cannot let activation through.
	if ( function_exists( 'is_multisite' ) && is_multisite() ) {
		if ( class_exists( MultisiteGuard::class ) ) {
			MultisiteGuard::refuse_activation( __FILE__ );
		}
		wp_die( esc_html__( 'Specflux Site Agent does not support WordPress multisite. It was not activated.', 'specflux-site-agent' ) );
	}

	if ( ! class_exists( Schema::class ) ) {
		return;
	}

	global $wpdb;
	if ( isset( $wpdb ) ) {
		Schema::maybe_upgrade( $wpdb );
	}

	// 0.4 K4: the daily prune of the connector activity list.
	if ( class_exists( \Specflux\SiteAgent\Connector\ActivityLog::class ) ) {
		\Specflux\SiteAgent\Connector\ActivityLog::schedule();
	}

	// 0.3 S10: no redirect, no site-wide notice — one one-time notice on the
	// Plugins screen only, rendered by RunsScreen::maybeRenderActivationNotice()
	// and cleared the first time it shows.
	if ( function_exists( 'set_transient' ) ) {
		set_transient( \Specflux\SiteAgent\Admin\RunsScreen::ACTIVATION_NOTICE_TRANSIENT, 1, WEEK_IN_SECONDS );
	}
}

/**
 * Stop the connector's daily activity prune.
 *
 * @return void
 */
function specflux_site_agent_deactivate(): void {
	if ( class_exists( \Specflux\SiteAgent\Connector\ActivityLog::class ) ) {
		\Specflux\SiteAgent\Connector\ActivityLog::unschedule();
	}
	if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
		wp_clear_scheduled_hook( 'specflux_site_agent_oauth_gc' );
	}
}

// Runtime autoloader for THIS PLUGIN'S OWN CLASSES ONLY (S2): deliberately a
// minimal spl_autoloader rather than Composer's vendor/autoload.php, because
// a dev install would otherwise shadow WordPress core's bundled AI Client SDK
// with the composer copy — a cross-version mix that fatals inside core's own
// glue (observed live: PromptBuilder vs core event-dispatcher mismatch).
// Dev tooling (phpcs/phpstan/phpunit) keeps using vendor/autoload.php.
if ( ! class_exists( Plugin::class ) ) {
	spl_autoload_register(
		static function ( string $class_name ): void {
			if ( ! str_starts_with( $class_name, 'Specflux\\SiteAgent\\' ) ) {
				return;
			}

			$relative = substr( $class_name, strlen( 'Specflux\\SiteAgent\\' ) );
			$path     = __DIR__ . '/src/' . str_replace( '\\', '/', $relative ) . '.php';

			if ( is_readable( $path ) ) {
				require_once $path;
			}
		}
	);
}

require_once __DIR__ . '/src/api.php';

/**
 * Register the packs and their Agent Safety governance BEFORE Agent Safety's
 * own wiring: it reads `belayline_governed_namespaces` and
 * `belayline_verb_map` inside its plugins_loaded priority-0 bootstrap, so a
 * pack wired at our boot priority below would never be governed at all.
 */
add_action(
	'plugins_loaded',
	static function (): void {
		if ( class_exists( Plugin::class ) && ! MultisiteGuard::refused() ) {
			Plugin::instance()->govern();
		}
	},
	-1
);

/**
 * Boot the plugin on plugins_loaded, AFTER Agent Safety's own wiring
 * (priority 0) so its classes exist when we look for them.
 */
add_action(
	'plugins_loaded',
	static function (): void {
		// Fail safe: without the autoloader there is no Plugin class and no
		// safe way to run anything — say so in wp-admin instead of fataling.
		if ( ! class_exists( Plugin::class ) ) {
			add_action(
				'admin_notices',
				static function (): void {
					printf(
						'<div class="notice notice-error"><p>%s</p></div>',
						esc_html__( 'Specflux Site Agent is inactive: its autoloader is missing (run `composer install` inside the plugin directory).', 'specflux-site-agent' )
					);
				}
			);

			return;
		}

		if ( MultisiteGuard::refused() ) {
			MultisiteGuard::refuse_runtime( __FILE__ );

			return;
		}

		Plugin::instance()->boot();
	},
	5
);
