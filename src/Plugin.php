<?php
/**
 * Plugin bootstrap, dependency gate, service container + PHP API.
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent;

use Specflux\SiteAgent\Approval\ApprovalBridge;
use Specflux\SiteAgent\Http\Ajax;
use Specflux\SiteAgent\Http\Rest;
use Specflux\SiteAgent\Model\AiClientGateway;
use Specflux\SiteAgent\Model\ImageCapability;
use Specflux\SiteAgent\Present\ResultPresenter;
use Specflux\SiteAgent\Model\ModelGatewayInterface;
use Specflux\SiteAgent\Packs\ObjectLookup;
use Specflux\SiteAgent\Packs\ToolBreadth;
use Specflux\SiteAgent\Run\Budget;
use Specflux\SiteAgent\Run\Clock;
use Specflux\SiteAgent\Run\GateMode;
use Specflux\SiteAgent\Run\Report;
use Specflux\SiteAgent\Run\Runner;
use Specflux\SiteAgent\Run\RunStatus;
use Specflux\SiteAgent\Run\StepKind;
use Specflux\SiteAgent\Run\WpdbRunStore;
use Specflux\SiteAgent\Setup\Checks;
use Specflux\SiteAgent\Skills\Skill;
use Specflux\SiteAgent\Skills\SkillSet;
use Specflux\SiteAgent\Tools\ToolExecutor;
use WP_Error;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * The one plugin instance. Owns the Agent Safety dependency check, the lazy
 * service graph, and the PHP API mirroring the HTTP surface (S9).
 */
final class Plugin {

	/**
	 * Marker class for the hard dependency check: Agent Safety's gate hook.
	 *
	 * Checking a PLUGIN-side class means "the whole Agent Safety host is
	 * loaded and its seams are wired", which is the property runs actually
	 * depend on.
	 */
	public const AGENT_SAFETY_MARKER = 'Belayline\Plugin\Hooks\AbilityPermissionGate';

	/** Singleton instance. */
	private static ?self $instance = null;

	/** Injected dependency probe for tests; null restores the real check. */
	private static ?bool $dependency_probe = null;

	/** Whether the Agent Safety dependency was satisfied at boot time. */
	private ?bool $available = null;

	/** Lazily built runner. */
	private ?Runner $runner = null;

	/** Lazily built background driver (0.4 U2). */
	private ?\Specflux\SiteAgent\Background\BackgroundDriver $background = null;

	/** The one pages-pack instance this request shares. */
	private ?\Specflux\SiteAgent\Packs\Pages\PagesPack $pages_pack = null;

	/** The one posts-pack instance this request shares (S5). */
	private ?\Specflux\SiteAgent\Packs\Posts\PostsPack $posts_pack = null;

	/** The one site-pack instance this request shares (S7). */
	private ?\Specflux\SiteAgent\Packs\Site\SitePack $site_pack = null;

	/** The one commerce-pack instance this request shares (S19), when WooCommerce is active. */
	private ?\Specflux\SiteAgent\Packs\Commerce\CommercePack $commerce_pack = null;

	/** Whether {@see govern()} has already wired its filters this request. */
	private bool $governed = false;

	/**
	 * Get (and lazily create) the plugin instance. Non-nullable by design:
	 * consumers feature-detect the FUNCTION (function_exists('specflux_site_agent')),
	 * then ask this instance whether the Agent Safety dependency holds.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/** Reset the singleton (tests only). */
	public static function reset(): void {
		self::$instance         = null;
		self::$dependency_probe = null;
	}

	/**
	 * The gate mode a run started right now would resolve to (0.3 S3):
	 * {@see GateMode::AgentSafety} when its gate class is loaded, otherwise
	 * {@see GateMode::BuiltIn}. Static so composition-root-adjacent code that
	 * has no natural instance in hand (a pack's `preflight()`) can ask the
	 * exact question `start()` asks, without duplicating the dependency probe.
	 */
	public static function currentGateMode(): GateMode {
		return self::instance()->available() ? GateMode::AgentSafety : GateMode::BuiltIn;
	}

	/**
	 * Test seam: force the dependency check outcome.
	 *
	 * @param bool|null $present Forced outcome, or null to restore reality.
	 */
	public static function set_dependency_probe( ?bool $present ): void {
		self::$dependency_probe = $present;
	}

	/**
	 * Register the packs and hand their governance data to Agent Safety.
	 *
	 * Split out of {@see boot()} and run EARLIER for one reason: Agent Safety
	 * reads `belayline_governed_namespaces` and `belayline_verb_map`
	 * inside its own `plugins_loaded` priority-0 bootstrap, while Specflux Site Agent
	 * boots at priority 5. A pack registered in `boot()` would therefore be
	 * ungoverned — the gate would return early for every `specflux/*` ability
	 * and no write would produce a verdict, an approval park or an audit row.
	 * specflux-site-agent.php calls this on `plugins_loaded` priority -1.
	 *
	 * Idempotent, and deliberately NOT gated on the Agent Safety dependency
	 * check: with Agent Safety absent both filters are inert, and `start()`
	 * still refuses every run as `specflux_site_agent_ungoverned`.
	 */
	public function govern(): void {
		if ( $this->governed ) {
			return;
		}
		$this->governed = true;

		// C4-S: SureCart joins WooCommerce as a store platform. Hooked before
		// anything asks which platforms exist (the polyfill registration, the
		// pack gate just below, the governed-namespace contribution).
		add_filter(
			'specflux_site_agent_store_platforms',
			static fn ( array $platforms ): array => array_merge( $platforms, array( new \Specflux\SiteAgent\Packs\Commerce\Platforms\SureCart\SureCartPlatform() ) ),
			10,
			1
		);

		$pages_pack = $this->pages_pack();
		$posts_pack = $this->posts_pack();
		$site_pack  = $this->site_pack();
		add_filter(
			'specflux_site_agent_packs',
			static fn ( array $packs ): array => $packs + array(
				'pages' => $pages_pack,
				'posts' => $posts_pack,
				'site'  => $site_pack,
			),
			10,
			1
		);

		// S19: the commerce pack registers only when WooCommerce is active —
		// with it absent, there is no `manage_woocommerce` capability and no
		// Woo abilities to poly-fill against, so the pack has nothing to do
		// at all (never mind govern). Unlike the Agent Safety check below,
		// THIS gate is unconditional: a WooCommerce-less site must never see
		// a commerce run option.
		if ( \Specflux\SiteAgent\Packs\Commerce\Platforms\StorePlatforms::anyActive() ) {
			$commerce_pack = $this->commerce_pack();
			add_filter(
				'specflux_site_agent_packs',
				static fn ( array $packs ): array => $packs + array( 'commerce' => $commerce_pack ),
				10,
				1
			);
		}

		\Specflux\SiteAgent\Packs\PackRegistry::contributeToAgentSafety();
	}

	/**
	 * The request's one commerce-pack instance (S19), lazily created only
	 * when {@see govern()}/{@see boot()} decided WooCommerce is active.
	 */
	private function commerce_pack(): \Specflux\SiteAgent\Packs\Commerce\CommercePack {
		if ( null === $this->commerce_pack ) {
			$this->commerce_pack = new \Specflux\SiteAgent\Packs\Commerce\CommercePack();
		}

		return $this->commerce_pack;
	}

	/**
	 * The request's one pages-pack instance (S10/S11).
	 */
	private function pages_pack(): \Specflux\SiteAgent\Packs\Pages\PagesPack {
		if ( null === $this->pages_pack ) {
			$this->pages_pack = new \Specflux\SiteAgent\Packs\Pages\PagesPack();
		}

		return $this->pages_pack;
	}

	/**
	 * The request's one posts-pack instance (S5).
	 */
	private function posts_pack(): \Specflux\SiteAgent\Packs\Posts\PostsPack {
		if ( null === $this->posts_pack ) {
			$this->posts_pack = new \Specflux\SiteAgent\Packs\Posts\PostsPack();
		}

		return $this->posts_pack;
	}

	/**
	 * The request's one site-pack instance (S7).
	 */
	private function site_pack(): \Specflux\SiteAgent\Packs\Site\SitePack {
		if ( null === $this->site_pack ) {
			$this->site_pack = new \Specflux\SiteAgent\Packs\Site\SitePack();
		}

		return $this->site_pack;
	}

