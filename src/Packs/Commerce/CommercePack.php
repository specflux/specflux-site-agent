<?php
/**
 * The commerce capability pack — catalogue (S19, stage 12: D1 add product,
 * D2 bulk price, D3 descriptions, D5 coupons) AND operations (S19, stage 13:
 * D4 refund + customer note, D6 store health report, D7 shipping/tax).
 *
 * TARGET REPO PATH: src/Packs/Commerce/CommercePack.php
 *
 * `abilityNamespaces()` = `['woocommerce/', 'senroflux/']` (S19): a role
 * resolves to Woo's own session ability when it is registered and
 * shape-compatible, else this pack's own polyfill — the pattern S9
 * generalised, applied here to a THIRD-PARTY namespace for the first time.
 *
 * `requiresAgentSafety()` is true (S19): the commerce pack is USELESS
 * without Agent Safety, because it governs only `senroflux/*`
 * ({@see governedNamespaces()}, unchanged base default) — Woo's own session
 * abilities are governed by Agent Safety's Woo integration module, which
 * does not exist in built-in-gate mode at all. Registered whenever
 * WooCommerce is active (composition root), independent of Agent Safety, so
 * its blocking setup check can still show and explain why Start is dead.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Packs\Commerce;

use Specflux\SenroFlux\Packs\Commerce\Platforms\PlatformSelection;
use Specflux\SenroFlux\Packs\Commerce\Platforms\StorePlatform;
use Specflux\SenroFlux\Packs\Commerce\Platforms\StorePlatforms;
use Specflux\SenroFlux\Packs\Commerce\Platforms\Woo\WooPlatform;
use Specflux\SenroFlux\Packs\Content\Media;
use Specflux\SenroFlux\Packs\Pack;
use Specflux\SenroFlux\Plugin;
use Specflux\SenroFlux\Run\GateMode;
use Specflux\SenroFlux\Setup\SetupCheck;
use Specflux\SenroFlux\Skills\Skill;
use Specflux\SenroFlux\Skills\SkillSource;
use WP_Error;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * The commerce pack.
 */
final class CommercePack extends Pack {

	/** The platform id a run pinned (set per tick by the composition root), or null. */
	private ?string $run_platform_id = null;

	public function name(): string {
		return 'commerce';
	}

	/** The store platform a new run pins: the selected one, or null when none is usable. */
	public function pinForRun(): ?string {
		return StorePlatforms::select()->platform?->id();
	}

	/**
	 * Scope this pack to the platform a run pinned for the length of one tick
	 * (null leaves it). Changing it drops the per-request resolved-ability cache.
	 *
	 * @param string|null $platform_id The run's pinned platform id.
	 */
	public function useRunPlatform( ?string $platform_id ): void {
		if ( $platform_id !== $this->run_platform_id ) {
			$this->run_platform_id = $platform_id;
			$this->forgetResolved();
		}
	}

	/**
	 * The platform this pack currently speaks for: the run's pinned platform,
	 * else the setting-or-only-candidate selection, else the first registered
	 * platform (so the static contract — namespaces, tiers, capability — still
	 * answers when none is usable and the setup check is what refuses).
	 */
	public function platform(): StorePlatform {
		return StorePlatforms::resolve( $this->run_platform_id );
	}

	/**
	 * @return array<string,string> The selected platform's role => template map.
	 */
	public function roles(): array {
		return array_map(
			static fn ( string|array $value ): string => StorePlatforms::roleSpec( $value )['polyfill'],
			$this->platform()->roleMap()
		);
	}

	/**
	 * @param string $role     Role.
	 * @param string $template The polyfill template.
	 */
	protected function nativeTemplate( string $role, string $template ): string {
		$map = $this->platform()->roleMap();

		return isset( $map[ $role ] ) ? StorePlatforms::roleSpec( $map[ $role ] )['native'] : $template;
	}

	/**
	 * Apply a role's platform `shape` when the call resolved to the platform's
	 * native ability (never to the `senroflux/` polyfill).
	 *
	 * @param string              $ability The concrete ability id.
	 * @param array<string,mixed> $input   The pack-shaped input.
	 * @return array<string,mixed>
	 */
	public function shapeInput( string $ability, array $input ): array {
		if ( 0 === strpos( $ability, self::POLYFILL_NAMESPACE ) ) {
			return $input;
		}

		$map = $this->platform()->roleMap();
		foreach ( $this->resolveAbilities() as $role => $resolved ) {
			if ( $resolved !== $ability || ! isset( $map[ $role ] ) ) {
				continue;
			}
			$shape = StorePlatforms::roleSpec( $map[ $role ] )['shape'];
			if ( null !== $shape ) {
				$shaped = $shape( $input );

				return is_array( $shaped ) ? $shaped : $input;
			}
		}

		return $input;
	}

