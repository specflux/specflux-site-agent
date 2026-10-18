import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { planProgress } from '../utils';
import { AuiButton } from './ui';

/** S10: pinned plan above the chat, with a Hide link and the pre-plan sentence. */
export default function PinnedPlan( { plan, steps } ) {
	const [ hidden, setHidden ] = useState( false );

	if ( ! plan ) {
		return (
			<div className="aui-card specflux-site-agent-pinned-plan specflux-site-agent-pinned-plan-none">
				<p>{ __( 'No plan yet. Nothing will change until you accept one.', 'specflux-site-agent' ) }</p>
			</div>
		);
	}

	const progress = planProgress( plan, steps );
	const done = progress.reduce( ( sum, p ) => sum + p.done, 0 );
	const total = progress.reduce( ( sum, p ) => sum + p.total, 0 );

	// `planProgress()` is a best-effort sequential match (see its docblock),
	// not an authoritative index the REST payload carries — the "~" prefix
	// and title make that visible in the UI instead of only in a comment, so
	// the count is never presented as more exact than it is.
	const approxTitle = __(
		'This count is estimated by matching the plan against executed calls in order; it may be briefly wrong if calls run out of the plan\'s listed order.',
		'specflux-site-agent'
	);
	const approxCount = ( doneCount, totalCount ) =>
		sprintf(
			/* translators: 1: steps done, 2: total steps; "~" marks the count as approximate. */
			__( '~%1$d / %2$d', 'specflux-site-agent' ),
			doneCount,
			totalCount
		);

	return (
		<div className="aui-card specflux-site-agent-pinned-plan">
			<div className="specflux-site-agent-pinned-plan-header">
				<span className="specflux-site-agent-pinned-plan-goal" dir="auto">{ plan.goal }</span>
				<span className="aui-num specflux-site-agent-pinned-plan-count" dir="ltr" title={ approxTitle }>
					{ approxCount( done, total ) }
				</span>
				<AuiButton variant="ghost" size="sm" className="specflux-site-agent-pinned-plan-toggle" onClick={ () => setHidden( ! hidden ) }>
					{ hidden ? __( 'Show', 'specflux-site-agent' ) : __( 'Hide', 'specflux-site-agent' ) }
				</AuiButton>
			</div>
			{ ! hidden && (
				<ol className="aui-steps specflux-site-agent-pinned-plan-steps">
					{ ( plan.steps || [] ).map( ( planStep, index ) => (
						<li key={ index } className={ progress[ index ] && progress[ index ].waiting ? 'is-waiting' : '' }>
							<span className="specflux-site-agent-plan-step-text" dir="auto">{ planStep.text }</span>
							{ progress[ index ] && progress[ index ].total > 1 && (
								<span className="specflux-site-agent-plan-step-count" dir="ltr" title={ approxTitle }>
									{ approxCount( progress[ index ].done, progress[ index ].total ) }
								</span>
							) }
							{ progress[ index ] && progress[ index ].waiting && (
								<span className="specflux-site-agent-plan-step-waiting">{ __( '· waiting for you', 'specflux-site-agent' ) }</span>
							) }
						</li>
					) ) }
				</ol>
			) }
		</div>
	);
}
