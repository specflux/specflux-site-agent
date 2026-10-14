<?php
/**
 * FluentCart as a store platform (C4-F).
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Packs\Commerce\Platforms\FluentCart;

use Specflux\SiteAgent\Packs\Commerce\DescriptionValidator;
use Specflux\SiteAgent\Packs\Commerce\Platforms\StorePlatform;
use Specflux\SiteAgent\Plugin;
use Specflux\SiteAgent\Run\GateMode;
use WP_Error;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * FluentCart (free) 1.7.0+.
 *
 * Role resolution: FluentCart's own `fluent-cart/` abilities exist only when
 * its MCP module is switched on (`fluent_cart_modules_settings['mcp']`, off by
 * default: `app/Modules/MCP/MCPInit.php` `boot()`), so EVERY role also has a
 * `specflux/fluentcart-*` polyfill and the pack works with the module off.
 * Where the native ability is a safe fit it is preferred (store report,
 * coupon create/enable, product and order reads). It is NOT adopted for the
 * refund: native `refund-order` is a two-call dry-run/confirm-token flow
 * (`app/Modules/MCP/Tools/OrderTools.php:1210-1232`, `WriteGuard::preview`
 * then `WriteGuard::confirm`) that a one-shot role call cannot satisfy, so the
 * refund role is polyfill-only and goes through the route
 * `POST /fluent-cart/orders/{order_id}/refund` (`app/Http/Routes/api.php:585`,
 * permission `orders/can_refund`) instead.
 *
 * Not supported on FluentCart, so no role: `catalogue` (Woo catalogue
 * listing), `order-note` (1.7.0's `add-order-note` is staff-only, there is no
 * customer-visible note to gate).
 *
 * @internal
 */
final class FluentCartPlatform implements StorePlatform {

	public const ID = 'fluentcart';

	/**
	 * The release whose source (`app/Modules/MCP/`, `app/Http/Routes/api.php`)
	 * this platform and Belayline Agent Governance's FluentCart catalog were verified against.
	 */
	public const MIN_VERSION = '1.7.0';

	/** @var (callable():?string)|null */
	private static $version_probe = null;

	/** Register this platform on the `specflux_site_agent_store_platforms` filter (composition root). */
	public static function boot(): void {
		if ( ! function_exists( 'add_filter' ) ) {
			return;
		}

		add_filter(
			'specflux_site_agent_store_platforms',
			static function ( $platforms ) {
				$platforms   = is_array( $platforms ) ? $platforms : array();
				$platforms[] = new self();

				return $platforms;
			},
			10,
			1
		);
	}

	/**
	 * Test seam: report a FluentCart version without defining the real
	 * constant (constants cannot be undefined between tests).
	 *
	 * @param (callable():?string)|null $probe Returns the version or null for "not installed"; null restores reality.
	 */
	public static function useVersionProbe( ?callable $probe ): void {
		self::$version_probe = $probe;
	}

	private static function installedVersion(): ?string {
		if ( null !== self::$version_probe ) {
			return ( self::$version_probe )();
		}

		return defined( 'FLUENTCART_VERSION' ) && is_string( constant( 'FLUENTCART_VERSION' ) ) ? (string) constant( 'FLUENTCART_VERSION' ) : null;
	}

	public function id(): string {
		return self::ID;
	}

	public function label(): string {
		return 'FluentCart';
	}

	public function isActive(): bool {
		return null === $this->inactiveReason();
	}

	public function inactiveReason(): ?string {
		$version = self::installedVersion();
		if ( null === $version || '' === $version ) {
			return 'not_installed';
		}
		if ( version_compare( $version, self::MIN_VERSION, '<' ) ) {
			return 'below_min_version';
		}

		return null;
	}

	public function minVersion(): string {
		return self::MIN_VERSION;
	}

	/** Belayline Agent Governance's FluentCart module exists only in Belayline Agent Governance mode (and 1.7.0+, same floor as ours). */
	public function isGoverned(): bool {
		return GateMode::AgentSafety === Plugin::currentGateMode();
	}

	/** @return list<string> */
	public function abilityNamespaces(): array {
		return array( 'fluent-cart/', 'specflux/' );
	}

	/**
	 * FluentCart's own gate is `PermissionManager::hasPermission()`, and for a
	 * site administrator that is `user_can( $user, 'manage_options' )`
	 * (`app/Services/Permission/PermissionManager.php:110`, `:173`); Pro's
	 * store roles are a Pro-only extra that FluentCart itself ignores in free.
	 */
	public function runCapability(): string {
		return 'manage_options';
	}