	/** @return string The selected platform's run capability (`manage_woocommerce` for Woo). */
	public function runCapability(): string {
		return $this->platform()->runCapability();
	}

	/**
	 * @return list<string> The selected platform's namespaces, ending in the
	 *                       polyfill namespace (required by {@see \Specflux\SenroFlux\Packs\PackRegistry::register()}).
	 */
	public function abilityNamespaces(): array {
		return $this->platform()->abilityNamespaces();
	}

	/** @return bool true — the commerce pack refuses to start without Agent Safety (S19). */
	public function requiresAgentSafety(): bool {
		return true;
	}

	/**
	 * The input-property keys this pack's client sends per ability template
	 * (S9 shape-compat seam), supplied by the platform.
	 *
	 * @param string $template Ability template.
	 * @return list<string>
	 */
	protected function inputProperties( string $template ): array {
		return $this->platform()->inputProperties( $template );
	}

	/**
	 * The S19 verb predicate. Dispatch is on the ability's name SEGMENT, so it
	 * holds whether a role resolved to the platform's own ability or this
	 * pack's fallback name. The platform owns the table.
	 *
	 * @param string              $ability The concrete ability id.
	 * @param array<string,mixed> $input   Call input.
	 */
	public function verbFor( string $ability, array $input ): string {
		return $this->platform()->verbFor( $this->baseName( $ability ), $input ) ?? $ability;
	}

	/**
	 * The full S19 verb => tier table (catalogue plus operations), mirrored
	 * from the selected platform.
	 *
	 * @return array<string,int>
	 */
	public function verbMap(): array {
		return $this->platform()->verbTiers();
	}

	/**
	 * The full S19 role => pack-verb split.
	 *
	 * @return array<string,list<string>>
	 */
	public function roleVerbs(): array {
		$verbs = array(
			'read'            => array( 'commerce/product-read' ),
			'catalogue'       => array( 'commerce/catalogue-read' ),
			'create'          => array( 'commerce/product-create-draft', 'commerce/product-publish' ),
			'update'          => array( 'commerce/product-update', 'commerce/price-change', 'commerce/product-publish' ),
			'image'           => array( 'commerce/product-image' ),
			'search'          => array( 'commerce/media-search' ),
			'image-generate'  => array( 'commerce/image-generate' ),
			'coupon-create'   => array( 'commerce/coupon-draft' ),
			'coupon-enable'   => array( 'commerce/coupon-enable' ),
			'order-read'      => array( 'commerce/order-read' ),
			'order-note'      => array( 'commerce/order-note-private', 'commerce/order-note-customer' ),
			'refund'          => array( 'commerce/refund' ),
			'shipping-write'  => array( 'commerce/shipping-write' ),
			'tax-write'       => array( 'commerce/tax-write' ),
			'store-report'    => array( 'commerce/store-report' ),
			'report-save'     => array( 'commerce/report-save' ),
			'order-status'    => array( 'commerce/order-status', 'commerce/order-status-notify' ),
			'product-trash'   => array( 'commerce/product-trash', 'commerce/product-delete-force' ),
			'stock'           => array( 'commerce/stock-adjust' ),
			'variation-price' => array( 'commerce/price-change' ),
			'sale-schedule'   => array( 'commerce/price-change' ),
		);

		// Roles only some platforms have (C4-S). Listed only when the platform
		// maps them, so a platform without them (WooCommerce: its product-update
		// carries price and status itself) keeps exactly the table above.
		$platform_only = array(
			// A price is its own object on SureCart, not a product field.
			'price'       => array( 'commerce/product-update', 'commerce/price-change' ),
			'status'      => array( 'commerce/product-update', 'commerce/product-publish' ),
			// The coupon's customer-facing code is a second step on SureCart.
			'coupon-code' => array( 'commerce/coupon-draft' ),
		);
		$mapped = $this->platform()->roleMap();
		foreach ( $platform_only as $role => $role_verbs ) {
			if ( isset( $mapped[ $role ] ) ) {
				$verbs[ $role ] = $role_verbs;
			}
		}

		// Only the roles this platform has: a role it does not map would be
		// grantable and resolve to an ability nobody registers.
		$ordered = array();
		foreach ( array_keys( $mapped ) as $role ) {
			if ( isset( $verbs[ $role ] ) ) {
				$ordered[ $role ] = $verbs[ $role ];
			}
		}

		return $ordered;
	}

