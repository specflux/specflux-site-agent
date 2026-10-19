import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { dismissCronHint } from '../api';
import { AuiButton, AuiNotice } from './ui';

/**
 * The real-cron hint. The server decides whether it applies (default WP-Cron
 * and a run was handed to the background, not yet dismissed); this only shows
 * it and remembers a dismissal per user.
 */
export default function CronHintNotice( { config } ) {
	const [ hidden, setHidden ] = useState( false );

	if ( ! config.cronHint || hidden ) {
		return null;
	}

	const dismiss = () => {
		setHidden( true );
		dismissCronHint( config ).catch( () => {} );
	};

	return (
		<AuiNotice tone="info" className="specflux-site-agent-cron-hint" data-cron-hint="">
			<p>
				{ __( 'Background runs only move when someone visits your site. On a quiet site, set up a real cron job so they keep going.', 'specflux-site-agent' ) }{ ' ' }
				{ config.cronHelpUrl && (
					<a href={ config.cronHelpUrl } target="_blank" rel="noopener noreferrer">
						{ __( 'How to set up a real cron job', 'specflux-site-agent' ) }
					</a>
				) }
			</p>
			<AuiButton variant="ghost" size="sm" onClick={ dismiss }>
				{ __( 'Dismiss', 'specflux-site-agent' ) }
			</AuiButton>
		</AuiNotice>
	);
}
