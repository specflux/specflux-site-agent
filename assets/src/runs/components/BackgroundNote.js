import { __ } from '@wordpress/i18n';

/**
 * The one-line answer to "what happens if I close this page?" for a running
 * run. `background` is the server's `{ available, reason }` (reason is
 * 'disabled' | 'missing' | 'too_old' when unavailable).
 */
export default function BackgroundNote( { background } ) {
	if ( ! background ) {
		return null;
	}

	if ( background.available ) {
		return (
			<p className="specflux-site-agent-background-note" data-background="available">
				{ __( 'Keeps running if you close this page.', 'specflux-site-agent' ) }
			</p>
		);
	}

	const hint = __( 'This run only advances while this page is open.', 'specflux-site-agent' );
	let why;
	switch ( background.reason ) {
		case 'disabled':
			why = __( 'Background running is turned off on this site.', 'specflux-site-agent' );
			break;
		case 'missing':
			why = __( 'Background running is unavailable because Action Scheduler is missing.', 'specflux-site-agent' );
			break;
		case 'too_old':
			why = __( 'Background running is unavailable because this site’s Action Scheduler is too old (3.6 or newer is needed).', 'specflux-site-agent' );
			break;
		default:
			why = __( 'Background running is unavailable.', 'specflux-site-agent' );
	}

	return (
		<p
			className="specflux-site-agent-background-note specflux-site-agent-background-note--unavailable"
			data-background={ background.reason || 'unavailable' }
		>
			{ why } { hint }
		</p>
	);
}
