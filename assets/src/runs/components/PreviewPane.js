import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
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
		<aside
			className={ 'senroflux-preview-pane' + ( collapsed ? ' is-collapsed' : '' ) }
			aria-label={ __( 'Preview', 'senroflux' ) }
		>
			<button
				type="button"
				className="senroflux-preview-toggle button"
				aria-expanded={ ! collapsed }
				aria-controls="senroflux-preview-body"
				onClick={ toggle }
			>
				{ collapsed ? __( 'Show preview', 'senroflux' ) : __( 'Hide preview', 'senroflux' ) }
			</button>
			<div id="senroflux-preview-body" className="senroflux-preview-body" hidden={ collapsed }>
				<ResultCard display={ display } raw="" />
				{ url && (
					<p className="senroflux-preview-open">
						<a href={ url } target="_blank" rel="noopener noreferrer">
							{ __( 'Open preview in a new tab', 'senroflux' ) }
							<span className="screen-reader-text">{ ' ' + __( '(opens in a new tab)', 'senroflux' ) }</span>
						</a>
					</p>
				) }
				{ iframe && url && (
					<iframe
						key={ `${ url }#${ seq }` }
						className="senroflux-preview-frame"
						title={ __( 'Preview of the latest draft', 'senroflux' ) }
						src={ url }
						sandbox={ PREVIEW_SANDBOX }
						referrerPolicy="no-referrer"
						loading="lazy"
					/>
				) }
			</div>
		</aside>
	);
}
