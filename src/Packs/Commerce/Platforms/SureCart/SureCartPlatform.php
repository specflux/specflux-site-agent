<?php
/**
 * SureCart as a store platform (C4-S).
 *
 * SureCart 4.9.3 registers 54 abilities under `surecart/` (only when the store
 * is connected and its own abilities toggle is on), so most roles are NATIVE.
 * SenroGate's SureCart module owns the tiers; this class mirrors them in pack
 * verbs and a parity test keeps the two honest.
 *
 * Role -> ability:
 * - read/create/update/price/order-read/refund/store-report/catalogue: native.
 * - coupon: TWO native roles. `coupon-create` -> `create-coupon` (a shape
 *   accepts the pack's code/discount vocabulary and emits SureCart's), then
 *   `coupon-code` -> `create-promotion` (the customer-facing code). Native, not
 *   a polyfill, so SenroGate's discount rule sees the real `surecart/create-coupon`.
 * - status: a polyfill only (`senroflux/surecart-product-status`).
 * - image, search, image-generate, order-note, shipping, tax, report-save: none.
 *   SureCart has no ability for them, and its PHP models cannot take a WordPress
 *   attachment (a product image needs a `Media` object uploaded to SureCart's
 *   servers by its JS admin; `Models/Media.php` and `ProductMedia.php` have no
 *   create path from PHP).
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Packs\Commerce\Platforms\SureCart;

use Specflux\SenroFlux\Packs\Commerce\DescriptionValidator;
use Specflux\SenroFlux\Packs\Commerce\Platforms\StorePlatform;
use Specflux\SenroFlux\Plugin;
use Specflux\SenroFlux\Run\GateMode;
use Throwable;
use WP_Error;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * SureCart.
 *
 * @internal
 */
final class SureCartPlatform implements StorePlatform {

	public const ID = 'surecart';

	/** The version whose abilities were read (4.9.3, source, 2026-10-07). SenroGate's floor is the same. */
	public const MIN_VERSION = '4.9.3';

	/** SenroGate's SureCart module; present and available only when it governs `surecart/`. */
	public const GATE_INTEGRATION = 'Specflux\\AgentSafety\\Plugin\\Integrations\\SureCart\\SureCartIntegration';

	/** Mirrors SenroGate's `DiscountElevationRule`. */
	public const PERCENT_THRESHOLD = 50.0;

	public const DEFAULT_AMOUNT_THRESHOLD = 10000;

	public const AMOUNT_THRESHOLD_FILTER = 'agent_safety_surecart_discount_amount_threshold';

	/** Currencies with no minor unit, so an amount is already whole. */
	private const ZERO_DECIMAL = array( 'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF' );

	/** @var callable|null Test seam: returns any of `version`, `connected`, `enabled`, `api`, `gated`. */
	private $env;

	/**
	 * @param callable|null $env Test seam over live detection; keys it omits stay live.
	 */
	public function __construct( ?callable $env = null ) {
		$this->env = $env;
	}

	public function id(): string {
		return self::ID;
	}

	public function label(): string {
		return 'SureCart';
	}

	public function isActive(): bool {
		return null === $this->inactiveReason();
	}

	/**
	 * `not_installed`, `below_min_version`, then the two SureCart switches that
	 * decide whether its abilities exist at all: `abilities_api_missing`,
	 * `store_not_connected`, `abilities_disabled`. Null while usable.
	 */
	public function inactiveReason(): ?string {
		$version = $this->probe( 'version', fn (): ?string => $this->liveVersion() );
		if ( null === $version ) {
			return 'not_installed';
		}
		// An unreadable version ('') counts as too old: fail closed.
		if ( ! is_string( $version ) || 1 !== preg_match( '/^\d+(\.\d+)*/', $version ) || version_compare( $version, self::MIN_VERSION, '<' ) ) {
			return 'below_min_version';
		}
		if ( true !== $this->probe( 'api', static fn (): bool => function_exists( 'wp_register_ability_category' ) ) ) {
			return 'abilities_api_missing';
		}
		if ( true !== $this->probe( 'connected', fn (): bool => $this->storeConnected() ) ) {
			return 'store_not_connected';
		}
		if ( true !== $this->probe( 'enabled', fn (): bool => $this->abilitiesEnabled() ) ) {
			return 'abilities_disabled';
		}

		return null;
	}

