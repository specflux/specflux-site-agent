'use strict';

/**
 * `assets/runs.js` treats a tick refused with 409 `specflux_site_agent_conflict`
 * (the background driver advanced the run) as "refresh and carry on": it reads
 * the run, adopts its step count and keeps polling, with no failure announcement.
 */

const test = require( 'node:test' );
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const { JSDOM, VirtualConsole } = require( 'jsdom' );

const SCRIPT_PATH = path.join( __dirname, '..', '..', 'assets', 'runs.js' );

async function run( handlers, waitMs ) {
	const dom = new JSDOM(
		`<!doctype html><html><body>
			<div id="specflux-site-agent-run-detail" data-run-id="1" data-step-count="1" data-status="running">
				<div id="specflux-site-agent-live-status"></div>
				<span id="specflux-site-agent-status-badge" class="specflux-site-agent-badge"></span>
				<ol class="specflux-site-agent-steps"></ol>
			</div>
		</body></html>`,
		{ url: 'https://specflux-site-agent.invalid/', virtualConsole: new VirtualConsole(), runScripts: 'outside-only' }
	);

	dom.window.specfluxSiteAgentRuns = {
		ajaxUrl: 'https://specflux-site-agent.invalid/wp-admin/admin-ajax.php',
		nonce: 'test-nonce',
		pollInterval: 5,
		parkAnnounceMs: 5,
		i18n: {},
	};

	const calls = [];
	dom.window.fetch = function ( url, init ) {
		const action = init.body.get( 'action' );
		calls.push( { action, stepCount: init.body.get( 'step_count' ) } );
		const handler = handlers[ action ];
		return Promise.resolve( { json: () => Promise.resolve( handler( calls.filter( ( c ) => c.action === action ).length ) ) } );
	};

	dom.window.eval( fs.readFileSync( SCRIPT_PATH, 'utf8' ) );
	await new Promise( ( resolve ) => setTimeout( resolve, waitMs ) );
	const live = dom.window.document.getElementById( 'specflux-site-agent-live-status' ).textContent;
	dom.window.close();

	return { calls, live };
}

const conflict = { success: false, data: { code: 'specflux_site_agent_conflict', message: 'The run advanced.' } };

test( 'a 409 refreshes the run, adopts its step_count and keeps polling', async () => {
	const { calls, live } = await run(
		{
			specflux_site_agent_tick: ( n ) =>
				1 === n ? conflict : { success: true, data: { run: { status: 'running', step_count: 12 }, new_steps: [], retry_after: 0 } },
			specflux_site_agent_get: () => ( { success: true, data: { run: { status: 'running', step_count: 11 } } } ),
		},
		150
	);

	assert.equal( calls[ 0 ].action, 'specflux_site_agent_tick' );
	assert.equal( calls[ 1 ].action, 'specflux_site_agent_get', 'the conflict triggers a read of the run' );
	assert.equal( calls[ 2 ].action, 'specflux_site_agent_tick', 'polling carries on' );
	assert.equal( calls[ 2 ].stepCount, '11', 'with the step_count the refresh reported' );
	assert.ok( ! /stopped/i.test( live ), `no failure announcement, saw: ${ live }` );
} );

test( 'any other refusal still stops the poll and announces it', async () => {
	const { calls, live } = await run(
		{ specflux_site_agent_tick: () => ( { success: false, data: { code: 'specflux_site_agent_forbidden' } } ) },
		100
	);

	assert.equal( calls.length, 1, 'polling stopped after the refusal' );
	assert.ok( /stopped/i.test( live ), `expected the failure announcement, saw: ${ live }` );
} );
