import { render, screen } from '@testing-library/react';
import { AuiButton } from '../components/ui';

describe( 'AuiButton toggles', () => {
	it( 'marks a button with aria-expanded and aria-controls as manually owned', () => {
		render( <AuiButton aria-expanded={ false } aria-controls="body">Toggle</AuiButton> );
		expect( screen.getByRole( 'button', { name: 'Toggle' } ) ).toHaveAttribute( 'data-aui-manual' );
	} );

	it( 'leaves other buttons without the attribute', () => {
		render( <><AuiButton>Plain</AuiButton><AuiButton aria-expanded="true">Expanded only</AuiButton></> );
		expect( screen.getByRole( 'button', { name: 'Plain' } ) ).not.toHaveAttribute( 'data-aui-manual' );
		expect( screen.getByRole( 'button', { name: 'Expanded only' } ) ).not.toHaveAttribute( 'data-aui-manual' );
	} );
} );