	/** What to tell a human who cannot start a SureCart run, per {@see inactiveReason()}. */
	public function inactiveMessage(): ?string {
		return match ( $this->inactiveReason() ) {
			'not_installed' => __( 'SureCart is not installed.', 'senroflux' ),
			'below_min_version' => sprintf(
				/* translators: %s: minimum SureCart version. */
				__( 'SenroFlux needs SureCart %s or newer.', 'senroflux' ),
				self::MIN_VERSION
			),
			'abilities_api_missing' => __( 'SureCart abilities need WordPress 6.9 or newer.', 'senroflux' ),
			'store_not_connected' => __( 'Connect your SureCart store first: SureCart registers no abilities until the store is connected.', 'senroflux' ),
			'abilities_disabled' => __( 'Enable abilities in SureCart > Settings > MCP: SureCart registers none while that switch is off.', 'senroflux' ),
			default => null,
		};
	}

	public function minVersion(): string {
		return self::MIN_VERSION;
	}

	/**
	 * Governed only while SenroGate's SureCart module is loaded and available:
	 * without it `surecart/` abilities are not classified by anyone.
	 */
	public function isGoverned(): bool {
		return true === $this->probe( 'gated', fn (): bool => $this->gateModuleAvailable() );
	}

	private function gateModuleAvailable(): bool {
		return GateMode::AgentSafety === Plugin::currentGateMode()
			&& class_exists( self::GATE_INTEGRATION )
			&& is_callable( array( self::GATE_INTEGRATION, 'available' ) )
			&& true === call_user_func( array( self::GATE_INTEGRATION, 'available' ) );
	}

	/** @return list<string> */
	public function abilityNamespaces(): array {
		return array( 'surecart/', 'senroflux/' );
	}

	/**
	 * `manage_sc_shop_settings`: the capability SureCart's own `get-store-info`
	 * ability checks (`Abilities/Abilities/GetStoreInfo.php:55`), held by its
	 * shop-manager role. The narrower `edit_sc_*` caps are checked per ability.
	 */
	public function runCapability(): string {
		return 'manage_sc_shop_settings';
	}

	/** @return array<string,string|array{polyfill:string,native?:string,shape?:callable|null}> */
	public function roleMap(): array {
		return array(
			'read'          => $this->native( 'get-product' ),
			'catalogue'     => $this->native( 'list-products' ),
			'create'        => $this->native( 'create-product' ),
			'update'        => $this->native( 'update-product' ),
			'price'         => $this->native( 'update-price' ),
			'status'        => SureCartProductStatus::TEMPLATE,
			'coupon-create' => $this->native( 'create-coupon', $this->shapeCoupon( ... ) ),
			'coupon-code'   => $this->native( 'create-promotion', $this->shapePromotion( ... ) ),
			'order-read'    => $this->native( 'get-order' ),
			'refund'        => $this->native( 'create-refund' ),
			'store-report'  => $this->native( 'get-store-dashboard' ),
		);
	}

	/**
	 * A native-only role. The `senroflux/` fallback name is never registered:
	 * SureCart abilities absent means {@see inactiveReason()} already says why.
	 *
	 * @return array{polyfill:string,native:string,shape:callable|null}
	 */
	private function native( string $ability, ?callable $shape = null ): array {
		return array(
			'polyfill' => 'surecart-' . $ability,
			'native'   => $ability,
			'shape'    => $shape,
		);
	}

	/** SureCart's records live remotely, so restoring is a human admin job: `manage_options`. */
	public function restoreCapability(): string {
		return 'manage_options';
	}

	/**
	 * The Tier-2 cards: a price change, a refund, a publish, a big discount.
	 * Remote lookups only add context; a failed one leaves the card without it.
	 *
	 * @param string              $pack_verb The resolved commerce verb.
	 * @param string              $base_name The ability name segment.
	 * @param array<string,mixed> $input     Call input.
	 */
	public function approvalSummary( string $pack_verb, string $base_name, array $input ): ?string {
		return match ( $pack_verb ) {
			'commerce/price-change' => $this->priceCard( $input ),
			'commerce/refund' => $this->refundCard( $input ),
			'commerce/product-publish' => $this->publishCard( $input ),
			'commerce/coupon-enable' => 'create-coupon' === $base_name ? $this->discountCard( $input ) : null,
			default => null,
		};
	}

