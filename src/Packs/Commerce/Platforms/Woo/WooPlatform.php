<?php
/**
 * WooCommerce as a store platform (C4-0): everything the commerce pack used to
 * hard-code for Woo, moved here unchanged.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Packs\Commerce\Platforms\Woo;

use Specflux\SenroFlux\Packs\Commerce\DescriptionValidator;
use Specflux\SenroFlux\Packs\Commerce\Platforms\StorePlatform;
use Specflux\SenroFlux\Plugin;
use Specflux\SenroFlux\Run\GateMode;
use WP_Error;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce.
 *
 * @internal
 */
final class WooPlatform implements StorePlatform {

	public const ID = 'woo';

	/**
	 * Minimum WooCommerce version. '0' = no floor: the pack never had one, and
	 * raising it is a deliberate behaviour change, not part of C4-0.
	 */
	public const MIN_VERSION = '0';

	public function id(): string {
		return self::ID;
	}

	public function label(): string {
		return 'WooCommerce';
	}

	public function isActive(): bool {
		return null === $this->inactiveReason();
	}

	public function inactiveReason(): ?string {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return 'not_installed';
		}
		if ( defined( 'WC_VERSION' ) && version_compare( (string) constant( 'WC_VERSION' ), self::MIN_VERSION, '<' ) ) {
			return 'below_min_version';
		}

