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
	 * Why {@see isActive()} is false: `not_installed` or `below_min_version`.
	 * Null while active.
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
	 * Commerce role => ability-id template (the name segment). The segment is
	 * resolved through {@see abilityNamespaces()}: the platform's native
	 * ability when registered and shape-compatible, else the `senroflux/`
	 * polyfill of the same name.
	 *
	 * @return array<string,string>
	 */
	public function roleMap(): array;

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
