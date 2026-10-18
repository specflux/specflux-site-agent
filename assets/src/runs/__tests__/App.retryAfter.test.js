/**
 * A tick that lands on a transient model error (or ends its bounded slice)
 * returns the run still `running` with `retry_after` seconds. The server no
 * longer sleeps in-request, so the Runs app has to wait that long itself
 * before ticking again; with no `retry_after` it ticks straight away.
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

function seed() {
	listRuns.mockResolvedValue( [ parkedRun ] );
	getRun.mockResolvedValue( {
		run: parkedRun,
		steps: [ { kind: 'approval', message: { verb: 'specflux/publish-page', tier: 1, args: {} } } ],
	} );
}

describe( 'tick retry_after', () => {
	beforeEach( () => {
		jest.clearAllMocks();
		jest.useFakeTimers();
	} );

	afterEach( () => {
		jest.useRealTimers();
	} );

	it( 'waits retry_after seconds before the next tick', async () => {
		seed();
		tickRun
			.mockResolvedValueOnce( { run: { ...parkedRun, status: 'running', step_count: 3 }, new_steps: [], retry_after: 4, continue: true } )
			.mockResolvedValueOnce( { run: { ...parkedRun, status: 'completed', step_count: 5 }, new_steps: [], retry_after: 0, continue: false } );

		render( <App config={ { initialRunId: '7', nonce: 'abc', consumer: 'admin', gateMode: 'agent_safety' } } /> );
		fireEvent.click( await screen.findByRole( 'button', { name: 'Approve' } ) );

		await waitFor( () => expect( tickRun ).toHaveBeenCalledTimes( 1 ) );

		await act( async () => {
			jest.advanceTimersByTime( 3900 );
		} );
		expect( tickRun ).toHaveBeenCalledTimes( 1 );

		await act( async () => {
			jest.advanceTimersByTime( 200 );
		} );
		await waitFor( () => expect( tickRun ).toHaveBeenCalledTimes( 2 ) );
		expect( tickRun.mock.calls[ 1 ][ 1 ] ).toBe( 3 );
	} );

	it( 'ticks again immediately when retry_after is 0 (a bounded slice ended)', async () => {
		seed();
		tickRun
			.mockResolvedValueOnce( { run: { ...parkedRun, status: 'running', step_count: 3 }, new_steps: [], retry_after: 0, continue: true } )
			.mockResolvedValueOnce( { run: { ...parkedRun, status: 'completed', step_count: 5 }, new_steps: [], retry_after: 0, continue: false } );

		render( <App config={ { initialRunId: '7', nonce: 'abc', consumer: 'admin', gateMode: 'agent_safety' } } /> );
		fireEvent.click( await screen.findByRole( 'button', { name: 'Approve' } ) );

		await waitFor( () => expect( tickRun ).toHaveBeenCalledTimes( 2 ) );
	} );
} );
