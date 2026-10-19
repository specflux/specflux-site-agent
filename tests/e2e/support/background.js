/**
 * Stage E2 helpers: drive the Action Scheduler queue the way a real site's
 * cron would, and read run/mail state straight from the database so a spec
 * can assert what happened while NO browser was open.
 */
const { wpCli, dbQuery, setOption, deleteOption } = require( './wp-cli' );

/** The hand-off grace (seconds) before the queue may take a run over. */
const GRACE_SECONDS = 20;

const sleep = ( ms ) => new Promise( ( resolve ) => setTimeout( resolve, ms ) );

/** Id of the newest run. */
function latestRunId() {
	return Number( dbQuery( 'SELECT MAX(id) FROM wp_specflux_site_agent_runs' ) );
}

/** One run's columns as an object. */
function runRow( id ) {
	const [ status, driver, notified ] = dbQuery(
		`SELECT status, last_driver, IFNULL(notified_park_step,'') FROM wp_specflux_site_agent_runs WHERE id=${ Number( id ) }`
	).split( '\t' );
	return { status, driver, notified };
}

/** Wait (poll) until the browser's own ticks have stopped, so a later "still running" assertion is stable. */
async function waitForTicksToStop( id, quietMs = 8000, maxMs = 60000 ) {
	const started = Date.now();
	let last = dbQuery( `SELECT step_count FROM wp_specflux_site_agent_runs WHERE id=${ Number( id ) }` );
	let since = Date.now();
	while ( Date.now() - started < maxMs ) {
		await sleep( 2000 );
		const now = dbQuery( `SELECT step_count FROM wp_specflux_site_agent_runs WHERE id=${ Number( id ) }` );
		if ( now !== last ) {
			last = now;
			since = Date.now();
		} else if ( Date.now() - since >= quietMs ) {
			return;
		}
	}
}

/** Sleep until the hand-off grace has certainly passed. */
async function waitPastGrace() {
	await sleep( ( GRACE_SECONDS + 3 ) * 1000 );
}

let migrated = false;

/**
 * A fresh install starts Action Scheduler on its hybrid store until its one-off
 * migration action has run; the hybrid store cannot look up a group, so
 * `action-scheduler run --group=...` refuses. Run that migration once first
 * (a no-op when it is already done).
 */
function ensureQueueMigrated() {
	if ( migrated ) {
		return;
	}
	wpCli( [ 'action-scheduler', 'run', '--hooks=action_scheduler/migration_hook' ] );
	migrated = true;
}

/**
 * Run our plugin's queued actions, as WP-Cron / a real runner would, until
 * `done( row )` is true or `maxRounds` have run.
 *
 * @param {number}                 id        Run id.
 * @param {(row:object)=>boolean}  done      Stop condition on the run row.
 * @param {number}                 maxRounds Safety bound.
 * @return {object} The final run row.
 */
function driveQueueUntil( id, done, maxRounds = 25 ) {
	ensureQueueMigrated();
	let row = runRow( id );
	for ( let i = 0; i < maxRounds && ! done( row ); i++ ) {
		wpCli( [ 'action-scheduler', 'run', '--group=specflux-site-agent', '--batch-size=20' ] );
		row = runRow( id );
	}
	return row;
}

/**
 * Let the Runs screen drive only its first `allowed` ticks, then cut it off, so
 * the rest of the run can only be driven by the queue. Without this a fast
 * screen finishes a short run before the spec can close the page.
 *
 * @param {import('@playwright/test').Page} page
 * @param {number} allowed Ticks the screen may complete.
 * @return {Promise<{reached: Promise<void>}>} `reached` resolves once the last allowed tick has returned.
 */
async function limitScreenTicks( page, allowed ) {
	let seen = 0;
	let release = () => {};
	const reached = new Promise( ( resolve ) => {
		release = resolve;
	} );
	await page.route( /admin-ajax\.php/, async ( route ) => {
		if ( ! ( route.request().postData() || '' ).includes( 'specflux_site_agent_tick' ) ) {
			return route.continue();
		}
		seen++;
		if ( seen > allowed ) {
			return route.abort();
		}
		const response = await route.fetch();
		await route.fulfill( { response } );
		if ( seen === allowed ) {
			release();
		}
	} );
	return { reached };
}

/** Shorten the tick slice so each (slow) fake model turn is one tick. Undo with {@see normalTicks}. */
function shortTicks() {
	setOption( 'specflux_site_agent_e2e_tick_slice', '10' );
}

function normalTicks() {
	deleteOption( 'specflux_site_agent_e2e_tick_slice' );
}

/** Start capturing wp_mail() into the fixture option (and clear what was captured). */
function captureMail() {
	deleteOption( 'specflux_site_agent_e2e_mail' );
	setOption( 'specflux_site_agent_e2e_capture_mail', '1' );
}

function stopCapturingMail() {
	deleteOption( 'specflux_site_agent_e2e_capture_mail' );
	deleteOption( 'specflux_site_agent_e2e_mail' );
}

/** @return {Array<{to:string|string[],subject:string,message:string}>} */
function capturedMail() {
	const out = wpCli( [ 'eval', 'echo wp_json_encode( get_option( "specflux_site_agent_e2e_mail", array() ) );' ] ).trim();
	return JSON.parse( out.split( '\n' ).pop() );
}

module.exports = {
	GRACE_SECONDS,
	sleep,
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
};
