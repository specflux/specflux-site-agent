import { render, screen, fireEvent } from '@testing-library/react';
import fs from 'fs';
import path from 'path';
import ApprovalDiff from '../components/ApprovalDiff';
import ParkCard from '../components/ParkCard';

const diff = {
	fields: [
		{ field: 'regular_price', current: '10.00', proposed: '12.50' },
		{ field: 'title', current: 'Old', proposed: 'New' },
	],
	content: {
		truncated: false,
		ops: [
			{ t: 'eq', text: 'Hello' },
			{ t: 'ins', text: 'brave new' },
			{ t: 'del', text: 'old' },
		],
	},
	menu: { ops: [ { t: 'eq', text: 'Home' }, { t: 'del', text: 'About' } ] },
};

const payload = ( extra = {} ) => ( {
	verb: 'commerce/price-change',
	tier: 2,
	args: { id: 7, regular_price: '12.50' },
	...extra,
} );

describe( 'ApprovalDiff', () => {
	it( 'shows current against proposed for scalar fields, with labels', () => {
		const { container } = render( <ApprovalDiff diff={ diff } /> );
		expect( screen.getByText( /What changes/ ) ).toBeInTheDocument();
		expect( screen.getByText( 'Regular price' ) ).toBeInTheDocument();
		expect( container.querySelector( 'del' ) ).toHaveTextContent( '10.00' );
		expect( container.querySelector( 'ins' ) ).toHaveTextContent( '12.50' );
		expect( screen.getAllByText( 'Current:' ).length ).toBe( 2 );
	} );

	it( 'renders the word diff with removed and added words readable without colour', () => {
		const { container } = render( <ApprovalDiff diff={ diff } /> );
		const ops = container.querySelectorAll( '.specflux-site-agent-diff-ops' )[ 0 ];
		expect( ops.querySelector( 'ins' ) ).toHaveTextContent( 'Added: brave new' );
		expect( ops.querySelector( 'del' ) ).toHaveTextContent( 'Removed: old' );
		expect( screen.getByText( 'Menu item labels' ) ).toBeInTheDocument();
	} );

	it( 'renders nothing for a missing, empty or malformed diff', () => {
		for ( const bad of [ undefined, null, 'x', 5, {}, { fields: 'no', content: 3 }, { fields: [ { field: 'nope', current: 'a', proposed: 'b' } ] } ] ) {
			const { container, unmount } = render( <ApprovalDiff diff={ bad } /> );
			expect( container ).toBeEmptyDOMElement();
			unmount();
		}
	} );

	it( 'renders text only, never markup', () => {
		const evil = { fields: [ { field: 'title', current: '<img src=x onerror=alert(1)>', proposed: '<b>x</b>' } ] };
		const { container } = render( <ApprovalDiff diff={ evil } /> );
		expect( container.querySelector( 'img, b' ) ).toBeNull();
		expect( container ).toHaveTextContent( '<img src=x onerror=alert(1)>' );
	} );

	it( 'styles with logical properties only, so it is RTL-safe', () => {
		const css = fs.readFileSync( path.join( __dirname, '..', 'style.css' ), 'utf8' );
		const block = css.slice( css.indexOf( 'Approve with a diff (0.4 S4)' ) );
		expect( block ).not.toMatch( /\b(margin|padding|border)-(left|right)\b|\b(left|right)\s*:|text-align/ );
		expect( block ).toMatch( /border-inline-start/ );
	} );
} );

describe( 'approve binds to the arguments, not the diff', () => {
	const approveWith = ( p ) => {
		const onResolve = jest.fn();
		const { unmount } = render( <ParkCard kind="approval" gateMode="built_in" payload={ p } onResolve={ onResolve } /> );
		fireEvent.click( screen.getByRole( 'button', { name: 'Approve' } ) );
		unmount();
		return onResolve.mock.calls;
	};

	it( 'submits the same payload with a diff, a tampered diff, or none', () => {
		const baseline = approveWith( payload() );
		expect( baseline ).toEqual( [ [ { action: 'approve' } ] ] );
		expect( approveWith( payload( { diff } ) ) ).toEqual( baseline );
		expect( approveWith( payload( { diff: { fields: [ { field: 'regular_price', current: '1', proposed: '0.01' } ] } } ) ) ).toEqual( baseline );
		expect( approveWith( payload( { diff: 'garbage' } ) ) ).toEqual( baseline );
	} );

	it( 'still shows the arguments in full beneath the diff', () => {
		const { container } = render( <ParkCard kind="approval" gateMode="built_in" payload={ payload( { diff } ) } /> );
		const pre = container.querySelector( '.specflux-site-agent-args' );
		expect( pre ).toHaveTextContent( '"regular_price": "12.50"' );
		const section = container.querySelector( '.specflux-site-agent-approval-diff' );
		expect( section.compareDocumentPosition( pre ) & Node.DOCUMENT_POSITION_FOLLOWING ).toBeTruthy();
	} );

	it( 'an unknown ability (no diff) renders no section but keeps the arguments', () => {
		const { container } = render( <ParkCard kind="approval" gateMode="built_in" payload={ payload() } /> );
		expect( container.querySelector( '.specflux-site-agent-approval-diff' ) ).toBeNull();
		expect( container.querySelector( '.specflux-site-agent-args' ) ).toBeInTheDocument();
	} );
} );