	/** @return array<string,string|array{polyfill:string,native?:string,shape?:callable|null}> */
	public function roleMap(): array {
		$coupon_shape = array( self::class, 'shapeCoupon' );

		return array(
			'read'           => array(
				'polyfill' => 'fluentcart-product-read',
				'native'   => 'get-product',
				'shape'    => array( self::class, 'shapeProductRead' ),
			),
			'create'         => 'fluentcart-product-create',
			'update'         => 'fluentcart-product-update',
			'image'          => 'fluentcart-set-product-image',
			'search'         => 'media-search',
			'image-generate' => 'generate-image',
			'coupon-create'  => array(
				'polyfill' => 'fluentcart-coupon-create',
				'native'   => 'manage-coupon',
				'shape'    => $coupon_shape,
			),
			'coupon-enable'  => array(
				'polyfill' => 'fluentcart-coupon-enable',
				'native'   => 'manage-coupon',
				'shape'    => $coupon_shape,
			),
			'order-read'     => array(
				'polyfill' => 'fluentcart-order-read',
				'native'   => 'get-order',
				'shape'    => array( self::class, 'shapeOrderRead' ),
			),
			// Polyfill-only: see the class docblock.
			'refund'         => 'fluentcart-orders-refund',
			'shipping-write' => 'fluentcart-shipping-zone-save',
			'tax-write'      => 'fluentcart-tax-rate-save',
			'store-report'   => array(
				'polyfill' => 'fluentcart-store-report',
				'native'   => 'get-sales-report',
				'shape'    => array( self::class, 'shapeStoreReport' ),
			),
			'report-save'    => 'save-store-report',
		);
	}

	/** Same capability as {@see runCapability()}: FluentCart's "can manage the store". */
	public function restoreCapability(): string {
		return 'manage_options';
	}

	// ------------------------------------------------------------------
	// Native input shapers (pack input => native ability input). Tolerant:
	// the model may already speak the native schema, so native keys win.
	// ------------------------------------------------------------------

	/**
	 * @param array<string,mixed> $input Pack input.
	 * @return array<string,mixed>
	 */
	public static function shapeProductRead( array $input ): array {
		$id = $input['product_id'] ?? $input['id'] ?? null;

		return null === $id ? $input : array( 'product_id' => (int) $id );
	}

	/**
	 * @param array<string,mixed> $input Pack input.
	 * @return array<string,mixed>
	 */
	public static function shapeOrderRead( array $input ): array {
		$id = $input['order_id'] ?? $input['id'] ?? null;

		return null === $id ? $input : array( 'order_id' => (int) $id );
	}

	/**
	 * @param array<string,mixed> $input Pack input (`from`/`to`).
	 * @return array<string,mixed>
	 */
	public static function shapeStoreReport( array $input ): array {
		$out = array();
		$map = array(
			'start_date' => $input['start_date'] ?? $input['from'] ?? null,
			'end_date'   => $input['end_date'] ?? $input['to'] ?? null,
		);
		foreach ( $map as $key => $value ) {
			if ( is_string( $value ) && '' !== $value ) {
				$out[ $key ] = $value;
			}
		}

		return $out;
	}

	/**
	 * One shaper for both coupon roles, because both resolve to the SAME native
	 * ability (`manage-coupon`) and the pack applies the first matching role's
	 * shape. It also NARROWS what the model sent, so a role can only do its own
	 * job: create is forced to `inactive` (a draft), enable only flips an
	 * existing coupon to `active` (no field edits ride along), and a
	 * `deactivate` is the one other thing passed through.
	 *
	 * @param array<string,mixed> $input Pack input.
	 * @return array<string,mixed>
	 */
	public static function shapeCoupon( array $input ): array {
		$coupon_id = isset( $input['coupon_id'] ) && is_numeric( $input['coupon_id'] ) ? (int) $input['coupon_id'] : 0;
		$has_code  = is_string( $input['code'] ?? null ) && '' !== trim( $input['code'] );

		if ( $coupon_id > 0 && ! $has_code ) {
			if ( 'deactivate' === self::lower( $input['action'] ?? null ) ) {
				return array(
					'action'    => 'deactivate',
					'coupon_id' => $coupon_id,
				);
			}

			return array(
				'action'    => 'update',
				'coupon_id' => $coupon_id,
				'status'    => 'active',
			);
		}

		$type = self::couponType( $input['type'] ?? $input['discount_type'] ?? null );
		$out  = array(
			'action' => 'create',
			'status' => 'inactive',
		);
		if ( $has_code ) {
			$out['code'] = trim( (string) $input['code'] );
		}
		if ( null !== $type ) {
			$out['type'] = $type;
		}
		if ( isset( $input['amount'] ) && is_numeric( $input['amount'] ) ) {
			$out['amount'] = 0 + $input['amount'];
		}
		foreach ( array( 'title', 'start_date' ) as $key ) {
			if ( is_string( $input[ $key ] ?? null ) && '' !== $input[ $key ] ) {
				$out[ $key ] = $input[ $key ];
			}
		}
		$end = $input['end_date'] ?? $input['expiry'] ?? null;
		if ( is_string( $end ) && '' !== $end ) {
			$out['end_date'] = $end;
		}

		return $out;
	}