	/**
	 * Wire runtime seams. Called once from plugins_loaded (priority 5, after
	 * Agent Safety's own priority-0 bootstrap so its classes exist).
	 */
	public function boot(): void {
		// K1: the MCP connector's server and gate hooks. Inert unless an admin
		// turns the connector on and the mcp-adapter plugin is present.
		\Specflux\SiteAgent\Connector\Connector::register();
		// 0.4 U2: the queue callbacks for background runs. Resolved lazily so
		// a request that never reaches the queue never builds the runner;
		// Availability decides at run time whether the queue is usable.
		add_action(
			\Specflux\SiteAgent\Background\BackgroundDriver::HOOK,
			function ( mixed ...$args ): void {
				if ( $this->ready() ) {
					$this->background()->handle( ...$args );
				}
			},
			10,
			3
		);
		add_action(
			\Specflux\SiteAgent\Background\BackgroundDriver::SWEEP_HOOK,
			function (): void {
				if ( $this->ready() ) {
					$this->background()->sweep();
				}
			}
		);
		// 0.4 U3: Belayline's approval lifecycle. Feature-detected by nature:
		// with Belayline absent (or older than 0.5) none of these ever fires.
		add_action(
			'belayline_approval_resolved',
			function ( mixed $approval_id = '', mixed $outcome = '' ): void {
				if ( $this->ready() ) {
					$this->approvalEvents()->onResolved( $approval_id, $outcome );
				}
			},
			10,
			3
		);
		add_action(
			'belayline_approval_expired',
			function ( mixed $approval_id = '', mixed $verb = '' ): void {
				if ( $this->ready() ) {
					$this->approvalEvents()->onExpired( $approval_id, $verb );
				}
			},
			10,
			2
		);
		add_filter( 'belayline_pending_ttl_seconds', array( \Specflux\SiteAgent\Background\ApprovalEvents::class, 'pendingTtl' ), 10, 3 );
		// 0.4 U3: wall-clock expiry and run retention (daily WP-Cron).
		\Specflux\SiteAgent\Run\RunLifecycle::register(
			fn (): ?\Specflux\SiteAgent\Run\RunLifecycle => $this->ready() ? new \Specflux\SiteAgent\Run\RunLifecycle( $this->runner()->store(), $this->runner() ) : null
		);
		add_action(
			'init',
			static function (): void {
				// Admin and cron requests only: a front-end hit never pays for the lookup.
				if ( is_admin() || wp_doing_cron() ) {
					\Specflux\SiteAgent\Background\BackgroundDriver::ensureSweepScheduled();
					\Specflux\SiteAgent\Run\RunLifecycle::schedule();
				}
			},
			20
		);
		// 0.3 S3: Agent Safety is no longer a hard dependency — its absence
		// only decides the gate mode a run starts in (GateMode::BuiltIn), so
		// boot wires the runtime either way. `available()` still reports
		// whether AS is present, for the advisory notice below and for
		// GateMode resolution at run start.
		$this->available = $this->dependency_present();

		// Schema v2 (0.2 S4): idempotent dbDelta, stamped by version option.
		global $wpdb;
		if ( isset( $wpdb ) ) {
			Schema::maybe_upgrade( $wpdb );
		}

		// Pages pack (S10/S11): its polyfill abilities, its pattern vocabulary
		// and its approval-summary builder. All wiring lives in this
		// composition root — nothing under src/Run knows packs exist. The pack
		// itself and its Agent Safety governance were registered earlier, by
		// govern(); calling it again here is a no-op that keeps boot() correct
		// on a host that never fired the early hook.
		$this->govern();
		$pages_pack = $this->pages_pack();
		$posts_pack = $this->posts_pack();
		$site_pack  = $this->site_pack();
		// S19: only built when WooCommerce is active (mirrors govern()'s own
		// gate) — a null entry here is filtered out below, never registered.
		$commerce_pack = \Specflux\SiteAgent\Packs\Commerce\Platforms\StorePlatforms::anyActive() ? $this->commerce_pack() : null;
		// The AS pack resolves the ability allow-list, which touches the
		// Abilities registry — that must not happen before `init`, so the
		// registration is deferred with the pack captured by value.
		add_action(
			'init',
			static function () use ( $pages_pack, $posts_pack, $site_pack, $commerce_pack ): void {
				$packs = null !== $commerce_pack
					? array( $pages_pack, $posts_pack, $site_pack, $commerce_pack )
					: array( $pages_pack, $posts_pack, $site_pack );
				foreach ( $packs as $pack ) {
					$as_pack = $pack->agentSafetyPack();
					if ( null === $as_pack ) {
						continue;
					}
					add_filter(
						'belayline_pack_registry',
						static function ( $registry ) use ( $as_pack ) {
							if ( is_object( $registry ) && method_exists( $registry, 'register' ) ) {
								$registry->register( $as_pack );
							}

							return $registry;
						},
						10,
						1
					);
				}

				// One role binding that unlocks pages, posts and site together.
				$bundle = \Specflux\SiteAgent\Packs\ContentBundle::agentSafetyPack( $pages_pack, $posts_pack, $site_pack );
				if ( null !== $bundle ) {
					add_filter(
						'belayline_pack_registry',
						static function ( $registry ) use ( $bundle ) {
							if ( is_object( $registry ) && method_exists( $registry, 'register' ) ) {
								$registry->register( $bundle );
							}

							return $registry;
						},
						10,
						1
					);
				}
			},
			5
		);
		\Specflux\SiteAgent\Packs\Content\Abilities::boot();
		\Specflux\SiteAgent\Packs\Content\Media::boot();
		// 0.5 K5: tool breadth — post list/trash/revisions/meta and comment moderation.
		\Specflux\SiteAgent\Packs\Content\PostTools::boot();
		\Specflux\SiteAgent\Packs\Content\Comments::boot();
		// S19: the commerce pack's own polyfill abilities. Registered
		// unconditionally like the other registrars above — `wp_register_ability`
		// is harmless with WooCommerce absent, and the pack itself never
		// reaches the model's tool set unless it was registered on
		// `specflux_site_agent_packs` above (WooCommerce active).
		\Specflux\SiteAgent\Packs\Commerce\Platforms\FluentCart\FluentCartPlatform::boot();
		\Specflux\SiteAgent\Packs\Commerce\Abilities::boot();
		\Specflux\SiteAgent\Packs\Pages\PublishSummary::boot();
		// Stage 14 (AS-15/S19): the commerce pack's own approval-summary
		// builder, plus the sibling covering the new Tier-2 content calls
		// (posts publish, site navigation, front page) — same
		// `belayline_approval_summary` hook, each inert for verbs it
		// does not own.
		\Specflux\SiteAgent\Packs\Commerce\CommerceSummary::boot();
		\Specflux\SiteAgent\Packs\Site\ContentSummary::boot();
		// S7: site navigation + front-page abilities. Navigation::boot() also
		// registers the shared `specflux-site` ability category.
		\Specflux\SiteAgent\Packs\Site\Navigation::boot();
		\Specflux\SiteAgent\Packs\Site\FrontPage::boot();
		// 0.3 quality feature 5: the style-variation abilities.
		\Specflux\SiteAgent\Packs\Site\Style::boot();
		// 0.5 K5: users, settings, classic menus, plugin and theme inventory.
		\Specflux\SiteAgent\Packs\Site\Users::boot();
		\Specflux\SiteAgent\Packs\Site\Settings::boot();
		\Specflux\SiteAgent\Packs\Site\Menus::boot();
		\Specflux\SiteAgent\Packs\Site\Inventory::boot();
		// 0.3 S4: the pages pack's vocabulary/validator plug into the shared
		// content registrar under its own slug — `list-patterns` and the write
		// abilities resolve THIS pair only while a 'pages' run is ticking (see
		// the useRunPack()/forgetRunPack() scoping in tick() below).
		$pages_vocabulary = new \Specflux\SiteAgent\Packs\Pages\Vocabulary();
		\Specflux\SiteAgent\Packs\Content\Abilities::registerSource(
			'pages',
			new \Specflux\SiteAgent\Packs\Pages\Validator( $pages_vocabulary ),
			$pages_vocabulary,
			'edit_pages'
		);
		// S5: same registration for the posts pack, under its own slug.
		$posts_vocabulary = new \Specflux\SiteAgent\Packs\Posts\Vocabulary();
		\Specflux\SiteAgent\Packs\Content\Abilities::registerSource(
			'posts',
			new \Specflux\SiteAgent\Packs\Posts\Validator( $posts_vocabulary ),
			$posts_vocabulary,
			'edit_posts'
		);
		// S7: same registration for the site pack, under its own slug — its
		// Vocabulary/Validator extend the pages pack's with the two
		// homepage-only patterns (page-links, intro).
		$site_vocabulary = new \Specflux\SiteAgent\Packs\Site\Vocabulary();
		\Specflux\SiteAgent\Packs\Content\Abilities::registerSource(
			'site',
			new \Specflux\SiteAgent\Packs\Site\Validator( $site_vocabulary ),
			$site_vocabulary,
			'manage_options'
		);
		// S14: object binding for pre-approval grants. Registered
		// unconditionally and answering FALSE until a tick opens a run context
		// — a missing hook would mean "no grant applies", never "every grant
		// applies", so the harness always speaks for itself.
		\Specflux\SiteAgent\Run\GrantEligibility::boot();
		add_action(
			'init',
			static function () use ( $pages_pack, $posts_pack, $site_pack ): void {
				// Pattern registration rides each pack's vocabulary; failures
				// must never break the site — the Validator refuses unknown
				// markup at write time regardless (fail closed there).
				$pages_pack->registerPatterns();
				$posts_pack->registerPatterns();
				$site_pack->registerPatterns();
			},
			20
		);

		// HTTP surface (S9). Both transports delegate to the PHP API below.
		( new Ajax() )->register();
		add_action( 'rest_api_init', array( new Rest(), 'register' ) );

		// 0.4 S6: direct-allow runs (no pack) tier passthrough abilities
		// through the site-wide verb map. Last in line: nothing another
		// callback returns can loosen a passthrough tier.
		add_filter(
			\Specflux\SiteAgent\Tools\VerbTier::VERB_MAP_FILTER,
			array( \Specflux\SiteAgent\Passthrough\Passthrough::class, 'filterVerbMap' ),
			PHP_INT_MAX
		);

		// Observation screen (S10).
		if ( function_exists( 'is_admin' ) && is_admin() ) {
			( new \Specflux\SiteAgent\Admin\RunsScreen() )->register();
			// 0.3 S20: the site-brief settings submenu.
			( new \Specflux\SiteAgent\Admin\SettingsScreen() )->register();
			( new \Specflux\SiteAgent\Admin\PrivacyPolicy() )->register();
		}

		// 0.3 S3: Agent Safety's absence is not fail-closed — runs still start
		// and tick without it, in GateMode::BuiltIn. The "install Belayline Agent Governance"
		// nudge lives only in the guided setup panel ({@see Onboarding}), never
		// as a notice on every admin screen (defect 3).
	}

