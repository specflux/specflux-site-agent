// @ts-check
const { test, expect } = require( '@playwright/test' );
const { setScript, resetRuns, dbQuery } = require( '../support/wp-cli' );
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
 * Stage E2: off-screen approval resume (0.4 unattended runs, Belayline Agent
 * Governance mode).
 *
 * The run's write is held by Belayline as a pending approval while no Runs
 * screen is open. Approving it on Belayline's Pending tab fires
 * `belayline_approval_resolved`; Site Agent queues the resume, and the next
 * queue pass finishes the run. Belayline emails its own approvals, so Site
 * Agent must not add a second one.
 */
test.describe.configure( { timeout: 600000 } );

test.describe( 'E2 approving on Belayline with the Runs screen closed resumes the run (Belayline Agent Governance mode)', () => {
	test.beforeEach( () => {
		resetRuns();
		setScript( planReadsWrite() );
		captureMail();
		shortTicks();
		// Earlier specs leave pending approvals behind; this one must find only its own.
		dbQuery( "DELETE FROM wp_belayline_approvals WHERE status='pending'" );
	} );

	test.afterEach( () => {
		stopCapturingMail();
		normalTicks();
	} );

	test( 'the held write is approved on the Pending tab and the queue finishes the run', async ( { page, context } ) => {
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
		await waitPastGrace();

		const parked = driveQueueUntil( id, ( row ) => 'awaiting_approval' === row.status );
		expect( parked.status, 'the write is held by Belayline' ).toBe( 'awaiting_approval' );
		expect( parked.driver ).toBe( 'background' );
		// Belayline mails its own approvals; Site Agent adds no park email of its own.
		const mail = capturedMail();
		expect( mail.filter( ( m ) => /A Site Agent run/.test( m.subject ) ), 'no Site Agent park email' ).toHaveLength( 0 );
		expect( mail.filter( ( m ) => /^\[Belayline Agent Governance\] Approval requested: specflux-e2e\/create-thing$/.test( m.subject ) ), "Belayline's own approval email" ).toHaveLength( 1 );

		// The pending approval is correlated to the run and waits up to 7 days.
		const [ correlation, days ] = dbQuery(
			"SELECT correlation_id, TIMESTAMPDIFF(DAY, created_ts, pending_expires_ts) FROM wp_belayline_approvals WHERE status='pending'"
		).split( '\t' );
		expect( correlation.startsWith( 'specflux:run:' ), `pending approval is correlated to a run (${ correlation })` ).toBe( true );
		expect( Number( days ) ).toBeGreaterThanOrEqual( 6 );
		expect( Number( days ) ).toBeLessThanOrEqual( 7 );

		const pending = await context.newPage();
		await pending.goto( '/wp-admin/admin.php?page=belayline-pending' );
		const card = pending.locator( 'article.aui-approval' );
		await expect( card ).toHaveCount( 1 );
		await card.getByRole( 'button', { name: 'Approve' } ).click();
		await expect( pending.getByText( 'is now approved' ) ).toBeVisible();
		await pending.close();

		const done = driveQueueUntil( id, ( row ) => 'completed' === row.status );
		expect( done.status, 'the resume was queued by the approval and finished the run' ).toBe( 'completed' );
		expect( done.driver ).toBe( 'background' );

		const reopened = await context.newPage();
		await gotoRuns( reopened, `&run_id=${ id }` );
		await waitLoaded( reopened );
		await expect( reopened.getByText( 'Created the launch post.' ) ).toBeVisible( { timeout: 30000 } );
		await reopened.close();
	} );
} );
