// @ts-check
const { test, expect } = require( '@playwright/test' );
const { setScript, resetRuns, setOption, deleteOption, dbQuery, wpCli } = require( '../support/wp-cli' );
const { call, text } = require( '../support/scenarios' );
const { gotoRuns, waitLoaded, startRun, waitSettled } = require( '../support/actions' );

/**
 * Stage E1: abilities from other plugins (0.4 S6).
 *
 * tests/e2e/fixtures/mu-specflux-e2e-extras.php registers `e2e-partner/ping`,
 * an ability from a namespace Specflux Site Agent does not own. By default
 * runs are not offered it; switching it on in Settings (as "Read") offers it
 * to every run, and the Settings screen refuses to enable more than 40.
 */
const PING = 'e2e-partner/ping';
const PING_CALLS = 'specflux_site_agent_e2e_ping_calls';
const SETTINGS = '/wp-admin/admin.php?page=specflux-site-agent-settings';

const pingScript = () => [ call( 'wpab__e2e-partner__ping', {} ), text( 'Ping finished.' ), text( 'Done.' ) ];

const pingCalls = () => {
	const raw = dbQuery( `SELECT option_value FROM wp_options WHERE option_name = '${ PING_CALLS }'` );
	return '' === raw ? 0 : Number( raw );
};

/** Run the ping script from the Runs screen and wait for it to settle. */
async function runPing( page ) {
	resetRuns();
	deleteOption( PING_CALLS );
	setScript( pingScript() );
	await gotoRuns( page );
	await waitLoaded( page );
	await startRun( page, 'Ping the partner plugin' );
	await waitSettled( page );
	await expect( page.locator( '.specflux-site-agent-chat-stream' ) ).toContainText( 'Ping finished', { timeout: 30000 } );
}

test.describe.configure( { mode: 'serial' } );

test.describe( 'E1 abilities from other plugins (built-in mode)', () => {
	test.beforeAll( () => {
		deleteOption( 'specflux_site_agent_passthrough' );
		deleteOption( 'specflux_site_agent_e2e_bulk_passthrough' );
		deleteOption( PING_CALLS );
	} );

	test.afterAll( () => {
		deleteOption( 'specflux_site_agent_passthrough' );
		deleteOption( 'specflux_site_agent_e2e_bulk_passthrough' );
		deleteOption( PING_CALLS );
	} );

	test( 'off by default: the ability is listed in Settings, unticked, and a run is not offered it', async ( { page } ) => {
		await page.goto( SETTINGS );
		const box = page.locator( `input[name="specflux_site_agent_pt_enabled[]"][value="${ PING }"]` );
		await expect( box ).toBeVisible();
		await expect( box ).not.toBeChecked();

		await runPing( page );
		// The model named the tool anyway; it was never admitted, so nothing ran.
		expect( pingCalls() ).toBe( 0 );
		await expect( page.locator( '.specflux-site-agent-card[data-card-type="error"]' ).first() ).toBeAttached();
	} );

	test( 'enabling it in Settings (as Read) makes it available to a run', async ( { page } ) => {
		await page.goto( SETTINGS );
		const box = page.locator( `input[name="specflux_site_agent_pt_enabled[]"][value="${ PING }"]` );
		await box.check();
		await page.locator( `select[name="specflux_site_agent_pt_mode[${ PING }]"]` ).selectOption( 'read' );
		await page.getByRole( 'button', { name: 'Save abilities' } ).click();

		await expect( page.locator( '[data-specflux-site-agent-notice]' ) ).toContainText( 'Abilities saved.' );
		await expect( box ).toBeChecked();

		await runPing( page );
		expect( pingCalls() ).toBe( 1 );
		// Read tier: it ran without a plan or an approval.
		await expect( page.locator( '#specflux-site-agent-park-heading' ) ).toHaveCount( 0 );
		const group = page.locator( '.specflux-site-agent-ledger-group', { hasText: 'Partner ping' } );
		await expect( group ).toHaveCount( 1 );
		await group.locator( 'summary' ).click();
		await expect( group ).toContainText( 'pong from the partner plugin' );
	} );

	test( 'at most 40 can be enabled: ticking more saves nothing', async ( { page } ) => {
		const before = dbQuery( "SELECT option_value FROM wp_options WHERE option_name = 'specflux_site_agent_passthrough'" );
		expect( before, 'the previous test left exactly the ping ability enabled' ).toContain( PING );

		setOption( 'specflux_site_agent_e2e_bulk_passthrough', '1' );
		await page.goto( SETTINGS );
		const boxes = page.locator( 'input[name="specflux_site_agent_pt_enabled[]"]' );
		// 41 bulk abilities plus the partner ping.
		await expect( boxes ).toHaveCount( 42 );
		for ( const box of await boxes.all() ) {
			await box.check();
		}
		await page.getByRole( 'button', { name: 'Save abilities' } ).click();

		await expect( page.locator( '[data-specflux-site-agent-notice].notice-error' ) ).toContainText( 'At most 40 abilities from other plugins can be enabled. Nothing was saved' );
		expect( dbQuery( "SELECT option_value FROM wp_options WHERE option_name = 'specflux_site_agent_passthrough'" ), 'nothing was saved' ).toBe( before );

		// Exactly at the cap is fine.
		await page.goto( SETTINGS );
		const all = await page.locator( 'input[name="specflux_site_agent_pt_enabled[]"]' ).all();
		for ( const box of all.slice( 0, 40 ) ) {
			await box.check();
		}
		await page.getByRole( 'button', { name: 'Save abilities' } ).click();
		await expect( page.locator( '[data-specflux-site-agent-notice]' ) ).toContainText( 'Abilities saved.' );
		expect( Number( wpCli( [ 'eval', 'echo count( array_filter( (array) get_option( "specflux_site_agent_passthrough" ), static fn ( $e ) => ! empty( $e["enabled"] ) ) );' ] ).trim() ) ).toBe( 40 );
	} );
} );
