// @ts-check
const { test, expect } = require( '@playwright/test' );
const { setScript, resetRuns, wpCli } = require( '../support/wp-cli' );
const { planReadsWrite } = require( '../support/scenarios' );
const { gotoRuns, waitLoaded, startRun, waitForPark, acceptPlan } = require( '../support/actions' );
const {
	latestRunId,
	runRow,
	waitForTicksToStop,
	waitPastGrace,
	driveQueueUntil,
	limitScreenTicks,
	shortTicks,
	normalTicks,
	captureMail,
	stopCapturingMail,
	capturedMail,
} = require( '../support/background' );

/**
 * Stage E2: the park email (0.4 unattended runs, built-in gate).
 *
 * A run whose owner has left the page parks on an approval during a
 * BACKGROUND tick; the owner gets one email with a link back to the run. The
 * mail is captured by the fixture in mu-specflux-e2e-extras.php
 * (`pre_wp_mail`), so nothing is sent for real.
 */
test.describe.configure( { timeout: 600000 } );

test.describe( 'E2 park email (built-in gate)', () => {
	test.beforeEach( () => {
		resetRuns();
		setScript( planReadsWrite() );
		captureMail();
		shortTicks();
	} );

	test.afterEach( () => {
		stopCapturingMail();
		normalTicks();
	} );

	test( 'a run that parks for approval in the background emails the owner once, with a link that opens it', async ( { page, context } ) => {
		const ownerEmail = wpCli( [ 'user', 'get', '1', '--field=user_email' ] ).trim();

		// The screen drives the plan park and the accept; the reads and the write are the queue's.
		const screen = await limitScreenTicks( page, 2 );
		await gotoRuns( page );
		await waitLoaded( page );
		await startRun( page, 'Build the launch page' );
		await waitForPark( page, 'plan', 'Plan: needs your OK' );
		await acceptPlan( page );
		await screen.reached;
		await page.close();

		const id = latestRunId();
		await waitForTicksToStop( id );
		expect( runRow( id ).status, 'the run is mid-flight, not yet parked on the approval' ).toBe( 'running' );
		expect( capturedMail(), 'a screen tick sends no email' ).toHaveLength( 0 );

		await waitPastGrace();
		const parked = driveQueueUntil( id, ( row ) => 'awaiting_approval' === row.status );
		expect( parked.status ).toBe( 'awaiting_approval' );
		expect( parked.driver, 'the park happened on a background tick' ).toBe( 'background' );

		const mail = capturedMail();
		expect( mail, 'exactly one park email' ).toHaveLength( 1 );
		const [ email ] = mail;
		expect( [ email.to ].flat() ).toEqual( [ ownerEmail ] );
		expect( email.subject ).toMatch( /^\[.+\] A Site Agent run needs your approval$/ );
		expect( email.message ).toContain( 'Goal: Build the launch page' );
		expect( email.message, 'no tool arguments in the mail' ).not.toContain( 'Launch Day' );
		const link = email.message.match( /https?:\/\/\S+/ )?.[ 0 ] ?? '';
		expect( link ).toContain( 'page=specflux-site-agent-runs' );
		expect( link ).toContain( `run_id=${ id }` );
		expect( runRow( id ).notified, 'the park is recorded as notified' ).not.toBe( '' );

		// Once per park: another background pass must not send it again.
		driveQueueUntil( id, () => false, 1 );
		expect( capturedMail() ).toHaveLength( 1 );

		// The link lands on the parked approval.
		const opened = await context.newPage();
		await opened.goto( link.replace( /&amp;/g, '&' ) );
		await waitLoaded( opened );
		await waitForPark( opened, 'approval', 'Approve this change?' );
		await opened.close();
	} );
} );
