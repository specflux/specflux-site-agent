// Dev-only (excluded from the zip). Screenshots every page in build/ui-snapshots/ at desktop and mobile
// widths and runs axe on it. Exit 1 on any serious/critical violation or a page error.
//
//   vendor/bin/phpunit bin/snapshots/UiSnapshotsTest.php
//   npx webpack --config bin/snapshots/webpack.runs.cjs
//   node bin/ui-snapshots.mjs
import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';

const root = path.resolve( path.dirname( fileURLToPath( import.meta.url ) ), '..' );
const dir = path.join( root, 'build/ui-snapshots' );
const types = { '.html': 'text/html', '.css': 'text/css', '.js': 'text/javascript', '.svg': 'image/svg+xml' };
const server = http.createServer( ( q, r ) => {
	const p = path.join( root, decodeURIComponent( new URL( q.url, 'http://x' ).pathname ) );
	if ( ! p.startsWith( root ) || ! fs.existsSync( p ) || fs.statSync( p ).isDirectory() ) {
		r.writeHead( 404 ).end( 'not found' );
		return;
	}
	r.writeHead( 200, { 'content-type': types[ path.extname( p ) ] || 'application/octet-stream' } ).end( fs.readFileSync( p ) );
} );
await new Promise( ( ok ) => server.listen( 0, '127.0.0.1', ok ) );
const port = server.address().port;

const files = fs.readdirSync( dir ).filter( ( f ) => f.endsWith( '.html' ) ).sort();
const pngs = path.join( dir, 'png' );
fs.mkdirSync( pngs, { recursive: true } );
const browser = await chromium.launch();
let bad = 0;
const errors = [];

for ( const [ width, tag ] of [ [ 1440, '' ], [ 390, '-mobile' ] ] ) {
	const page = await ( await browser.newContext( { viewport: { width, height: 1300 } } ) ).newPage();
	page.on( 'console', ( m ) => 'error' === m.type() && errors.push( `${ m.text() }` ) );
	page.on( 'pageerror', ( e ) => errors.push( String( e ) ) );
	for ( const f of files ) {
		await page.goto( `http://127.0.0.1:${ port }/build/ui-snapshots/${ f }`, { waitUntil: 'load' } );
		await page.waitForTimeout( 300 );
		const h = await page.evaluate( () => document.documentElement.scrollHeight );
		await page.setViewportSize( { width, height: Math.max( 1300, h ) } );
		await page.waitForTimeout( 100 );
		await page.screenshot( { path: path.join( pngs, f.replace( '.html', tag + '.png' ) ), fullPage: true } );
		await page.setViewportSize( { width, height: 1300 } );
		if ( tag ) {
			continue;
		}
		const res = await new AxeBuilder( { page } ).include( '.aui' ).analyze();
		const n = ( s ) => res.violations.filter( ( v ) => v.impact === s ).length;
		console.log( f.padEnd( 26 ), `critical=${ n( 'critical' ) } serious=${ n( 'serious' ) } moderate=${ n( 'moderate' ) } minor=${ n( 'minor' ) }` );
		for ( const v of res.violations ) {
			console.log( '   ', v.impact, v.id, v.nodes.slice( 0, 3 ).map( ( x ) => x.target.join( ' ' ) ).join( ' | ' ) );
		}
		bad += n( 'critical' ) + n( 'serious' );
	}
}
await browser.close();
server.close();
console.log( 'console/page errors:', errors.length, errors.slice( 0, 6 ) );
process.exit( bad || errors.length ? 1 : 0 );