	/**
	 * Can this plugin start runs? Consumers MUST treat false as "Specflux Site Agent
	 * absent" and keep their existing behaviour.
	 */
	public function available(): bool {
		if ( null === $this->available ) {
			$this->available = $this->dependency_present();
		}

		return $this->available;
	}

	// ------------------------------------------------------------------
	// PHP API (S9)
	// ------------------------------------------------------------------

	/**
	 * Create a run for the CURRENT user; returns its initial RunState
	 * (status pending). The consumer drives it with tick().
	 *
	 * @param string            $consumer       Consumer identifier (e.g. 'specflux-mac').
	 * @param string            $goal           Goal text (becomes the first user step).
	 * @param list<string>      $allow          Ability allow-list: exact ids or globs.
	 *                                          IGNORED when $pack is given (S9).
	 * @param array<string,int> $budget         Optional per-run overrides.
	 * @param string|null       $pack           Pack name (S9); derives the allow-list.
	 * @param list<string>|null $skills_disable Non-required skill ids to drop (S8).
	 * @param int|null          $follow_up_of   0.3 S20: a source run id to seed this run
	 *                                          from. Forces $pack to the source's pack.
	 * @param string|null       $model_provider Pinned model provider id. Null
	 *                                          (with $model_id also null) means automatic
	 *                                          selection. A follow-up run with both null
	 *                                          inherits the source run's pinned model.
	 * @param string|null       $model_id       Pinned model id; must be given
	 *                                          together with $model_provider or not at all.
	 * @return array<string,mixed>|WP_Error RunState or specflux_site_agent_ungoverned /
	 *                                      specflux_site_agent_bad_request / pack_unknown /
	 *                                      pack_unbound / skills_too_large /
	 *                                      follow_up_not_found / follow_up_not_finished /
	 *                                      follow_up_unsupported / follow_up_forbidden /
	 *                                      specflux_site_agent_model_unavailable.
	 */
	public function start(
		string $consumer,
		string $goal,
		array $allow = array(),
		array $budget = array(),
		?string $pack = null,
		?array $skills_disable = null,
		?int $follow_up_of = null,
		?string $model_provider = null,
		?string $model_id = null
	): array|WP_Error {
		if ( ! $this->ready() ) {
			return $this->ungoverned_error();
		}

		// 0.3 S3: a third-party consumer may not drive a built-in-mode run —
		// its approval park can only be resolved on Specflux Site Agent's own Runs
		// screen, which is the one consumer this refusal exempts.
		if ( GateMode::BuiltIn === self::currentGateMode() && \Specflux\SiteAgent\Admin\RunsScreen::CONSUMER !== $consumer ) {
			return $this->ungoverned_error();
		}

		$user_id      = (int) get_current_user_id();
		$caller_allow = $allow; // Captured BEFORE the pack derives it (S9).
		$model_chosen = null !== $model_provider || null !== $model_id;

		// 0.3 S20: a follow-up run. Resolved BEFORE pack resolution — the
		// source's pack REPLACES whatever $pack the caller passed, fail
		// closed: a source with no pack of its own (a direct-allow run) has
		// no "source pack's run capability" to check, so it is refused
		// rather than guessed at.
		if ( null !== $follow_up_of ) {
			$source = $this->runner()->store()->getRun( $follow_up_of );
			if ( null === $source ) {
				return new WP_Error( 'follow_up_not_found', __( 'The source run was not found.', 'specflux-site-agent' ), array( 'status' => 404 ) );
			}
			// Stage 22b: the viewer must also be able to SEE the source run — its
			// object list seeds the follow-up, so a run `get()` would refuse
			// must not leak through here.
			if ( ! $this->maySee( $source ) ) {
				return new WP_Error( 'follow_up_forbidden', __( 'This run belongs to another user.', 'specflux-site-agent' ), array( 'status' => 403 ) );
			}
			if ( ! in_array( $source->status, array( RunStatus::Completed, RunStatus::Failed, RunStatus::Cancelled ), true ) ) {
				return new WP_Error( 'follow_up_not_finished', __( 'The source run has not finished yet.', 'specflux-site-agent' ), array( 'status' => 400 ) );
			}
			if ( null === $source->pack ) {
				return new WP_Error( 'follow_up_unsupported', __( 'The source run has no capability pack to follow up on.', 'specflux-site-agent' ), array( 'status' => 400 ) );
			}

			$source_pack_obj = $this->packRegistry()->get( $source->pack );
			$run_capability  = null !== $source_pack_obj ? $source_pack_obj->runCapability() : '';
			if ( '' === $run_capability || ! function_exists( 'current_user_can' ) || ! current_user_can( $run_capability ) ) {
				return new WP_Error( 'follow_up_forbidden', __( 'You do not hold the source run\'s capability.', 'specflux-site-agent' ), array( 'status' => 403 ) );
			}

			$pack = $source->pack; // Forced (S20), regardless of what the caller asked for.

			// A follow-up with no explicit model choice inherits the
			// source run's pinned model (also possibly automatic); an explicit
			// choice from the caller always overrides.
			if ( ! $model_chosen ) {
				$model_provider = $source->modelProvider;
				$model_id       = $source->modelId;
			}
		}

		// A candidate pair must be given as BOTH fields or NEITHER —
		// half a pair can never be silently completed or dropped. Checked
		// before any DB write, like every other start() refusal.
		if ( ( null === $model_provider ) !== ( null === $model_id ) ) {
			return new WP_Error(
				'specflux_site_agent_bad_request',
				__( 'Provide both a model provider and a model id, or leave both empty for automatic.', 'specflux-site-agent' ),
				array( 'status' => 400 )
			);
		}
		// Only an explicit choice is validated: an inherited pin whose model has
		// since gone away is still just a preference, and falls back to automatic.
		if ( $model_chosen && null !== $model_provider && null !== $model_id ) {
			$model_valid = \Specflux\SiteAgent\Model\ModelChoice::validate( $model_provider, $model_id );
			if ( is_wp_error( $model_valid ) ) {
				return $model_valid;
			}
		}

		// 0.3 S11: start() re-decides on the server through the SAME evaluator
		// the setup panel renders — the harness's own blocking checks (the
		// provider check…) refuse here exactly as they disable the panel's
		// Start button. Advisory checks (Agent Safety) never block. This runs
		// for BOTH the direct-allow and pack paths; a pack's OWN checks are
		// asked separately below, by preflight().
		$harness_failure = Checks::firstBlockingFailure( Checks::harnessChecks() );
		if ( null !== $harness_failure ) {
			return new WP_Error(
				$harness_failure->errorCode(),
				$harness_failure->messageFor( $user_id ),
				array( 'status' => 400 )
			);
		}

		// S9: pack resolution first — an unknown pack is a 400 before any DB
		// write. A caller-supplied $allow is IGNORED when a pack is given: the
		// pack is the single source of the allow-list (the direct-allow path
		// keeps working with $pack = null).
		$pack_obj       = null;
		$store_platform = null;
		$withheld_roles = array();
		if ( null !== $pack ) {
			$pack_obj = $this->packRegistry()->get( $pack );
			if ( null === $pack_obj ) {
				return new WP_Error(
					'pack_unknown',
					__( 'Unknown pack.', 'specflux-site-agent' ),
					array( 'status' => 400 )
				);
			}
			$allow = $pack_obj->allowList();

			// S13: preflight — skills ceiling plus Capability-Packs binding
			// (the binding check itself is completed with the pages pack).
			$preflight = $pack_obj->preflight( $user_id, $consumer, $goal, $skills_disable );
			if ( is_wp_error( $preflight ) ) {
				// Refused: skills_too_large (400) or pack_unbound (400).
				return $preflight;
			}

			// C4-0: a commerce run pins the store platform it starts on. The
			// selection (the only usable platform, else the admin's choice)
			// is resolved ONCE here and then scoped over the rest of start() so
			// the allow-list below is the pinned platform's. No usable
			// platform pins nothing (the commerce setup check is what refuses).
			$store_platform = $pack_obj->pinForRun();
			if ( null !== $store_platform ) {
				$pack_obj->useRunPlatform( $store_platform );
				$allow = $pack_obj->allowList();
			}

			// 0.3 S6: roles whose declared capability the starting user lacks
			// are WITHHELD — decided once, here, from who is starting the run
			// (never per call, which is the gate's job). Their resolved
			// abilities are dropped from the tool set the same way (S6: "the
			// run's tool set"), never merely hidden by a later filter.
			$withheld_roles = self::withheldRolesFor( $pack_obj, $user_id );
			if ( array() !== $withheld_roles ) {
				$resolved           = $pack_obj->resolveAbilities();
				$withheld_abilities = array();
				foreach ( $withheld_roles as $role ) {
					if ( isset( $resolved[ $role ] ) ) {
						$withheld_abilities[] = $resolved[ $role ];
					}
				}
				$allow = array_values( array_diff( $allow, $withheld_abilities ) );
			}

			// Tool breadth is connector-only: drop it from the run's allow-list
			// (ToolRegistry refuses it too, so a hand-built allow can't bring it back).
			$allow = array_values( array_filter( $allow, static fn ( $ability ): bool => ! ToolBreadth::isBreadthAbility( (string) $ability ) ) );

			// The allow-list is fixed; the pin lives on the run row from here.
			$pack_obj->useRunPlatform( null );
		}

		// The "non-empty allow" guard applies only to the DIRECT path: with a
		// pack the allow is derived, so it is never empty here.
		if ( '' === trim( $consumer ) || '' === trim( $goal ) || ( array() === $allow && null === $pack_obj ) ) {
			return new WP_Error(
				'specflux_site_agent_bad_request',
				__( 'A run needs a consumer, a goal, and a non-empty allow-list.', 'specflux-site-agent' ),
				array( 'status' => 400 )
			);
		}

		// S15: capture the two best-effort locales at start so a DIFFERENT
		// admin answering a park never switches them. Resolved BEFORE
		// collecting skills: S5 promotes `harness/content-language` off the
		// pack and onto every run, so the ceiling must be checked against the
		// same locale-rendered body `instructionFor()` will render later.
		$conversation_locale = function_exists( 'get_user_locale' ) ? get_user_locale( $user_id ) : '';
		if ( '' === $conversation_locale && function_exists( 'get_locale' ) ) {
			$conversation_locale = get_locale();
		}
		$content_locale = function_exists( 'get_locale' ) ? get_locale() : '';

		// S8: collect skills WITH the pack's skills and the disable list; the
		// ceiling is a start-time gate — refused, never truncated.
		$skills  = SkillSet::collect( $consumer, $goal, $pack_obj, $skills_disable, $content_locale );
		$ceiling = SkillSet::ceilingError( $skills );
		if ( null !== $ceiling ) {
			return $ceiling;
		}

		// 0.3 S3: resolve the gate mode ONCE, here, and pin it on the run row.
		// It never changes afterwards, even if Agent Safety is later
		// installed/removed while the run is in flight (S3's mismatch check).
		$gate_mode = self::currentGateMode();

		$run_budget = Budget::sanitize( $budget, null !== $pack_obj ? $pack_obj->defaultBudget() : array() );
		if ( ! ImageCapability::available() ) {
			// Proof-run defect fix: a site whose configured models cannot
			// output images must not offer generate-image — a zero images
			// budget withholds the tool, drops the verb from the plan's
			// vocabulary and steers the pack's guidance to stock search, so
			// no approval is ever parked for a call that can only fail.
			$run_budget[ Budget::IMAGES ] = 0;
		}

		$store  = $this->runner()->store();
		$run_id = $store->createRun(
			$user_id,
			$consumer,
			$goal,
			$allow,
			$run_budget,
			$pack,
			$conversation_locale,
			$content_locale,
			$gate_mode,
			$withheld_roles,
			$follow_up_of,
			$model_provider,
			$model_id,
			$store_platform
		);

		// S9: when a pack drove the allow-list, record that a caller-supplied
		// $allow was ignored (a seq-1 system note; the first real step lands
		// after it).
		if ( null !== $pack_obj && array() !== $caller_allow ) {
			$store->appendSystemNote(
				$run_id,
				array(
					'note'          => 'allow_from_pack',
					'pack'          => $pack,
					'ignored_allow' => array_values( $caller_allow ),
				)
			);
		}

		// S8: snapshot the skill set at start (skills_json) — the audit trail
		// of what the instruction was assembled from.
		$store->updateRun(
			$run_id,
			array(
				// S8: the disable list rides the RUN, not just the start call —
				// every tick re-collects the same set the ceiling was checked
				// against, so a dropped skill stays dropped.
				'skills_disable_json' => null !== $skills_disable ? array_values( array_filter( $skills_disable, 'is_string' ) ) : null,
				'skills_json'         => array_map(
					static fn ( Skill $skill ): array => array(
						'id'      => $skill->id,
						'sha256'  => hash( 'sha256', $skill->body ),
						'source'  => $skill->source->value,
						'version' => $skill->version,
					),
					$skills
				),
			)
		);

		return $this->get( $run_id );
	}

