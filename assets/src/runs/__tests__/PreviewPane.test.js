import { render, screen, fireEvent } from '@testing-library/react';
import fs from 'fs';
import path from 'path';
import PreviewPane from '../components/PreviewPane';
import { latestPreviewable } from '../utils';
import { PREVIEW_IFRAME_ENABLED, PREVIEW_SANDBOX } from '../previewConfig';

const post = ( seq, title, status = 'draft', url = `https://example.test/?p=${ seq }&preview=true` ) => ( {
	seq,
	kind: 'tool_result',
	display: {
		type: 'post',
		title,
		status,
		links: [
			{ label: 'Edit', url: `https://example.test/wp-admin/post.php?post=${ seq }&action=edit`, kind: 'edit' },
			{ label: 'Preview', url, kind: 'preview' },
		],
	},
} );

const steps = [
	post( 1, 'First draft' ),
	{ seq: 2, kind: 'model' },
	post( 3, 'Second draft' ),
	{ seq: 4, kind: 'tool_result', display: { type: 'list', title: 'Posts', items: [] } },
];

beforeEach( () => window.localStorage.clear() );

describe( 'latestPreviewable', () => {
	it( 'picks the latest post/product with a preview or view link', () => {
		expect( latestPreviewable( steps ).display.title ).toBe( 'Second draft' );
	} );

	it( 'is null when nothing previewable exists', () => {
		expect( latestPreviewable( [ steps[ 1 ], steps[ 3 ] ] ) ).toBeNull();
		expect( latestPreviewable( undefined ) ).toBeNull();
	} );
} );

describe( 'PreviewPane', () => {
	it( 'renders the latest previewable object with an open-in-new-tab link', () => {
		render( <PreviewPane steps={ steps } userId={ 7 } /> );
		expect( screen.getByRole( 'complementary', { name: 'Preview' } ) ).toBeInTheDocument();
		expect( screen.getByText( 'Second draft' ) ).toBeInTheDocument();
		expect( screen.queryByText( 'First draft' ) ).not.toBeInTheDocument();
		const open = screen.getByRole( 'link', { name: /Open preview in a new tab/ } );
		expect( open ).toHaveAttribute( 'href', 'https://example.test/?p=3&preview=true' );
		expect( open ).toHaveAttribute( 'rel', 'noopener noreferrer' );
	} );

	it( 'renders nothing without a previewable object', () => {
		const { container } = render( <PreviewPane steps={ [ steps[ 1 ] ] } userId={ 7 } /> );
		expect( container ).toBeEmptyDOMElement();
	} );

	it( 'collapses by keyboard-operable button and persists per user', () => {
		const { unmount } = render( <PreviewPane steps={ steps } userId={ 7 } /> );
		const toggle = screen.getByRole( 'button', { name: 'Hide preview' } );
		expect( toggle ).toHaveAttribute( 'aria-expanded', 'true' );
		fireEvent.click( toggle );
		expect( screen.getByRole( 'button', { name: 'Show preview' } ) ).toHaveAttribute( 'aria-expanded', 'false' );
		expect( document.getElementById( 'senroflux-preview-body' ) ).not.toBeVisible();
		unmount();

		// Same user, fresh mount: still collapsed. Another user: expanded.
		render( <PreviewPane steps={ steps } userId={ 7 } /> );
		expect( screen.getByRole( 'button', { name: 'Show preview' } ) ).toBeInTheDocument();
		render( <PreviewPane steps={ steps } userId={ 8 } /> );
		expect( screen.getAllByRole( 'button', { name: 'Hide preview' } ) ).toHaveLength( 1 );
	} );

	it( 'shows no iframe by default', () => {
		expect( PREVIEW_IFRAME_ENABLED ).toBe( false );
		const { container } = render( <PreviewPane steps={ steps } userId={ 7 } /> );
		expect( container.querySelector( 'iframe' ) ).toBeNull();
	} );

	it( 'when the iframe is on, scripts are sandboxed and allow-same-origin is never set', () => {
		const { container } = render( <PreviewPane steps={ steps } userId={ 7 } iframe /> );
		const frame = container.querySelector( 'iframe' );
		expect( frame ).toHaveAttribute( 'sandbox', 'allow-scripts' );
		expect( frame.getAttribute( 'sandbox' ) ).not.toMatch( /allow-same-origin/ );
		expect( PREVIEW_SANDBOX.split( /\s+/ ) ).not.toContain( 'allow-same-origin' );
	} );

	it( 'drops an unsafe preview URL', () => {
		const bad = [ post( 1, 'Bad', 'draft', 'javascript:alert(1)' ) ];
		const { container } = render( <PreviewPane steps={ bad } userId={ 7 } iframe /> );
		expect( screen.queryByRole( 'link', { name: /Open preview/ } ) ).not.toBeInTheDocument();
		expect( container.querySelector( 'iframe' ) ).toBeNull();
	} );

	it( 'never lets allow-same-origin appear anywhere in the pane source', () => {
		const dir = path.join( __dirname, '..' );
		const source = [ 'components/PreviewPane.js' ].map( ( f ) => fs.readFileSync( path.join( dir, f ), 'utf8' ) ).join( '' );
		expect( source.replace( /\/\*[\s\S]*?\*\/|\/\/.*$/gm, '' ) ).not.toMatch( /allow-same-origin/ );
	} );

	it( 'uses logical properties only for the pane layout', () => {
		const css = fs.readFileSync( path.join( __dirname, '..', 'style.css' ), 'utf8' );
		const block = css.slice( css.indexOf( 'Preview pane (0.4 S3)' ) );
		expect( block ).not.toMatch( /\b(margin|padding|border)-(left|right)\b|\b(left|right)\s*:/ );
	} );
} );
