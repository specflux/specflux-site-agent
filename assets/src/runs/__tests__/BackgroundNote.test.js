import { render, screen } from '@testing-library/react';
import BackgroundNote from '../components/BackgroundNote';
import Chat from '../components/Chat';

const run = { id: 1, goal: 'Publish the page', gate_mode: 'agent_safety' };

describe( 'BackgroundNote', () => {
	it( 'says the run keeps going when background running is available', () => {
		render( <BackgroundNote background={ { available: true, reason: '' } } /> );
		expect( screen.getByText( 'Keeps running if you close this page.' ) ).toBeInTheDocument();
	} );

	it.each( [
		[ 'disabled', /turned off on this site/ ],
		[ 'missing', /Action Scheduler is missing/ ],
		[ 'too_old', /Action Scheduler is too old/ ],
	] )( 'explains %s and that the run only advances while the page is open', ( reason, why ) => {
		render( <BackgroundNote background={ { available: false, reason } } /> );
		const note = screen.getByText( why );
		expect( note ).toHaveTextContent( 'only advances while this page is open' );
		expect( note ).toHaveAttribute( 'data-background', reason );
		expect( screen.queryByText( 'Keeps running if you close this page.' ) ).not.toBeInTheDocument();
	} );

	it( 'still warns, generically, on an unknown reason', () => {
		render( <BackgroundNote background={ { available: false, reason: 'whatever' } } /> );
		expect( screen.getByText( /Background running is unavailable\./ ) ).toHaveTextContent( 'only advances while this page is open' );
	} );

	it( 'renders nothing when the server sent no background object', () => {
		const { container } = render( <BackgroundNote /> );
		expect( container ).toBeEmptyDOMElement();
	} );
} );

describe( 'Chat shows the background line only while a run is running', () => {
	it.each( [ 'running', 'pending' ] )( 'shows it for a %s run', ( status ) => {
		render( <Chat run={ { ...run, status } } steps={ [] } background={ { available: true, reason: '' } } /> );
		expect( screen.getByText( 'Keeps running if you close this page.' ) ).toBeInTheDocument();
	} );

	it( 'shows the reason for an unavailable queue', () => {
		render( <Chat run={ { ...run, status: 'running' } } steps={ [] } background={ { available: false, reason: 'missing' } } /> );
		expect( screen.getByText( /Action Scheduler is missing/ ) ).toBeInTheDocument();
	} );

	it.each( [ 'completed', 'failed', 'cancelled', 'awaiting_user', 'awaiting_plan', 'awaiting_approval' ] )(
		'shows nothing for a %s run',
		( status ) => {
			render( <Chat run={ { ...run, status } } steps={ [] } background={ { available: true, reason: '' } } /> );
			expect( screen.queryByText( 'Keeps running if you close this page.' ) ).not.toBeInTheDocument();
		}
	);
} );
