// @ts-check
const { test } = require( '@playwright/test' );
const path = require( 'path' );
const { setupFor } = require( '../support/global-setup' );

const storageStatePath = path.join( __dirname, '..', 'support', 'storage-state.agent_safety.json' );

test( 'Belayline Agent Governance mode setup: plugin active, pack bound, admin logged in', async ( {}, testInfo ) => {
	// See built-in.setup.js: several sequential wp-cli round trips.
	testInfo.setTimeout( 900000 );
	await setupFor( 'agent_safety', storageStatePath );
} );