	/**
	 * The request-scoped pack registry (S9). Boots from the `specflux_site_agent_packs`
	 * filter; the pages pack registers through it (stage 8).
	 */
	private function packRegistry(): \Specflux\SiteAgent\Packs\PackRegistry {
		return \Specflux\SiteAgent\Packs\PackRegistry::fromFilters();
	}

	/**
	 * Advance one run by at most one model turn.
	 *
	 * @param int                $run_id              Run id.
	 * @param int                $expected_step_count Caller's last-known step_count.
	 * @param array<string,mixed>|null $resume Park resolution (S5): shape must
	 *                                          match the run's park kind, else
	 *                                          `resume_mismatch`. The 0.1
	 *                                          `?string $approval_action`
	 *                                          parameter is REMOVED (breaking,
	 *                                          S5) — a string here is a type
	 *                                          error by contract.
	 * @param string                   $driver  Who is ticking: 'screen' (the
	 *                                          browser, default) or 'background';
	 *                                          recorded on the run row.
	 * @return array<string,mixed>|WP_Error RunState (plus `retry_after` seconds
	 *                                      and `continue`) or a protocol error.
	 */
	public function tick( int $run_id, int $expected_step_count, ?array $resume = null, string $driver = 'screen' ): array|WP_Error {
		if ( ! $this->ready() ) {
			return $this->ungoverned_error();
		}

		// S10: a Tier-2 call parked inside this tick renders an approval row
		// naming the run that drafted the page. That provenance is read from
		// the RUN ROW here, never from the tool call's arguments — and the
		// scope is one tick, because several ticks share one PHP process under
		// PHPUnit, WP-CLI and cron.
		$run = $this->runner()->store()->getRun( $run_id );

		// 0.3 S3: same third-party-consumer refusal as start(), re-checked on
		// every tick — a run's consumer never changes after start(), but a
		// consumer could still poll a run it never started (its own bug, but
		// one that must not resolve a built-in park it cannot answer).
		if ( null !== $run && GateMode::BuiltIn === $run->gateMode && \Specflux\SiteAgent\Admin\RunsScreen::CONSUMER !== $run->consumer ) {
			return $this->ungoverned_error();
		}

		\Specflux\SiteAgent\Packs\Pages\PublishSummary::useRunContext( null !== $run ? $run->goal : null );
		// 0.3 S4: vocabulary-bearing content abilities resolve THIS run's pack
		// for the scope of one tick — never a model-supplied `pack` argument.
		\Specflux\SiteAgent\Packs\Content\Abilities::useRunPack( null !== $run ? $run->pack : null );
		// 0.3 S8: the shared content registrar's stale-write compare reads
		// and updates THIS run's tracker — never a model-supplied run id.
		// Same discipline as useRunPack() above; scoped for one tick only.
		\Specflux\SiteAgent\Packs\Content\Abilities::useRunContext( $run_id, $this->runner()->store() );
		// 0.3 S5: the media registrar's images-budget spend count and
		// attachment cap are both derived from THIS run's row/steps.
		\Specflux\SiteAgent\Packs\Content\Media::useRunContext( $run_id, $this->runner()->store() );
		// 0.3 S7/S8: the site navigation registrar's stale-write compare reads
		// and updates THIS run's tracker — same discipline as Content\Abilities.
		\Specflux\SiteAgent\Packs\Site\Navigation::useRunContext( $run_id, $this->runner()->store() );
		// 0.3 S8: the front-page registrar's own stale-write compare, scoped
		// the same way — its own OBJECT_ID, so it never collides with
		// Navigation's marker on the same run.
		\Specflux\SiteAgent\Packs\Site\FrontPage::useRunContext( $run_id, $this->runner()->store() );
		// 0.3 quality feature 5: the style registrar's own stale-write
		// compare, scoped the same way — its own OBJECT_ID.
		\Specflux\SiteAgent\Packs\Site\Style::useRunContext( $run_id, $this->runner()->store() );
		// S19: the commerce polyfills' own stale-write compare (coupon-enable
		// only — see the class docblock), scoped the same way.
		\Specflux\SiteAgent\Packs\Commerce\Abilities::useRunContext( $run_id, $this->runner()->store() );

		// C4-0: a commerce run answers for the store platform it pinned at start.
		$commerce = null !== $run ? self::pack_for_run( $run ) : null;
		if ( null !== $commerce && null !== $run?->storePlatform ) {
			$commerce->useRunPlatform( $run->storePlatform );
		}

		try {
			$result = $this->runner()->tick( $run_id, $expected_step_count, $resume, $driver );

			// 0.4 U2 hand-off: the ONE place every tick (ajax, REST and the
			// background driver itself) passes through. A tick that leaves the
			// run still running, or hold-parked, queues the next background
			// tick; polls reach the queue only through here.
			$this->background()->handOff( $run_id, $result, $driver );
			// 0.4 U3: and the one place a background tick's new park is mailed.
			( new \Specflux\SiteAgent\Background\ParkNotifier( $this->runner()->store() ) )->afterTick( $run_id, $result, $driver );

			return $result;
		} finally {
			\Specflux\SiteAgent\Packs\Pages\PublishSummary::forgetRunContext();
			\Specflux\SiteAgent\Packs\Content\Abilities::forgetRunPack();
			\Specflux\SiteAgent\Packs\Content\Abilities::forgetRunContext();
			\Specflux\SiteAgent\Packs\Content\Media::forgetRunContext();
			\Specflux\SiteAgent\Packs\Site\Navigation::forgetRunContext();
			\Specflux\SiteAgent\Packs\Site\FrontPage::forgetRunContext();
			\Specflux\SiteAgent\Packs\Site\Style::forgetRunContext();
			\Specflux\SiteAgent\Packs\Commerce\Abilities::forgetRunContext();
			if ( null !== $commerce ) {
				$commerce->useRunPlatform( null );
			}
		}
	}

