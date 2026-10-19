<?php
/**
 * Site Agent → Runs: the observation + standalone-start screen (S10/S13).
 *
 * TARGET REPO PATH: src/Admin/RunsScreen.php
 *
 * 0.2 stage 9. The screen is now the FULL human seam for a run:
 *   - a "New run" form (S13) that starts through the ONE start path
 *     `specflux_site_agent()->start( 'specflux-site-agent-admin', … )`, gated by the chosen pack's
 *     S13 preflight (a preflight failure replaces the form with a notice
 *     linking to Agent Capability Packs; Specflux Site Agent never auto-binds);
 *   - the run detail, which renders the three park cards INLINE (question /
 *     plan / approval) server-side and submits their resolutions back through
 *     the ONE tick path `specflux_site_agent()->tick()` (S5 resume objects);
 *   - the existing list, cancel, and complete-without-JS behaviour.
 *
 * The mutations below are plain admin-post handlers. Since 0.3 the screen itself
 * is the React app in `assets/src/runs/`; the server renders the shared app
 * header (see {@see Shell}), the setup notices and the mount point.
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Admin;

use Specflux\SiteAgent\Plugin;
use Specflux\SiteAgent\Admin\Ui\Kit;
use Specflux\SiteAgent\Admin\Ui\Shell;
use Specflux\SiteAgent\Approval\GrantBridge;
use Specflux\SiteAgent\Http\ConsumerPolicy;
use Specflux\SiteAgent\Model\ModelChoice;
use Specflux\SiteAgent\Packs\Pack;
use Specflux\SiteAgent\Packs\PackRegistry;
use Specflux\SiteAgent\Run\Report;
use Specflux\SiteAgent\Run\RunStatus;
use Specflux\SiteAgent\Run\SiteBrief;
use Specflux\SiteAgent\Run\StepKind;
use Specflux\SiteAgent\Setup\Checks;
use Specflux\SiteAgent\Setup\Onboarding;
use Specflux\SiteAgent\Setup\SetupCheck;
use Specflux\SiteAgent\Tools\VerbTier;
use WP_Error;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Render + act on Specflux Site Agent runs.
 *
 * The one consumer id the screen starts runs for is `specflux-site-agent-admin` (S13),
 * and it goes through the SAME server-side policy seam as every other
 * consumer. `specflux-site-agent-admin` is registered in `specflux_site_agent_http_consumers`
 * ONLY while the current user holds the screen capability (see
 * {@see self::registerAdminConsumer()}), and the New-run handler resolves the
 * request through {@see ConsumerPolicy} before it ever reaches `start()`. So:
 *
 *   - one start path, one allow-list authority, one budget ceiling;
 *   - a user without the capability resolves to `specflux_site_agent_unknown_consumer`
 *     (403) at the policy seam — fail closed — as well as being stopped by the
 *     handler's own `current_user_can()` check;
 *   - a JS consumer POSTing `wp_ajax_specflux_site_agent_start` with `specflux-site-agent-admin`
 *     gets exactly the same treatment, because it is the same filter entry.
 *
 * The chosen pack still narrows the allow-list inside `start()` (S9: with a
 * pack, the pack is the single source of the allow-list), so the policy
 * allow-list is the OUTER bound, not the effective one.
 */
class RunsScreen {

	public const SLUG = Shell::RUNS;

	/**
	 * The consumer id label for admin-started runs (S13). Public (0.3 S3): the
	 * only consumer allowed to drive a built-in-mode run — its approval park
	 * can only be resolved on this screen, so a third-party consumer starting
	 * or ticking one is refused (`specflux_site_agent_ungoverned`, Plugin::start/tick).
	 */
	public const CONSUMER = 'specflux-site-agent-admin';

	/** Goal length cap (S13: required, ≤ 1000 chars). */
	private const MAX_GOAL = 1000;

	/** Transient set on activation, cleared the first time the Plugins-screen notice renders (S10). */
	public const ACTIVATION_NOTICE_TRANSIENT = 'specflux_site_agent_activation_notice';

