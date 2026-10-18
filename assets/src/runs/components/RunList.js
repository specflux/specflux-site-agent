import { __, sprintf } from '@wordpress/i18n';
import { RUN_TABS, runsForTab, tabCounts } from '../utils';
import StatusPill from './StatusPill';
import { AuiCard, AuiTabs } from './ui';

/**
 * S22 pseudo-locale finding: `RUN_TABS`' own `label` field is a bare
 * string, deliberately — `utils.js` makes no WordPress calls at all (its
 * own docblock), so it cannot call `__()`. The translated label lives here
 * instead, keyed by the tab's stable `key`, with the untranslated label as
 * a fallback only for a key this map doesn't know about.
 */
const TAB_LABELS = {
	needs_you: __( 'Needs you', 'specflux-site-agent' ),
	running: __( 'Running', 'specflux-site-agent' ),
	finished: __( 'Finished', 'specflux-site-agent' ),
	all: __( 'All', 'specflux-site-agent' ),
};

const tabLabel = ( key ) => TAB_LABELS[ key ] || RUN_TABS.find( ( t ) => t.key === key )?.label || key;

/**
 * The left-hand run list (S10): tabs All / Needs you (count) / Running /
 * Finished, "Needs you" the default. Every tab's count comes from the SAME
 * `runsForTab` predicate that filters its rows, so a count can never drift
 * from the list it describes (a named live-review finding).
 */
export default function RunList( { runs, activeTab, onTabChange, selectedRunId, onSelectRun } ) {
	const counts = tabCounts( runs );
	const visible = runsForTab( runs, activeTab );

	return (
		<AuiCard as="aside" className="specflux-site-agent-run-list" aria-label={ __( 'Runs', 'specflux-site-agent' ) }>
			<AuiTabs
				className="specflux-site-agent-run-tabs"
				tabClassName="specflux-site-agent-run-tab"
				active={ activeTab }
				onChange={ onTabChange }
				tabs={ RUN_TABS.map( ( tab ) => ( {
					key: tab.key,
					label: tabLabel( tab.key ),
					count: counts[ tab.key ],
					tone: 'needs_you' === tab.key && counts[ tab.key ] > 0 ? 'warn' : undefined,
				} ) ) }
			/>
			<ul className="specflux-site-agent-run-rows">
				{ visible.map( ( run ) => (
					<li key={ run.id }>
						<button
							type="button"
							className={ `specflux-site-agent-run-row${ run.id === selectedRunId ? ' is-selected' : '' }` }
							onClick={ () => onSelectRun( run.id ) }
						>
							<span className="specflux-site-agent-run-row-title" dir="auto">{ run.goal }</span>
							<StatusPill status={ run.status } stalled={ run.stalled } />
							<span className="specflux-site-agent-run-row-pack">{ run.pack }</span>
						</button>
					</li>
				) ) }
				{ 0 === visible.length && (
					<li className="specflux-site-agent-run-rows-empty">
						{ /* translators: %s: run list tab label. */ sprintf( __( 'No runs in %s.', 'specflux-site-agent' ), tabLabel( activeTab ) ) }
					</li>
				) }
			</ul>
		</AuiCard>
	);
}