	/**
	 * The id an object a Tier >= 1 verb wrote carries in its ability output;
	 * the output shapes are the platform's.
	 *
	 * @param string              $verb   The pack verb.
	 * @param array<string,mixed> $args   The call's args.
	 * @param array<string,mixed> $output The call's output.
	 */
	public function objectIdForWrite( string $verb, array $args, array $output ): ?string {
		return $this->platform()->objectIdForWrite( $verb, $args, $output );
	}

	/**
	 * Products keep their bare post id; every other kind a commerce run
	 * writes is qualified so it cannot collide with a product, and so the
	 * report can resolve it ({@see \Specflux\SenroFlux\Packs\ObjectLookup}).
	 * `order-read` carries the order prefix too, which is what makes an
	 * order a verifiable write — nothing reads a coupon, zone, tax rate,
	 * saved report page or generated image back.
	 *
	 * @param string $verb The pack verb.
	 */
	public function objectIdPrefix( string $verb ): string {
		return match ( $verb ) {
			'commerce/image-generate' => Media::OBJECT_ID_PREFIX,
			'commerce/coupon-draft',
			'commerce/coupon-enable' => ReportLookup::COUPON_PREFIX,
			'commerce/order-read',
			'commerce/order-note-private',
			'commerce/order-note-customer',
			'commerce/refund',
			'commerce/order-status',
			'commerce/order-status-notify' => ReportLookup::ORDER_PREFIX,
			'commerce/shipping-write' => ReportLookup::ZONE_PREFIX,
			'commerce/tax-write' => ReportLookup::TAX_RATE_PREFIX,
			'commerce/report-save' => ReportLookup::PAGE_PREFIX,
			default => parent::objectIdPrefix( $verb ),
		};
	}

	/**
	 * S19 stage 13: `commerce/order-note-customer` and `commerce/refund` are
	 * NEVER pre-approval-granted, however many times an accepted plan lists
	 * them — they ask a human every single time (the operations skill says
	 * so). Both share Woo-owned/polyfill abilities with a grantable sibling
	 * (`order-add-note` also carries the private-note verb; `orders-refund`
	 * has no grantable sibling but is still named here so the rule reads as
	 * one list rather than one special case), and
	 * {@see \Specflux\SenroFlux\Run\Runner::poisonedGateVerbs()} (S19 stage
	 * 12 guard) already refuses to let a grant issued for the private-note
	 * verb be spent on a customer-visible note instead — see that method's
	 * docblock for why "no grant at all" is the only safe reading for a
	 * shared ability.
	 *
	 * @return list<string>
	 */
	public function ungrantableVerbs(): array {
		return array( 'commerce/order-note-customer', 'commerce/refund' );
	}

	/**
	 * S19: refuse a bad call to a platform-owned ability BEFORE it reaches that
	 * ability's own permission check (the platform decides which abilities and
	 * which rules; for Woo, the description validator on `product-create`/`-update`).
	 *
	 * @param string              $ability The concrete ability id.
	 * @param array<string,mixed> $input   The call input.
	 */
	public function validateCall( string $ability, array $input ): ?WP_Error {
		return $this->platform()->validateCall( $this->baseName( $ability ), $input );
	}

	/**
	 * The two pack skills (S19). `commerce/operations-rules` now DESCRIBES
	 * ENFORCED stage-13 behaviour: refunds/customer notes ask every time
	 * (`ungrantableVerbs()`), the `refunds` budget defaults to one per run,
	 * and the store report never writes.
	 *
	 * @return list<Skill>
	 */
	public function skills( bool $images_available = true ): array {
		unset( $images_available );
		return array(
			new Skill(
				'commerce/catalogue-rules',
				'Catalogue rules',
				$this->catalogueRulesBody(),
				false,
				SkillSource::Pack,
				'2'
			),
			new Skill(
				'commerce/operations-rules',
				'Operations rules',
				$this->operationsRulesBody(),
				false,
				SkillSource::Pack,
				'2'
			),
		);
	}