	/**
	 * Cancel an owned, non-terminal run.
	 *
	 * @param int $run_id Run id.
	 * @return array<string,mixed>|WP_Error Fresh RunState or an error.
	 */
	public function cancel( int $run_id ): array|WP_Error {
		if ( ! $this->ready() ) {
			return $this->ungoverned_error();
		}

		$store = $this->runner()->store();
		$run   = $store->getRun( $run_id );
		if ( null === $run ) {
			return new WP_Error( 'specflux_site_agent_not_found', __( 'Run not found.', 'specflux-site-agent' ), array( 'status' => 404 ) );
		}
		if ( ! $this->maySee( $run ) ) {
			return new WP_Error( 'specflux_site_agent_forbidden', __( 'This run belongs to another user.', 'specflux-site-agent' ), array( 'status' => 403 ) );
		}
		if ( $run->status->isTerminal() ) {
			return $this->get( $run_id ); // Already finished: state unchanged.
		}

		// 0.3 S14: the same legacy-run check tick() runs, at the top of
		// cancel too — a 0.2 run gets one consistent terminal outcome
		// (started_under_0_2) whichever surface touches it, never a plain
		// cancel.
		if ( null !== $this->runner()->legacyRunRefusal( $run ) ) {
			return $this->get( $run_id );
		}

		// 0.3 S3: the same mismatch check tick() runs, at the top of cancel
		// too — a run whose gate mode no longer matches the environment fails
		// with a partial report (gate_mode_changed) instead of a plain cancel.
		if ( null !== $this->runner()->gateModeMismatch( $run ) ) {
			return $this->get( $run_id );
		}

		// C4-0: and for a pinned store platform that has vanished.
		if ( null !== $this->runner()->storePlatformMismatch( $run ) ) {
			return $this->get( $run_id );
		}

		$store->updateRun(
			$run_id,
			array(
				'status'      => RunStatus::Cancelled->value,
				'finished_at' => gmdate( 'Y-m-d H:i:s' ),
			)
		);

		// S12: cancel is a terminal transition — build + persist a partial
		// report. The Runner never sees the cancel, so call into it directly.
		// S14: and for the same reason, this is where a cancelled run's
		// pre-approval grants are withdrawn.
		$this->runner()->revokeGrants( $run_id );
		$this->runner()->report( $run_id );

		return $this->get( $run_id );
	}

	/**
	 * Save or dismiss a brief suggestion (0.3 S20).
	 *
	 * Capability + nonce are the CALLER's job (REST/admin-post): this is the
	 * ONE place the decision itself is carried out, so both surfaces share
	 * one implementation of "already resolved" and the brief's own cap.
	 *
	 * @param int         $run_id Run id.
	 * @param int         $seq    The suggestion step's seq.
	 * @param string      $action 'save' | 'dismiss'.
	 * @param string|null $text   Optional edited text.
	 * @return array<string,mixed>|WP_Error
	 */
	public function resolveSuggestion( int $run_id, int $seq, string $action, ?string $text = null ): array|WP_Error {
		if ( ! $this->ready() ) {
			return $this->ungoverned_error();
		}

		return \Specflux\SiteAgent\Run\SuggestionResolver::resolve( $this->runner()->store(), $run_id, $seq, $action, $text );
	}

