// @ts-check
const { test, expect } = require( '@playwright/test' );
const { wpCli, dbQuery, setOption, deleteOption } = require( '../support/wp-cli' );
const { httpCall, openSession, toolFor, MCP_PATH, ensurePrettyPermalinks } = require( '../support/mcp' );

/**
 * Stage E1: the AI-app connector (0.4 K1-K4), end to end over real HTTP.
 *
 * Built-in gate mode (this file is not an `.as-mode` spec): reads run, a
 *   write parks until a site admin approves that exact call, then runs once.
 *
 * The flow is one story, so the tests share state and run in order:
 *   switch the connector on (AI apps tab) -> create a read-only and a
 *   read-and-write connection (AI apps tab) -> speak MCP with each ->
 *   see the parked write in the activity list -> approve it in the AI apps
 *   tab's "Waiting for approval" section -> the repeat runs exactly once ->
 *   an identical repeat parks again.
 *
 * Environment note: the mounted mcp-adapter reports a version below the
 * connector's minimum; tests/e2e/fixtures/mu-specflux-e2e-extras.php fixes
 * that with the connector's own version-probe seam.
 */
test.describe.configure( { mode: 'serial' } );

const CREATE_ARGS = { title: 'E2E connector thing' };
const THINGS_OPTION = 'specflux_site_agent_e2e_things_created';

/** @type {{readOnly:string,readWrite:string}} */
const auth = { readOnly: '', readWrite: '' };

/** Number of things the fixture's create-thing ability has actually created. */
function thingsCreated() {
	const raw = dbQuery( `SELECT option_value FROM wp_options WHERE option_name = '${ THINGS_OPTION }'` );
	return '' === raw ? 0 : Number( raw );
}

const APPS_URL = '/wp-admin/admin.php?page=specflux-site-agent-apps';

/** Open the collapsed "All activity" disclosure and return its table body rows. */
async function openActivity( page ) {
	await page.locator( 'summary', { hasText: 'All activity' } ).click();
	return page.locator( 'table', { has: page.getByRole( 'columnheader', { name: 'Outcome' } ) } ).locator( 'tbody tr' );
}

/**
 * Make one connection on the AI apps tab and read the one-time config back.
 *
 * @param {import('@playwright/test').Page} page
 * @param {'read'|'read_write'} scope
 * @return {Promise<string>} The `Authorization` header value the config shows.
 */
async function createConnection( page, scope ) {
	await page.goto( APPS_URL );
	const card = page.locator( '.specflux-site-agent-app-card', { has: page.getByRole( 'heading', { name: 'Other app' } ) } );
	await card.getByRole( 'button', { name: 'Create connection' } ).click();
	const dialog = page.getByRole( 'dialog', { name: /Connect another app/ } );
	await dialog.getByRole( 'radio', { name: 'read' === scope ? /Read only/ : /Read and write/ } ).check();
	await dialog.getByRole( 'button', { name: 'Create connection' } ).click();

	await expect( page.locator( '.aui-notice--success' ).first() ).toContainText( 'Connection created.' );
	const config = await page.locator( '#specflux-site-agent-config' ).textContent();
	const match = /Header: Authorization: (Basic \S+)/.exec( config || '' );
	expect( match, 'the one-time config shows the Authorization header' ).not.toBeNull();
	return /** @type {RegExpExecArray} */ ( match )[ 1 ];
}