	/**
	 * `product:`/`price:`/`order:`/`coupon:` lookups, remote GETs. A bare id is
	 * a product (the pack tracks products unprefixed). An id this platform
	 * cannot load is described as such, never an error.
	 *
	 * @param string $object_id The tracked object id.
	 * @return array{object_type:string,title:string,status:string,edit_url:?string,preview_url:?string}|null
	 */
	public function describeObject( string $object_id ): ?array {
		$prefix = 'product';
		$id     = $object_id;
		if ( 1 === preg_match( '/^(product|price|order|coupon):(.+)$/', $object_id, $m ) ) {
			$prefix = $m[1];
			$id     = $m[2];
		} elseif ( str_contains( $object_id, ':' ) ) {
			return null;
		}

		$model = match ( $prefix ) {
			'price' => 'Price',
			'order' => 'Order',
			'coupon' => 'Coupon',
			default => 'Product',
		};
		$data = SureCartRemote::find( $model, $id );
		if ( null === $data ) {
			return array(
				'object_type' => 'surecart_' . $prefix,
				/* translators: 1: object kind such as product, 2: SureCart object id. */
				'title'       => sprintf( __( 'SureCart %1$s %2$s (couldn\'t load it from SureCart)', 'senroflux' ), $prefix, $id ),
				'status'      => 'unknown',
				'edit_url'    => null,
				'preview_url' => null,
			);
		}

		$title = $this->stringAt( $data, 'name' )
			?? ( 'order' === $prefix ? $this->orderTitle( $data, $id ) : null )
			?? $id;
		$state = ( true === ( $data['archived'] ?? false ) || is_string( $data['archived_at'] ?? null ) )
			? 'archived'
			: ( $this->stringAt( $data, 'status' ) ?? 'unknown' );

		return array(
			'object_type' => 'surecart_' . $prefix,
			'title'       => $title,
			'status'      => $state,
			'edit_url'    => $this->editUrl( $prefix, $id, $data ),
			'preview_url' => 'product' === $prefix ? $this->stringAt( $data, 'permalink' ) : null,
		);
	}

	/**
	 * Only the `senroflux/` product-status polyfill is ours to register.
	 *
	 * @return list<string>
	 */
	public function polyfills(): array {
		return array( SureCartProductStatus::TEMPLATE );
	}

	/** @return array<string,int> */
	public function verbTiers(): array {
		return array(
			'commerce/product-read'         => 0,
			'commerce/catalogue-read'       => 0,
			'commerce/product-create-draft' => 1,
			'commerce/product-update'       => 1,
			'commerce/price-change'         => 2,
			'commerce/product-publish'      => 2,
			'commerce/coupon-draft'         => 1,
			'commerce/coupon-enable'        => 2,
			'commerce/order-read'           => 0,
			'commerce/refund'               => 2,
			'commerce/store-report'         => 0,
		);
	}

	/**
	 * Keys checked against SureCart's own schemas for a native role. A shaped
	 * role names the native keys it relies on.
	 *
	 * @param string $template Ability template.
	 * @return list<string>
	 */
	public function inputProperties( string $template ): array {
		return match ( $template ) {
			'surecart-get-product'    => array( 'id' ),
			'surecart-get-order'      => array( 'id' ),
			'surecart-create-product' => array( 'name', 'description' ),
			'surecart-update-product' => array( 'id', 'name', 'description' ),
			'surecart-update-price'   => array( 'id', 'amount' ),
			'surecart-create-coupon'  => array( 'name' ),
			'surecart-create-promotion' => array( 'coupon', 'code' ),
			'surecart-create-refund'  => array( 'charge', 'amount', 'reason' ),
			default                   => array(),
		};
	}

	/**
	 * The pack verb, mirroring SenroGate's tier for the ability and its input
	 * rules (PriceElevationRule, DiscountElevationRule).
	 *
	 * @param string              $base_name Ability name segment.
	 * @param array<string,mixed> $input     Call input.
	 */
	public function verbFor( string $base_name, array $input ): ?string {
		return match ( $base_name ) {
			'get-product'       => 'commerce/product-read',
			'list-products'     => 'commerce/catalogue-read',
			'get-order'         => 'commerce/order-read',
			'get-store-dashboard' => 'commerce/store-report',
			'create-product'    => 'commerce/product-create-draft',
			'update-product'    => 'commerce/product-update',
			'update-price'      => $this->priceVerb( $input ),
			'create-coupon'     => $this->bigDiscount( $this->nativeCoupon( $input ) ) ? 'commerce/coupon-enable' : 'commerce/coupon-draft',
			'create-promotion'  => 'commerce/coupon-draft',
			'create-refund'     => 'commerce/refund',
			SureCartProductStatus::TEMPLATE => SureCartProductStatus::PUBLISHED === ( $input['status'] ?? null ) ? 'commerce/product-publish' : 'commerce/product-update',
			default             => null,
		};
	}

