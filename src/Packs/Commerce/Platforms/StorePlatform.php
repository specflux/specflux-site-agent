<?php
/**
 * A store platform the commerce pack can drive (C4-0).
 *
 * The commerce pack owns the platform-neutral half of commerce: the role and
 * verb vocabulary, skills, budgets, the report. Everything that is specific to
 * one store plugin (WooCommerce today; FluentCart and SureCart later) lives
 * behind this interface, so adding a platform is one new class plus one
 * registration on the `senroflux_store_platforms` filter.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Packs\Commerce\Platforms;

use WP_Error;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * One store platform.
 *
 * @internal Not yet a public extension point.
 */
interface StorePlatform {

	/** Stable machine id, e.g. `woo`. Pinned on every run; never reuse one. */
	public function id(): string;

	/** Human label for the settings screen and setup check. */
	public function label(): string;

	/**
	 * Is the platform installed, active and at least its minimum version?
	 * {@see inactiveReason()} says why not.
	 */
	public function isActive(): bool;

	/**
	 * Why {@see isActive()} is false: `not_installed` or `below_min_version`,
	 * or a platform-specific reason when the platform is installed but its
	 * abilities are not usable (SureCart: `abilities_api_missing`,
	 * `store_not_connected`, `abilities_disabled`). Null while active.
	 */
	public function inactiveReason(): ?string;

	/** The minimum supported platform version (the platform's own constant). */
	public function minVersion(): string;

	/** Does SenroGate govern this platform's abilities right now (feature-detected)? */
	public function isGoverned(): bool;

	/**
	 * Ability namespaces roles resolve through, in preference order. MUST end
	 * with `senroflux/` (the polyfill namespace).
	 *
	 * @return list<string>
	 */
	public function abilityNamespaces(): array;

	/** The WordPress capability that gates starting a commerce run on this platform. */
	public function runCapability(): string;

	/**
	 * Commerce role => ability. A value is either a template string (the
	 * native ability and the `senroflux/` polyfill share that name segment),
	 * or `array{polyfill:string, native?:string, shape?:callable|null}`:
	 * `polyfill` is the `senroflux/` template, `native` the platform ability's
	 * namespace-relative name when it differs (default: the polyfill name), and
	 * `shape` an optional `fn(array $input): array` turning the pack's input
	 * into the native ability's input, applied only when the native ability
	 * (not the polyfill) was resolved, after validation and approval.
	 * {@see StorePlatforms::roleSpec()} normalises a value.
	 *
	 * The shape-compat check uses {@see inputProperties()} for the role's
	 * polyfill template against the native schema, so a shaped role should
	 * return the native keys (or none).
	 *
	 * @return array<string,string|array{polyfill:string,native?:string,shape?:callable|null}>
	 */
	public function roleMap(): array;

	/**
	 * The capability a human needs to see and undo this platform's store
	 * changes (`manage_woocommerce` for Woo).
	 */
	public function restoreCapability(): string;

	/**
	 * A platform-specific approval card for a Tier-2 pack verb, or null to use
	 * the commerce pack's default (Woo-shaped) card.
	 *
	 * @param string              $pack_verb The resolved commerce verb.
	 * @param string              $base_name The ability name segment.
	 * @param array<string,mixed> $input     Call input.
	 */
	public function approvalSummary( string $pack_verb, string $base_name, array $input ): ?string;

	/**
	 * Run-report lookup for a prefixed store object id (`order:`, `coupon:`,
	 * `zone:`, `taxrate:`, `page:`), or null to use the default (Woo) lookup.
	 *
	 * @param string $object_id The tracked object id.
	 * @return array{object_type:string,title:string,status:string,edit_url:?string,preview_url:?string}|null
	 */
	public function describeObject( string $object_id ): ?array;

	/**
	 * The `senroflux/` polyfill templates this platform needs registered.
	 *
	 * @return list<string>
	 */
	public function polyfills(): array;

	/**
	 * SenroFlux-side verb => tier mirror (kept in step with SenroGate's catalog
	 * by the parity test).
	 *
	 * @return array<string,int>
	 */
	public function verbTiers(): array;

	/**
	 * Input-property keys the pack's client sends for an ability template
	 * (shape-compat check against the platform's native abilities).
	 *
	 * @param string $template Ability template.
	 * @return list<string>
	 */
	public function inputProperties( string $template ): array;

	/**
	 * The pack verb for an ability (by name segment) and its input, or null
	 * when the platform does not know the ability.
	 *
	 * @param string              $base_name Ability name segment.
	 * @param array<string,mixed> $input     Call input.
	 */
	public function verbFor( string $base_name, array $input ): ?string;

	/**
	 * Pre-call refusal for a platform-owned ability, or null.
	 *
	 * @param string              $base_name Ability name segment.
	 * @param array<string,mixed> $input     Call input.
	 */
	public function validateCall( string $base_name, array $input ): ?WP_Error;

	/**
	 * The id a Tier >= 1 write produced, read from the call's args/output, or null.
	 *
	 * @param string              $verb   The pack verb.
	 * @param array<string,mixed> $args   The call's args.
	 * @param array<string,mixed> $output The call's output.
	 */
	public function objectIdForWrite( string $verb, array $args, array $output ): ?string;
}