	/**
	 * Woo-flavoured and FluentCart-native discount types onto FluentCart's two
	 * (`fixed`, `percentage`); anything else is dropped so the ability refuses.
	 *
	 * @param mixed $value Raw type.
	 */
	public static function couponType( mixed $value ): ?string {
		return match ( self::lower( $value ) ) {
			'percentage', 'percent' => 'percentage',
			'fixed', 'fixed_cart', 'fixed_product' => 'fixed',
			default => null,
		};
	}

	private static function lower( mixed $value ): string {
		return is_string( $value ) ? strtolower( trim( $value ) ) : '';
	}

	// ------------------------------------------------------------------
	// Cards and lookups
	// ------------------------------------------------------------------

	/**
	 * Tier-2 cards from FluentCart's own tables, in the commerce pack's card
	 * voice. Null for a verb that keeps the default card.
	 *
	 * @param string              $pack_verb The resolved commerce verb.
	 * @param string              $base_name The ability name segment.
	 * @param array<string,mixed> $input     Call input.
	 */
	public function approvalSummary( string $pack_verb, string $base_name, array $input ): ?string {
		unset( $base_name );

		return match ( $pack_verb ) {
			'commerce/refund' => $this->refundCard( $input ),
			'commerce/coupon-enable' => $this->couponCard( $input ),
			'commerce/price-change' => $this->priceCard( $input ),
			'commerce/product-publish' => $this->publishCard( $input ),
			'commerce/shipping-write' => $this->zoneCard( $input ),
			'commerce/tax-write' => $this->taxCard( $input ),
			default => null,
		};
	}

	/** @param array<string,mixed> $input Call input. */
	private function refundCard( array $input ): ?string {
		$order_id = is_numeric( $input['order_id'] ?? null ) ? (int) $input['order_id'] : 0;
		$order    = FluentCartLookup::row( 'fct_orders', $order_id );
		if ( null === $order ) {
			return null;
		}

		$currency  = is_string( $order['currency'] ?? null ) ? $order['currency'] : '';
		$paid      = (int) ( $order['total_paid'] ?? 0 );
		$refunded  = (int) ( $order['total_refund'] ?? 0 );
		$remaining = max( 0, $paid - $refunded );
		$amount    = is_numeric( $input['amount'] ?? null ) ? (int) round( (float) $input['amount'] * 100 ) : $remaining;
		$reason    = is_string( $input['reason'] ?? null ) ? $input['reason'] : '';
		$mode      = is_string( $order['mode'] ?? null ) ? $order['mode'] : '';

		$row  = sprintf( 'Refund order #%d', $order_id );
		$row .= sprintf(
			' — paid %s, already refunded %s, remaining refundable %s',
			esc_html( FluentCartLookup::money( $paid, $currency ) ),
			esc_html( FluentCartLookup::money( $refunded, $currency ) ),
			esc_html( FluentCartLookup::money( $remaining, $currency ) )
		);
		$row .= sprintf( ' — refund amount %s', esc_html( FluentCartLookup::money( $amount, $currency ) ) );
		$row .= 'live' === $mode
			? ' — LIVE order: money moves through the payment gateway'
			: ' — ' . ( '' !== $mode ? esc_html( $mode ) . '-mode order' : 'order mode unknown' );
		if ( '' !== $reason ) {
			$row .= sprintf( ' — reason (the agent\'s words): &quot;%s&quot;', esc_html( $reason ) );
		}

		return $row;
	}