	/**
	 * Read one run's current state (no lock, no model calls).
	 *
	 * @param int $run_id Run id.
	 * @return array<string,mixed>|WP_Error
	 */
	public function get( int $run_id ): array|WP_Error {
		if ( ! $this->ready() ) {
			return $this->ungoverned_error();
		}

		$store = $this->runner()->store();
		$run   = $store->getRun( $run_id );
		if ( null === $run ) {
			return new WP_Error( 'specflux_site_agent_not_found', __( 'Run not found.', 'specflux-site-agent' ), array( 'status' => 404 ) );
		}
		if ( ! $this->maySee( $run ) ) {
			return new WP_Error( 'specflux_site_agent_forbidden', __( 'This run belongs to another user.', 'specflux-site-agent' ), array( 'status' => 403 ) );
		}

		$steps       = array();
		$suggestions = array();
		$resolutions = array();
		$presenter   = new ResultPresenter();
		foreach ( $store->getSteps( $run_id ) as $step ) {
			$steps[] = array(
				'seq'         => $step->seq,
				'kind'        => $step->kind->value,
				'message'     => $step->messageArray,
				'tool_name'   => $step->toolName,
				'approval_id' => $step->approvalId,
				'status'      => $step->status,
				'tokens_in'   => $step->tokensIn,
				'tokens_out'  => $step->tokensOut,
				'duration_ms' => $step->durationMs,
				// 0.3 S23: additive. The tier the gate classified for a
				// tool_result or approval step (0/1/2), lifted out of the
				// step's own message_json ({@see Runner::appendToolResult()},
				// {@see Runner::appendApprovalStep()}) so the Runs screen can
				// show a tier badge on any ledger row without knowing the
				// per-kind message shape. Null when the step has no mapped
				// tier (harness-internal tool results, questions, plans).
				'tier'        => is_array( $step->messageArray ) && array_key_exists( 'tier', $step->messageArray )
					? $step->messageArray['tier']
					: null,
				// 0.4 S1: additive. The card the Runs screen renders for a
				// tool_result, computed here at read time from the stored
				// response (no schema change); null for every other kind.
				'display'     => StepKind::ToolResult === $step->kind
					? $presenter->present( (string) $step->toolName, self::toolResponse( $step->messageArray ), $step->status )
					: null,
			);

			// 0.3 S20: brief suggestions, listed with their resolution (a
			// later `suggestion_resolved` system note, never a rewrite of
			// the suggestion step itself).
			if ( StepKind::Suggestion === $step->kind && is_array( $step->messageArray ) ) {
				$suggestions[ $step->seq ] = array(
					'seq'    => $step->seq,
					'text'   => (string) ( $step->messageArray['text'] ?? '' ),
					'status' => 'pending',
				);
			}
			if ( StepKind::System === $step->kind && is_array( $step->messageArray )
				&& 'suggestion_resolved' === ( $step->messageArray['note'] ?? '' )
			) {
				$resolutions[ (int) ( $step->messageArray['suggestion_seq'] ?? -1 ) ] = $step->messageArray;
			}
		}
		foreach ( $resolutions as $seq => $resolution ) {
			if ( isset( $suggestions[ $seq ] ) ) {
				$suggestions[ $seq ]['status'] = (string) ( $resolution['action'] ?? '' ) === 'save' ? 'saved' : 'dismissed';
				$suggestions[ $seq ]['text']   = (string) ( $resolution['text'] ?? $suggestions[ $seq ]['text'] );
			}
		}

		$plan_ui     = $this->runner()->parkedPlanUi( $run );
		$approval_ui = $this->runner()->parkedApprovalUi( $run );
		// 0.4 S3.2: the report's Changes area, built at read time. Null for a
		// live run, or a viewer without the run's capability.
		$changes_section = ( new \Specflux\SiteAgent\Approval\ChangesBridge() )->section( $run );
		$hold_ui         = $this->runner()->parkedHoldUi( $run );

		return array(
			'run'         => array(
				'id'                  => $run->id,
				'user_id'             => $run->userId,
				'consumer'            => $run->consumer,
				'goal'                => $run->goal,
				'status'              => $run->status->value,
				'allow'               => $run->allow,
				'budget'              => $run->budget,
				'step_count'          => $run->stepCount,
				'tokens_in'           => $run->tokensIn,
				'tokens_out'          => $run->tokensOut,
				'error'               => $run->error,
				// 0.2 S4/S17: the pack name and the two captured locales ride
				// on every read; remaining/skills/report land with the
				// features that fill them (S8, S12, S17).
				'pack'                => $run->pack,
				// 0.3 S3: pinned at start(), rendered once by the run header.
				'gate_mode'           => $run->gateMode->value,
				// 0.3 S6: pinned at start(), rendered once by the run header.
				'withheld_roles'      => $run->withheldRoles,
				// Stage 22b (S6): the pack's own one-line notice for those
				// roles — the run view shows it at start. Null when nothing
				// is withheld (or the pack has nothing to say).
				'withheld_notice'     => $this->withheldNoticeFor( $run ),
				// 0.3 S20: the source run id when this run is a follow-up.
				'follow_up_of'        => $run->followUpOf,
				// Pinned at start(), rendered once by the run header.
				// Null means automatic selection.
				'model'               => null !== $run->modelProvider && null !== $run->modelId
					? array(
						'provider' => $run->modelProvider,
						'id'       => $run->modelId,
					)
					: null,
				'conversation_locale' => $run->conversationLocale,
				'content_locale'      => $run->contentLocale,
				// 0.2 S12: the harness-built report (result_json), surfaced on
				// every read so a terminal run carries its changes list.
				'report'              => $run->result,
			) + ( null !== $changes_section ? array( 'changes_section' => $changes_section ) : array() ),
			'steps'       => $steps,
			// 0.3 S20: keyed by seq in $suggestions above; re-indexed for the caller.
			'suggestions' => array_values( $suggestions ),
			// A run parked on a plan carries the plan card's UI facts (e.g.
			// `preapprove_available`), fresh, so the card survives a reload.
			// Likewise a run parked on an approval carries the pack's summary.
			'ui'          => array_filter(
				array(
					'plan'     => $plan_ui,
					'approval' => $approval_ui,
					'hold'     => $hold_ui,
				),
				static fn ( ?array $facts ): bool => null !== $facts
			),
		);
	}

	/**
	 * Preview or perform an undo of one run's recorded changes (0.4 S3.2).
	 *
	 * A human click only: the REST route proves the session and nonce, and
	 * Belayline Agent Governance re-checks the actor. Never reachable as a model tool.
	 *
	 * @param int      $run_id    Run id.
	 * @param int|null $change_id One change of the run, or null for the whole run.
	 * @param bool     $preview   True to write nothing and report what would happen.
	 * @return array<string,mixed>|WP_Error
	 */
	public function undoRun( int $run_id, ?int $change_id, bool $preview ): array|WP_Error {
		if ( ! $this->ready() ) {
			return $this->ungoverned_error();
		}

		$run = $this->runner()->store()->getRun( $run_id );
		if ( null === $run ) {
			return new WP_Error( 'specflux_site_agent_not_found', __( 'Run not found.', 'specflux-site-agent' ), array( 'status' => 404 ) );
		}
		if ( ! $this->maySee( $run ) ) {
			return new WP_Error( 'specflux_site_agent_forbidden', __( 'This run belongs to another user.', 'specflux-site-agent' ), array( 'status' => 403 ) );
		}

		$bridge = new \Specflux\SiteAgent\Approval\ChangesBridge();

		return $preview
			? $bridge->preview( $run, $change_id )
			: $bridge->undo( $run, $change_id, (int) get_current_user_id() );
	}