	/**
	 * The pack's description rule (D3) on SureCart's own product abilities,
	 * before they reach SureCart's permission check.
	 *
	 * @param string              $base_name Ability name segment.
	 * @param array<string,mixed> $input     Call input.
	 */
	public function validateCall( string $base_name, array $input ): ?WP_Error {
		if ( ! in_array( $base_name, array( 'create-product', 'update-product' ), true ) ) {
			return null;
		}
		if ( ! isset( $input['description'] ) || ! is_string( $input['description'] ) ) {
			return null;
		}

		return ( new DescriptionValidator() )->validate( $input['description'] );
	}

	/**
	 * Products are tracked by their bare id (so a `get-product` read verifies
	 * them). A price change is tracked as its PRODUCT, taken from the price's
	 * own `product`, so the same read verifies it. A coupon is tracked by its
	 * coupon id (a promotion by the coupon it belongs to). A refund is not
	 * tracked: its order is not in the refund output and finding it needs a
	 * GET, which a pure mapper must not make.
	 *
	 * @param string              $verb   The pack verb.
	 * @param array<string,mixed> $args   The call's args.
	 * @param array<string,mixed> $output The call's output.
	 */
	public function objectIdForWrite( string $verb, array $args, array $output ): ?string {
		$id = match ( $verb ) {
			'commerce/product-create-draft',
			'commerce/product-update',
			'commerce/price-change',
			'commerce/product-publish' => $this->productIdOf( $output ),
			'commerce/coupon-draft',
			'commerce/coupon-enable' => $this->couponIdOf( $args, $output ),
			default => null,
		};

		return ( is_string( $id ) && SureCartRemote::validId( $id ) ) ? $id : null;
	}

	// ------------------------------------------------------------------
	// Verb predicates, mirrored from SenroGate
	// ------------------------------------------------------------------

	/**
	 * PriceElevationRule: an `amount`, or any non-null `archived` that is
	 * truthy, is the money-changing edit. A rename alone stays Tier 1.
	 *
	 * @param array<string,mixed> $input Call input.
	 */
	private function priceVerb( array $input ): string {
		if ( ( array_key_exists( 'amount', $input ) && null !== $input['amount'] ) || $this->flagOn( $input['archived'] ?? null ) ) {
			return 'commerce/price-change';
		}

		return 'commerce/product-update';
	}

	/** SenroGate's SureCartTruthy::flagOn(): scalars by truthiness, any non-scalar counts as on. */
	private function flagOn( mixed $value ): bool {
		if ( null === $value ) {
			return false;
		}

		return is_scalar( $value ) ? (bool) $value : true;
	}

	/**
	 * DiscountElevationRule: `percent_off` >= 50 or `amount_off` above the
	 * threshold (same filter, same default). A non-scalar fails closed.
	 *
	 * @param array<string,mixed> $coupon Native coupon input.
	 */
	private function bigDiscount( array $coupon ): bool {
		if ( array_key_exists( 'percent_off', $coupon ) && null !== $coupon['percent_off'] ) {
			$percent = $coupon['percent_off'];
			if ( ! is_scalar( $percent ) || (float) $percent >= self::PERCENT_THRESHOLD ) {
				return true;
			}
		}
		if ( array_key_exists( 'amount_off', $coupon ) && null !== $coupon['amount_off'] ) {
			$amount = $coupon['amount_off'];
			if ( ! is_scalar( $amount ) || abs( (int) $amount ) > $this->amountThreshold() ) {
				return true;
			}
		}

		return false;
	}