	/** @param array<string,mixed> $input Call input. */
	private function couponCard( array $input ): ?string {
		$coupon_id = is_numeric( $input['coupon_id'] ?? null ) ? (int) $input['coupon_id'] : 0;
		$coupon    = FluentCartLookup::row( 'fct_coupons', $coupon_id );
		if ( null === $coupon ) {
			return null;
		}

		$type   = is_string( $coupon['type'] ?? null ) ? $coupon['type'] : '';
		$amount = 'fixed' === $type ? FluentCartLookup::money( $coupon['amount'] ?? null ) : (string) ( $coupon['amount'] ?? '' ) . '%';
		$end    = is_string( $coupon['end_date'] ?? null ) && '' !== $coupon['end_date'] ? $coupon['end_date'] : 'none';

		$row  = sprintf( 'Enable coupon &quot;%s&quot;', esc_html( is_string( $coupon['code'] ?? null ) ? $coupon['code'] : '' ) );
		$row .= sprintf( ' — discount %s %s', esc_html( $amount ), esc_html( $type ) );
		$row .= sprintf( ' — ends %s', esc_html( $end ) );
		$row .= sprintf( ' — used %d times so far', (int) ( $coupon['use_count'] ?? 0 ) );

		return $row;
	}

	/** @param array<string,mixed> $input Call input. */
	private function priceCard( array $input ): string {
		$product_id = is_numeric( $input['id'] ?? null ) ? (int) $input['id'] : 0;
		$title      = function_exists( 'get_the_title' ) && $product_id > 0 ? (string) get_the_title( $product_id ) : '';

		$proposed = array();
		foreach ( array(
			'regular_price' => 'regular',
			'sale_price'    => 'sale',
		) as $key => $label ) {
			if ( array_key_exists( $key, $input ) && null !== $input[ $key ] && '' !== $input[ $key ] ) {
				$proposed[] = sprintf( '%s %s', $label, esc_html( is_scalar( $input[ $key ] ) ? (string) $input[ $key ] : '' ) );
			}
		}

		$row = sprintf( 'Change price for &quot;%s&quot;', esc_html( '' !== $title ? $title : '#' . $product_id ) );
		if ( array() !== $proposed ) {
			$row .= sprintf( ' — proposed %s (store currency, as typed)', implode( ', ', $proposed ) );
		}

		return $row;
	}

	/** @param array<string,mixed> $input Call input. */
	private function publishCard( array $input ): string {
		$product_id = is_numeric( $input['id'] ?? null ) ? (int) $input['id'] : 0;
		$title      = is_string( $input['name'] ?? null ) && '' !== $input['name']
			? $input['name']
			: ( function_exists( 'get_the_title' ) && $product_id > 0 ? (string) get_the_title( $product_id ) : '' );
		$new        = $product_id <= 0;

		$row = sprintf( $new ? 'Publish new product &quot;%s&quot;' : 'Publish product &quot;%s&quot;', esc_html( '' !== $title ? $title : '#' . $product_id ) );
		if ( ! $new && function_exists( 'get_preview_post_link' ) ) {
			$preview = (string) get_preview_post_link( $product_id );
			if ( '' !== $preview ) {
				$row .= sprintf( ' — <a href="%s">preview</a>', esc_url( $preview ) );
			}
		}

		return $row;
	}

	/** @param array<string,mixed> $input Call input. */
	private function zoneCard( array $input ): string {
		$name      = is_string( $input['name'] ?? null ) ? $input['name'] : '';
		$zone_id   = is_numeric( $input['zone_id'] ?? null ) ? (int) $input['zone_id'] : 0;
		$countries = is_array( $input['countries'] ?? null ) ? array_filter( $input['countries'], 'is_string' ) : array();
		$methods   = is_array( $input['methods'] ?? null ) ? count( $input['methods'] ) : 0;

		$row  = $zone_id > 0 ? sprintf( 'Update shipping zone #%d', $zone_id ) : 'Create shipping zone';
		$row .= sprintf( ' &quot;%s&quot;', esc_html( $name ) );
		$row .= sprintf( ' — %s', array() === $countries ? 'whole world' : esc_html( implode( ', ', $countries ) ) );
		$row .= sprintf( ' — %d shipping method(s) added', $methods );

		return $row;
	}

