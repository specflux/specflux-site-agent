// @ts-check
const crypto = require( 'crypto' );
const { test, expect, request } = require( '@playwright/test' );
const { wpCli, setOption } = require( '../support/wp-cli' );
const { openSession, toolFor, MCP_PATH } = require( '../support/mcp' );
const { BASE_URL } = require( '../support/global-setup' );

/**
 * Stage E1: sign-in for AI apps - OAuth 2.1 with PKCE (0.4 K5-K8).
 *
 * An "AI app" is played by the test: it discovers the endpoints, registers
 * itself, sends the admin's browser through the real consent screen, catches
 * the redirect (a loopback address intercepted in the browser - no server),
 * trades the code for a token and calls the connector with it.
 *
 * Needs pretty permalinks (OAuth discovery refuses plain ones) and the
 * connector switched on; the mounted mcp-adapter's version is covered by the
 * probe in tests/e2e/fixtures/mu-specflux-e2e-extras.php.
 */
test.describe.configure( { mode: 'serial' } );

const OAUTH = '/wp-json/specflux-site-agent/oauth';
const MCP_URL = `${ BASE_URL }${ MCP_PATH }`;
const REDIRECT = 'http://127.0.0.1:53682/callback';

const b64url = ( buf ) => buf.toString( 'base64' ).replace( /\+/g, '-' ).replace( /\//g, '_' ).replace( /=+$/, '' );

/** @type {import('@playwright/test').APIRequestContext} */
let api;

const flow = {
	clientId: '',
	verifier: b64url( crypto.randomBytes( 48 ) ),
	state: b64url( crypto.randomBytes( 12 ) ),
	code: '',
	access: '',
};
const challenge = () => b64url( crypto.createHash( 'sha256' ).update( flow.verifier ).digest() );

/** POST a JSON body to the token endpoint. */
function tokenCall( data ) {
	return api.post( `${ OAUTH }/token`, { data } );
}

test.describe( 'E1 OAuth 2.1 sign-in for AI apps', () => {
	test.beforeAll( async () => {
		if ( '' === wpCli( [ 'option', 'get', 'permalink_structure' ] ).trim() ) {
			wpCli( [ 'rewrite', 'structure', '/%postname%/' ] );
		}
		setOption( 'specflux_site_agent_connector_enabled', '1' );
		// Registration is limited to 10 an hour per address; start each run with a clean counter.
		wpCli( [ 'db', 'query', "DELETE FROM wp_options WHERE option_name LIKE '%specflux_site_agent_oauth_rl_%'" ] );
		api = await request.newContext( { baseURL: BASE_URL } );
	} );

	test.afterAll( async () => {
		setOption( 'specflux_site_agent_connector_enabled', '0' );
		await api.dispose();
	} );

	test( 'both discovery documents are served, at the root and under the resource path', async () => {
		const paths = [
			'/.well-known/oauth-protected-resource',
			`/.well-known/oauth-protected-resource${ MCP_PATH }`,
		];
		for ( const path of paths ) {
			const res = await api.get( path );
			expect( res.status(), path ).toBe( 200 );
			const doc = await res.json();
			expect( doc.resource, path ).toBe( MCP_URL );
			expect( doc.authorization_servers, path ).toHaveLength( 1 );
			expect( doc.scopes_supported, path ).toEqual( expect.arrayContaining( [ 'specflux:read', 'specflux:write' ] ) );
		}

		for ( const path of [ '/.well-known/oauth-authorization-server', '/.well-known/openid-configuration' ] ) {
			const res = await api.get( path );
			expect( res.status(), path ).toBe( 200 );
			const doc = await res.json();
			expect( doc.token_endpoint, path ).toBe( `${ BASE_URL }${ OAUTH }/token` );
			expect( doc.registration_endpoint, path ).toBe( `${ BASE_URL }${ OAUTH }/register` );
			expect( doc.authorization_endpoint, path ).toContain( 'action=specflux_site_agent_oauth_authorize' );
			expect( doc.code_challenge_methods_supported, path ).toEqual( [ 'S256' ] );
			expect( doc.token_endpoint_auth_methods_supported, path ).toEqual( [ 'none' ] );
		}

		// The unauthenticated MCP route points a client at the resource document.
		const challenge401 = await api.post( MCP_PATH, { data: { jsonrpc: '2.0', id: 1, method: 'initialize', params: {} } } );
		expect( [ 401, 403 ] ).toContain( challenge401.status() );
		expect( challenge401.headers()[ 'www-authenticate' ] || '' ).toContain( 'resource_metadata=' );
	} );

	test( 'dynamic client registration: 201 for a good redirect, 400 for a bad one', async () => {
		const ok = await api.post( `${ OAUTH }/register`, {
			data: { client_name: 'E2E Test App', redirect_uris: [ REDIRECT ], token_endpoint_auth_method: 'none' },
		} );
		expect( ok.status() ).toBe( 201 );
		const client = await ok.json();
		expect( client.client_id ).toMatch( /^snf_[0-9a-f]{32}$/ );
		expect( client.redirect_uris ).toEqual( [ REDIRECT ] );
		flow.clientId = client.client_id;

		const bad = await api.post( `${ OAUTH }/register`, {
			data: { client_name: 'Evil App', redirect_uris: [ 'http://evil.example.com/callback' ] },
		} );
		expect( bad.status() ).toBe( 400 );
		expect( ( await bad.json() ).error ).toBe( 'invalid_redirect_uri' );
	} );

	test( 'the admin approves read only on the consent screen and the app gets a code back', async ( { page } ) => {
		const params = new URLSearchParams( {
			action: 'specflux_site_agent_oauth_authorize',
			client_id: flow.clientId,
			redirect_uri: REDIRECT,
			response_type: 'code',
			code_challenge: challenge(),
			code_challenge_method: 'S256',
			state: flow.state,
			scope: 'specflux:read specflux:write',
			resource: MCP_URL,
		} );

		// The "app" listens on a loopback address; the browser never reaches a real server.
		await page.route( 'http://127.0.0.1:53682/**', ( route ) => route.fulfill( { status: 200, contentType: 'text/html', body: '<p>app received the code</p>' } ) );

		await page.goto( `/wp-admin/admin-post.php?${ params.toString() }` );
		await expect( page.getByRole( 'heading', { name: 'Connect E2E Test App?' } ) ).toBeVisible();
		await expect( page.getByText( 'Not verified. This name was chosen by the app itself.' ) ).toBeVisible();
		// The host the browser will be sent to is shown prominently.
		await expect( page.locator( '.host' ) ).toContainText( '127.0.0.1:53682' );
		// Even though the app asked for write, read only is the default choice.
		const readOnly = page.getByRole( 'radio', { name: /Read only/ } );
		await expect( readOnly ).toBeChecked();
		await readOnly.check();

		const callback = page.waitForRequest( ( req ) => req.url().startsWith( REDIRECT ) );
		await page.getByRole( 'button', { name: 'Approve' } ).click();
		const url = new URL( ( await callback ).url() );

		expect( url.searchParams.get( 'state' ) ).toBe( flow.state );
		flow.code = url.searchParams.get( 'code' ) || '';
		expect( flow.code ).toMatch( /^[0-9a-f]{64}$/ );
	} );

	test( 'the code is exchanged for a read-only bearer token', async () => {
		const res = await tokenCall( {
			grant_type: 'authorization_code',
			code: flow.code,
			code_verifier: flow.verifier,
			client_id: flow.clientId,
			redirect_uri: REDIRECT,
		} );
		expect( res.status() ).toBe( 200 );
		const body = await res.json();
		expect( body.token_type ).toBe( 'Bearer' );
		expect( body.scope ).toBe( 'specflux:read' );
		expect( body.access_token ).toMatch( /^[0-9a-f]{64}$/ );
		expect( body.refresh_token ).toMatch( /^[0-9a-f]{64}$/ );
		expect( res.headers()[ 'cache-control' ] ).toContain( 'no-store' );
		flow.access = body.access_token;
	} );

	test( 'the bearer token works on the MCP route, with the tools a read-only grant allows', async () => {
		const session = await openSession( `Bearer ${ flow.access }` );
		try {
			expect( session.initStatus ).toBe( 200 );
			const tools = await session.listTools();
			expect( toolFor( tools, 'read-thing' ) ).not.toBe( '' );
			expect( toolFor( tools, 'create-thing' ) ).toBe( '' );

			const read = await session.callTool( toolFor( tools, 'read-thing' ), { id: 'thing-1' } );
			expect( read.isError, read.text ).toBe( false );
			expect( read.text ).toContain( 'Fixture thing content.' );

			// Naming a write tool the grant does not allow is refused, listed or not.
			const write = await session.callTool( 'specflux-e2e-create-thing', { title: 'nope' } );
			expect( write.isError ).toBe( true );
			expect( write.isError ).toBe( true );
		} finally {
			await session.dispose();
		}
	} );

	test( 'the same bearer token is refused everywhere else in the REST API', async () => {
		const auth = { Authorization: `Bearer ${ flow.access }` };

		const me = await api.get( '/wp-json/wp/v2/users/me', { headers: auth } );
		expect( [ 401, 403 ] ).toContain( me.status() );
		expect( await me.text() ).not.toContain( '"slug":"admin"' );

		const posts = await api.get( '/wp-json/wp/v2/posts?status=draft', { headers: auth } );
		expect( [ 401, 403 ] ).toContain( posts.status() );

		// The query-string route override must not smuggle the token to another route.
		const smuggled = await api.get( `${ MCP_PATH }?rest_route=/wp/v2/users/me`, { headers: auth } );
		expect( [ 401, 403, 404 ] ).toContain( smuggled.status() );
	} );

	test( 'a bogus code is invalid_grant', async () => {
		const res = await tokenCall( {
			grant_type: 'authorization_code',
			code: 'f'.repeat( 64 ),
			code_verifier: flow.verifier,
			client_id: flow.clientId,
			redirect_uri: REDIRECT,
		} );
		expect( res.status() ).toBe( 400 );
		expect( ( await res.json() ).error ).toBe( 'invalid_grant' );
	} );

	test( 'redeeming the same code twice is invalid_grant and revokes what the first redemption issued', async () => {
		const replay = await tokenCall( {
			grant_type: 'authorization_code',
			code: flow.code,
			code_verifier: flow.verifier,
			client_id: flow.clientId,
			redirect_uri: REDIRECT,
		} );
		expect( replay.status() ).toBe( 400 );
		expect( ( await replay.json() ).error ).toBe( 'invalid_grant' );

		// The leaked-code rule: the token issued the first time stops working.
		const after = await openSession( `Bearer ${ flow.access }` );
		try {
			expect( [ 401, 403 ] ).toContain( after.initStatus );
		} finally {
			await after.dispose();
		}
	} );
} );
