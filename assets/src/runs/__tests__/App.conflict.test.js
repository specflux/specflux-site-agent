/**
 * A tick refused with a 409 (`specflux_site_agent_conflict`) means the
 * background driver advanced the run, or holds its claim. The Runs app
 * refreshes the run and keeps ticking; it never shows an error banner.
 */

import { render, screen, waitFor, fireEvent, act } from '@testing-library/react';
import App from '../components/App';
import { listRuns, getRun, tickRun } from '../api';

jest.mock( '../api' );

const parkedRun = {
	id: 7,
	goal: 'Publish the spring page',
	status: 'awaiting_approval',
	pack: 'pages',
	viewer_may_tick: true,
};

const config = { initialRunId: '7', nonce: 'abc', consumer: 'admin', gateMode: 'agent_safety', background: { available: true, reason: '' } };

// Let queued promise callbacks (and their state updates) run inside act().
async function settle() {
	await act( async () => {
		for ( let i = 0; i < 20; i++ ) {
			await Promise.resolve();
		}
	} );
}

function conflict() {
	return Object.assign( new Error( 'The run advanced since your last request; refresh its state.' ), {
		code: 'specflux_site_agent_conflict',
	} );
}

describe( 'tick 409 conflict', () => {
	beforeEach( () => {
		jest.clearAllMocks();
		jest.useFakeTimers();
		listRuns.mockResolvedValue( [ parkedRun ] );
		getRun.mockResolvedValueOnce( {
			run: parkedRun,
			steps: [ { kind: 'approval', message: { verb: 'specflux/publish-page', tier: 1, args: {} } } ],
		} );
	} );

	afterEach( () => {
		jest.useRealTimers();
	} );

	it( 'refreshes the run, continues with the fresh step_count and shows no error', async () => {
		tickRun
			.mockRejectedValueOnce( conflict() )
			.mockResolvedValueOnce( { run: { ...parkedRun, status: 'completed', step_count: 9 }, new_steps: [], retry_after: 0, continue: false } );
		// The refresh: the background driver moved the run on to step 8, still running.
		getRun.mockResolvedValueOnce( { run: { ...parkedRun, status: 'running', step_count: 8 }, steps: [] } );

		render( <App config={ config } /> );
		const approve = await screen.findByRole( 'button', { name: 'Approve' } );
		await act( async () => {
			fireEvent.click( approve );
		} );
		await settle();
		await waitFor( () => expect( tickRun ).toHaveBeenCalledTimes( 1 ) );
		await waitFor( () => expect( getRun ).toHaveBeenCalledTimes( 2 ) );
		await settle();
		expect( document.querySelector( '.specflux-site-agent-action-error' ) ).toBeNull();

		await act( async () => {
			jest.advanceTimersByTime( 3100 );
		} );
		await settle();

		await waitFor( () => expect( tickRun ).toHaveBeenCalledTimes( 2 ) );
		expect( tickRun.mock.calls[ 1 ][ 1 ] ).toBe( 8 );
		await act( async () => {
			jest.advanceTimersByTime( 100 );
		} );
		await settle();
		expect( document.querySelector( '.specflux-site-agent-action-error' ) ).toBeNull();
	} );

	it( 'stops quietly when the refreshed run is no longer running', async () => {
		tickRun.mockRejectedValueOnce( conflict() );
		getRun.mockResolvedValueOnce( { run: { ...parkedRun, status: 'completed', step_count: 8 }, steps: [] } );

		render( <App config={ config } /> );
		fireEvent.click( await screen.findByRole( 'button', { name: 'Approve' } ) );

		await waitFor( () => expect( getRun ).toHaveBeenCalledTimes( 2 ) );
		await act( async () => {
			jest.advanceTimersByTime( 10000 );
		} );

		expect( tickRun ).toHaveBeenCalledTimes( 1 );
		expect( document.querySelector( '.specflux-site-agent-action-error' ) ).toBeNull();
	} );

	it( 'still surfaces any other tick error', async () => {
		tickRun.mockRejectedValueOnce( Object.assign( new Error( 'Boom' ), { code: 'specflux_site_agent_forbidden' } ) );

		render( <App config={ config } /> );
		fireEvent.click( await screen.findByRole( 'button', { name: 'Approve' } ) );

		expect( await screen.findByText( 'Boom' ) ).toBeInTheDocument();
	} );
} );