	/** @param array<string,mixed> $input Call input. */
	private function taxCard( array $input ): string {
		$country = is_string( $input['country'] ?? null ) ? strtoupper( $input['country'] ) : '';
		$state   = is_string( $input['state'] ?? null ) && '' !== $input['state'] ? ', ' . strtoupper( $input['state'] ) : '';
		$rate    = is_scalar( $input['rate'] ?? null ) ? (string) $input['rate'] : '';
		$name    = is_string( $input['name'] ?? null ) ? $input['name'] : '';
		$id      = is_numeric( $input['tax_rate_id'] ?? null ) ? (int) $input['tax_rate_id'] : 0;

		$row  = $id > 0 ? sprintf( 'Update tax rate #%d', $id ) : 'Create tax rate';
		$row .= sprintf( ' &quot;%s&quot; — %s%s at %s%% — takes effect at checkout immediately', esc_html( $name ), esc_html( $country ), esc_html( $state ), esc_html( $rate ) );

		return $row;
	}

	/**
	 * `order:`, `coupon:`, `customer:`, `zone:`, `taxrate:` from FluentCart's
	 * tables; `page:` (and anything else) keeps the default lookup.
	 *
	 * @param string $object_id The tracked object id.
	 * @return array{object_type:string,title:string,status:string,edit_url:?string,preview_url:?string}|null
	 */
	public function describeObject( string $object_id ): ?array {
		$app = static fn ( string $hash ): ?string => function_exists( 'admin_url' ) ? admin_url( 'admin.php?page=fluent-cart#/' . $hash ) : null;

		if ( str_starts_with( $object_id, 'order:' ) ) {
			$id  = (int) substr( $object_id, 6 );
			$row = FluentCartLookup::row( 'fct_orders', $id );

			return $this->described(
				$row,
				'fluentcart_order',
				/* translators: %d: order id. */
				sprintf( __( 'Order #%d', 'specflux-site-agent' ), $id ),
				$row['status'] ?? '',
				$app( 'orders/' . $id . '/view' )
			);
		}

		if ( str_starts_with( $object_id, 'coupon:' ) ) {
			$id  = (int) substr( $object_id, 7 );
			$row = FluentCartLookup::row( 'fct_coupons', $id );
			$tag = null !== $row && is_string( $row['code'] ?? null ) ? $row['code'] : '';

			return $this->described( $row, 'fluentcart_coupon', '' !== $tag ? $tag : '#' . $id, $row['status'] ?? '', $app( 'coupons' ) );
		}

		if ( str_starts_with( $object_id, 'customer:' ) ) {
			$id    = (int) substr( $object_id, 9 );
			$row   = FluentCartLookup::row( 'fct_customers', $id );
			$name  = null !== $row ? trim( ( is_string( $row['first_name'] ?? null ) ? $row['first_name'] : '' ) . ' ' . ( is_string( $row['last_name'] ?? null ) ? $row['last_name'] : '' ) ) : '';
			$email = null !== $row && is_string( $row['email'] ?? null ) ? $row['email'] : '';

			return $this->described( $row, 'fluentcart_customer', '' !== $name ? $name : ( '' !== $email ? $email : '#' . $id ), $row['status'] ?? '', $app( 'customers' ) );
		}

		if ( str_starts_with( $object_id, 'zone:' ) ) {
			$id  = (int) substr( $object_id, 5 );
			$row = FluentCartLookup::row( 'fct_shipping_zones', $id );

			return $this->described( $row, 'fluentcart_shipping_zone', is_string( $row['name'] ?? null ) ? $row['name'] : '#' . $id, '', $app( 'settings/shipping' ) );
		}

		if ( str_starts_with( $object_id, 'taxrate:' ) ) {
			$id  = (int) substr( $object_id, 8 );
			$row = FluentCartLookup::row( 'fct_tax_rates', $id );

			return $this->described( $row, 'fluentcart_tax_rate', is_string( $row['name'] ?? null ) ? $row['name'] : '#' . $id, '', $app( 'settings/tax' ) );
		}

		return null;
	}

	/**
	 * @param array<string,mixed>|null $row    The table row, or null when absent.
	 * @param mixed                    $status Row status.
	 * @return array{object_type:string,title:string,status:string,edit_url:?string,preview_url:?string}
	 */
	private function described( ?array $row, string $type, string $title, mixed $status, ?string $edit_url ): array {
		if ( null === $row ) {
			return array(
				'object_type' => 'unknown',
				'title'       => '',
				'status'      => '',
				'edit_url'    => null,
				'preview_url' => null,
			);
		}

		return array(
			'object_type' => $type,
			'title'       => $title,
			'status'      => is_string( $status ) ? $status : '',
			'edit_url'    => $edit_url,
			'preview_url' => null,
		);
	}

