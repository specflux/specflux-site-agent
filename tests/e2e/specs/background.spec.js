// @ts-check
const { test, expect } = require( '@playwright/test' );
const { setScript, resetRuns, dbQuery } = require( '../support/wp-cli' );
const { longReads, BACKGROUND_DONE } = require( '../support/scenarios' );
const { gotoRuns, waitLoaded, startRun } = require( '../support/actions' );
const { latestRunId, runRow, waitForTicksToStop, waitPastGrace, driveQueueUntil, limitScreenTicks, shortTicks, normalTicks, sleep } = require( '../support/background' );

/**
 * Stage E2: a run finishes with the browser closed (0.4 unattended runs).
 *
 * The Runs screen drives a run while it is open. When the tab goes away the
 * Action Scheduler action queued after the last screen tick (one hand-off
 * grace later) takes over. Here a page starts a many-turn run, is closed
 * mid-run, and the queue is then driven by hand (`wp action-scheduler run`,
 * standing in for WP-Cron) until the run completes.
 */
test.describe.configure( { timeout: 600000 } );

test.describe( 'E2 a run finishes in the background after the page is closed', () => {
	test.beforeEach( () => {
		resetRuns();
		setScript( longReads() );
		shortTicks();
	} );

	test.afterEach( () => {
		normalTicks();
	} );

	test( 'the queue completes the run and the result is there when it is reopened', async ( { page, context } ) => {
		// The screen may drive one tick; everything after that is the queue's.
		const screen = await limitScreenTicks( page, 1 );
		await gotoRuns( page );
		await waitLoaded( page );
		await startRun( page, 'Check the things' );
		await screen.reached;

		// The screen has ticked at least once, so the hand-off action exists.
		let id = 0;
		for ( let i = 0; i < 30 && ! id; i++ ) {
			await sleep( 1000 );
			const found = latestRunId();
			if ( found && dbQuery( `SELECT IFNULL(last_tick_at,'') FROM wp_specflux_site_agent_runs WHERE id=${ found }` ) ) {
				id = found;
			}
		}
		expect( id, 'the screen started a run and ticked it' ).toBeGreaterThan( 0 );

		await page.close();
		await waitForTicksToStop( id );

		const midway = runRow( id );
		expect( [ 'pending', 'running' ], 'the run is still mid-flight with no browser open' ).toContain( midway.status );
		expect( midway.driver ).toBe( 'screen' );

		await waitPastGrace();
		const done = driveQueueUntil( id, ( row ) => 'completed' === row.status );
		expect( done.status ).toBe( 'completed' );
		expect( done.driver, 'the queue, not a screen, drove the last tick' ).toBe( 'background' );

		const reopened = await context.newPage();
		await gotoRuns( reopened, `&run_id=${ id }` );
		await waitLoaded( reopened );
		await expect( reopened.getByText( BACKGROUND_DONE ) ).toBeVisible( { timeout: 30000 } );
		await reopened.close();
	} );
} );
