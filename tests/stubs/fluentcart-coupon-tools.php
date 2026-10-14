<?php
/**
 * Stand-in for FluentCart's `CouponTools` (the code its MCP `manage-coupon`
 * runs), so the coupon polyfills can be exercised without FluentCart.
 * Test-only; the namespace is FluentCart's own on purpose.
 *
 * @package Specflux Site Agent
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- FluentCart's real namespace, faked.

declare ( strict_types = 1 );

namespace FluentCart\App\Modules\MCP\Tools;

use Specflux\SiteAgent\Tests\Packs\Commerce\Platforms\FakeFluentCartCoupons;

final class CouponTools {

	/**
	 * @param array<string,mixed> $params Native `manage-coupon` input.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function manageCoupon( array $params = array() ): array|\WP_Error {
		FakeFluentCartCoupons::$calls[] = $params;

		return FakeFluentCartCoupons::$result ?? array();
	}
}
