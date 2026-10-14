import { __ } from '@wordpress/i18n';
import { isParkedStatus, isTerminalStatus } from '../utils';

const LABELS = {
	pending: __( 'Pending', 'specflux-site-agent' ),
	running: __( 'Running', 'specflux-site-agent' ),
	awaiting_approval: __( 'Needs you · waiting for approval', 'specflux-site-agent' ),
	awaiting_user: __( 'Needs you · waiting for your answer', 'specflux-site-agent' ),
	awaiting_plan: __( 'Needs you · waiting on the plan', 'specflux-site-agent' ),
	completed: __( 'Completed', 'specflux-site-agent' ),
	failed: __( 'Failed', 'specflux-site-agent' ),
	cancelled: __( 'Cancelled', 'specflux-site-agent' ),
};

/** The run-list/header status pill (S10 park kinds are named in the row). */
export default function StatusPill( { status, stalled } ) {
	const modifier = isParkedStatus( status )
		? 'needs'
		: isTerminalStatus( status )
		? 'done'
		: 'running';

	return (
		<span className={ `specflux-site-agent-pill specflux-site-agent-pill-${ modifier }` }>
			{ stalled ? __( 'Paused while closed — open to continue', 'specflux-site-agent' ) : LABELS[ status ] || status }
		</span>
	);
}