	private function catalogueRulesBody(): string {
		return implode(
			"\n",
			array(
				'Products, prices and descriptions: a new product is created as a draft unless you are explicitly asked to publish it. Publishing a product, or changing its regular or sale price, always asks a human first.',
				'To work from a product category or find products with no description, call products-catalogue — it shows each product\'s categories and whether it has a description; the other product read cannot.',
				'State every price in the store\'s own currency, with its own number of decimal places — never invent a currency or round differently from what the store already shows.',
				'Product descriptions and short descriptions may use ONLY these HTML tags: p, ul, ol, li, strong, em, a, h3. A description is at most 1,500 words. Anything outside that tag list, or an unsafe link scheme, is refused whole — it is never trimmed or rewritten for you, so write within the rule the first time.',
				'To add or replace a product\'s image, use an existing media library attachment that already has alt text — an attachment with no alt text, or one that is not an image, is refused. A library image\'s attachment id comes from commerce/media-search.',
				'Variable products keep their prices on the variations: change a variation\'s price with variation-price-update (name the parent product_id and the variation_id), never by editing the parent. A sale is a sale price plus optional start and end dates in the store\'s timezone — use sale-schedule with a sale_price below the regular price; an empty sale_price clears it. Both are price changes and ask a human first.',
				'To remove a product, trash it with product-delete — it is always a trash, reversible, and never a permanent delete: a call with force set is refused whole.',
				'Coupons are created as drafts and enabled as a separate step. A coupon code that already exists is refused — check first if you are not sure it is free. Enabling a coupon you created earlier in this same run can fail if the coupon changed since you created it; re-read it before enabling in that case.',
			)
		);
	}

	private function operationsRulesBody(): string {
		return implode(
			"\n",
			array(
				'Refunds and customer-visible order notes ask a human every single time, however a plan was approved — they are never pre-approved in bulk like an ordinary Tier-2 action.',
				'At most one refund per run by default.',
				'Changing an order\'s status with order-update-status asks a human first when the new status emails the customer or moves stock (pending, processing, on-hold, completed, cancelled, refunded); it is never undone by setting the old status back. Give the status without the wc- prefix and read the order first.',
				'Stock changes (stock-adjust) always ask a human: they change what can be sold. Prefer increase/decrease over set when stock moves with orders, state the quantity you expect afterwards, and use stock_status only for a product that does not manage a quantity.',
				'A store report is read-only: producing one never writes anything, and saving it as a page is a separate, explicit step.',
			)
		);
	}

	/**
	 * The S19 setup checks: in built-in-gate mode (Agent Safety absent) the
	 * WHOLE test is the blocking `commerce/agent-safety` check, worded and
	 * linked for someone who can install plugins; in Agent Safety mode the
	 * pack falls back to the base's own capability + binding checks
	 * unchanged.
	 *
	 * @param int $user_id The user the run would be started for.
	 * @return list<\Specflux\SenroFlux\Setup\SetupCheck>
	 */
	public function setupChecks( int $user_id ): array {
		if ( GateMode::BuiltIn === Plugin::currentGateMode() ) {
			return array( $this->agentSafetyCheck() );
		}

		$checks = parent::setupChecks( $user_id );
		if ( PlatformSelection::AMBIGUOUS === StorePlatforms::select()->status ) {
			array_unshift( $checks, $this->platformCheck() );
		}

		return $checks;
	}

	/** The `commerce/platform` check: several governed platforms, none chosen. */
	private function platformCheck(): SetupCheck {
		$message = __( 'More than one store platform is active. Choose which one SenroFlux should use in SenroFlux > Settings.', 'senroflux' );

		return new SetupCheck(
			'commerce/platform',
			SetupCheck::BLOCKING,
			false,
			$message,
			function_exists( 'admin_url' ) ? admin_url( 'admin.php?page=senroflux-settings' ) : null,
			StorePlatforms::CAPABILITY,
			__( 'More than one store platform is active. Ask a site administrator to choose which one SenroFlux should use.', 'senroflux' ),
			'store_platform_unselected'
		);
	}

