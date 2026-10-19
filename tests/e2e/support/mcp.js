/**
 * A tiny MCP-over-HTTP client for the connector / OAuth specs (stage E1).
 *
 * It speaks the adapter's streamable-HTTP shape directly: one JSON-RPC
 * message per POST, the `Mcp-Session-Id` header handed back by `initialize`
 * replayed on every later call. No cookies are ever sent, so the only way in
 * is the credential the caller passes (an application-password Basic header
 * or an OAuth bearer token) - exactly what an external AI app would have.
 */
const { BASE_URL } = require( './global-setup' );
const { wpCli } = require( './wp-cli' );

/** wp-env installs with plain permalinks, which have no /wp-json/ or /.well-known/ routes; set pretty ones. */
function ensurePrettyPermalinks() {
	if ( '' === wpCli( [ 'option', 'get', 'permalink_structure' ] ).trim() ) {
		wpCli( [ 'rewrite', 'structure', '/%postname%/', '--hard' ] );
	}
}

/** The connector's endpoint, relative to the site root (pretty permalinks, as wp-env sets up). */
const MCP_PATH = '/wp-json/specflux-site-agent/mcp';

/** `Authorization` header value for an application password. */
function basicAuth( login, password ) {
	return 'Basic ' + Buffer.from( `${ login }:${ password.replace( /\s+/g, '' ) }` ).toString( 'base64' );
}

/**
 * One HTTP call with Node's fetch. Used instead of Playwright's APIRequestContext
 * on purpose: inside the test runner that context did not deliver the
 * `Authorization` header to wp-env (the same header worked from curl and from
 * fetch), which would make every "refused" assertion pass for the wrong reason.
 *
 * @param {string} path
 * @param {{method?:string,headers?:Object,json?:any,form?:Object}} [opts]
 * @return {Promise<{status:number,headers:Object<string,string>,text:string,json:any}>}
 */
async function httpCall( path, { method = 'GET', headers = {}, json, form } = {} ) {
	const init = { method, headers: { ...headers }, redirect: 'manual' };
	if ( undefined !== json ) {
		init.body = JSON.stringify( json );
		init.headers[ 'Content-Type' ] = 'application/json';
	} else if ( form ) {
		init.body = new URLSearchParams( form ).toString();
		init.headers[ 'Content-Type' ] = 'application/x-www-form-urlencoded';
	}
	// Apache closes idle keep-alive sockets; Node may pick one up just as it dies. Retry that once.
	let res;
	try {
		res = await fetch( BASE_URL + path, init );
	} catch ( e ) {
		res = await fetch( BASE_URL + path, init );
	}
	const text = await res.text();
	let parsed = null;
	try {
		parsed = JSON.parse( text );
	} catch ( e ) {
		parsed = null;
	}
	return { status: res.status, headers: Object.fromEntries( res.headers.entries() ), text, json: parsed };
}

/**
 * Open an MCP session with one credential.
 *
 * @param {string} authorization Full `Authorization` header value.
 * @return {Promise<{ctx:import('@playwright/test').APIRequestContext,sessionId:string,rpc:Function,listTools:Function,callTool:Function,dispose:Function,initStatus:number}>}
 */
async function openSession( authorization ) {
	let sessionId = '';
	let nextId = 1;

	/** POST one JSON-RPC request; returns the HTTP status, parsed body and headers. */
	async function rpc( method, params = {}, { session = true } = {} ) {
		const headers = {
			Authorization: authorization,
			Accept: 'application/json, text/event-stream',
			'Content-Type': 'application/json',
		};
		if ( session && sessionId ) {
			headers[ 'Mcp-Session-Id' ] = sessionId;
			// mcp-adapter 0.7+ refuses a 2025-06-18 session without this header.
			headers[ 'MCP-Protocol-Version' ] = '2025-06-18';
		}
		const res = await httpCall( MCP_PATH, { method: 'POST', headers, json: { jsonrpc: '2.0', id: nextId++, method, params } } );
		return { status: res.status, body: res.json, headers: res.headers };
	}

	const init = await rpc(
		'initialize',
		{
			protocolVersion: '2025-06-18',
			capabilities: {},
			clientInfo: { name: 'specflux-e2e', version: '1.0.0' },
		},
		{ session: false }
	);
	sessionId = init.headers[ 'mcp-session-id' ] || '';

	/** `tools/list`, returned as the list of tool names. */
	async function listTools() {
		const res = await rpc( 'tools/list' );
		if ( res.body && res.body.error ) {
			throw new Error( 'tools/list failed: ' + JSON.stringify( res.body.error ) );
		}
		const tools = res.body && res.body.result && Array.isArray( res.body.result.tools ) ? res.body.result.tools : [];
		return tools.map( ( t ) => t.name );
	}

	/**
	 * `tools/call`, normalised: a refusal is either a JSON-RPC error or a tool
	 * result with `isError`; both come back as `isError: true` plus the text.
	 */
	async function callTool( name, args = {} ) {
		const res = await rpc( 'tools/call', { name, arguments: args } );
		const body = res.body || {};
		if ( body.error ) {
			return { isError: true, text: String( body.error.message || '' ), raw: body, status: res.status };
		}
		const result = body.result || {};
		const text = ( Array.isArray( result.content ) ? result.content : [] )
			.map( ( c ) => ( c && 'string' === typeof c.text ? c.text : '' ) )
			.join( '\n' );
		return { isError: true === result.isError, text, raw: body, status: res.status };
	}

	return { sessionId, rpc, listTools, callTool, dispose: async () => {}, initStatus: init.status };
}

/** The tool name for an ability id, found in a tool list (the adapter rewrites `/`, so match on the tail). */
function toolFor( names, abilityTail ) {
	return names.find( ( n ) => n.endsWith( abilityTail ) ) || '';
}

module.exports = { httpCall, ensurePrettyPermalinks, MCP_PATH, basicAuth, openSession, toolFor };