		return null;
	}

	public function minVersion(): string {
		return self::MIN_VERSION;
	}

	/** Woo's own session abilities are governed by SenroGate's Woo module, which exists only in SenroGate mode. */
	public function isGoverned(): bool {
		return GateMode::AgentSafety === Plugin::currentGateMode();
	}

	/** @return list<string> */
	public function abilityNamespaces(): array {
		return array( 'woocommerce/', 'senroflux/' );
	}

	public function runCapability(): string {
		return 'manage_woocommerce';
	}

	/** @return array<string,string|array{polyfill:string,native?:string,shape?:callable|null}> */
	public function roleMap(): array {
		return array(
			'read'           => 'products-query',
			'catalogue'      => 'products-catalogue',
			'create'         => 'product-create',
			'update'         => 'product-update',
			'image'          => 'set-product-image',
			'search'         => 'media-search',
			'image-generate' => 'generate-image',
			'coupon-create'  => 'coupon-create',
			'coupon-enable'  => 'coupon-enable',
			// 'order-note' resolves to ONE ability (Woo's own `order-add-note`)
			// that spans TWO pack verbs, same shape as 'update'.
			'order-read'     => 'orders-query',
			'order-note'     => 'order-add-note',
			'refund'         => 'orders-refund',
			'shipping-write' => 'shipping-zone-save',
			'tax-write'      => 'tax-rate-save',
			'store-report'   => 'store-report',
			'report-save'    => 'save-store-report',
		);
	}

	public function restoreCapability(): string {
		return 'manage_woocommerce';
	}

	/** Woo keeps the commerce pack's built-in cards. */
	public function approvalSummary( string $pack_verb, string $base_name, array $input ): ?string {
		unset( $pack_verb, $base_name, $input );

		return null;
	}

	/** Woo keeps the built-in lookup. */
	public function describeObject( string $object_id ): ?array {
		unset( $object_id );

		return null;
	}

	/** @return list<string> */
	public function polyfills(): array {
		return array(
			'set-product-image',
			'coupon-create',
			'coupon-enable',
			'orders-refund',
			'shipping-zone-save',
			'tax-rate-save',
			'products-catalogue',
			'store-report',
			'save-store-report',
		);
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
	 * The input-property keys the client sends per template, checked only
	 * against Woo's OWN abilities.
	 *
	 * @param string $template Ability template.
	 * @return list<string>
	 */
	public function inputProperties( string $template ): array {
		return match ( $template ) {
			'products-query'  => array( 'id' ),
			'product-create'  => array( 'name', 'sku', 'description', 'short_description', 'status', 'regular_price', 'sale_price' ),
			'product-update'  => array( 'id', 'name', 'sku', 'description', 'short_description', 'status', 'regular_price', 'sale_price' ),
			'media-search'    => array( 'query' ),
			'orders-query'    => array( 'id' ),
			'order-add-note'  => array( 'id', 'note', 'customer_note' ),
			default           => array(),
		};
	}

	/**
	 * The S19 verb predicate, by ability name segment.
	 *
	 * @param string              $base_name Ability name segment.
	 * @param array<string,mixed> $input     Call input.
	 */
	public function verbFor( string $base_name, array $input ): ?string {
		return match ( $base_name ) {
			'products-query'     => 'commerce/product-read',
			'products-catalogue' => 'commerce/catalogue-read',
			'product-create'     => $this->createVerb( $input ),
			'product-update'     => $this->updateVerb( $input ),
			'set-product-image'  => 'commerce/product-image',
			'media-search'       => 'commerce/media-search',
			'generate-image'     => 'commerce/image-generate',
			'coupon-create'      => 'commerce/coupon-draft',
			'coupon-enable'      => 'commerce/coupon-enable',
			'orders-query'       => 'commerce/order-read',
			'order-add-note'     => $this->noteVerb( $input ),
			'orders-refund'      => 'commerce/refund',
			'shipping-zone-save' => 'commerce/shipping-write',
			'tax-rate-save'      => 'commerce/tax-write',
			'store-report'       => 'commerce/store-report',
			'save-store-report'  => 'commerce/report-save',
			default              => null,
		};
	}

	/**
	 * S19: BEFORE a call to a WOO-OWNED ability (`product-create`,
	 * `product-update`) reaches that ability's own permission check, refuse a
	 * description/short_description with a disallowed tag, an unsafe link
	 * scheme, or too many words (D3).
	 *
	 * @param string              $base_name Ability name segment.
	 * @param array<string,mixed> $input     Call input.
	 */
	public function validateCall( string $base_name, array $input ): ?WP_Error {
		if ( ! in_array( $base_name, array( 'product-create', 'product-update' ), true ) ) {
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
	 * Woo's own `product-create`/`product-update` nest the product under
	 * `product`; `order-add-note` answers the note, so the order is the call's
	 * own `id`; every polyfill names its own key.
	 *
	 * @param string              $verb   The pack verb.
	 * @param array<string,mixed> $args   The call's args.
	 * @param array<string,mixed> $output The call's output.
	 */
	public function objectIdForWrite( string $verb, array $args, array $output ): ?string {
		$product = is_array( $output['product'] ?? null ) ? $output['product'] : array();

		$id = match ( $verb ) {
			'commerce/product-create-draft',
			'commerce/product-update',
			'commerce/price-change',
			'commerce/product-publish' => $product['id'] ?? $output['id'] ?? $args['id'] ?? null,
			'commerce/product-image' => $output['product_id'] ?? null,
			'commerce/image-generate' => $output['attachment_id'] ?? null,
			'commerce/coupon-draft',
			'commerce/coupon-enable' => $output['coupon_id'] ?? null,
			'commerce/order-note-private',
			'commerce/order-note-customer' => $args['id'] ?? null,
			'commerce/refund' => $output['order_id'] ?? null,
			'commerce/shipping-write' => $output['zone_id'] ?? null,
			'commerce/tax-write' => $output['tax_rate_id'] ?? null,
			'commerce/report-save' => $output['page_id'] ?? null,
			default => null,
		};

		return ( is_numeric( $id ) && (int) $id > 0 ) ? (string) (int) $id : null;
	}

	/**
	 * `order-add-note`'s predicate: only a strict `true` `customer_note` is the
	 * customer-visible (Tier 2, ungrantable) note.
	 *
	 * @param array<string,mixed> $input Call input.
	 */
	private function noteVerb( array $input ): string {
		return true === ( $input['customer_note'] ?? false )
			? 'commerce/order-note-customer'
			: 'commerce/order-note-private';
	}

	/**
	 * `product-create`'s predicate: only `publish`/`future` make it a publish.
	 *
	 * @param array<string,mixed> $input Call input.
	 */
	private function createVerb( array $input ): string {
		return $this->isPublishStatus( $input ) ? 'commerce/product-publish' : 'commerce/product-create-draft';
	}

	/**
	 * `product-update`'s predicate. When one call carries both a price field
	 * and a publish/future status, PUBLISH WINS (fail-closed: the card names
	 * the more consequential action).
	 *
	 * @param array<string,mixed> $input Call input.
	 */
	private function updateVerb( array $input ): string {
		if ( $this->isPublishStatus( $input ) ) {
			return 'commerce/product-publish';
		}
		if ( $this->isPriceChange( $input ) ) {
			return 'commerce/price-change';
		}

		return 'commerce/product-update';
	}

	/**
	 * S19: "when `regular_price` or `sale_price` is present" — present AND
	 * non-null.
	 *
	 * @param array<string,mixed> $input Call input.
	 */
	private function isPriceChange( array $input ): bool {
		foreach ( array( 'regular_price', 'sale_price' ) as $key ) {
			if ( array_key_exists( $key, $input ) && null !== $input[ $key ] ) {
				return true;
			}
		}

		// Mirrors SenroGate's guarded meta keys: a price or stock write via
		// `meta_data` is the same money-shaping edit as the named fields.
		$meta = $input['meta_data'] ?? null;
		if ( is_array( $meta ) ) {
			foreach ( $meta as $entry ) {
				if ( is_array( $entry ) && is_string( $entry['key'] ?? null ) && in_array( $entry['key'], array( '_price', '_regular_price', '_sale_price', '_stock', '_manage_stock' ), true ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * @param array<string,mixed> $input Call input.
	 */
	private function isPublishStatus( array $input ): bool {
		$status = $input['status'] ?? null;
		if ( ! is_string( $status ) ) {
			return false;
		}

		return in_array( strtolower( trim( $status ) ), array( 'publish', 'future' ), true );
	}
}