	private function amountThreshold(): int {
		$value = function_exists( 'apply_filters' )
			? apply_filters( self::AMOUNT_THRESHOLD_FILTER, self::DEFAULT_AMOUNT_THRESHOLD ) // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- SenroGate's own hook, read so both sides agree.
			: self::DEFAULT_AMOUNT_THRESHOLD;

		// A broken filter must not disable the rule.
		return ( is_int( $value ) && $value >= 0 ) ? $value : self::DEFAULT_AMOUNT_THRESHOLD;
	}

	// ------------------------------------------------------------------
	// Shapes: the pack's vocabulary in, SureCart's out. Native input passes through.
	// ------------------------------------------------------------------

	/**
	 * Coupon input in the pack's vocabulary (`code`, `discount_type`, `amount`,
	 * `expiry`, `usage_limit`, `product_ids`) mapped to `create-coupon`'s.
	 * SureCart's own keys win; pack-only keys are dropped (the code belongs to
	 * the promotion, created in the second step).
	 *
	 * @param array<string,mixed> $input Pack or native input.
	 * @return array<string,mixed>
	 */
	private function nativeCoupon( array $input ): array {
		$out = $input;

		if ( ! isset( $out['name'] ) && is_string( $input['code'] ?? null ) && '' !== $input['code'] ) {
			$out['name'] = $input['code'];
		}
		if ( isset( $input['amount'] ) && ! isset( $out['percent_off'] ) && ! isset( $out['amount_off'] ) ) {
			$type = is_string( $input['discount_type'] ?? null ) ? strtolower( $input['discount_type'] ) : '';
			if ( in_array( $type, array( 'percent', 'percentage' ), true ) ) {
				$out['percent_off'] = $input['amount'];
			} else {
				// Anything else is a fixed amount, the stricter reading is applied by bigDiscount().
				$out['amount_off'] = $input['amount'];
			}
		}
		if ( ! isset( $out['redeem_by'] ) && is_string( $input['expiry'] ?? null ) && false !== strtotime( $input['expiry'] ) ) {
			$out['redeem_by'] = (int) strtotime( $input['expiry'] );
		}
		if ( ! isset( $out['max_redemptions'] ) && isset( $input['usage_limit'] ) ) {
			$out['max_redemptions'] = $input['usage_limit'];
		}
		if ( isset( $out['product_ids'] ) && is_array( $out['product_ids'] ) ) {
			$out['product_ids'] = array_values( array_map( 'strval', $out['product_ids'] ) );
		}

		return array_diff_key( $out, array_flip( array( 'code', 'discount_type', 'amount', 'expiry', 'usage_limit' ) ) );
	}

	/**
	 * @param array<string,mixed> $input Pack or native input.
	 * @return array<string,mixed>
	 */
	private function shapeCoupon( array $input ): array {
		return $this->nativeCoupon( $input );
	}

	/**
	 * `create-promotion` takes `coupon` (the coupon's id) and `code`; accept
	 * `coupon_id` for it.
	 *
	 * @param array<string,mixed> $input Pack or native input.
	 * @return array<string,mixed>
	 */
	private function shapePromotion( array $input ): array {
		if ( ! isset( $input['coupon'] ) && isset( $input['coupon_id'] ) ) {
			$input['coupon'] = $input['coupon_id'];
			unset( $input['coupon_id'] );
		}

		return $input;
	}

	// ------------------------------------------------------------------
	// Cards
	// ------------------------------------------------------------------

	/** @param array<string,mixed> $input Call input. */
	private function priceCard( array $input ): ?string {
		$id = is_string( $input['id'] ?? null ) ? $input['id'] : '';
		if ( '' === $id ) {
			return null;
		}

		$current  = SureCartRemote::find( 'Price', $id );
		$currency = is_string( $current['currency'] ?? null ) ? $current['currency'] : '';
		$parts    = array();

		if ( isset( $input['amount'] ) && is_numeric( $input['amount'] ) ) {
			$new = $this->money( (int) $input['amount'], $currency );
			if ( is_array( $current ) && isset( $current['amount'] ) && is_numeric( $current['amount'] ) ) {
				/* translators: 1: current price, 2: new price. */
				$parts[] = sprintf( __( 'change the price from %1$s to %2$s', 'senroflux' ), $this->money( (int) $current['amount'], $currency ), $new );
			} else {
				/* translators: %s: new price. */
				$parts[] = sprintf( __( 'set the price to %s', 'senroflux' ), $new );
			}
		}
		if ( $this->flagOn( $input['archived'] ?? null ) ) {
			$parts[] = __( 'archive it, so customers can no longer buy at it', 'senroflux' );
		}
		if ( array() === $parts ) {
			return null;
		}

		$name = is_array( $current ) ? $this->stringAt( $current, 'name' ) : null;

		/* translators: 1: price name or id, 2: what will happen. */
		return sprintf( __( 'SureCart price %1$s: %2$s. This takes effect for customers straight away.', 'senroflux' ), $name ?? $id, implode( __( ', and ', 'senroflux' ), $parts ) );
	}

