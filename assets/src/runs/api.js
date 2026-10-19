/**
 * REST + admin-ajax calls the Runs screen drives (S9/S10). `listRuns`/
 * `getRun` are plain reads over REST (both scoped by `Plugin::maySee()`,
 * which already covers a screen-capability holder viewing a delegated run).
 *
 * `startRun`/`tickRun`/`cancelRun` go over **admin-ajax**, not REST (17c):
 * {@see \Specflux\SiteAgent\Admin\ScreenCapability::tickAsScreen()} — the
 * delegation allowance that lets a screen-capability holder resolve a run
 * they do not own — only wraps `Ajax::handleTick()`. REST's `/runs/{id}/tick`
 * calls `specflux_site_agent()->tick()` directly with no such wrapper, so it would
 * 403 a delegated (non-owner) tick that admin-ajax accepts. Using REST here
 * would silently narrow what the screen can do relative to the retired PHP
 * cards, so this stage uses admin-ajax for every WRITE action and keeps REST
 * for the two reads.
 */

import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

const NAMESPACE = '/specflux-site-agent/v1';

/** GET /specflux-site-agent/v1/runs -> `{ runs: [...] }` (release/0.3, 0c1107e). */
export function listRuns() {
	return apiFetch( { path: `${ NAMESPACE }/runs` } ).then( ( response ) => response.runs || [] );
}

/** GET /specflux-site-agent/v1/runs/{id} -> `{ run, steps, suggestions, ui }`. */
export function getRun( runId ) {
	return apiFetch( { path: `${ NAMESPACE }/runs/${ runId }` } );
}

/**
 * POST one admin-ajax action (`specflux_site_agent_start`/`_tick`/`_cancel`), fail
 * closed on a non-2xx transport error or a `{ success: false }` envelope —
 * the caller always gets either the RunState `data` or a thrown Error whose
 * `.code`/`.message` mirror the server's `{ code, message }` shape.
 *
 * @param {string}                    action Ajax action name (without the `specflux_site_agent_` prefix already applied).
 * @param {Object}                    fields Extra POST fields (nonce is added here).
 * @param {{ajaxUrl:string,nonce:string}} config Screen config (`window.specfluxSiteAgentRunsConfig`).
 */
function postAjax( action, fields, config ) {
	const body = new URLSearchParams();
	body.set( 'action', action );
	body.set( 'nonce', config.nonce || '' );
	Object.keys( fields ).forEach( ( key ) => {
		const value = fields[ key ];
		if ( undefined === value || null === value ) {
			return;
		}
		body.set( key, 'object' === typeof value ? JSON.stringify( value ) : String( value ) );
	} );

	return window
		.fetch( config.ajaxUrl || window.ajaxurl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body,
		} )
		.then( ( response ) => response.json().then( ( json ) => ( { status: response.status, json } ) ) )
		.then( ( { status, json } ) => {
			// check_ajax_referer() answers a stale nonce (a tab left open past
			// its session) with a bare "-1" and HTTP 403: say what to do.
			if ( 403 === status && -1 === json ) {
				const expired = new Error( __( 'This page has expired. Reload it to continue.', 'specflux-site-agent' ) );
				expired.code = 'specflux_site_agent_session_expired';
				throw expired;
			}
			if ( ! json || true !== json.success ) {
				const data = ( json && json.data ) || {};
				const error = new Error( data.message || 'specflux_site_agent_request_failed' );
				error.code = data.code;
				throw error;
			}
			return json.data;
		} );
}

/**
 * admin-ajax `specflux_site_agent_start`: {consumer, goal, pack?, model_provider?, model_id?}
 * -> RunState. `model` is `{ provider, id } | null` — null (the "Automatic"
 * choice) sends neither field, so the request is byte-identical to the
 * pre-model-picker shape and the server falls back to its own default.
 *
 * @param {string}      goal
 * @param {Object}      config
 * @param {string}      [pack]
 * @param {?{provider: string, id: string}} [model] Chosen (provider, model) pair, or null/omitted for automatic.
 * @param {number}      [followUpOf] A finished run to follow up (0.3 S20). The server forces
 *                                   the pack to that run's own, whatever `pack` says.
 */