	/** Register the assets, the admin-post/ajax handlers and the admin consumer. The menu and the legacy-URL redirects live in {@see Shell}. */
	public function register(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'commandPaletteAssets' ) );
		add_action( 'admin_post_specflux_site_agent_cancel_run', array( $this, 'handleCancel' ) );
		add_action( 'admin_post_specflux_site_agent_new_run', array( $this, 'handleNewRun' ) );
		add_action( 'admin_post_specflux_site_agent_answer', array( $this, 'handleAnswer' ) );
		add_action( 'admin_post_specflux_site_agent_plan_decision', array( $this, 'handlePlanDecision' ) );
		add_action( 'admin_post_specflux_site_agent_approval_decision', array( $this, 'handleApprovalDecision' ) );
		add_action( 'admin_post_specflux_site_agent_suggestion_decision', array( $this, 'handleSuggestionDecision' ) );
		add_action( 'wp_ajax_specflux_site_agent_setup_panel', array( $this, 'handleSetupPanel' ) );
		add_action( 'wp_ajax_specflux_site_agent_setup_brief', array( $this, 'handleSetupBrief' ) );
		add_action( 'wp_ajax_specflux_site_agent_dismiss_cron_hint', array( $this, 'handleDismissCronHint' ) );
		add_action( 'admin_notices', array( $this, 'maybeRenderActivationNotice' ) );
		add_filter( ConsumerPolicy::FILTER, array( $this, 'registerAdminConsumer' ) );
	}

	/**
	 * The capability that unlocks the screen; filterable per S10/S13.
	 *
	 * The same capability gates see/start/answer/act/cancel. Writes STILL
	 * require `edit_pages` at the ability (S13) — this only controls who may
	 * drive a run from this screen.
	 */
	public function capability(): string {
		return ScreenCapability::current();
	}

	/**
	 * Register `specflux-site-agent-admin` as an HTTP consumer — ONLY for a user who
	 * holds the screen capability (S13).
	 *
	 * This is what makes the Runs screen's start "one start path": the New-run
	 * form and any `wp_ajax_specflux_site_agent_start` call resolve through the SAME
	 * {@see ConsumerPolicy} entry, so the allow-list and the budget ceiling are
	 * server-owned in both. A visitor without the capability never sees the
	 * consumer registered at all, so `ConsumerPolicy::resolve()` refuses with
	 * `specflux_site_agent_unknown_consumer` (403) — fail closed.
	 *
	 * The allow-list here is the union of the registered packs' allow-lists:
	 * the OUTER bound of what an admin-started run may ever touch. `start()`
	 * then narrows it to the chosen pack (S9), which is always given from this
	 * screen. No `budget` is registered: {@see ConsumerPolicy::resolve()}
	 * then builds the ceiling from `Budget::defaults()` over the chosen
	 * pack's own `defaultBudget()`, so a pack asking for more (the site pack)
	 * gets it, and the request may still only lower it (S13). Registering
	 * `Budget::defaults()` here capped every pack back to the generic table.
	 *
	 * @param mixed $consumers The consumer map so far.
	 * @return array<string,array{allow?:list<string>,budget?:array<string,int>}>
	 */
	public function registerAdminConsumer( mixed $consumers ): array {
		$consumers = is_array( $consumers ) ? $consumers : array();

		if ( ! ScreenCapability::held() ) {
			return $consumers;
		}

		$allow = array();
		foreach ( PackRegistry::fromFilters()->all() as $pack ) {
			foreach ( $pack->allowList() as $ability ) {
				if ( is_string( $ability ) && '' !== $ability ) {
					$allow[ $ability ] = true;
				}
			}
		}

		if ( array() === $allow ) {
			// No pack, nothing to allow: leave the consumer UNregistered rather
			// than registering an empty (and therefore refused) entry.
			return $consumers;
		}

		$consumers[ self::CONSUMER ] = array(
			'allow' => array_keys( $allow ),
		);

		return $consumers;
	}

	/**
	 * This screen's URL, with any extra query arguments (`run_id`, `goal`, error flags).
	 *
	 * @param array<string,string|int|bool> $args Extra query arguments.
	 */
	public static function url( array $args = array() ): string {
		return Shell::url( 'runs', $args );
	}

	/**
	 * The one place every redirect-then-stop goes through — a test subclass
	 * overrides THIS, not `wp_safe_redirect()` + `exit` directly, so a test
	 * can observe the destination without killing the PHPUnit process.
	 */
	protected function redirectAndExit( string $url ): void {
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Enqueue the React screen's build output only on this page (0.3 S10/S17).
	 *
	 * The kit's stylesheet is a dependency of the React stylesheet, so the kit
	 * classes the React tree renders are always defined. The build's
	 * own `index.asset.php` (generated by `@wordpress/scripts`) supplies the
	 * dependency list and a content-hash version, so this never hand-lists
	 * `@wordpress/*` handles that could drift from what actually got bundled.
	 */
	public function assets( string $hook ): void {
		if ( ! str_contains( $hook, self::SLUG ) ) {
			return;
		}

		// The React stylesheet depends on the kit's (Shell enqueues it on the same hook; enqueueing is idempotent).
		Kit::enqueue();

		$asset_file = SPECFLUX_SITE_AGENT_PATH . 'build/runs/index.asset.php';
		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		/** @var array{dependencies:list<string>,version:string} $asset */
		$asset = require $asset_file;

		// `wp_enqueue_script()` wants `array<non-empty-string>`; the build's
		// own manifest already only ever lists real handles, but this is
		// re-checked (fail closed) rather than trusted blindly from disk.
		$dependencies = array_values( array_filter( $asset['dependencies'], static fn ( string $handle ): bool => '' !== $handle ) );

		wp_enqueue_style( 'specflux-site-agent-runs', SPECFLUX_SITE_AGENT_URL . 'build/runs/style-index.css', array( Kit::HANDLE_STYLE ), $asset['version'] );
		// S22 RTL gate: `wp-scripts build` emits a `style-index-rtl.css`
		// alongside the LTR stylesheet, but nothing loads it unless the
		// handle is told an RTL replacement exists — `wp_style_add_data()`
		// with the `rtl` key is how core wires that swap in for any locale
		// whose `WP_Locale::is_rtl()` is true (arabic, hebrew, etc.).
		wp_style_add_data( 'specflux-site-agent-runs', 'rtl', 'replace' );
		wp_enqueue_script( 'specflux-site-agent-runs', SPECFLUX_SITE_AGENT_URL . 'build/runs/index.js', $dependencies, $asset['version'], true );

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( 'specflux-site-agent-runs', 'specflux-site-agent' );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, decides which run opens first.
		$run_id = absint( $_GET['run_id'] ?? ( $_GET['run'] ?? 0 ) );

		// 0.3 S10: the command palette's "Specflux Site Agent: run "<text>"" command
		// (see `commandPaletteAssets()`) links here with `goal` set — it only
		// ever PRE-FILLS the message box, never starts a run itself, so this
		// is read-only same as `run_id` above. Capped at the same length the
		// no-JS New-run form enforces (`MAX_GOAL`) so a very long deep link
		// can't paste something the form would itself have refused.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only prefill, no state change.
		$goal = sanitize_textarea_field( wp_unslash( $_GET['goal'] ?? '' ) );
		if ( $this->strLen( $goal ) > self::MAX_GOAL ) {
			$goal = function_exists( 'mb_substr' ) ? mb_substr( $goal, 0, self::MAX_GOAL ) : substr( $goal, 0, self::MAX_GOAL );
		}

		wp_localize_script(
			'specflux-site-agent-runs',
			'specfluxSiteAgentRunsConfig',
			array(
				'initialRunId'       => $run_id > 0 ? $run_id : null,
				'initialGoal'        => '' !== $goal ? $goal : null,
				// 0.4 S3: keys the preview pane's saved collapsed state.
				'userId'             => get_current_user_id(),
				// 0.3 S3: the SITE-WIDE mode a NEW run would start under —
				// the empty state's promise sentence and the tier badge both
				// read this, never a per-run value, since no run may be
				// selected yet.
				'gateMode'           => Plugin::currentGateMode()->value,
				// 0.4 U2: whether a run keeps going with the page closed, and
				// if not, why ('disabled' | 'missing' | 'too_old').
				'background'         => \Specflux\SiteAgent\Background\Availability::status(),
				// Real-cron hint: true only on a default WP-Cron site that has
				// queued a background run and where this person has not dismissed it.
				'cronHint'           => \Specflux\SiteAgent\Background\CronHint::shouldShow( get_current_user_id() ),
				'cronHelpUrl'        => \Specflux\SiteAgent\Background\CronHint::HELP_URL,
				'examples'           => $this->exampleGoals(),
				// 0.3 S10 (runs-pack fix): the packs the CURRENT viewer's
				// preflight allows — the same set `exampleGoals()` already
				// names — so the message box can offer a real pack choice
				// instead of starting a pack-less run with an empty verb map
				// (every read/plan call would then be refused fail-closed,
				// {@see \Specflux\SiteAgent\Tools\VerbTier}).
				'packs'              => $this->runnablePacks(),
				// runs-pack fix: the packs the viewer's preflight refuses,
				// with why — so the message box can SAY something when the
				// picker above is short a pack, or empty (every pack
				// refused), instead of going silent.
				'unavailablePacks'   => $this->unavailablePacks(),
				// Stage 22b (J1): a failing BLOCKING harness check (no model
				// provider, WordPress too old) disables Start. The setup panel
				// above the app names the fix; this is the app's half. It is
				// refreshed on window focus through `specflux_site_agent_setup_panel`.
				'startBlocked'       => $this->startBlocked( get_current_user_id() ),
				// Guided setup: the "Set up Specflux Site Agent" panel's steps, computed
				// per viewer ({@see Onboarding::steps()}) and refreshed on focus.
				'setupSteps'         => Onboarding::steps( get_current_user_id() ),
				// Per-run model choice: only currently-configured
				// providers/models, grouped by provider — the new-run form's
				// select and the run header's display name both read this
				// same list, never re-deriving it from a role guess.
				'modelChoices'       => ModelChoice::availableChoices(),
				// 0.3 S10 (17c): the admin-ajax surface's tick/cancel/start
				// actions carry the SAME `specflux_site_agent_run` nonce + `read`
				// capability check the admin-post handlers use, RE-CHECKED
				// server-side in `Ajax` — this is only the browser's half.
				// admin-ajax (not REST) is the interaction layer's surface:
				// {@see \Specflux\SiteAgent\Admin\ScreenCapability::tickAsScreen()}
				// only wraps the ajax tick handler, so it is the one path
				// that lets a screen-capability holder resolve a run they do
				// not own; REST's tick route has no such wrapper.
				'nonce'              => wp_create_nonce( 'specflux_site_agent_run' ),
				// The one HTTP consumer this screen is allowed to start as
				// (S13); registered only for a holder of the screen
				// capability ({@see registerAdminConsumer()}).
				'consumer'           => self::CONSUMER,
				// 0.3 S20: gates the React suggestion card's Save/Dismiss —
				// server-computed, never re-derived from a role guess on the
				// client, since `manage_options` (not the screen capability)
				// is what the REST route itself checks.
				'canManageSiteBrief' => current_user_can( 'manage_options' ),
			)
		);
	}

	/**
	 * Register the "Specflux Site Agent: run "<text>"" command palette command,
	 * site-wide (0.3 S10) — Cmd/Ctrl+K is available on every wp-admin screen
	 * since WP 6.9 (`wp_enqueue_command_palette_assets()`, hooked on this
	 * same `admin_enqueue_scripts` action), not only the Runs screen, so this
	 * runs unconditionally rather than being gated by `$hook` like
	 * {@see self::assets()}.
	 *
	 * Fail closed: a user who does not hold the screen capability never gets
	 * the script at all, so there is nothing for them to see or invoke.
	 *
	 * This command is a NAVIGATION only. It opens the Runs screen with the
	 * typed text already in the message box (via the SAME read-only `goal`
	 * query arg `assets()` reads above) and stops there — it never calls
	 * `specflux_site_agent()->start()` or anything that ticks a run. A human still has
	 * to look at the pre-filled box and click "Start run" themselves.
	 */
	public function commandPaletteAssets(): void {
		if ( ! current_user_can( $this->capability() ) ) {
			return;
		}

		$asset_file = SPECFLUX_SITE_AGENT_PATH . 'build/commands/index.asset.php';
		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		/** @var array{dependencies:list<string>,version:string} $asset */
		$asset = require $asset_file;

		$dependencies = array_values( array_filter( $asset['dependencies'], static fn ( string $handle ): bool => '' !== $handle ) );

		wp_enqueue_script( 'specflux-site-agent-commands', SPECFLUX_SITE_AGENT_URL . 'build/commands/index.js', $dependencies, $asset['version'], true );

		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( 'specflux-site-agent-commands', 'specflux-site-agent' );
		}

		wp_localize_script(
			'specflux-site-agent-commands',
			'specfluxSiteAgentCommandsConfig',
			array(
				'runsUrl' => Shell::url( 'runs' ),
			)
		);
	}

	/**
	 * Example goals for the empty state (S10), one per pack the CURRENT
	 * viewer's preflight allows. `[assumed]`: `Pack` declares no example-goal
	 * text of its own, so this names the pack rather than inventing a
	 * plausible-sounding goal it never asked to run — flagged in the
	 * stage-17a report.
	 *
	 * @return list<string>
	 */
	protected function exampleGoals(): array {
		$user_id  = get_current_user_id();
		$examples = array();

		foreach ( PackRegistry::fromFilters()->all() as $pack ) {
			if ( true === $pack->preflight( $user_id ) ) {
				$examples[] = sprintf(
					/* translators: %s: a capability pack's name, e.g. "pages". */
					__( 'Try something with the %s pack', 'specflux-site-agent' ),
					$pack->name()
				);
			}
		}

		return $examples;
	}

	/**
	 * The packs the CURRENT viewer may run (0.3 S10, runs-pack fix): the same
	 * preflight-passing set {@see exampleGoals()} names, as `{ name, label }`
	 * pairs for the message box's pack picker. `Pack` declares no separate
	 * human label (only `name()`), so `label` is the pack's own name — a pack
	 * name is DATA text, same as an example goal, never translated here.
	 *
	 * @return list<array{name:string,label:string}>
	 */
	protected function runnablePacks(): array {
		$user_id = get_current_user_id();
		$packs   = array();

		foreach ( PackRegistry::fromFilters()->all() as $pack ) {
			if ( true === $pack->preflight( $user_id ) ) {
				$packs[] = array(
					'name'  => $pack->name(),
					'label' => $pack->name(),
				);
			}
		}

		return $packs;
	}

	/**
	 * Does a failing BLOCKING harness check (the provider check) stop this
	 * viewer starting any run right now? Pack-level checks are not asked
	 * here: they already shape {@see runnablePacks()}.
	 */
	protected function startBlocked( int $user_id ): bool {
		if ( null !== Checks::firstBlockingFailure( Checks::harnessChecks() ) ) {
			return true;
		}

		// The old Agent Safety plugin beside Belayline Agent Governance: the panel's step 3.
		// The binding step (4) needs no entry here; it already empties
		// {@see runnablePacks()}.
		return Onboarding::oldPluginBlocks( $user_id );
	}

	/**
	 * The packs the CURRENT viewer may NOT run, with why (runs-pack fix): a
	 * pack whose {@see \Specflux\SiteAgent\Packs\Pack::preflight()} fails used
	 * to simply vanish from {@see runnablePacks()} — when EVERY pack fails,
	 * the message box rendered no picker and no explanation, so the screen
	 * said nothing about why nothing could run. `reason` is the preflight
	 * `WP_Error`'s own message (`pack_unbound`, `skills_too_large`, or a
	 * capability-check message — see {@see \Specflux\SiteAgent\Packs\Pack::preflight()}
	 * and {@see \Specflux\SiteAgent\Packs\Pack::setupChecks()}), already
	 * translated by whichever check produced it — never re-translated here.
	 *
	 * @return list<array{name:string,reason:string}>
	 */
	protected function unavailablePacks(): array {
		$user_id = get_current_user_id();
		$packs   = array();

		foreach ( PackRegistry::fromFilters()->all() as $pack ) {
			$result = $pack->preflight( $user_id );
			if ( true !== $result ) {
				$packs[] = array(
					'name'   => $pack->name(),
					'reason' => $result->get_error_message(),
				);
			}
		}

		return $packs;
	}

	// ------------------------------------------------------------------
	// admin-post handlers (all no-JS capable)
	// ------------------------------------------------------------------

	/** admin-post endpoint backing the Cancel button: nonce + capability + ownership + redirect. */
	public function handleCancel(): void {
		$run_id = isset( $_GET['run_id'] ) ? absint( $_GET['run_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified below.
		if ( 0 === $run_id ) {
			return;
		}

		check_admin_referer( 'specflux_site_agent_cancel_' . $run_id );

		if ( ! current_user_can( $this->capability() ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'specflux-site-agent' ) );
		}

		specflux_site_agent()->cancel( $run_id );

		$this->redirectBack( $run_id );
	}

	/**
	 * admin-post endpoint backing the "New run" form (S13).
	 *
	 * ONE start path (S13): verify nonce + capability, validate the goal
	 * (required, ≤ 1000), then resolve the request through the SAME
	 * {@see ConsumerPolicy} seam every HTTP consumer goes through. The policy
	 * owns the allow-list and the budget CEILING; the request may only lower
	 * the budget. Only then does this call
	 * `specflux_site_agent()->start( 'specflux-site-agent-admin', $goal, $allow, $budget, $pack )`.
	 *
	 * `specflux-site-agent-admin` is only in the consumer map while the current user
	 * holds the screen capability, so a request that slips past the capability
	 * check would still be refused at the policy seam (fail closed, twice).
	 *
	 * The pack's own S13 preflight is re-run inside `start()` (fail closed);
	 * a preflight failure surfaces as the preflight notice on the list view.
	 */
	public function handleNewRun(): void {
		check_admin_referer( 'specflux_site_agent_new_run' );

		if ( ! current_user_can( $this->capability() ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'specflux-site-agent' ) );
		}

		$goal = sanitize_textarea_field( wp_unslash( $_POST['goal'] ?? '' ) );
		if ( '' === $goal ) {
			$this->redirectList( 'specflux_site_agent_bad_request' );

			return;
		}

		$goal_len = $this->strLen( $goal );
		if ( $goal_len > self::MAX_GOAL ) {
			$this->redirectList( 'specflux_site_agent_bad_request' );

			return;
		}

		$pack = sanitize_text_field( wp_unslash( $_POST['pack'] ?? '' ) );
		if ( '' === $pack ) {
			$this->redirectList( 'specflux_site_agent_bad_request' );

			return;
		}

		// 0.3 S20: a follow-up run. start() forces the pack to the source
		// run's own pack regardless of the $pack chosen above (fail closed —
		// the form's pack choice is ignored, never trusted, once a source is
		// named).
		$follow_up_of = absint( $_POST['follow_up_of'] ?? 0 );

		// S7: the chosen pack's own default-budget overrides become the
		// ceiling ConsumerPolicy clamps against, instead of the generic
		// registered-consumer table — otherwise a pack asking for a flat,
		// high budget (the site pack) would be clamped straight back down.
		$pack_obj              = PackRegistry::fromFilters()->get( $pack );
		$pack_budget_overrides = null !== $pack_obj ? $pack_obj->defaultBudget() : array();

		// The one policy seam: the server owns the allow-list and the ceiling,
		// the request may only lower the budget (S13 "lower-only" is exactly
		// `Budget::clamp( $requested, $ceiling )` inside ConsumerPolicy).
		$policy = ConsumerPolicy::resolve( self::CONSUMER, $this->rawBudgetInput(), $pack_budget_overrides );
		if ( is_wp_error( $policy ) ) {
			$this->redirectList( (string) $policy->get_error_code() );

			return;
		}

		$result = specflux_site_agent()->start(
			self::CONSUMER,
			$goal,
			$policy['allow'],   // Outer bound; start() narrows it to the pack (S9).
			$policy['budget'],
			$pack,              // The pack is the single source of the allow-list.
			null,
			0 !== $follow_up_of ? $follow_up_of : null
		);

		if ( is_wp_error( $result ) ) {
			$this->redirectList( (string) $result->get_error_code() );

			return;
		}

		$this->redirectBack( (int) ( $result['run']['id'] ?? 0 ) );
	}

	/** admin-post endpoint backing the question park's Answer/Skip (S5/S6). */
	public function handleAnswer(): void {
		$run_id = absint( $_POST['run_id'] ?? 0 );
		check_admin_referer( 'specflux_site_agent_answer_' . $run_id );

		$resume = $this->assembleAnswerResume();
		if ( is_wp_error( $resume ) ) {
			$this->redirectBack( $run_id, (string) $resume->get_error_code() );

			return;
		}

		$this->redirectBack( $run_id, $this->tickErrorCode( $this->tickThroughScreen( $run_id, absint( $_POST['step_count'] ?? 0 ), $resume ) ) );
	}

	/** admin-post endpoint backing the plan park's Submit (S5/S7). */
	public function handlePlanDecision(): void {
		$run_id = absint( $_POST['run_id'] ?? 0 );
		check_admin_referer( 'specflux_site_agent_plan_' . $run_id );

		$resume = $this->assemblePlanResume();
		if ( is_wp_error( $resume ) ) {
			$this->redirectBack( $run_id, (string) $resume->get_error_code() );

			return;
		}

		$this->redirectBack( $run_id, $this->tickErrorCode( $this->tickThroughScreen( $run_id, absint( $_POST['step_count'] ?? 0 ), $resume ) ) );
	}

	/** admin-post endpoint backing the approval park's Approve/Reject (S5/S6). */
	public function handleApprovalDecision(): void {
		$run_id = absint( $_POST['run_id'] ?? 0 );
		check_admin_referer( 'specflux_site_agent_approval_' . $run_id );

		$action = sanitize_text_field( wp_unslash( $_POST['specflux_site_agent_approval_action'] ?? '' ) );
		if ( ! in_array( $action, array( 'approve', 'reject' ), true ) ) {
			$this->redirectBack( $run_id, 'resume_mismatch' );

			return;
		}

		// Approval parks resume with exactly { "action": "approve" | "reject" }.
		$this->redirectBack(
			$run_id,
			$this->tickErrorCode(
				$this->tickThroughScreen( $run_id, absint( $_POST['step_count'] ?? 0 ), array( 'action' => $action ) )
			)
		);
	}

	/**
	 * admin-post endpoint backing a suggestion's Save/Dismiss (0.3 S20).
	 *
	 * `manage_options` and the nonce are BOTH checked here — RE-CHECKED
	 * inside {@see \Specflux\SiteAgent\Plugin::resolveSuggestion()}'s callee
	 * is not the point; this is the one human-click seam, and it fails
	 * closed on its own, same as {@see \Specflux\SiteAgent\Http\Rest::routeSuggestionDecision()}.
	 */
	public function handleSuggestionDecision(): void {
		$run_id = absint( $_POST['run_id'] ?? 0 );
		check_admin_referer( 'specflux_site_agent_suggestion_' . $run_id );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'specflux-site-agent' ) );
		}

		$seq    = absint( $_POST['seq'] ?? 0 );
		$action = sanitize_text_field( wp_unslash( $_POST['specflux_site_agent_suggestion_action'] ?? '' ) );
		$text   = isset( $_POST['text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['text'] ) ) : null;

		$result = specflux_site_agent()->resolveSuggestion( $run_id, $seq, $action, $text );

		$this->redirectBack( $run_id, is_wp_error( $result ) ? (string) $result->get_error_code() : null );
	}

	/**
	 * The error code to carry back in the redirect, or null on success.
	 *
	 * A park handler used to DISCARD the tick result, so a 403 (or a
	 * `specflux_site_agent_conflict`) redirected silently back to a card that looked
	 * unchanged. Surfacing the code lets `renderRunErrorFlash()` say what
	 * happened.
	 *
	 * @param array<string,mixed>|WP_Error $result The tick outcome.
	 */
	private function tickErrorCode( array|WP_Error $result ): ?string {
		if ( ! is_wp_error( $result ) ) {
			return null;
		}

		$code = (string) $result->get_error_code();

		return '' !== $code ? $code : 'specflux_site_agent_bad_request';
	}

	// ------------------------------------------------------------------
	// Resume assembly (the S5 shapes, assembled on the screen)
	// ------------------------------------------------------------------

	/**
	 * Assemble an awaiting_user resume: { "answer": { "text"?, "choice"? } } or
	 * { "skip": true } (S5). Invalid shapes surface as WP_Error so the park card
	 * re-renders rather than being half-resolved.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	protected function assembleAnswerResume(): array|WP_Error {
		if ( 'skip' === $this->param( 'specflux_site_agent_answer_action' ) ) {
			return array( 'skip' => true );
		}

		$choice = (string) $this->param( 'specflux_site_agent_answer_choice' );
		$other  = (string) $this->param( 'specflux_site_agent_answer_other' );
		$text   = (string) $this->param( 'specflux_site_agent_answer_text' );

		// The "Other" affordance (or a textarea-only card) wins: a typed answer
		// is a text answer, never a choice.
		if ( '__other__' === $choice ) {
			if ( '' === trim( $other ) ) {
				return new WP_Error(
					'resume_mismatch',
					__( 'Type your answer, or pick one of the offered choices.', 'specflux-site-agent' ),
					array( 'status' => 400 )
				);
			}

			return array( 'answer' => array( 'text' => $other ) );
		}

		if ( '' !== $choice ) {
			// `choice_not_offered` is enforced by the Runner against the stored
			// payload (S5): the screen sends what the user picked.
			return array( 'answer' => array( 'choice' => $choice ) );
		}

		if ( '' !== trim( $text ) ) {
			return array( 'answer' => array( 'text' => $text ) );
		}

		return new WP_Error(
			'resume_mismatch',
			__( 'Choose a response to continue.', 'specflux-site-agent' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Assemble an awaiting_plan resume: { "plan": { "action", "note"? } } (S5).
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	protected function assemblePlanResume(): array|WP_Error {
		$action = (string) $this->param( 'specflux_site_agent_plan_action' );
		$note   = (string) $this->param( 'specflux_site_agent_plan_note' );

		if ( ! in_array( $action, array( 'accept', 'accept_preapprove', 'veto' ), true ) ) {
			return new WP_Error(
				'resume_mismatch',
				__( 'Choose Accept or Veto.', 'specflux-site-agent' ),
				array( 'status' => 400 )
			);
		}

		if ( 'veto' === $action && '' === trim( $note ) ) {
			return new WP_Error(
				'resume_mismatch',
				__( 'A veto needs a note saying why.', 'specflux-site-agent' ),
				array( 'status' => 400 )
			);
		}

		$plan = array( 'action' => $action );
		if ( '' !== $note ) {
			$plan['note'] = $note;
		}

		return array( 'plan' => $plan );
	}

	// ------------------------------------------------------------------
	// Acting-user rule (S6/S7): a screen-capability holder may act on ANY run
	// ------------------------------------------------------------------

	/**
	 * Advance a run through `specflux_site_agent()->tick()` from the screen context.
	 *
	 * The Runner's `mayTick()` defaults to owner-only but is gated by the
	 * `specflux_site_agent_can_tick` filter (the delegation seam). A holder of the screen
	 * capability may answer/act on a run even when they are not its owner; the
	 * Runner itself records the `answered_by` system step (S6/S7).
	 *
	 * The capability check happens HERE, inside
	 * {@see ScreenCapability::tickAsScreen()} — the park handlers above verify
	 * the nonce, not the capability, so this method must never assume they did.
	 * The allowance is scoped to this ONE run id and removed in a `finally`, so
	 * it never leaks outside this call. The exact same helper backs the
	 * admin-ajax poll ({@see \Specflux\SiteAgent\Http\Ajax::handleTick()}), so
	 * polling a delegated run behaves like submitting its form.
	 *
	 * @param array<string,mixed>|null $resume Park resolution.
	 * @return array<string,mixed>|WP_Error RunState or an error.
	 */
	private function tickThroughScreen( int $run_id, int $step_count, ?array $resume ): array|WP_Error {
		return ScreenCapability::tickAsScreen(
			$run_id,
			static fn (): array|WP_Error => specflux_site_agent()->tick( $run_id, $step_count, $resume )
		);
	}

	// ------------------------------------------------------------------
	// Rendering
	// ------------------------------------------------------------------

	/** Redirect to the detail view and stop processing (test-observable). */
	protected function redirectBack( int $run_id, ?string $error_code = null ): void {
		$args = array( 'run_id' => $run_id );
		if ( null !== $error_code && '' !== $error_code ) {
			$args['specflux_site_agent_run_error'] = $error_code;
		}
		wp_safe_redirect( self::url( $args ) );

		exit;
	}

	/** Redirect back to the list (used by start-failure / missing input). */
	protected function redirectList( string $error_code ): void {
		wp_safe_redirect( self::url( array( 'specflux_site_agent_start_error' => $error_code ) ) );

		exit;
	}

	/**
	 * Render list or detail.
	 *
	 * 0.3 S11: a blocked setup state still renders the FULL screen (the setup
	 * panel, then the empty state / example goals / palette command below it)
	 * — the panel names what to fix, it never replaces the screen.
	 */
	/**
	 * 0.3 S10: the screen is now the React app's mount point. The server
	 * still renders the S11 setup panel (its OWN evaluator, independent of
	 * the run list/detail this replaces) and a `<noscript>` fallback; every
	 * run-list row, park card and report table that 0.2 rendered here is
	 * RETIRED in favour of `assets/src/runs/` (S10, a breaking change named
	 * in the readme changelog). The admin-post handlers below are UNCHANGED
	 * — 17b still needs them (stage 16's suggestion Save/Dismiss UI, in
	 * particular) even though their no-JS forms no longer render.
	 */
	public function render(): void {
		if ( ! current_user_can( $this->capability() ) ) {
			return;
		}

		Shell::open( 'runs' );

		echo $this->renderSetupPanel( get_current_user_id() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped fragment.

		echo '<noscript><div class="aui-notice aui-notice--warning" role="status">' . Kit::icon( 'warning' ) . '<div>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped markup.
			. esc_html__( 'The Specflux Site Agent Runs screen needs JavaScript to be enabled in your browser.', 'specflux-site-agent' )
			. '</div></div></noscript>';

		echo '<div id="specflux-site-agent-runs-root"></div>';

		Shell::close();
	}

	// ------------------------------------------------------------------
	// Setup panel (0.3 S11): the harness's own checks (provider, ability review). Pack-level checks (`<pack>/capability`,
	// `<pack>/binding`) render inline in the new-run form's pack picker
	// (stage 8's per-pack preflight notice) — this panel is the harness's
	// half of the SAME evaluator ({@see Checks}).
	// ------------------------------------------------------------------

	/**
	 * The server-rendered notices above the app: ADVISORY harness checks only
	 * (an ability awaiting review). Every blocking check, and the "install
	 * Belayline Agent Governance" nudge, now lives in the React "Set up Specflux Site Agent" panel
	 * ({@see Onboarding}), so the same problem is never said twice. Always
	 * emits the `#specflux-site-agent-setup-panel` container, which the screen swaps on
	 * window focus.
	 */
	private function renderSetupPanel( int $user_id ): string {
		$html = '<div id="specflux-site-agent-setup-panel" class="specflux-site-agent-setup-panel">';
		foreach ( Checks::harnessChecks() as $check ) {
			if ( $check->isBlocking() ) {
				continue;
			}
			$html .= $this->renderSetupCheckNotice( $check, $user_id );
		}
		$html .= '</div>';

		return $html;
	}

	/** One check's notice: an error for a failing blocking check, a warning for an advisory. */
	private function renderSetupCheckNotice( SetupCheck $check, int $user_id ): string {
		$tone    = $check->isBlocking() ? 'error' : 'warning';
		$message = $check->messageFor( $user_id );
		$link    = '';

		if ( $check->fixVisibleFor( $user_id ) && null !== $check->fixUrl() ) {
			$link = sprintf(
				' <a href="%s">%s</a>',
				esc_url( $check->fixUrl() ),
				esc_html__( 'Fix this', 'specflux-site-agent' )
			);
		}

		// `specflux-site-agent-setup-check` and `data-check-id` are what the
		// screen's script and the e2e specs find a check by.
		return sprintf(
			'<div class="aui-notice aui-notice--%1$s specflux-site-agent-setup-check" role="%5$s" data-check-id="%2$s">%6$s<div>%3$s%4$s</div></div>',
			esc_attr( $tone ),
			esc_attr( $check->id() ),
			esc_html( $message ),
			$link, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from esc_url()/esc_html() above.
			'error' === $tone ? 'alert' : 'status',
			Kit::icon( 'error' === $tone ? 'x' : 'warning' ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped markup.
		);
	}

	/**
	 * admin-ajax: re-render the setup panel (S11 "refresh on window focus").
	 * Nonce-protected; capability = the computed screen capability (the same
	 * one that gates the whole screen).
	 */
	public function handleSetupPanel(): void {
		check_ajax_referer( 'specflux_site_agent_run', 'nonce' );

		if ( ! current_user_can( $this->capability() ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'specflux-site-agent' ) ), 403 );
		}

		$user_id  = get_current_user_id();
		$pack     = sanitize_text_field( wp_unslash( $_POST['pack'] ?? '' ) );
		$pack_obj = '' !== $pack ? PackRegistry::fromFilters()->get( $pack ) : null;

		$checks         = null !== $pack_obj ? Checks::forPack( $pack_obj, $user_id ) : Checks::harnessChecks();
		$start_disabled = null !== Checks::firstBlockingFailure( $checks ) || Onboarding::oldPluginBlocks( $user_id );

		wp_send_json_success(
			array(
				'html'              => $this->renderSetupPanel( $user_id ),
				'steps'             => Onboarding::steps( $user_id ),
				'start_enabled'     => ! $start_disabled,
				// Stage 22b: the whole start state, so the screen refreshes
				// the pack picker (and its "unavailable" notices) on focus
				// too, not just the panel.
				'packs'             => $this->runnablePacks(),
				'unavailable_packs' => $this->unavailablePacks(),
			)
		);
	}

	/**
	 * admin-ajax: save or skip the site brief from the setup panel's step 5.
	 * Same nonce as every Runs-screen write; `manage_options` like the
	 * suggestion card's Save. A too-long brief is refused, never truncated.
	 */
	public function handleSetupBrief(): void {
		check_ajax_referer( 'specflux_site_agent_run', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'specflux-site-agent' ) ), 403 );
		}

		$action = sanitize_key( wp_unslash( $_POST['brief_action'] ?? '' ) );

		if ( 'skip' === $action ) {
			update_option( Onboarding::BRIEF_SKIPPED_OPTION, 1, false );
		} elseif ( 'save' === $action ) {
			$saved = SiteBrief::set( sanitize_textarea_field( wp_unslash( $_POST['brief'] ?? '' ) ) );
			if ( is_wp_error( $saved ) ) {
				wp_send_json_error(
					array(
						'code'    => $saved->get_error_code(),
						'message' => $saved->get_error_message(),
					),
					400
				);
			}
		} else {
			wp_send_json_error( array( 'message' => __( 'Unknown action.', 'specflux-site-agent' ) ), 400 );
		}

		wp_send_json_success( array( 'steps' => Onboarding::steps( get_current_user_id() ) ) );
	}

	/**
	 * admin-ajax `specflux_site_agent_dismiss_cron_hint`: hide the real-cron
	 * hint for the current user on this site. Same nonce as every Runs-screen
	 * write; anyone who can see the screen may dismiss their own hint.
	 */
	public function handleDismissCronHint(): void {
		check_ajax_referer( 'specflux_site_agent_run', 'nonce' );

		if ( ! current_user_can( $this->capability() ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'specflux-site-agent' ) ), 403 );
		}

		\Specflux\SiteAgent\Background\CronHint::dismiss( get_current_user_id() );
		wp_send_json_success( array( 'dismissed' => true ) );
	}

	/**
	 * One-time notice on the Plugins screen only (S10 activation: no
	 * redirect, no site-wide notice). The transient is set on activation
	 * ({@see \Specflux\SiteAgent\specflux_site_agent_activate()}) and deleted the first
	 * time this renders, so it never shows twice.
	 */
	public function maybeRenderActivationNotice(): void {
		global $pagenow;

		if ( 'plugins.php' !== $pagenow || ! function_exists( 'get_transient' ) ) {
			return;
		}

		if ( ! get_transient( self::ACTIVATION_NOTICE_TRANSIENT ) ) {
			return;
		}

		delete_transient( self::ACTIVATION_NOTICE_TRANSIENT );

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s <a href="%s">%s</a></p></div>',
			esc_html__( 'Specflux Site Agent is active.', 'specflux-site-agent' ),
			esc_url( Shell::url( 'runs' ) ),
			esc_html__( 'Start a run', 'specflux-site-agent' )
		);
	}

	/**
	 * 0.3 S9: a run "needs" the viewer when it is parked AND the viewer may
	 * tick it (the same `specflux_site_agent_can_tick` delegation seam the Runner
	 * itself gates on) — a stalled `running` run is NOT a park (S9 names
	 * park kinds and the stalled state as two different things), so it never
	 * qualifies here even though it also wants a human to reopen the tab.
	 *
	 * @param array<string,mixed> $run {@see \Specflux\SiteAgent\Plugin::listRecent()} row shape.
	 */
	public static function needsYou( array $run ): bool {
		return self::isParkedStatus( (string) $run['status'] ) && (bool) ( $run['viewer_may_tick'] ?? false );
	}

	/**
	 * 0.3 S20: eligible for "Follow up" — the run is `completed`, `failed` or
	 * `cancelled`, it has a pack (a direct-allow run has no "source pack's
	 * run capability" to check, so it is never eligible — fail closed), and
	 * the CURRENT viewer holds that pack's run capability.
	 *
	 * @param array<string,mixed> $run {@see \Specflux\SiteAgent\Plugin::listRecent()} row shape.
	 */
	public static function eligibleForFollowUp( array $run ): bool {
		if ( ! in_array(
			(string) $run['status'],
			array( RunStatus::Completed->value, RunStatus::Failed->value, RunStatus::Cancelled->value ),
			true
		) ) {
			return false;
		}

		$pack_name = is_string( $run['pack'] ?? null ) ? $run['pack'] : '';
		if ( '' === $pack_name ) {
			return false;
		}

		$pack = PackRegistry::fromFilters()->get( $pack_name );
		if ( null === $pack ) {
			return false;
		}

		$capability = $pack->runCapability();

		return '' !== $capability && function_exists( 'current_user_can' ) && current_user_can( $capability );
	}

	/** Whether `$status` is one of the three park statuses (S9). */
	public static function isParkedStatus( string $status ): bool {
		return in_array(
			$status,
			array( RunStatus::AwaitingApproval->value, RunStatus::AwaitingUser->value, RunStatus::AwaitingPlan->value ),
			true
		);
	}

	/**
	 * The list row's status text (0.3 S9): the park kind for a parked run,
	 * "paused while closed — open to continue" for a stalled `running` run,
	 * or the ordinary status label otherwise.
	 *
	 * @param array<string,mixed> $run {@see \Specflux\SiteAgent\Plugin::listRecent()} row shape.
	 */
	public static function listRowLabel( array $run ): string {
		$status = (string) $run['status'];

		$park_kind = self::parkKindLabel( $status );
		if ( null !== $park_kind ) {
			return $park_kind;
		}

		if ( RunStatus::Running->value === $status && (bool) ( $run['stalled'] ?? false ) ) {
			return __( 'paused while closed — open to continue', 'specflux-site-agent' );
		}

		return self::statusLabel( $status );
	}

	/**
	 * The S9 park-kind phrasing for the Runs LIST row — distinct wording from
	 * {@see self::statusLabel()}'s detail-screen badge, which S9 leaves
	 * unchanged. Null for a non-parked status.
	 */
	public static function parkKindLabel( string $status ): ?string {
		return match ( $status ) {
			RunStatus::AwaitingUser->value => __( 'waiting for your answer', 'specflux-site-agent' ),
			RunStatus::AwaitingApproval->value => __( 'waiting for approval', 'specflux-site-agent' ),
			RunStatus::AwaitingPlan->value => __( 'waiting on the plan', 'specflux-site-agent' ),
			default => null,
		};
	}

	/**
	 * status value => translated label (S15 harness chrome).
	 *
	 * The enum VALUES stay machine-stable everywhere they matter (the CSS
	 * class, `data-status`, the poll payload); only what a human READS is
	 * translated.
	 *
	 * @return array<string,string>
	 */
	public static function statusLabels(): array {
		return array(
			RunStatus::Pending->value          => __( 'pending', 'specflux-site-agent' ),
			RunStatus::Running->value          => __( 'running', 'specflux-site-agent' ),
			RunStatus::AwaitingApproval->value => __( 'awaiting approval', 'specflux-site-agent' ),
			RunStatus::AwaitingUser->value     => __( 'awaiting your answer', 'specflux-site-agent' ),
			RunStatus::AwaitingPlan->value     => __( 'awaiting plan decision', 'specflux-site-agent' ),
			RunStatus::Completed->value        => __( 'completed', 'specflux-site-agent' ),
			RunStatus::Failed->value           => __( 'failed', 'specflux-site-agent' ),
			RunStatus::Cancelled->value        => __( 'cancelled', 'specflux-site-agent' ),
		);
	}

	/** One translated status label; an unknown value falls back to itself. */
	public static function statusLabel( string $status ): string {
		return self::statusLabels()[ $status ] ?? $status;
	}

	/** A POST value, unslashed + trimmed (textarea-safe), or ''. */
	private function param( string $key ): string {
		// sanitize_textarea_field preserves newlines; it is safe for a textarea
		// answer/note and harmless for a short radio/button value. The nonce is
		// verified by the handler's check_admin_referer before this runs.
		return sanitize_textarea_field( wp_unslash( isset( $_POST[ $key ] ) ? $_POST[ $key ] : '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
	}

	/**
	 * The five ceiling inputs as raw ints (for Budget::clamp).
	 *
	 * @return array<string,int>
	 */
	private function rawBudgetInput(): array {
		$out = array();
		foreach ( array( 'max_steps', 'max_tool_calls', 'max_tokens', 'max_questions', 'max_plans' ) as $key ) {
			if ( isset( $_POST[ $key ] ) && '' !== $_POST[ $key ] ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by the handler's check_admin_referer.
				$out[ $key ] = absint( $_POST[ $key ] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by the handler's check_admin_referer.
			}
		}

		return $out;
	}

	/** mb_strlen with a strlen fallback (mbstring may be absent). */
	private function strLen( string $value ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $value ) : strlen( $value );
	}
}