	/** @param array<string,mixed> $input Call input. */
	private function refundCard( array $input ): ?string {
		$charge = is_string( $input['charge'] ?? null ) ? $input['charge'] : '';
		if ( '' === $charge || ! isset( $input['amount'] ) || ! is_numeric( $input['amount'] ) ) {
			return null;
		}

		$found    = SureCartRemote::find( 'Charge', $charge );
		$currency = is_string( $found['currency'] ?? null ) ? $found['currency'] : '';
		$reason   = is_string( $input['reason'] ?? null ) ? str_replace( '_', ' ', $input['reason'] ) : __( 'no reason given', 'senroflux' );

		return sprintf(
			/* translators: 1: refund amount, 2: SureCart charge id, 3: reason. */
			__( 'Refund %1$s on SureCart charge %2$s (reason: %3$s). The money goes back to the customer\'s payment method and cannot be undone.', 'senroflux' ),
			$this->money( (int) $input['amount'], $currency ),
			$charge,
			$reason
		);
	}

	/** @param array<string,mixed> $input Call input. */
	private function publishCard( array $input ): ?string {
		$id = is_string( $input['id'] ?? null ) ? $input['id'] : '';
		if ( '' === $id ) {
			return null;
		}

		$product = SureCartRemote::find( 'Product', $id );
		$name    = is_array( $product ) ? $this->stringAt( $product, 'name' ) : null;

		/* translators: %s: product name or id. */
		return sprintf( __( 'Publish SureCart product %s. It becomes visible to customers and can be bought.', 'senroflux' ), $name ?? $id );
	}

	/** @param array<string,mixed> $input Call input. */
	private function discountCard( array $input ): string {
		$coupon = $this->nativeCoupon( $input );
		$name   = is_string( $coupon['name'] ?? null ) ? $coupon['name'] : __( '(unnamed)', 'senroflux' );

		if ( isset( $coupon['percent_off'] ) && is_scalar( $coupon['percent_off'] ) ) {
			/* translators: 1: coupon name, 2: percentage. */
			return sprintf( __( 'Create SureCart coupon %1$s for %2$s%% off. Discounts of 50%% or more need your approval.', 'senroflux' ), $name, (string) $coupon['percent_off'] );
		}
		if ( isset( $coupon['amount_off'] ) && is_scalar( $coupon['amount_off'] ) ) {
			$currency = is_string( $coupon['currency'] ?? null ) ? $coupon['currency'] : '';

			/* translators: 1: coupon name, 2: discount amount. */
			return sprintf( __( 'Create SureCart coupon %1$s for %2$s off. A large fixed discount needs your approval.', 'senroflux' ), $name, $this->money( (int) $coupon['amount_off'], $currency ) );
		}

		/* translators: %s: coupon name. */
		return sprintf( __( 'Create SureCart coupon %s with a discount that needs your approval.', 'senroflux' ), $name );
	}

	/** An amount in the currency's smallest unit as a plain string. */
	private function money( int $minor, string $currency ): string {
		$code  = strtoupper( $currency );
		$whole = in_array( $code, self::ZERO_DECIMAL, true ) ? (string) $minor : number_format( $minor / 100, 2, '.', '' );

		return trim( $whole . ' ' . $code );
	}

	// ------------------------------------------------------------------
	// Detection
	// ------------------------------------------------------------------

	/**
	 * A detection value: the test seam's when it supplies one, else the live one.
	 *
	 * @param string   $key  Seam key.
	 * @param callable $live Live detection.
	 */
	private function probe( string $key, callable $live ): mixed {
		if ( null !== $this->env ) {
			$values = ( $this->env )();
			if ( is_array( $values ) && array_key_exists( $key, $values ) ) {
				return $values[ $key ];
			}
		}

		return $live();
	}

	/** SureCart's main class. Named through a variable: it is not a dependency of this repo. */
	private const MAIN_CLASS = 'SureCart';

