'use strict';

/**
 * `assets/runs.js` honours the tick result's `retry_after`: after a transient
 * model error the server returns the run still `running` with `retry_after`
 * seconds instead of sleeping in-request, and the poller must wait that long
 * rather than re-polling at `pollInterval`.
 */

const test = require( 'node:test' );
const assert = require( 'node:assert/strict' );
const fs = require( 'node:fs' );
const path = require( 'node:path' );
const { JSDOM, VirtualConsole } = require( 'jsdom' );

const SCRIPT_PATH = path.join( __dirname, '..', '..', 'assets', 'runs.js' );

async function countFetchesAfter( retryAfter, waitMs ) {
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

	let fetches = 0;
	dom.window.fetch = function () {
		fetches++;
		return Promise.resolve( {
			json: () =>
				Promise.resolve( {
					success: true,
					data: { run: { status: 'running', step_count: 1 }, new_steps: [], retry_after: retryAfter },
				} ),
		} );
	};

	dom.window.eval( fs.readFileSync( SCRIPT_PATH, 'utf8' ) );
	await new Promise( ( resolve ) => setTimeout( resolve, waitMs ) );
	dom.window.close();

	return fetches;
}

test( 'without retry_after the poller keeps polling at pollInterval', async () => {
	const fetches = await countFetchesAfter( 0, 150 );
	assert.ok( fetches > 3, `expected several polls, saw ${ fetches }` );
} );

test( 'retry_after makes the poller wait instead of re-polling at pollInterval', async () => {
	const fetches = await countFetchesAfter( 30, 150 );
	assert.equal( fetches, 1, 'only the first poll may fire inside the retry_after window' );
} );
