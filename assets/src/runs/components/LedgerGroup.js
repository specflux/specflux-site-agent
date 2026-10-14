import { useState } from '@wordpress/element';
import { __, sprintf, _n } from '@wordpress/i18n';
import TierBadge from './TierBadge';
import ResultCard from './ResultCard';
import { stepLabel, stepResult, stepVerb, stepTier, ledgerLabels } from '../utils';

/**
 * A collapsed run of consecutive tool calls (S10: "consecutive tool calls
 * collapse to 'N actions'"). Expanded, each shows a label, the ability id, a
 * tier badge (AS mode only) and the result; a resolved approval is marked on
 * its call.
 *
 * @param {Object}  props
 * @param {Array}   props.calls    `{ step, approvedByViewer }` entries from `groupSteps`.
 * @param {string}  props.gateMode 'agent_safety' | 'built_in'.
 */
export default function LedgerGroup( { calls, gateMode } ) {
	const [ open, setOpen ] = useState( false );
	const count = calls.length;
	const toolCallFallback = __( 'Tool call', 'specflux-site-agent' );
	const summaryLabels = ledgerLabels( calls, toolCallFallback ).join( ', ' );

	return (
		<details className="specflux-site-agent-ledger-group" open={ open } onToggle={ ( e ) => setOpen( e.target.open ) }>
			<summary>
				{ /* translators: %d: number of actions in this ledger group. */ sprintf( _n( '%d action', '%d actions', count, 'specflux-site-agent' ), count ) }
				{ /* S22 pseudo-locale: the labels are mechanically derived from
				 * the raw ability id (stepLabel()), not translatable UI
				 * chrome — data-specflux-site-agent-content marks it as data. */ }
				{ summaryLabels && <span data-specflux-site-agent-content>{ `: ${ summaryLabels }` }</span> }
			</summary>
			<ol className="specflux-site-agent-ledger">
				{ calls.map( ( call, index ) => (
					<li key={ index } className="specflux-site-agent-ledger-row">
						<span className="specflux-site-agent-ledger-status">{ 'rejected' === call.step.status ? '✕' : '✓' }</span>
						<span className="specflux-site-agent-ledger-label">{ stepLabel( call.step, toolCallFallback ) }</span>
						<span className="specflux-site-agent-ledger-verb">{ stepVerb( call.step ) }</span>
						<TierBadge gateMode={ gateMode } tier={ stepTier( call.step ) } />
						{ call.approvedByViewer && (
							<span className="specflux-site-agent-ledger-you">{ __( 'Approved by you', 'specflux-site-agent' ) }</span>
						) }
						<span className="specflux-site-agent-ledger-result">
							{ 'rejected' === call.step.status ? (
								<span className="specflux-site-agent-ledger-you">{ __( 'Rejected by you, not done', 'specflux-site-agent' ) }</span>
							) : (
								/* S22 pseudo-locale: raw tool-result data (often
								 * a JSON dump), never chrome. */
								<ResultCard display={ call.step.display } raw={ stepResult( call.step ) } />
							) }
						</span>
					</li>
				) ) }
			</ol>
		</details>
	);
}