export function startRun( goal, config, pack, model, followUpOf ) {
	const fields = { consumer: config.consumer, goal, pack };
	if ( followUpOf ) {
		fields.follow_up_of = followUpOf;
	}
	if ( model ) {
		fields.model_provider = model.provider;
		fields.model_id = model.id;
	}
	return postAjax( 'specflux_site_agent_start', fields, config );
}

/**
 * admin-ajax `specflux_site_agent_setup_panel` (0.3 S11, "refresh on window focus"):
 * the setup panel's markup plus the whole start state — `{ html,
 * start_enabled, packs, unavailable_packs, steps }`. Same nonce/capability as every
 * other write, but read-only: it changes nothing on the server.
 */
export function fetchSetupState( config ) {
	return postAjax( 'specflux_site_agent_setup_panel', {}, config );
}

/**
 * admin-ajax `specflux_site_agent_setup_brief` (guided setup, step "site brief"): save
 * the site brief (`action` 'save', with `brief`) or skip the step ('skip').
 * Administrators only; a too-long brief is refused, never truncated. Returns
 * `{ steps }`, the refreshed setup steps.
 */
export function saveSetupBrief( action, brief, config ) {
	return postAjax( 'specflux_site_agent_setup_brief', { brief_action: action, brief }, config );
}

/** admin-ajax `specflux_site_agent_dismiss_cron_hint`: hide the real-cron hint for this user on this site. */
export function dismissCronHint( config ) {
	return postAjax( 'specflux_site_agent_dismiss_cron_hint', {}, config );
}

/** admin-ajax `specflux_site_agent_tick`: {run_id, step_count, resume?} -> RunState. */
export function tickRun( runId, stepCount, resume, config ) {
	return postAjax( 'specflux_site_agent_tick', { run_id: runId, step_count: stepCount, resume: resume || undefined }, config );
}

/** admin-ajax `specflux_site_agent_cancel`: {run_id} -> RunState. */
export function cancelRun( runId, config ) {
	return postAjax( 'specflux_site_agent_cancel', { run_id: runId }, config );
}

/**
 * POST /specflux-site-agent/v1/runs/{id}/suggestions/{seq}: {action, text?} ->
 * `{run_id, seq, action, text}` (0.3 S20). This goes over REST, not
 * admin-ajax — the route already requires `manage_options` and a REST nonce
 * (`apiFetch`'s own nonce middleware, the same one `listRuns`/`getRun`
 * already rely on), and it is never called on a delegated run's behalf the
 * way tick/cancel are, so it does not need the screen's admin-ajax
 * delegation wrapper.
 *
 * @param {number} runId
 * @param {number} seq    The suggestion step's own `seq`.
 * @param {'save'|'dismiss'} action
 * @param {string} [text] Edited text; omitted keeps the suggestion's own text.
 */
export function resolveSuggestion( runId, seq, action, text ) {
	const data = { action };
	if ( undefined !== text ) {
		data.text = text;
	}
	return apiFetch( { path: `${ NAMESPACE }/runs/${ runId }/suggestions/${ seq }`, method: 'POST', data } );
}

/**
 * POST /specflux-site-agent/v1/runs/{id}/changes/preview (0.4 S3.2): what undoing the
 * run, or one of its changes, would do now. Writes nothing. Human click only;
 * the nonce is the run's own, from its `changes_section`.
 *
 * @param {number} runId
 * @param {string} nonce    `run.changes_section.nonce`.
 * @param {number} [changeId] One change of the run; omitted means the whole run.
 */
export function previewUndo( runId, nonce, changeId ) {
	const data = { nonce };
	if ( changeId ) {
		data.change_id = changeId;
	}
	return apiFetch( { path: `${ NAMESPACE }/runs/${ runId }/changes/preview`, method: 'POST', data } );
}

/** POST /specflux-site-agent/v1/runs/{id}/changes/undo (0.4 S3.2): the same shape, and it restores. */
export function undoChanges( runId, nonce, changeId ) {
	const data = { nonce };
	if ( changeId ) {
		data.change_id = changeId;
	}
	return apiFetch( { path: `${ NAMESPACE }/runs/${ runId }/changes/undo`, method: 'POST', data } );
}