	private function installed(): bool {
		$class = self::MAIN_CLASS;

		return class_exists( $class ) && is_callable( array( $class, 'plugin' ) );
	}

	/** SureCart defines no version constant: `SureCart::plugin()->version()` reads the plugin header. */
	private function liveVersion(): ?string {
		if ( ! $this->installed() ) {
			return null;
		}

		try {
			$class   = self::MAIN_CLASS;
			$plugin  = $class::plugin();
			$version = ( is_object( $plugin ) && method_exists( $plugin, 'version' ) ) ? $plugin->version() : null;
		} catch ( Throwable $e ) {
			unset( $e );

			return '';
		}

		return is_string( $version ) ? $version : '';
	}

	/** SureCart's own gate: `ApiToken::get()` non-empty (`AbilitiesServiceProvider.php:41`). */
	private function storeConnected(): bool {
		$class = '\\SureCart\\Models\\ApiToken';
		if ( ! class_exists( $class ) ) {
			return false;
		}

		try {
			return ! empty( $class::get() );
		} catch ( Throwable $e ) {
			unset( $e );

			return false;
		}
	}

	/** SureCart's own gate: option `surecart_mcp_abilities_enabled`, default on (`AbilitiesServiceProvider.php:46`). */
	private function abilitiesEnabled(): bool {
		return function_exists( 'get_option' ) ? (bool) get_option( 'surecart_mcp_abilities_enabled', true ) : true;
	}

	// ------------------------------------------------------------------
	// Small readers
	// ------------------------------------------------------------------

	/**
	 * @param array<string,mixed> $output Ability output.
	 */
	private function productIdOf( array $output ): ?string {
		$price = $output['price'] ?? null;
		if ( is_array( $price ) ) {
			return $this->idOf( $price['product'] ?? null );
		}

		return is_array( $output['product'] ?? null ) ? $this->idOf( $output['product']['id'] ?? null ) : null;
	}

	/**
	 * @param array<string,mixed> $args   Call args.
	 * @param array<string,mixed> $output Ability output.
	 */
	private function couponIdOf( array $args, array $output ): ?string {
		if ( is_array( $output['coupon'] ?? null ) ) {
			return $this->idOf( $output['coupon']['id'] ?? null );
		}
		if ( is_array( $output['promotion'] ?? null ) ) {
			return $this->idOf( $output['promotion']['coupon'] ?? null ) ?? $this->idOf( $args['coupon'] ?? null );
		}

		return null;
	}

	/** An id given as a string or as an expanded object with an `id`. */
	private function idOf( mixed $value ): ?string {
		if ( is_array( $value ) ) {
			$value = $value['id'] ?? null;
		}

		return ( is_string( $value ) && '' !== $value ) ? $value : null;
	}

	/** @param array<string,mixed> $data Object data. */
	private function stringAt( array $data, string $key ): ?string {
		return ( is_string( $data[ $key ] ?? null ) && '' !== $data[ $key ] ) ? $data[ $key ] : null;
	}

	/** @param array<string,mixed> $data Order data. */
	private function orderTitle( array $data, string $id ): ?string {
		unset( $id );
		$number = $this->stringAt( $data, 'number' );

		/* translators: %s: SureCart order number. */
		return null === $number ? null : sprintf( __( 'Order %s', 'senroflux' ), $number );
	}

	/**
	 * SureCart's admin pages are `admin.php?page=sc-{products,coupons}&action=edit&id=`
	 * (`Controllers/Admin/Products/…:164`, `…:216`); orders follow the same
	 * pattern, which is inferred, not read.
	 *
	 * @param array<string,mixed> $data Object data.
	 */
	private function editUrl( string $prefix, string $id, array $data ): ?string {
		if ( ! function_exists( 'admin_url' ) ) {
			return null;
		}

		$page = match ( $prefix ) {
			'order' => 'sc-orders',
			'coupon' => 'sc-coupons',
			'price' => 'sc-products',
			default => 'sc-products',
		};
		if ( 'price' === $prefix ) {
			$product = $this->idOf( $data['product'] ?? null );
			if ( null === $product ) {
				return null;
			}
			$id = $product;
		}

		return admin_url( 'admin.php?page=' . $page . '&action=edit&id=' . rawurlencode( $id ) );
	}
}
