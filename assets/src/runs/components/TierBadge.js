import { __, sprintf } from '@wordpress/i18n';
import { AuiBadge } from './ui';

/**
 * A tier as an integer, or null when there isn't a usable one.
 *
 * The two payloads that carry a tier do not agree on its type: a plan step's
 * `tier` arrives as a number, while a parked approval's arrives as the STRING
 * `"1"` (it is stored that way on the approval step). The first version of
 * this component required `Number.isInteger()`, so the plan card showed badges
 * and the approval card silently showed none — in Agent Safety mode the
 * approver was never told the tier of the call they were approving, including
 * `Tier 2 · irreversible`. Found by the S22 Playwright suite's Agent Safety
 * spec on its first run.
 *
 * Only a genuine integer or an integer-valued string counts. `"1.5"`, `"abc"`,
 * `true` and `null` all yield null, so an unknown tier still renders nothing
 * rather than a misleading badge.
 *
 * @param {unknown} value Raw tier from a step or park payload.
 * @return {number|null} The tier, or null.
 */
function normalizeTier( value ) {
	if ( Number.isInteger( value ) ) {
		return value;
	}

	if ( 'string' === typeof value && /^-?\d+$/.test( value.trim() ) ) {
		return Number.parseInt( value, 10 );
	}

	return null;
}

/**
 * The tier badge (S10): "the tier badge appears in Agent Safety mode only".
 * `gateMode !== 'agent_safety'` renders NOTHING — not a hidden badge, not an
 * empty span — because the built-in gate must never leak the tier concept.
 */
export default function TierBadge( { gateMode, tier: rawTier } ) {
	const tier = normalizeTier( rawTier );

	if ( 'agent_safety' !== gateMode || null === tier ) {
		return null;
	}

	const className = `specflux-site-agent-tier specflux-site-agent-tier-${ tier }`;
	// Plain language for a site owner; the tier number stays one hover away.
	const label =
		0 === tier
			? __( 'Read only', 'specflux-site-agent' )
			: 1 === tier
			? __( 'Makes changes', 'specflux-site-agent' )
			: 2 === tier
			? __( 'Needs approval · can\'t be undone', 'specflux-site-agent' )
			: /* translators: %d: tier number. */
			  sprintf( __( 'Tier %d', 'specflux-site-agent' ), tier );
	/* translators: %d: tier number. */
	const title = sprintf( __( 'Tier %d', 'specflux-site-agent' ), tier );

	// Kit tier vocabulary: Tier 0 neutral, Tier 1 warn, Tier 2 ink.
	const tone = 1 === tier ? 'warn' : tier >= 2 ? 'ink' : undefined;

	return (
		<AuiBadge tone={ tone } className={ className } data-testid="tier-badge" title={ title }>
			{ label }
		</AuiBadge>
	);
}
