// @ts-check
const { test, expect } = require( '@playwright/test' );
const { setScript, resetRuns, wpCli } = require( '../support/wp-cli' );
const { retitlePost } = require( '../support/scenarios' );
const { gotoRuns, waitLoaded, startRun, waitForPark, acceptPlan, approveCall, waitSettled } = require( '../support/actions' );

/**
 * Stage E1: undo from the run report (0.4 S3.2), Belayline Agent Governance
 * mode.
 *
 * A run retitles a real post; the report's Changes area shows the recorded
 * before/after, "Undo this run" first previews what would be restored (and
 * writes nothing), and confirming puts the original title back.
 *
 * Undo needs Belayline's change-history API (`belayline()->changes()`,
 * Belayline 0.5+). Belayline's `main` branch does not have it yet, so this
 * spec skips itself against a build without it (the report then shows the
 * one "Undo needs Belayline Agent Governance" line, which is asserted instead).
 */
// Each wp-cli round trip costs several seconds in Docker; these specs make many.
test.describe.configure( { timeout: 300000 } );

const ORIGINAL = 'E1 original title';
const CHANGED = 'E1 changed by the agent';

/** @return {boolean} Is the undo API reachable in the running site? */
function undoApiAvailable() {
	const out = wpCli( [
		'eval',
		'echo ( function_exists( "belayline" ) && is_object( belayline() ) && method_exists( belayline(), "changes" ) && null !== belayline()->changes() ) ? "yes" : "no";',
	] ).trim();
	return 'yes' === out;
}

const postTitle = ( id ) => wpCli( [ 'post', 'get', String( id ), '--field=post_title' ] ).trim();

test.describe( 'E1 undo a run from its report (Belayline Agent Governance mode)', () => {
	/** @type {number} */
	let postId;

	test.beforeEach( () => {
		resetRuns();
		// Run ids restart at 1 after resetRuns(), and Belayline files changes under the run's id:
		// drop earlier runs' recorded changes so this run's report lists only its own.
		wpCli( [ 'db', 'query', 'TRUNCATE TABLE wp_belayline_changes' ] );
		postId = Number( wpCli( [ 'post', 'create', `--post_title=${ ORIGINAL }`, '--post_status=draft', '--porcelain' ] ).trim() );
		setScript( retitlePost( postId, CHANGED ) );
	} );

	test.afterEach( () => {
		try {
			wpCli( [ 'post', 'delete', String( postId ), '--force' ] );
		} catch ( e ) {
			// Already gone.
		}
	} );

	/** Run the scripted retitle, reopen the finished run, and return the Changes area (or skip when undo is unavailable). */
	async function runAndOpenReport( page ) {
		const available = undoApiAvailable();

		await gotoRuns( page );
		await waitLoaded( page );
		await startRun( page, 'Retitle the launch post' );
		await waitForPark( page, 'plan', 'Plan: needs your OK' );
		await acceptPlan( page );
		await waitForPark( page, 'approval', 'Approve this change?' );
		await approveCall( page );
		await waitSettled( page );

		expect( postTitle( postId ), 'the run changed the post' ).toBe( CHANGED );

		const report = page.locator( '.specflux-site-agent-report' );
		await expect( report ).toBeVisible( { timeout: 30000 } );

		// The Changes area is built by the server when it reads the run; reopen the finished run.
		const runId = wpCli( [ 'db', 'query', 'SELECT id FROM wp_specflux_site_agent_runs ORDER BY id DESC LIMIT 1', '--skip-column-names' ] ).trim();
		await gotoRuns( page, `&run_id=${ Number( runId ) }` );
		await waitLoaded( page );
		await expect( report ).toBeVisible( { timeout: 30000 } );

		if ( ! available ) {
			// Without the change-history API the report says so instead of offering an undo.
			await expect( page.locator( '.specflux-site-agent-changes-note' ) ).toContainText( 'Undo needs Belayline' );
			await expect( page.locator( '.specflux-site-agent-changes-undo-run' ) ).toHaveCount( 0 );
			test.skip( true, "Belayline's change-history API (belayline()->changes()) is not in this build; undo cannot run." );
		}
		return page.locator( '.specflux-site-agent-changes' );
	}

	test( 'the report shows the recorded change; undo previews what it would restore and writes nothing', async ( { page } ) => {
		const changes = await runAndOpenReport( page );
		await expect( changes ).toBeVisible();
		const row = changes.locator( '.specflux-site-agent-changes-row' );
		await expect( row ).toHaveCount( 1 );
		await expect( row.locator( '.specflux-site-agent-changes-state' ) ).toHaveText( 'Can be undone' );
		const diff = row.locator( '.specflux-site-agent-changes-diff' );
		await expect( diff ).toContainText( ORIGINAL );
		await expect( diff ).toContainText( CHANGED );

		await changes.locator( '.specflux-site-agent-changes-undo-run' ).click();
		const dialog = page.getByRole( 'dialog' );
		await expect( dialog ).toBeVisible();
		await expect( dialog ).toContainText( 'Undo this run?' );
		await expect( dialog.locator( '.specflux-site-agent-undo-item.is-restorable' ) ).toHaveCount( 1 );
		await expect( dialog ).toContainText( 'Will be restored' );
		expect( postTitle( postId ), 'a preview must not write' ).toBe( CHANGED );

		await dialog.getByRole( 'button', { name: 'Cancel' } ).click();
		await expect( dialog ).toHaveCount( 0 );
		expect( postTitle( postId ), 'cancel leaves the change in place' ).toBe( CHANGED );
	} );

	// Regression: Belayline treated a role-bound human admin as an agent token (fixed in Belayline c754219).
	test( 'confirming the undo restores the previous title', async ( { page } ) => {
		const changes = await runAndOpenReport( page );
		await changes.locator( '.specflux-site-agent-changes-undo-run' ).click();
		await page.getByRole( 'dialog' ).getByRole( 'button', { name: 'Confirm undo' } ).click();

		await expect( changes.locator( '.specflux-site-agent-undo-result-line' ) ).toHaveText( 'Undone.', { timeout: 30000 } );
		await expect( changes.locator( '.specflux-site-agent-undo-item.is-restored' ) ).toHaveCount( 1 );
		await expect( changes.locator( '.specflux-site-agent-changes-row .specflux-site-agent-changes-state' ) ).toHaveText( 'Restored' );
		expect( postTitle( postId ), 'the original title is back' ).toBe( ORIGINAL );
		await expect( changes.locator( '.specflux-site-agent-changes-undo-run' ) ).toHaveCount( 0 );
	} );
} );