	/**
	 * Most recent runs the viewer may see.
	 *
	 * 0.3 S10 (owner decision, 2026-09-20): scoped to {@see maySee()} OR the
	 * delegation seam. Until 0.3 this returned every run on the site, which
	 * was looser than {@see get()} on the same run: the list showed a goal
	 * whose detail the same viewer was refused with `specflux_site_agent_forbidden`.
	 * The screen capability is a PACK RUN capability (S10) — `edit_pages` is
	 * enough to open it — so the old behaviour showed one author's run goals
	 * to every other author. The drivable clause is kept because S13
	 * deliberately lets a screen-capability holder resolve a parked run they
	 * do not own; dropping it would break that.
	 *
	 * Scoping happens in the query: a viewer without the screen-wide
	 * capability gets their own runs from an owner-filtered SELECT, so other
	 * users' newer runs cannot use up the LIMIT or reach this method at all.
	 * Only when something hooks `specflux_site_agent_can_tick` are other
	 * users' rows read, to honour that delegation.
	 *
	 * @param int $limit Max rows returned.
	 * @return list<array<string,mixed>> Lightweight run summaries.
	 */
	public function listRecent( int $limit = 50 ): array {
		if ( ! $this->ready() ) {
			return array();
		}

		$viewer_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		$store     = $this->runner()->store();

		if ( $this->holdsRunsCapability() ) {
			$visible = $store->listRecent( $limit );
		} else {
			$visible = $viewer_id > 0 ? $store->listRecentForUser( $viewer_id, $limit ) : array();

			if ( has_filter( 'specflux_site_agent_can_tick' ) ) {
				$delegated = array_filter(
					$store->listRecent( $limit ),
					static fn ( \Specflux\SiteAgent\Run\Run $run ): bool => $run->userId !== $viewer_id
						&& (bool) apply_filters( 'specflux_site_agent_can_tick', false, $run )
				);

				$visible = array_merge( $visible, $delegated );
				usort(
					$visible,
					static fn ( \Specflux\SiteAgent\Run\Run $a, \Specflux\SiteAgent\Run\Run $b ): int => array( $b->updatedAtUtc ?? '', $b->id ) <=> array( $a->updatedAtUtc ?? '', $a->id )
				);
				$visible = array_slice( $visible, 0, max( 1, $limit ) );
			}
		}

		return array_map(
			function ( \Specflux\SiteAgent\Run\Run $run ) use ( $viewer_id ): array {
				return array(
					'id'              => $run->id,
					'user_id'         => $run->userId,
					'consumer'        => $run->consumer,
					'goal'            => $run->goal,
					'status'          => $run->status->value,
					'step_count'      => $run->stepCount,
					'tokens_in'       => $run->tokensIn,
					'tokens_out'      => $run->tokensOut,
					'updated_at'      => $run->updatedAtUtc,
					// 0.3 S20: the Runs list needs both to offer "Follow up"
					// only on an eligible finished run, and to link a
					// follow-up's row back to its source.
					'pack'            => $run->pack,
					'follow_up_of'    => $run->followUpOf,
					// 0.3 S9: "Needs you" reuses the SAME delegation seam the
					// Runner itself gates ticking on — never a re-derived rule
					// that could drift from it.
					'viewer_may_tick' => (bool) apply_filters( 'specflux_site_agent_can_tick', $viewer_id === $run->userId, $run ),
					// 0.3 S9: a `running` run with no live tick claim (the SAME
					// lock_until Runner::tick() itself sets/releases) is stalled
					// — its owner closed the tab mid-loop.
					'stalled'         => RunStatus::Running === $run->status
						&& ( null === $run->lockUntilUtc || $run->lockUntilUtc < gmdate( 'Y-m-d H:i:s', Clock::now() ) ),
				);
			},
			array_values( $visible )
		);
	}

	// ------------------------------------------------------------------
	// Internals
	// ------------------------------------------------------------------

	/**
	 * The FunctionResponse's response out of a tool_result message.
	 *
	 * @param array<string,mixed>|null $message Stored message_json.
	 * @return array<string,mixed>
	 */
	private static function toolResponse( ?array $message ): array {
		$response = $message['parts'][0]['functionResponse']['response'] ?? null;

		return is_array( $response ) ? $response : array();
	}

	/**
	 * Is the plugin backed by a database handle and the Runner class loaded?
	 *
	 * 0.3 S3: this used to also require {@see available()} (Agent Safety
	 * present) — Specflux Site Agent's hard dependency on Agent Safety is retired.
	 * Agent Safety's absence now only decides the gate mode a run starts in
	 * ({@see GateMode::BuiltIn}), never whether the plugin may run at all.
	 */
	private function ready(): bool {
		global $wpdb;

		return isset( $wpdb ) && class_exists( Runner::class );
	}

	/**
	 * May the current user SEE (and cancel) this run? S13: the run's owner,
	 * or a holder of the Runs-screen capability — the screen must render and
	 * act on delegated runs without impersonating the owner.
	 */
	private function maySee( \Specflux\SiteAgent\Run\Run $run ): bool {
		if ( (int) get_current_user_id() === $run->userId ) {
			return true;
		}

		return $this->holdsRunsCapability();
	}

	/** Does the viewer hold the screen-wide capability that sees every run? */
	private function holdsRunsCapability(): bool {
		/** Filters the capability required to see a run. `@internal`. */
		$capability = apply_filters( 'specflux_site_agent_runs_capability', 'manage_options' );

		return function_exists( 'current_user_can' ) && current_user_can( (string) $capability );
	}

	/**
	 * The pack's own notice for a run's withheld roles (S6), or null.
	 */
	private function withheldNoticeFor( \Specflux\SiteAgent\Run\Run $run ): ?string {
		if ( null === $run->pack || array() === $run->withheldRoles ) {
			return null;
		}

		$pack = $this->packRegistry()->get( $run->pack );

		return null !== $pack ? $pack->withheldRoleNotice( $run->withheldRoles ) : null;
	}

	/**
	 * The `specflux_site_agent_ungoverned` (409) error every entry point returns when
	 * this specific request cannot be governed at all — fail closed, never
	 * half-run. 0.3 S3 narrows WHEN this fires to two cases: the plugin isn't
	 * backed by a database/Runner ({@see ready()}), or a third-party consumer
	 * tried to start or tick a built-in-mode run (its approval park can only
	 * be resolved on Specflux Site Agent's own Runs screen).
	 */
	private function ungoverned_error(): WP_Error {
		return new WP_Error(
			'specflux_site_agent_ungoverned',
			__( 'Specflux Site Agent cannot govern this run: either it is not fully installed, or a built-in-mode run may only be driven from the Specflux Site Agent Runs screen.', 'specflux-site-agent' ),
			array( 'status' => 409 )
		);
	}

	/** Belayline approval events, built lazily over the shared runner and driver. */
	private function approvalEvents(): \Specflux\SiteAgent\Background\ApprovalEvents {
		return new \Specflux\SiteAgent\Background\ApprovalEvents( $this->runner()->store(), $this->runner(), $this->background() );
	}

	/** The background driver, built lazily over the shared runner. */
	private function background(): \Specflux\SiteAgent\Background\BackgroundDriver {
		if ( null === $this->background ) {
			$this->background = new \Specflux\SiteAgent\Background\BackgroundDriver(
				$this->runner()->store(),
				$this->runner(),
				fn ( int $run_id, int $step_count, string $driver, ?array $resume = null ): array|WP_Error => $this->tick( $run_id, $step_count, $resume, $driver )
			);
		}

		return $this->background;
	}