test.describe( 'E1 AI-app connector (built-in mode)', () => {
	test.beforeAll( () => {
		ensurePrettyPermalinks();
		// A clean slate: no earlier connections, parked calls, grants or counters.
		for ( const args of [
			[ 'user', 'application-password', 'delete', 'admin', '--all' ],
			[ 'user', 'meta', 'delete', 'admin', 'specflux_site_agent_connections' ],
		] ) {
			try {
				wpCli( args );
			} catch ( e ) {
				// Nothing to delete on a clean install.
			}
		}
		deleteOption( 'specflux_site_agent_connector_approvals' );
		deleteOption( THINGS_OPTION );
		deleteOption( 'specflux_site_agent_connector_enabled' );
		wpCli( [ 'db', 'query', 'TRUNCATE TABLE wp_specflux_site_agent_connector_activity' ] );
		wpCli( [ 'db', 'query', "DELETE FROM wp_options WHERE option_name LIKE 'specflux\\_site\\_agent\\_cxa\\_%'" ] );
	} );

	test.afterAll( () => {
		setOption( 'specflux_site_agent_connector_enabled', '0' );
	} );

	test( 'the AI apps tab explains itself while AI apps are switched off', async ( { page } ) => {
		await page.goto( APPS_URL );
		await expect( page.getByRole( 'heading', { name: 'No AI apps connected yet' } ) ).toBeVisible();
		await expect( page.getByRole( 'button', { name: 'Let AI apps connect to this site' } ) ).toHaveAttribute( 'aria-pressed', 'false' );
		await expect( page.getByRole( 'button', { name: 'Create connection' } ) ).toHaveCount( 0 );
	} );

	test( 'an admin switches the connector on from the AI apps tab', async ( { page } ) => {
		await page.goto( APPS_URL );
		const turnOn = page.getByRole( 'button', { name: 'Turn on connector' } );
		await expect( turnOn, 'the mounted adapter counts as ready' ).toBeEnabled();
		await turnOn.click();
		await expect( page.getByRole( 'button', { name: 'Let AI apps connect to this site' } ) ).toHaveAttribute( 'aria-pressed', 'true' );
		expect( dbQuery( "SELECT option_value FROM wp_options WHERE option_name = 'specflux_site_agent_connector_enabled'" ) ).toBe( '1' );
	} );

	test( 'creates a read-only and a read-and-write connection, each shown once', async ( { page } ) => {
		auth.readOnly = await createConnection( page, 'read' );
		auth.readWrite = await createConnection( page, 'read_write' );
		expect( auth.readOnly ).not.toBe( auth.readWrite );

		await page.goto( APPS_URL );
		// The Connected table also lists sign-in apps (the OAuth spec's); this story is about passwords.
		const rows = page
			.locator( 'table', { has: page.getByRole( 'columnheader', { name: 'Last used' } ) } )
			.locator( 'tbody tr' )
			.filter( { has: page.getByRole( 'cell', { name: 'Password', exact: true } ) } );
		await expect( rows ).toHaveCount( 2 );
		await expect( rows.filter( { has: page.getByRole( 'cell', { name: 'Read only', exact: true } ) } ) ).toHaveCount( 1 );
		await expect( rows.filter( { has: page.getByRole( 'cell', { name: 'Read and write', exact: true } ) } ) ).toHaveCount( 1 );
		// The password is not kept: a fresh page load shows no config.
		await expect( page.locator( '#specflux-site-agent-config' ) ).toHaveCount( 0 );
	} );

	test( 'the endpoint refuses a request with no connection', async () => {
		const res = await httpCall( MCP_PATH, { method: 'POST', json: { jsonrpc: '2.0', id: 1, method: 'initialize', params: {} } } );
		expect( [ 401, 403 ] ).toContain( res.status );
	} );

	test( 'a read-only connection lists fewer tools than a read-and-write one, and can read', async () => {
		const ro = await openSession( auth.readOnly );
		const rw = await openSession( auth.readWrite );
		try {
			expect( ro.initStatus ).toBe( 200 );
			expect( rw.initStatus ).toBe( 200 );

			const roTools = await ro.listTools();
			const rwTools = await rw.listTools();

			expect( roTools.length ).toBeGreaterThan( 0 );
			expect( roTools.length ).toBeLessThan( rwTools.length );
			expect( toolFor( roTools, 'read-thing' ) ).not.toBe( '' );
			expect( toolFor( roTools, 'create-thing' ) ).toBe( '' );
			expect( toolFor( rwTools, 'read-thing' ) ).not.toBe( '' );
			expect( toolFor( rwTools, 'create-thing' ) ).not.toBe( '' );
			// The harness-only tools never leave wp-admin, on either connection.
			expect( rwTools.filter( ( n ) => /propose-plan|ask-user|suggest-brief/.test( n ) ) ).toEqual( [] );

			const read = await ro.callTool( toolFor( roTools, 'read-thing' ), { id: 'thing-1' } );
			expect( read.isError, read.text ).toBe( false );
			expect( read.text ).toContain( 'Fixture thing content.' );
		} finally {
			await ro.dispose();
			await rw.dispose();
		}
	} );

	test( 'a write from the read-only connection is refused and nothing is created', async () => {
		const rw = await openSession( auth.readWrite );
		const ro = await openSession( auth.readOnly );
		try {
			// Name the write tool even though the read-only list hides it: a client can name anything.
			const writeTool = toolFor( await rw.listTools(), 'create-thing' );
			expect( writeTool ).not.toBe( '' );

			const refused = await ro.callTool( writeTool, CREATE_ARGS );
			expect( refused.isError ).toBe( true );
			expect( refused.text.toLowerCase() ).toMatch( /read-only|not available|not allowed|denied/ );
			expect( thingsCreated() ).toBe( 0 );
			expect( dbQuery( "SELECT option_value FROM wp_options WHERE option_name = 'specflux_site_agent_connector_approvals'" ) ).toBe( '' );
		} finally {
			await ro.dispose();
			await rw.dispose();
		}
	} );

	test( 'a write from the read-and-write connection is held for approval and listed as waiting', async ( { page } ) => {
		const rw = await openSession( auth.readWrite );
		try {
			const writeTool = toolFor( await rw.listTools(), 'create-thing' );
			const held = await rw.callTool( writeTool, CREATE_ARGS );
			expect( held.isError ).toBe( true );
			expect( held.text ).toContain( 'needs the site owner' );
			expect( held.text ).toContain( 'page=specflux-site-agent-apps' );
			expect( thingsCreated() ).toBe( 0 );
		} finally {
			await rw.dispose();
		}

		// The activity list on the AI apps tab: ran (the read), refused, waiting.
		await page.goto( APPS_URL );
		const activity = await openActivity( page );
		await expect( activity.filter( { hasText: 'specflux-e2e/create-thing' } ).filter( { hasText: 'Waiting for approval' } ).first() ).toBeVisible();
		await expect( activity.filter( { hasText: 'specflux-e2e/create-thing' } ).filter( { hasText: 'Refused' } ).first() ).toBeVisible();
		await expect( activity.filter( { hasText: 'specflux-e2e/read-thing' } ).filter( { has: page.getByRole( 'cell', { name: 'Ran', exact: true } ) } ).first() ).toBeVisible();

		// And in the waiting section, with the exact arguments.
		const card = page.locator( 'article.specflux-site-agent-waiting-card', { hasText: 'specflux-e2e/create-thing' } );
		await expect( card ).toHaveCount( 1 );
		await expect( card ).toContainText( 'E2E connector thing' );

		// The old Runs-screen tab URL still lands here.
		await page.goto( '/wp-admin/admin.php?page=specflux-site-agent-runs&tab=ai-apps' );
		await expect( page ).toHaveURL( /page=specflux-site-agent-apps/ );
	} );

	test( 'once approved, the repeat runs exactly once; an identical repeat parks again', async ( { page } ) => {
		await page.goto( APPS_URL );
		const posted = page.waitForResponse( ( r ) => r.url().includes( 'admin-post.php' ) && 'POST' === r.request().method() );
		await page.locator( 'article.specflux-site-agent-waiting-card', { hasText: 'specflux-e2e/create-thing' } ).getByRole( 'button', { name: 'Approve' } ).click();
		await posted;
		// The approval is now a one-time grant row.
		expect( Number( dbQuery( "SELECT COUNT(*) FROM wp_options WHERE option_name LIKE 'specflux\\_site\\_agent\\_cxa\\_%'" ) ) ).toBe( 1 );

		const rw = await openSession( auth.readWrite );
		try {
			const writeTool = toolFor( await rw.listTools(), 'create-thing' );

			const ran = await rw.callTool( writeTool, CREATE_ARGS );
			expect( ran.isError, ran.text ).toBe( false );
			expect( ran.text ).toContain( 'E2E connector thing' );
			expect( thingsCreated() ).toBe( 1 );

			// The approval was spent: the very same call parks again and does not run.
			const again = await rw.callTool( writeTool, CREATE_ARGS );
			expect( again.isError ).toBe( true );
			expect( again.text ).toContain( 'needs the site owner' );
			expect( thingsCreated() ).toBe( 1 );
		} finally {
			await rw.dispose();
		}

		await page.goto( APPS_URL );
		const activity = await openActivity( page );
		await expect( activity.filter( { hasText: 'specflux-e2e/create-thing' } ).filter( { has: page.getByRole( 'cell', { name: 'Ran', exact: true } ) } ) ).toHaveCount( 1 );
		// Parked twice (the first ask and the repeat after the approval was spent).
		expect( await activity.filter( { hasText: 'specflux-e2e/create-thing' } ).filter( { hasText: 'Waiting for approval' } ).count() ).toBeGreaterThanOrEqual( 2 );
	} );

	test( 'revoking a connection stops it working', async ( { page } ) => {
		await page.goto( APPS_URL );
		const posted = page.waitForResponse( ( r ) => r.url().includes( 'admin-post.php' ) && 'POST' === r.request().method() );
		const readOnlyRow = () =>
			page
				.locator( 'tr' )
				.filter( { has: page.getByRole( 'cell', { name: 'Password', exact: true } ) } )
				.filter( { has: page.getByRole( 'cell', { name: 'Read only', exact: true } ) } );
		await readOnlyRow().getByRole( 'button', { name: 'Revoke' } ).click();
		await posted;
		await expect( readOnlyRow() ).toHaveCount( 0 );

		const ro = await openSession( auth.readOnly );
		try {
			expect( [ 401, 403 ] ).toContain( ro.initStatus );
		} finally {
			await ro.dispose();
		}
	} );
} );
