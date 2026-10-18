import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { AuiButton, AuiCard } from './ui';
import ResultCard, { safeUrl } from './ResultCard';
import { latestPreviewable, readPreviewCollapsed, storePreviewCollapsed } from '../utils';
import { PREVIEW_IFRAME_ENABLED, PREVIEW_SANDBOX } from '../previewConfig';

/**
 * The right-hand preview pane: the latest previewable object (a post, page or
 * product with a preview or view link) from the run's tool results.
 *
 * Without the iframe, it shows the result card plus "Open preview in a new
 * tab", the same exposure WordPress's own Preview button has. With
 * `iframe` on it also frames the page under `sandbox="allow-scripts"` and
 * NEVER `allow-same-origin`, so script in model-written content runs in an
 * opaque origin and cannot touch wp-admin.
 *
 * Collapsed state is remembered per user in localStorage.
 *
 * @param {Object}  props
 * @param {Array}   props.steps    The run's steps.
 * @param {number}  [props.userId] Current user id (keys the saved state).
 * @param {boolean} [props.iframe] Override for {@see PREVIEW_IFRAME_ENABLED}.
 */
export default function PreviewPane( { steps, userId, iframe = PREVIEW_IFRAME_ENABLED } ) {
	const [ collapsed, setCollapsed ] = useState( () => readPreviewCollapsed( userId ) );
	const found = latestPreviewable( steps );
	if ( ! found ) {
		return null;
	}

	const { display, link, seq } = found;
	const url = safeUrl( link.url );
	const toggle = () => {
		const next = ! collapsed;
		setCollapsed( next );
		storePreviewCollapsed( userId, next );
	};

	return (
		<AuiCard
			as="aside"
			className={ 'specflux-site-agent-preview-pane' + ( collapsed ? ' is-collapsed' : '' ) }
			aria-label={ __( 'Preview', 'specflux-site-agent' ) }
		>
			<AuiButton
				size="sm"
				className="specflux-site-agent-preview-toggle"
				aria-expanded={ ! collapsed }
				aria-controls="specflux-site-agent-preview-body"
				onClick={ toggle }
			>
				{ collapsed ? __( 'Show preview', 'specflux-site-agent' ) : __( 'Hide preview', 'specflux-site-agent' ) }
			</AuiButton>
			<div id="specflux-site-agent-preview-body" className="specflux-site-agent-preview-body" hidden={ collapsed }>
				<ResultCard display={ display } raw="" />
				{ url && (
					<p className="specflux-site-agent-preview-open">
						<a href={ url } target="_blank" rel="noopener noreferrer">
							{ __( 'Open preview in a new tab', 'specflux-site-agent' ) }
							<span className="screen-reader-text">{ ' ' + __( '(opens in a new tab)', 'specflux-site-agent' ) }</span>
						</a>
					</p>
				) }
				{ iframe && url && (
					<iframe
						key={ `${ url }#${ seq }` }
						className="specflux-site-agent-preview-frame"
						title={ __( 'Preview of the latest draft', 'specflux-site-agent' ) }
						src={ url }
						sandbox={ PREVIEW_SANDBOX }
						referrerPolicy="no-referrer"
						loading="lazy"
					/>
				) }
			</div>
		</AuiCard>
	);
}
