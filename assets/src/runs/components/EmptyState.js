import { __ } from '@wordpress/i18n';
import { AuiIcon } from './ui';

/**
 * S10 empty state: "Nothing has run yet", a promise sentence that follows the
 * gate mode, and example goals from the packs the viewer can actually run.
 */
export default function EmptyState( { gateMode, examples, onPickExample } ) {
	const promise =
		'agent_safety' === gateMode
			? __( 'It reads what is there, asks when something is unclear, shows you a plan, and waits for your click before anything irreversible.', 'specflux-site-agent' )
			: __( 'It reads what is there, asks when something is unclear, shows you a plan, and asks before it changes anything.', 'specflux-site-agent' );

	return (
		<div className="aui-empty specflux-site-agent-empty-state">
			<AuiIcon name="robot" />
			<h2>{ __( 'Nothing has run yet', 'specflux-site-agent' ) }</h2>
			<p>{ promise }</p>
			{ examples.length > 0 && (
				<ul className="specflux-site-agent-empty-examples">
					{ examples.map( ( example ) => (
						<li key={ example }>
							<button type="button" className="aui-btn aui-btn--secondary specflux-site-agent-empty-example" onClick={ () => onPickExample( example ) }>
								{ example }
							</button>
						</li>
					) ) }
				</ul>
			) }
		</div>
	);
}
