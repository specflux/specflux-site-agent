import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { AuiButton, AuiIcon } from './ui';

/** S20: a suggestion's own per-line cap — {@see \Specflux\SiteAgent\Tools\SuggestBriefTool::MAX_TEXT_CHARS}. */
const MAX_TEXT_CHARS = 200;

/**
 * A brief suggestion (0.3 S20), ported from the retired PHP card in
 * `src/Admin/RunsScreen.php` (stage 16) into the React chat stream.
 *
 * The model never writes the site brief — only a human click on Save does,
 * through `POST /specflux-site-agent/v1/runs/{id}/suggestions/{seq}`
 * ({@see \Specflux\SiteAgent\Run\SuggestionResolver::resolve()}), which is
 * itself gated by `manage_options` server-side. This component mirrors that
 * split rather than re-deciding it: an administrator gets Save/Dismiss with
 * editable text; anyone else gets the text as read-only, copyable content
 * and a line explaining who can act on it — no buttons at all, since a
 * non-admin clicking anything here could never actually do anything (the
 * REST route would 403 it).
 *
 * @param {Object}   props
 * @param {Object}   props.suggestion     `{ seq, text, status }` — status is
 *                                        'pending' | 'saved' | 'dismissed'.
 * @param {boolean}  props.canManageBrief Server-computed `current_user_can( 'manage_options' )`
 *                                        (0.3 S20) — never re-derived from a role
 *                                        guess on the client.
 * @param {Function} [props.onResolve]    `( seq, action, text ) => Promise` —
 *                                        omitted renders the actions disabled.
 */
export default function SuggestionCard( { suggestion, canManageBrief, onResolve } ) {
	const [ text, setText ] = useState( suggestion.text );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );

	const pending = 'pending' === suggestion.status;

	const submit = ( action ) => {
		if ( ! onResolve || busy ) {
			return;
		}
		setBusy( true );
		setError( '' );
		onResolve( suggestion.seq, action, text.trim() )
			.catch( ( err ) => {
				setError( ( err && err.message ) || __( 'Could not save this suggestion.', 'specflux-site-agent' ) );
			} )
			.finally( () => {
				setBusy( false );
			} );
	};

	return (
		<div className="aui-card aui-approval specflux-site-agent-suggestion-card">
			<div className="aui-approval-h">
				<AuiIcon name="pencil" />
				<h4 className="specflux-site-agent-suggestion-heading">{ __( 'Suggested addition to the site brief', 'specflux-site-agent' ) }</h4>
			</div>

			{ ! pending && (
				<p className="aui-muted specflux-site-agent-suggestion-status">
					{ 'saved' === suggestion.status
						? __( 'Saved to the site brief.', 'specflux-site-agent' )
						: __( 'Dismissed.', 'specflux-site-agent' ) }
				</p>
			) }

			{ canManageBrief ? (
				pending ? (
					<>
						<textarea
							className="aui-textarea specflux-site-agent-suggestion-text"
							value={ text }
							onChange={ ( e ) => setText( e.target.value ) }
							maxLength={ MAX_TEXT_CHARS }
							disabled={ busy }
							aria-label={ __( 'Suggested brief text', 'specflux-site-agent' ) }
						/>
						{ error && <p className="specflux-site-agent-suggestion-error">{ error }</p> }
						<div className="aui-approval-f specflux-site-agent-suggestion-actions">
							<AuiButton variant="primary" disabled={ busy || '' === text.trim() } onClick={ () => submit( 'save' ) }>
								{ __( 'Save', 'specflux-site-agent' ) }
							</AuiButton>
							<AuiButton disabled={ busy } onClick={ () => submit( 'dismiss' ) }>
								{ __( 'Dismiss', 'specflux-site-agent' ) }
							</AuiButton>
						</div>
					</>
				) : (
					<pre className="specflux-site-agent-suggestion-text-final" dir="auto">{ suggestion.text }</pre>
				)
			) : (
				<>
					<pre className="specflux-site-agent-suggestion-text-final" dir="auto" tabIndex={ 0 }>
						{ suggestion.text }
					</pre>
					{ pending && (
						<p className="aui-muted specflux-site-agent-suggestion-admin-only">
							{ __( 'An administrator can add this to the site brief.', 'specflux-site-agent' ) }
						</p>
					) }
				</>
			) }
		</div>
	);
}