	// ------------------------------------------------------------------
	// Governance mirror
	// ------------------------------------------------------------------

	/** @return list<string> */
	public function polyfills(): array {
		return array(
			'fluentcart-product-read',
			'fluentcart-order-read',
			'fluentcart-product-create',
			'fluentcart-product-update',
			'fluentcart-set-product-image',
			'fluentcart-coupon-create',
			'fluentcart-coupon-enable',
			'fluentcart-orders-refund',
			'fluentcart-shipping-zone-save',
			'fluentcart-tax-rate-save',
			'fluentcart-store-report',
			'save-store-report',
		);
	}

	/**
	 * The pack-verb tiers are platform-neutral: the same table as WooCommerce.
	 * The parity test checks every native `fluent-cart/` ability this platform
	 * classifies against Belayline Agent Governance's FluentCartVerbCatalog.
	 *
	 * @return array<string,int>
	 */
	public function verbTiers(): array {
		return array(
			'commerce/product-read'         => 0,
			'commerce/catalogue-read'       => 0,
			'commerce/product-create-draft' => 1,
			'commerce/product-update'       => 1,
			'commerce/price-change'         => 2,
			'commerce/product-publish'      => 2,
			'commerce/product-image'        => 1,
			'commerce/media-search'         => 0,
			'commerce/image-generate'       => 1,
			'commerce/coupon-draft'         => 1,
			'commerce/coupon-enable'        => 2,
			'commerce/order-read'           => 0,
			'commerce/order-note-private'   => 1,
			'commerce/order-note-customer'  => 2,
			'commerce/refund'               => 2,
			'commerce/shipping-write'       => 2,
			'commerce/tax-write'            => 2,
			'commerce/store-report'         => 0,
			'commerce/report-save'          => 2,
		);
	}

	/**
	 * The shape-compat check runs only against FluentCart's OWN abilities, and
	 * every native-backed role here is shaped, so there is nothing to require
	 * of a native schema beyond its existence.
	 *
	 * @param string $template Ability template.
	 * @return list<string>
	 */
	public function inputProperties( string $template ): array {
		unset( $template );

		return array();
	}

	/**
	 * @param string              $base_name Ability name segment.
	 * @param array<string,mixed> $input     Call input.
	 */
	public function verbFor( string $base_name, array $input ): ?string {
		return match ( $base_name ) {
			'get-product', 'fluentcart-product-read' => 'commerce/product-read',
			'get-order', 'fluentcart-order-read' => 'commerce/order-read',
			'fluentcart-product-create' => $this->isPublishStatus( $input ) ? 'commerce/product-publish' : 'commerce/product-create-draft',
			'fluentcart-product-update' => $this->updateVerb( $input ),
			'fluentcart-set-product-image' => 'commerce/product-image',
			'media-search' => 'commerce/media-search',
			'generate-image' => 'commerce/image-generate',
			'manage-coupon' => $this->couponVerb( $input ),
			'fluentcart-coupon-create' => 'commerce/coupon-draft',
			'fluentcart-coupon-enable' => 'commerce/coupon-enable',
			'refund-order', 'fluentcart-orders-refund' => 'commerce/refund',
			'fluentcart-shipping-zone-save' => 'commerce/shipping-write',
			'fluentcart-tax-rate-save' => 'commerce/tax-write',
			'get-sales-report', 'fluentcart-store-report' => 'commerce/store-report',
			'save-store-report' => 'commerce/report-save',
			default => null,
		};
	}

