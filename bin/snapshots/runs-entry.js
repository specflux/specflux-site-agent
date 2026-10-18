/* Dev-only: mounts the Runs app with fixture data. Chosen scene: window.__SCENE__ ('parked' | 'finished'). */
import apiFetch from '@wordpress/api-fetch';
import { render } from '@wordpress/element';
import App from '../../assets/src/runs/components/App';
import '../../assets/src/runs/style.css';

const parked = { id: 1, goal: 'Publish the spring sale page', status: 'awaiting_approval', pack: 'pages', viewer_may_tick: true, step_count: 6, model: 'gpt-4.1' };
const finished = { id: 2, goal: 'Put a spring sale banner on the homepage', status: 'completed', pack: 'pages', viewer_may_tick: true, step_count: 9, model: 'gpt-4.1' };
const running = { id: 3, goal: 'Check every product has an image', status: 'running', pack: 'commerce', viewer_may_tick: false, step_count: 2 };
const cancelled = { id: 4, goal: 'Rewrite the about page', status: 'failed', pack: 'pages', viewer_may_tick: false, step_count: 4 };

const model = ( seq, text ) => ( { seq, kind: 'model', status: 'ok', message: { parts: [ { text } ] } } );
const post = { type: 'post', title: 'Spring sale', status: 'draft', links: [ { label: 'Edit', url: 'https://greenleaf-goods.com/wp-admin/post.php?post=42&action=edit', kind: 'edit' }, { label: 'Preview', url: 'https://greenleaf-goods.com/?p=42&preview=true', kind: 'preview' } ] };

const details = {
	1: {
		run: { ...parked, budget: { max_steps: 30 } },
		steps: [
			model( 1, 'I read the homepage and the shop page. The sale page is a draft, so I can publish it once you approve.' ),
			{ seq: 2, kind: 'tool_result', status: 'ok', tool_name: 'content__get-page', message: { verb: 'content/get-page', response: { id: 42 } }, display: post },
			{ seq: 3, kind: 'approval', status: 'parked', message: { parked: true, approval_id: 'a1', verb: 'content/publish-page', tier: 2, args: { id: 42, status: 'publish' }, summary: 'Publish the page <strong>Spring sale</strong> (page 42).', diff: { fields: [ { field: 'status', current: 'draft', proposed: 'publish' }, { field: 'title', current: 'Spring sale (draft)', proposed: 'Spring sale' } ] } } },
		],
		ui: { approval: { step_id: 3, summary: 'Publish the page <strong>Spring sale</strong> (page 42).', diff: { fields: [ { field: 'status', current: 'draft', proposed: 'publish' }, { field: 'title', current: 'Spring sale (draft)', proposed: 'Spring sale' } ] } } },
		suggestions: [],
	},
	2: {
		run: { ...finished, report: { gate_mode: 'built_in', changes: [ { object_id: 7, object_type: 'page', title: 'Homepage', verified: true, edit_url: 'https://greenleaf-goods.com/wp-admin/post.php?post=7&action=edit' }, { object_id: 42, object_type: 'page', title: 'Spring sale', verified: false } ], withheld_roles: [], grants: [], adopted: [], left_for_you: [] } },
		steps: [
			model( 1, 'I added the banner to the top of the homepage and saved the sale page as a draft.' ),
			{ seq: 2, kind: 'tool_result', status: 'ok', tool_name: 'content__update-page', message: { verb: 'content/update-page', response: { id: 7 } }, display: post },
		],
		ui: {},
		suggestions: [],
	},
	3: { run: running, steps: [ model( 1, 'Reading the product list.' ) ], ui: {}, suggestions: [] },
	4: { run: { ...cancelled, error: { code: 'empty_model_turn', message: 'The model returned an empty reply twice in a row.' } }, steps: [], ui: {}, suggestions: [] },
};

apiFetch.use( ( options ) => {
	const match = /\/runs\/(\d+)$/.exec( options.path );
	if ( match ) {
		return Promise.resolve( details[ match[ 1 ] ] );
	}
	return Promise.resolve( { runs: [ parked, finished, running, cancelled ] } );
} );
apiFetch.setFetchHandler = apiFetch.setFetchHandler || ( () => {} );

window.fetch = () => Promise.resolve( { status: 200, json: () => Promise.resolve( { success: true, data: { html: '', start_enabled: true, packs: [], unavailable_packs: [], steps: [] } } ) } );

const scene = window.__SCENE__ || 'parked';
const config = {
	initialRunId: 'finished' === scene ? '2' : '1',
	nonce: 'x',
	consumer: 'specflux-site-agent-admin',
	gateMode: 'built_in',
	userId: 1,
	canManageSiteBrief: true,
	background: { available: false, reason: 'missing' },
	examples: [],
	packs: [ { name: 'pages', label: 'Pages' } ],
	unavailablePacks: [],
	startBlocked: false,
	setupSteps: [],
	modelChoices: {},
};

render( <App config={ config } />, document.getElementById( 'specflux-site-agent-runs-root' ) );
