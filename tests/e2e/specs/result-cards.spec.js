// @ts-check
const { test, expect } = require( '@playwright/test' );
const { setScript, resetRuns, wpCli } = require( '../support/wp-cli' );
const { retitlePost } = require( '../support/scenarios' );
const { gotoRuns, waitLoaded, startRun, waitForPark, acceptPlan, approveCall, waitSettled } = require( '../support/actions' );

/**
 * Stage E1: tool results as result cards, and the preview pane (0.4 S3.1).
 *
 * A run retitles a real draft post. Its tool result arrives as a `post`
 * display payload: the conversation shows it as a card (title, status,
 * Edit / Preview links, the raw JSON behind a "Show raw" disclosure) and the
 * right-hand pane offers the preview, which the person can hide and which
 * stays hidden after a reload.
 */
// Each wp-cli round trip costs several seconds in Docker; these specs make many.
test.describe.configure( { timeout: 300000 } );

const TITLE = 'E1 card post (retitled)';

test.describe( 'E1 result cards and the preview pane (built-in mode)', () => {
	/** @type {number} */
	let postId;

	test.beforeEach( () => {
		resetRuns();
		postId = Number( wpCli( [ 'post', 'create', '--post_title=E1 card post', '--post_status=draft', '--porcelain' ] ).trim() );
		setScript( retitlePost( postId, TITLE ) );
	} );

	test.afterEach( () => {
		try {
			wpCli( [ 'post', 'delete', String( postId ), '--force' ] );
		} catch ( e ) {
			// Already gone.
		}
	} );

	/** Run the scripted retitle to the end and open the finished run; returns its id. */
	async function runAndOpen( page ) {
		await gotoRuns( page );
		await waitLoaded( page );
		await startRun( page, 'Retitle the launch post' );
		await waitForPark( page, 'plan', 'Plan: needs your OK' );
		await acceptPlan( page );
		await waitForPark( page, 'approval', 'Approve this change?' );
		await approveCall( page );
		await waitSettled( page );
		await expect( page.locator( '.specflux-site-agent-report' ) ).toBeVisible( { timeout: 30000 } );

		// Cards come from the server's read of the run (the live tick responses carry
		// raw steps only), so open the finished run the way a person returning to it would.
		const runId = wpCli( [ 'db', 'query', 'SELECT id FROM wp_specflux_site_agent_runs ORDER BY id DESC LIMIT 1', '--skip-column-names' ] ).trim();
		await gotoRuns( page, `&run_id=${ Number( runId ) }` );
		await waitLoaded( page );
		return Number( runId );
	}

	test( 'a post result renders as a card with links, and the preview pane shows it', async ( { page } ) => {
		await runAndOpen( page );

		// --- The card in the conversation -------------------------------------
		// Consecutive tool calls collapse into a ledger group; open the one holding the retitle.
		const group = page.locator( '.specflux-site-agent-ledger-group', { hasText: 'Retitle post' } );
		await expect( group ).toHaveCount( 1 );
		await group.locator( 'summary' ).click();

		const card = group.locator( '.specflux-site-agent-card[data-card-type="post"]' );
		await expect( card ).toBeVisible();
		await expect( card.locator( '.specflux-site-agent-card-title' ) ).toContainText( TITLE );
		await expect( card.locator( '.specflux-site-agent-card-status' ) ).toHaveText( 'draft' );
		await expect( card.getByRole( 'link', { name: /^Edit/ } ) ).toHaveAttribute( 'href', new RegExp( `post=${ postId }` ) );
		const cardPreview = card.getByRole( 'link', { name: /^Preview/ } );
		await expect( cardPreview ).toHaveAttribute( 'href', /^https?:\/\// );
		await expect( cardPreview ).toHaveAttribute( 'target', '_blank' );
		await expect( cardPreview ).toHaveAttribute( 'rel', /noopener/ );

		// The raw result is there on request, not by default.
		await expect( card.locator( '.specflux-site-agent-card-raw' ) ).toHaveCount( 0 );
		await card.getByRole( 'button', { name: 'Show raw' } ).click();
		await expect( card.locator( '.specflux-site-agent-card-raw' ) ).toContainText( 'post_type' );
		await card.getByRole( 'button', { name: 'Hide raw' } ).click();
		await expect( card.locator( '.specflux-site-agent-card-raw' ) ).toHaveCount( 0 );

		// --- The preview pane --------------------------------------------------
		const pane = page.locator( '.specflux-site-agent-preview-pane' );
		await expect( pane ).toBeVisible();
		const body = pane.locator( '#specflux-site-agent-preview-body' );
		await expect( body ).toBeVisible();
		await expect( body.locator( '.specflux-site-agent-card[data-card-type="post"]' ) ).toContainText( TITLE );
		const open = body.getByRole( 'link', { name: /^Open preview in a new tab/ } );
		await expect( open ).toHaveAttribute( 'target', '_blank' );
		await expect( open ).toHaveAttribute( 'href', /^https?:\/\// );
		// Off by default: model-written content is not framed inside wp-admin.
		await expect( pane.locator( 'iframe' ) ).toHaveCount( 0 );
	} );

	test( 'the pane can be hidden and remembers it is hidden', async ( { page } ) => {
		const runId = await runAndOpen( page );
		const pane = page.locator( '.specflux-site-agent-preview-pane' );
		const body = pane.locator( '#specflux-site-agent-preview-body' );
		await expect( body ).toBeVisible();

		await pane.getByRole( 'button', { name: 'Hide preview' } ).click();
		await expect( body ).toBeHidden();
		await expect( pane ).toHaveClass( /is-collapsed/ );

		// A fresh load of the same run keeps the pane hidden (remembered per user).
		await gotoRuns( page, `&run_id=${ runId }` );
		await waitLoaded( page );
		const reloaded = page.locator( '.specflux-site-agent-preview-pane' );
		await expect( reloaded ).toHaveClass( /is-collapsed/ );
		await reloaded.getByRole( 'button', { name: 'Show preview' } ).click();
		await expect( reloaded.locator( '#specflux-site-agent-preview-body' ) ).toBeVisible();
	} );
} );
