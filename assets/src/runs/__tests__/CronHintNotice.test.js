import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import CronHintNotice from '../components/CronHintNotice';
import { dismissCronHint } from '../api';

jest.mock( '../api' );

const url = 'https://developer.wordpress.org/plugins/cron/hooking-wp-cron-into-the-system-task-scheduler/';

describe( 'CronHintNotice', () => {
	beforeEach( () => {
		jest.clearAllMocks();
		dismissCronHint.mockResolvedValue( { dismissed: true } );
	} );

	it( 'renders the hint and its help link when the server flags it', () => {
		render( <CronHintNotice config={ { cronHint: true, cronHelpUrl: url, nonce: 'n' } } /> );
		expect( screen.getByText( /Background runs only move when someone visits your site/ ) ).toBeInTheDocument();
		expect( screen.getByRole( 'link', { name: 'How to set up a real cron job' } ) ).toHaveAttribute( 'href', url );
	} );

	it.each( [ [ { cronHint: false } ], [ {} ] ] )( 'renders nothing without the flag (%j)', ( config ) => {
		const { container } = render( <CronHintNotice config={ config } /> );
		expect( container ).toBeEmptyDOMElement();
	} );

	it( 'dismiss hides it and calls the endpoint with the screen config', async () => {
		const config = { cronHint: true, cronHelpUrl: url, nonce: 'n' };
		render( <CronHintNotice config={ config } /> );
		fireEvent.click( screen.getByRole( 'button', { name: 'Dismiss' } ) );
		expect( dismissCronHint ).toHaveBeenCalledWith( config );
		await waitFor( () => expect( screen.queryByText( /Background runs only move/ ) ).not.toBeInTheDocument() );
	} );
} );
