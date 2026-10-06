import { render, screen, fireEvent } from '@testing-library/react';
import ResultCard from '../components/ResultCard';
import LedgerGroup from '../components/LedgerGroup';

const links = [
	{ label: 'Edit', url: 'https://example.test/wp-admin/post.php?post=5&action=edit', kind: 'edit' },
	{ label: 'Preview', url: 'https://example.test/?p=5&preview=true', kind: 'preview' },
];

describe( 'ResultCard', () => {
	it( 'renders a post with Edit/Preview links opening safely in a new tab', () => {
		render( <ResultCard raw="{}" display={ { type: 'post', title: 'Hello', status: 'draft', links } } /> );
		expect( screen.getByText( 'Hello' ) ).toBeInTheDocument();
		const edit = screen.getByRole( 'link', { name: /Edit/ } );
		expect( edit ).toHaveAttribute( 'target', '_blank' );
		expect( edit ).toHaveAttribute( 'rel', 'noopener noreferrer' );
		expect( screen.getByRole( 'link', { name: /Preview/ } ) ).toBeInTheDocument();
	} );

	it( 'renders a link card', () => {
		render( <ResultCard raw="" display={ { type: 'link', title: 'Preview ready', links: [ links[ 1 ] ] } } /> );
		expect( screen.getByRole( 'link', { name: /Preview/ } ) ).toBeInTheDocument();
	} );

	it( 'renders media with alt text, and flags missing alt', () => {
		const { rerender, container } = render(
			<ResultCard raw="" display={ { type: 'media', title: 'Pic', thumbnail_url: 'https://example.test/a.jpg', alt: 'A cat', links: [] } } />
		);
		expect( screen.getByAltText( 'A cat' ) ).toBeInTheDocument();
		expect( screen.queryByText( 'No alt text' ) ).not.toBeInTheDocument();
		rerender( <ResultCard raw="" display={ { type: 'media', title: 'Pic', thumbnail_url: 'https://example.test/a.jpg', alt: '', links: [] } } /> );
		expect( container.querySelector( 'img' ) ).toHaveAttribute( 'alt', '' );
		expect( screen.getByText( 'No alt text' ) ).toBeInTheDocument();
	} );

	it( 'renders product and coupon fields', () => {
		const { unmount } = render(
			<ResultCard raw="" display={ { type: 'product', title: 'Mug', status: 'publish', fields: [ { label: 'Price', value: '12.00' } ], links: [] } } />
		);
		expect( screen.getByText( 'Price' ) ).toBeInTheDocument();
		expect( screen.getByText( '12.00' ) ).toBeInTheDocument();
		unmount();
		render( <ResultCard raw="" display={ { type: 'coupon', title: 'SAVE10', fields: [ { label: 'Amount', value: '10' } ], links: [] } } /> );
		expect( screen.getByText( 'SAVE10' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Amount' ) ).toBeInTheDocument();
	} );

	it( 'renders a list with a "more" note', () => {
		render(
			<ResultCard
				raw=""
				display={ { type: 'list', title: 'Posts', count: 12, items: [ { label: 'One', detail: 'draft' }, { label: 'Two', detail: '' } ], more: 10 } }
			/>
		);
		expect( screen.getByText( 'One' ) ).toBeInTheDocument();
		expect( screen.getByText( /10 more not shown/ ) ).toBeInTheDocument();
	} );

	it( 'renders a real table with header scopes', () => {
		render(
			<ResultCard raw="" display={ { type: 'table', title: 'Report', columns: [ 'Measure', 'Value' ], rows: [ [ 'Orders', '4' ] ], more: 0 } } />
		);
		expect( screen.getByRole( 'table' ) ).toBeInTheDocument();
		expect( screen.getByRole( 'columnheader', { name: 'Measure' } ) ).toHaveAttribute( 'scope', 'col' );
		expect( screen.getByRole( 'rowheader', { name: 'Orders' } ) ).toHaveAttribute( 'scope', 'row' );
		expect( screen.queryByText( /more not shown/ ) ).not.toBeInTheDocument();
	} );

	it( 'renders an error as readable text with a non-color cue', () => {
		render( <ResultCard raw="" display={ { type: 'error', title: 'That did not work', message: 'Nope' } } /> );
		expect( screen.getByText( 'Error' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Nope' ) ).toBeInTheDocument();
	} );

	it( 'falls back to the existing text for null or unknown display', () => {
		const { rerender } = render( <ResultCard raw='{"a":1}' display={ null } /> );
		expect( screen.getByText( '{"a":1}' ) ).toBeInTheDocument();
		rerender( <ResultCard raw="plain" display={ { type: 'mystery' } } /> );
		expect( screen.getByText( 'plain' ) ).toBeInTheDocument();
		expect( screen.queryByText( 'Show raw' ) ).not.toBeInTheDocument();
	} );

	it( 'never renders unsafe URLs as links or images', () => {
		render(
			<ResultCard
				raw=""
				display={ {
					type: 'media',
					title: 'Bad',
					thumbnail_url: 'javascript:alert(1)',
					alt: 'x',
					links: [ { label: 'Evil', url: 'javascript:alert(1)' }, { label: 'Data', url: 'data:text/html,hi' }, { label: 'Fine', url: 'https://example.test/' } ],
				} }
			/>
		);
		expect( screen.queryByRole( 'link', { name: /Evil/ } ) ).not.toBeInTheDocument();
		expect( screen.queryByRole( 'link', { name: /Data/ } ) ).not.toBeInTheDocument();
		expect( screen.queryByAltText( 'x' ) ).not.toBeInTheDocument();
		expect( screen.getByRole( 'link', { name: /Fine/ } ) ).toBeInTheDocument();
	} );

	it( 'does not interpret HTML in payload text', () => {
		const { container } = render( <ResultCard raw="" display={ { type: 'error', title: '<b>x</b>', message: '<img src=x onerror=alert(1)>' } } /> );
		expect( container.querySelector( 'b, img' ) ).toBeNull();
	} );

	it( 'toggles the raw JSON, collapsed by default', () => {
		render( <ResultCard raw='{"id":5}' display={ { type: 'link', title: 'T', links: [] } } /> );
		const toggle = screen.getByRole( 'button', { name: 'Show raw' } );
		expect( toggle ).toHaveAttribute( 'aria-expanded', 'false' );
		expect( screen.queryByText( '{"id":5}' ) ).not.toBeInTheDocument();
		fireEvent.click( toggle );
		expect( screen.getByText( '{"id":5}' ) ).toBeInTheDocument();
		expect( screen.getByRole( 'button', { name: 'Hide raw' } ) ).toHaveAttribute( 'aria-expanded', 'true' );
	} );
} );

describe( 'LedgerGroup with cards', () => {
	it( 'keeps the N-actions summary and approval note while showing the card', () => {
		const step = {
			kind: 'tool_result',
			status: 'ok',
			tool_name: 'senroflux/create-post',
			display: { type: 'post', title: 'Hello', status: 'draft', links },
			message: { parts: [ { functionResponse: { response: { id: 5 } } } ] },
		};
		render( <LedgerGroup gateMode="built_in" calls={ [ { step, approvedByViewer: true } ] } /> );
		expect( screen.getByText( /1 action/ ) ).toBeInTheDocument();
		expect( screen.getByText( 'Approved by you' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Hello' ) ).toBeInTheDocument();
	} );
} );