	/**
	 * Mirrors Belayline Agent Governance's CouponActivationElevationRule for native-shaped input
	 * (an explicit `action`), and reads the pack's own coupon inputs (no
	 * `action`) by their shape, which {@see shapeCoupon()} then pins to a draft
	 * create or an enable.
	 *
	 *  - `deactivate`, or an explicit `status` of `inactive`: Tier 1.
	 *  - any other explicit `status`: Tier 2 (active, or unrecognised).
	 *  - no status: a create is Tier 2 (FluentCart makes it active); an update
	 *    is Tier 1 only when the stored coupon is currently inactive.
	 *
	 * @param array<string,mixed> $input Call input.
	 */
	private function couponVerb( array $input ): string {
		$action = self::lower( $input['action'] ?? null );
		$status = is_string( $input['status'] ?? null ) ? self::lower( $input['status'] ) : null;

		if ( 'deactivate' === $action || 'inactive' === $status ) {
			return 'commerce/coupon-draft';
		}
		if ( null !== $status && '' !== $status ) {
			return 'commerce/coupon-enable';
		}

		$coupon_id = is_numeric( $input['coupon_id'] ?? null ) ? (int) $input['coupon_id'] : 0;

		if ( '' === $action ) {
			// The pack's own shapes: a code means a draft create, a bare id an enable.
			return ( is_string( $input['code'] ?? null ) && '' !== trim( $input['code'] ) ) ? 'commerce/coupon-draft' : 'commerce/coupon-enable';
		}

		if ( 'update' === $action && $coupon_id > 0 && 'inactive' === FluentCartLookup::couponStatus( $coupon_id ) ) {
			return 'commerce/coupon-draft';
		}

		return 'commerce/coupon-enable';
	}

	/**
	 * Publish wins over price (the card names the more consequential action),
	 * same as WooCommerce.
	 *
	 * @param array<string,mixed> $input Call input.
	 */
	private function updateVerb( array $input ): string {
		if ( $this->isPublishStatus( $input ) ) {
			return 'commerce/product-publish';
		}
		foreach ( array( 'regular_price', 'sale_price' ) as $key ) {
			if ( array_key_exists( $key, $input ) && null !== $input[ $key ] ) {
				return 'commerce/price-change';
			}
		}

		return 'commerce/product-update';
	}

	/** @param array<string,mixed> $input Call input. */
	private function isPublishStatus( array $input ): bool {
		$status = $input['status'] ?? null;

		return is_string( $status ) && in_array( strtolower( trim( $status ) ), array( 'publish', 'future' ), true );
	}

	/**
	 * S19 description rules for the product polyfills (here the pack owns the
	 * ability, so the same validator runs before the call, as it does for
	 * WooCommerce's own).
	 *
	 * @param string              $base_name Ability name segment.
	 * @param array<string,mixed> $input     Call input.
	 */
	public function validateCall( string $base_name, array $input ): ?WP_Error {
		if ( ! in_array( $base_name, array( 'fluentcart-product-create', 'fluentcart-product-update' ), true ) ) {
			return null;
		}

		$validator = new DescriptionValidator();
		foreach ( array( 'description', 'short_description' ) as $field ) {
			if ( ! isset( $input[ $field ] ) || ! is_string( $input[ $field ] ) ) {
				continue;
			}
			$violation = $validator->validate( $input[ $field ] );
			if ( null !== $violation ) {
				return $violation;
			}
		}

		return null;
	}

	/**
	 * Polyfill outputs are flat (`product_id`, `coupon_id`, `order_id`, ...);
	 * native outputs wrap theirs in FluentCart's `{summary,data,meta}` envelope
	 * (`MCPHelper::envelope`), so `data` is read as well.
	 *
	 * @param string              $verb   The pack verb.
	 * @param array<string,mixed> $args   The call's args.
	 * @param array<string,mixed> $output The call's output.
	 */
	public function objectIdForWrite( string $verb, array $args, array $output ): ?string {
		$data = is_array( $output['data'] ?? null ) ? $output['data'] : array();

		$id = match ( $verb ) {
			'commerce/product-create-draft',
			'commerce/product-update',
			'commerce/price-change',
			'commerce/product-publish' => $output['product_id'] ?? $output['id'] ?? $args['id'] ?? null,
			'commerce/product-image' => $output['product_id'] ?? null,
			'commerce/image-generate' => $output['attachment_id'] ?? null,
			'commerce/coupon-draft',
			'commerce/coupon-enable' => $output['coupon_id'] ?? $data['coupon_id'] ?? $args['coupon_id'] ?? null,
			'commerce/refund' => $output['order_id'] ?? $data['order_id'] ?? $args['order_id'] ?? null,
			'commerce/shipping-write' => $output['zone_id'] ?? null,
			'commerce/tax-write' => $output['tax_rate_id'] ?? null,
			'commerce/report-save' => $output['page_id'] ?? null,
			default => null,
		};

		return ( is_numeric( $id ) && (int) $id > 0 ) ? (string) (int) $id : null;
	}
}
