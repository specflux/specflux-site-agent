<?php
/**
 * The combined `senroflux-content` Capability Pack SenroFlux offers SenroGate.
 *
 * A role binds to ONE SenroGate pack, and no single per-pack descriptor passes
 * all three content binding checks (the site pack lacks the term abilities the
 * posts pack needs, and SenroGate's `owner` pack approves nothing, so it is
 * refused). This bundle is the strict union of the pages, posts and site
 * allow-lists with tier 2 behind approval: binding a role to it unlocks all
 * three. It adds no ability of its own and never widens the three lists.
 *
 * SenroFlux only DESCRIBES the pack. Binding a role to it is SenroGate's own
 * Capability Packs page, saved by a human.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Packs;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

/**
 * Builds the combined content pack descriptor for SenroGate.
 */
final class ContentBundle {

	/** The SenroGate pack name a role is bound to. */
	public const NAME = 'senroflux-content';

	/** The SenroFlux packs the bundle unlocks. */
	public const PACK_NAMES = array( 'pages', 'posts', 'site' );

	/** Is this SenroFlux pack one the bundle covers? */
	public static function covers( string $pack_name ): bool {
		return in_array( $pack_name, self::PACK_NAMES, true );
	}

	/**
	 * The SenroGate descriptor: the union of the given packs' allow-lists.
	 *
	 * @param Pack ...$packs The content packs to union (pages, posts, site).
	 * @return object|null null when SenroGate's Pack class is absent.
	 */
	public static function agentSafetyPack( Pack ...$packs ): ?object {
		if ( ! class_exists( \Specflux\AgentSafety\Packs\Pack::class ) ) {
			return null;
		}

		return new \Specflux\AgentSafety\Packs\Pack(
			name: self::NAME,
			allow: self::allowList( ...$packs ),
			approvalByClass: array( 'tier2' => true ),
		);
	}

	/**
	 * Deduped union of the packs' allow-lists, in first-seen order.
	 *
	 * @param Pack ...$packs Packs to union.
	 * @return list<string>
	 */
	public static function allowList( Pack ...$packs ): array {
		$union = array();
		foreach ( $packs as $pack ) {
			foreach ( $pack->allowList() as $ability ) {
				$union[ $ability ] = true;
			}
		}

		return array_map( 'strval', array_keys( $union ) );
	}
}
