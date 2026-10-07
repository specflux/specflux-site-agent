<?php
/**
 * Recorded calls and canned answer for the stand-in FluentCart `CouponTools`
 * (tests/stubs/fluentcart-coupon-tools.php). Test-only.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Packs\Commerce\Platforms;

final class FakeFluentCartCoupons {

	/** @var list<array<string,mixed>> */
	public static array $calls = array();

	/** @var array<string,mixed>|\WP_Error|null */
	public static mixed $result = null;
}
