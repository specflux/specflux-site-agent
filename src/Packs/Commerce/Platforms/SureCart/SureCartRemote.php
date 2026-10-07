<?php
/**
 * The one door to SureCart's PHP models (C4-S).
 *
 * SureCart keeps its data on SureCart's servers; its models in
 * `app/src/Models/` are API clients, so every `find()` and `update()` here is
 * a network round trip. Everything in the SureCart platform that talks to a
 * model goes through this class, which is also the seam tests replace so no
 * test touches the network.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Packs\Commerce\Platforms\SureCart;

use Closure;
use Throwable;
use WP_Error;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * @internal
 */
final class SureCartRemote {

	/** Models the platform may read or write. Anything else is refused. */
	private const MODELS = array( 'Product', 'Price', 'Coupon', 'Promotion', 'Order', 'Charge' );

	/** @var Closure(string,string,mixed):mixed|null Test seam: `fn( op, model, payload )`. */
	private static ?Closure $override = null;

	/**
	 * Replace the transport (tests). Null restores the live models.
	 *
	 * @param callable|null $transport `fn( string $op, string $model, mixed $payload ): mixed`.
	 */
	public static function use( ?callable $transport ): void {
		self::$override = null === $transport ? null : Closure::fromCallable( $transport );
	}

	/**
	 * GET one object. Null when it cannot be loaded, for any reason.
	 *
	 * @param string $model Model short name, e.g. `Product`.
	 * @param string $id    SureCart object id.
	 * @return array<string,mixed>|null
	 */
	public static function find( string $model, string $id ): ?array {
		if ( ! self::allowed( $model ) || ! self::validId( $id ) ) {
			return null;
		}

		try {
			$result = null !== self::$override
				? ( self::$override )( 'find', $model, $id )
				: self::live( $model, 'find', $id );
		} catch ( Throwable $e ) {
			unset( $e );

			return null;
		}

		return self::toArray( $result );
	}

	/**
	 * PATCH one object. The error is returned, never thrown.
	 *
	 * @param string              $model Model short name.
	 * @param array<string,mixed> $data  Attributes, including `id`.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function update( string $model, array $data ): array|WP_Error {
		$id = $data['id'] ?? null;
		if ( ! self::allowed( $model ) || ! is_string( $id ) || ! self::validId( $id ) ) {
			return new WP_Error( 'surecart_bad_request', 'That SureCart object cannot be updated.', array( 'status' => 400 ) );
		}

		try {
			$result = null !== self::$override
				? ( self::$override )( 'update', $model, $data )
				: self::live( $model, 'update', $data );
		} catch ( Throwable $e ) {
			return new WP_Error( 'surecart_unreachable', 'SureCart could not be reached.', array( 'status' => 502 ) );
		}

		if ( $result instanceof WP_Error ) {
			return $result;
		}

		$array = self::toArray( $result );

		return null === $array
			? new WP_Error( 'surecart_bad_response', 'SureCart answered with something unexpected.', array( 'status' => 502 ) )
			: $array;
	}

	/** SureCart object ids are opaque strings (uuids): never trust anything else. */
	public static function validId( string $id ): bool {
		return 1 === preg_match( '/^[A-Za-z0-9_-]+$/', $id );
	}

	private static function allowed( string $model ): bool {
		return in_array( $model, self::MODELS, true );
	}

	/**
	 * @param string $model Model short name.
	 * @param string $op    `find` or `update`.
	 * @param mixed  $arg   Id or attributes.
	 */
	private static function live( string $model, string $op, mixed $arg ): mixed {
		$class = '\\SureCart\\Models\\' . $model;
		if ( ! class_exists( $class ) ) {
			return null;
		}

		// phpcs:ignore WordPress.NamingConventions.ValidFunctionName -- dynamic static call on SureCart's own model.
		return $class::$op( $arg );
	}

	/**
	 * @param mixed $result A SureCart model, an array, a WP_Error or null.
	 * @return array<string,mixed>|null
	 */
	private static function toArray( mixed $result ): ?array {
		if ( is_array( $result ) ) {
			return $result;
		}
		if ( is_object( $result ) && ! $result instanceof WP_Error && method_exists( $result, 'toArray' ) ) {
			$array = $result->toArray();

			return is_array( $array ) ? $array : null;
		}

		return null;
	}
}