	/**
	 * Build the runner graph lazily (per request). The gateway is filterable
	 * so hosts/tests can swap the model seam wholesale.
	 */
	private function runner(): Runner {
		if ( null !== $this->runner ) {
			return $this->runner;
		}

		global $wpdb;

		/** Filters the model gateway class. `@internal`. */
		$gateway_class = apply_filters( 'specflux_site_agent_model_gateway', AiClientGateway::class );
		if ( ! is_string( $gateway_class ) || ! class_exists( $gateway_class ) ) {
			$gateway_class = AiClientGateway::class;
		}

		/** @var ModelGatewayInterface $gateway */
		$gateway = new $gateway_class();

		$this->runner = new Runner(
			new WpdbRunStore( $wpdb ),
			new ToolExecutor(),
			$gateway,
			new ApprovalBridge(),
			// S12 (defect fix): a report row for a type-qualified id (an
			// attachment, a term, a coupon…) is resolved through the pack's
			// OWN lookup; every other id falls back to the post/page lookup.
			// The composition root is the one place allowed to know all of
			// them.
			static fn ( string|int $object_id ): array => ObjectLookup::resolve( $object_id ),
			// S9: a run started with a pack is fenced/annotated by the PACK's
			// verb map; direct-allow runs keep the site-wide filter seam. The
			// composition root is the one place that may reference Packs.
			static function ( \Specflux\SiteAgent\Run\Run $run ): ?array {
				$pack = self::pack_for_run( $run );

				// 0.4 S6: exposed passthrough abilities are tiered (Tier 2
				// unless built-in Read) and become known verbs. The pack's
				// own entries win any collision.
				return null !== $pack ? $pack->verbMap() + \Specflux\SiteAgent\Passthrough\Passthrough::tierMap( $run->gateMode ) : null;
			},
			// S7/S10: the fence tiers a call by its PACK VERB, not by the
			// ability that carries it — `specflux/publish-post` is
			// `pages/update-live` or `pages/publish` depending on the args,
			// and only the pack can tell them apart. A direct-allow run has no
			// pack, so its verb stays the ability id (S9).
			static function ( \Specflux\SiteAgent\Run\Run $run, string $ability, array $args ): string {
				$pack = self::pack_for_run( $run );

				// 0.4 S6: a passthrough ability's verb is its own name; no
				// pack knows it.
				if ( null !== $pack && array_key_exists( $ability, \Specflux\SiteAgent\Passthrough\Passthrough::tierMap( $run->gateMode ) ) ) {
					return $ability;
				}

				return null !== $pack ? $pack->verbFor( $ability, $args ) : $ability;
			},
			// S8: the pack whose skills ride every tick's instruction. The
			// Runner passes it straight to SkillSet without knowing the type —
			// the pack seam stays a composition-root concern.
			static fn ( \Specflux\SiteAgent\Run\Run $run ): ?\Specflux\SiteAgent\Packs\Pack => self::pack_for_run( $run ),
			// S12: which argument carries an object's id is the pack's
			// knowledge; the harness only knows the id is opaque.
			static function ( \Specflux\SiteAgent\Run\Run $run, string $verb ): string {
				$pack = self::pack_for_run( $run );

				return null !== $pack ? $pack->objectIdKey( $verb ) : 'id';
			},
			new \Specflux\SiteAgent\Approval\GrantBridge(),
			// S14: a grant is issued against the verb AGENT SAFETY sees — the
			// resolved ability id — never the pack verb a plan step names.
			// Only the pack can map one to the other; a direct-allow run has
			// no pack and its verbs already are ability ids.
			static function ( \Specflux\SiteAgent\Run\Run $run, string $pack_verb ): ?string {
				$pack = self::pack_for_run( $run );

				return null !== $pack ? $pack->gateVerbFor( $pack_verb ) : $pack_verb;
			},
			// 0.3 S3: the environment's CURRENT gate mode, asked fresh at the
			// top of every tick/cancel/park resolution and compared with the
			// one pinned on the run at start() -- a mismatch fails the run
			// (gate_mode_changed) instead of silently switching enforcement.
			static fn (): GateMode => self::currentGateMode(),
			// S6: withheld roles' RESOLVED abilities, for the execution-time
			// defence in depth — only the pack can map a role name back to
			// its ability id.
			static function ( \Specflux\SiteAgent\Run\Run $run ): array {
				$pack = self::pack_for_run( $run );
				if ( null === $pack || array() === $run->withheldRoles ) {
					return array();
				}

				$resolved  = $pack->resolveAbilities();
				$abilities = array();
				foreach ( $run->withheldRoles as $role ) {
					if ( isset( $resolved[ $role ] ) ) {
						$abilities[] = $resolved[ $role ];
					}
				}

				return $abilities;
			},
			// S19: the accepted plan's ungrantable pack verbs — only the pack
			// knows which of its own verbs must ask every time rather than be
			// pre-approved; a direct-allow run has no pack and nothing is
			// ungrantable there.
			static function ( \Specflux\SiteAgent\Run\Run $run ): array {
				$pack = self::pack_for_run( $run );

				return null !== $pack ? $pack->ungrantableVerbs() : array();
			},
			// S12 (defect fix): the per-verb id PREFIX ({@see Pack::objectIdPrefix()})
			// — only the pack knows which of its verbs share an id space with
			// another object kind; a direct-allow run has no pack and needs
			// no prefix.
			static function ( \Specflux\SiteAgent\Run\Run $run, string $verb ): string {
				$pack = self::pack_for_run( $run );

				return null !== $pack ? $pack->objectIdPrefix( $verb ) : '';
			},
			// S12 (defect fix, live run 56): the id a Tier >= 1 write just
			// wrote, for a pack verb whose ability output carries no id of
			// its own (the site pack's navigation/front-page singletons) —
			// only the pack knows the verb writes a fixed object; a
			// direct-allow run has no pack and no opinion.
			static function ( \Specflux\SiteAgent\Run\Run $run, string $verb, array $args, array $output ): ?string {
				$pack = self::pack_for_run( $run );

				return null !== $pack ? $pack->objectIdForWrite( $verb, $args, $output ) : null;
			},
			// 0.3 S14: the legacy-run watermark {@see \Specflux\SiteAgent\Schema::maybe_upgrade()}
			// recorded at the 0.2 -> 0.3 upgrade, or null when the option was
			// never written (a fresh install, or a site with no live 0.2 run).
			static function (): ?int {
				$value = get_option( 'specflux_site_agent_legacy_run_watermark', false );

				return is_numeric( $value ) ? (int) $value : null;
			},
			// The read counterpart of the write-id resolver above: a
			// singleton read takes no id argument, so only the pack can say
			// which object it read.
			static function ( \Specflux\SiteAgent\Run\Run $run, string $verb, array $args ): ?string {
				$pack = self::pack_for_run( $run );

				return null !== $pack ? $pack->objectIdForRead( $verb, $args ) : null;
			},
			// S23 (SPEC-SITE-AGENT-PRO.md §5 F4): only the pack knows its own
			// guidance-hash, if any; a direct-allow run has no pack.
			static function ( \Specflux\SiteAgent\Run\Run $run ): ?string {
				$pack = self::pack_for_run( $run );

				return null !== $pack ? $pack->guidesHash() : null;
			},
			// C4-0: is the store platform a commerce run pinned still usable?
			static fn ( string $platform_id ): bool => \Specflux\SiteAgent\Packs\Commerce\Platforms\StorePlatforms::usable( $platform_id )
		);

		return $this->runner;
	}

	/**
	 * 0.3 S6: the role names a pack withholds from a starting user — those
	 * whose declared {@see \Specflux\SiteAgent\Packs\Pack::roleCapabilities()}
	 * capability `$user_id` lacks. A role the pack declares no capability for
	 * is never withheld (the base's empty map means "nothing to check").
	 *
	 * @param \Specflux\SiteAgent\Packs\Pack $pack    The pack a run is starting with.
	 * @param int                            $user_id The starting user.
	 * @return list<string>
	 */
	private static function withheldRolesFor( \Specflux\SiteAgent\Packs\Pack $pack, int $user_id ): array {
		$withheld = array();
		foreach ( $pack->roleCapabilities() as $role => $capability ) {
			if ( '' === $capability ) {
				continue;
			}
			if ( ! function_exists( 'user_can' ) || ! user_can( $user_id, $capability ) ) {
				$withheld[] = $role;
			}
		}

		return $withheld;
	}

	/**
	 * The pack a run was started with, or null for a direct-allow run (and for
	 * a pack name no longer registered — fail closed to the ability-name verb
	 * space, where nothing is mapped and the fence treats every call as tier 2).
	 *
	 * @param \Specflux\SiteAgent\Run\Run $run The run.
	 */
	private static function pack_for_run( \Specflux\SiteAgent\Run\Run $run ): ?\Specflux\SiteAgent\Packs\Pack {
		if ( null === $run->pack || '' === $run->pack ) {
			return null;
		}

		return \Specflux\SiteAgent\Packs\PackRegistry::fromFilters()->get( $run->pack );
	}

	/**
	 * Does Agent Safety's gate class exist right now? Overridable in tests
	 * because PHP can never undefine a real class mid-process.
	 */
	private function dependency_present(): bool {
		if ( null !== self::$dependency_probe ) {
			return self::$dependency_probe;
		}

		return class_exists( self::AGENT_SAFETY_MARKER );
	}
}