	/** The `commerce/agent-safety` setup check (S19). */
	private function agentSafetyCheck(): SetupCheck {
		$message = __( 'The commerce pack needs the SenroGate plugin active — without it, WooCommerce writes have no governance to run under.', 'senroflux' );

		return new SetupCheck(
			'commerce/agent-safety',
			SetupCheck::BLOCKING,
			false,
			$message,
			function_exists( 'admin_url' ) ? admin_url( 'plugin-install.php?s=agent-safety&tab=search&type=term' ) : null,
			'install_plugins',
			$message,
			'pack_requires_agent_safety'
		);
	}

	/**
	 * The Agent Safety pack descriptor (S19), registered on
	 * `agent_safety_pack_registry`. Same reading as the pages pack's own
	 * (see {@see \Specflux\SenroFlux\Packs\Pages\PagesPack::agentSafetyPack()}
	 * for the full rationale): `allow` is the RESOLVED ability list, and
	 * every Tier-2 ability is approval-gated.
	 */
	public function agentSafetyPack(): ?object {
		if ( ! class_exists( \Specflux\AgentSafety\Packs\Pack::class ) ) {
			return null;
		}

		return new \Specflux\AgentSafety\Packs\Pack(
			name: 'commerce',
			allow: $this->allowList(),
			approvalByClass: array( 'tier2' => true ),
		);
	}

	/**
	 * S13 real binding check, same shape as the pages pack's (duplicated
	 * rather than shared — the base leaves this abstract per-pack, and no
	 * shared helper exists yet across packs).
	 *
	 * @return WP_Error|null null when bound, else pack_unbound.
	 */
	protected function agentSafetyBindingError( int $user_id ): ?WP_Error {
		if ( ! function_exists( 'agent_safety' ) || null === agent_safety() ) {
			return $this->packUnboundError();
		}

		if ( ! class_exists( \Specflux\AgentSafety\Plugin\Support\PackResolver::class )
			|| ! class_exists( \Specflux\AgentSafety\Packs\Pack::class )
			|| ! class_exists( \Specflux\AgentSafety\Policy\Tier::class )
		) {
			return $this->packUnboundError();
		}

		$resolved = $this->resolveAsPackForUser( $user_id );
		if ( null === $resolved ) {
			return $this->packUnboundError();
		}

		foreach ( $this->allowList() as $ability ) {
			if ( ! $resolved->allows( $ability ) ) {
				return $this->packUnboundError();
			}
		}

		if ( ! $resolved->requiresApproval( \Specflux\AgentSafety\Policy\Tier::Irreversible ) ) {
			return $this->packUnboundError();
		}

		return null;
	}

	/**
	 * Resolve the AS pack for a SPECIFIC user: the first bound token
	 * (`user:N` then `role:<slug>`) resolves to a pack, else the registry
	 * default (fail-closed `default-agent`).
	 *
	 * @return \Specflux\AgentSafety\Packs\Pack|null
	 */
	private function resolveAsPackForUser( int $user_id ): ?\Specflux\AgentSafety\Packs\Pack {
		$resolver = new \Specflux\AgentSafety\Plugin\Support\PackResolver();
		$registry = $resolver->registry();
		$bindings = $registry->bindings();

		foreach ( $this->userTokens( $user_id ) as $token ) {
			if ( isset( $bindings[ $token ] ) ) {
				$pack = $registry->get( $bindings[ $token ] );
				if ( null !== $pack ) {
					return $pack;
				}
			}
		}

		return $registry->resolve( null );
	}

	/**
	 * Identity tokens for a user id, in binding priority order.
	 *
	 * @return list<string>
	 */
	private function userTokens( int $user_id ): array {
		$tokens = array( 'user:' . $user_id );

		if ( function_exists( 'get_userdata' ) ) {
			$user = get_userdata( $user_id );
			if ( $user && is_array( $user->roles ) ) {
				foreach ( $user->roles as $role ) {
					if ( is_string( $role ) && '' !== $role ) {
						$tokens[] = 'role:' . $role;
					}
				}
			}
		}

		return $tokens;
	}

	/** @return WP_Error pack_unbound (400). */
	private function packUnboundError(): WP_Error {
		return new WP_Error(
			'pack_unbound',
			__( 'This pack is not bound to your user. Ask an administrator to bind `user:N` or `role:administrator` to the commerce pack.', 'senroflux' ),
			array( 'status' => 400 )
		);
	}
}
