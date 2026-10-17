/**
 * A tiny MCP-over-HTTP client for the connector / OAuth specs (stage E1).
 *
 * It speaks the adapter's streamable-HTTP shape directly: one JSON-RPC
 * message per POST, the `Mcp-Session-Id` header handed back by `initialize`
 * replayed on every later call. No cookies are ever sent, so the only way in
 * is the credential the caller passes (an application-password Basic header
 * or an OAuth bearer token) - exactly what an external AI app would have.
 */
const { request } = require( '@playwright/test' );
const { BASE_URL } = require( './global-setup' );

/** The connector's endpoint, relative to the site root (pretty permalinks, as wp-env sets up). */
const MCP_PATH = '/wp-json/specflux-site-agent/mcp';

/** `Authorization` header value for an application password. */
function basicAuth( login, password ) {
	return 'Basic ' + Buffer.from( `${ login }:${ password.replace( /\s+/g, '' ) }` ).toString( 'base64' );
}

/**
 * Open an MCP session with one credential.
 *
 * @param {string} authorization Full `Authorization` header value.
 * @return {Promise<{ctx:import('@playwright/test').APIRequestContext,sessionId:string,rpc:Function,listTools:Function,callTool:Function,dispose:Function,initStatus:number}>}
 */
async function openSession( authorization ) {
	const ctx = await request.newContext( {
		baseURL: BASE_URL,
		extraHTTPHeaders: {
			Authorization: authorization,
			Accept: 'application/json, text/event-stream',
			'Content-Type': 'application/json',
		},
	} );

	let sessionId = '';
	let nextId = 1;

	/** POST one JSON-RPC request; returns the HTTP status, parsed body and headers. */
	async function rpc( method, params = {}, { session = true } = {} ) {
		const headers = {};
		if ( session && sessionId ) {
			headers[ 'Mcp-Session-Id' ] = sessionId;
		}
		const res = await ctx.post( MCP_PATH, {
			headers,
			data: { jsonrpc: '2.0', id: nextId++, method, params },
		} );
		let body = null;
		try {
			body = await res.json();
		} catch ( e ) {
			body = null;
		}
		return { status: res.status(), body, headers: res.headers() };
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

	return { ctx, sessionId, rpc, listTools, callTool, dispose: () => ctx.dispose(), initStatus: init.status };
}

/** The tool name for an ability id, found in a tool list (the adapter rewrites `/`, so match on the tail). */
function toolFor( names, abilityTail ) {
	return names.find( ( n ) => n.endsWith( abilityTail ) ) || '';
}

module.exports = { MCP_PATH, basicAuth, openSession, toolFor };
