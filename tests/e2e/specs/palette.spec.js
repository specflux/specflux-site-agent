// @ts-check
const { test, expect } = require( '@playwright/test' );
const { resetRuns } = require( '../support/wp-cli' );
const { assertNoSeriousA11y } = require( '../support/a11y' );

/**
 * S10: "the command palette opening the screen with a goal without starting
 * a run." The command opens a NEW TAB (by design — see
 * assets/src/commands/index.js's own comment on why it never navigates the
 * admin page the user was already on) with the goal pre-filled, and never
 * calls startRun/tickRun itself.
 */
test.describe( 'S10 command palette', () => {
	test( 'opens the Runs screen with the goal pre-filled, and starts nothing', async ( { page, context } ) => {
		resetRuns();

		await page.goto( '/wp-admin/index.php' );
		// Opened via the `core/commands` data store rather than a keyboard
		// shortcut: WP 7.1's admin does not bind Ctrl/Cmd+K outside the block
		// editor (confirmed live — pressing it opens nothing), but the
		// palette itself, and Specflux Site Agent's command loader registered into it,
		// are present site-wide (this page loads
		// build/commands/index.js). What this spec is responsible for is
		// Specflux Site Agent's OWN command behaviour, not core's keybinding.
		await page.waitForFunction( () => typeof window.wp !== 'undefined' && typeof window.wp.commands !== 'undefined' );
		await page.evaluate( () => window.wp.data.dispatch( 'core/commands' ).open() );

		const paletteInput = page.locator( '.commands-command-menu input[type="text"], .commands-command-menu input[type="search"]' ).first();
		await paletteInput.waitFor( { state: 'visible', timeout: 10000 } );
		await paletteInput.fill( 'Specflux Site Agent: run keyboard palette smoke goal' );

		const commandItem = page.locator( '[role="option"]', { hasText: 'Specflux Site Agent: run' } ).first();
		await commandItem.waitFor( { state: 'visible', timeout: 10000 } );

		const [ popup ] = await Promise.all( [ context.waitForEvent( 'page' ), commandItem.click() ] );
		await popup.waitForLoadState();

		expect( popup.url() ).toContain( 'page=specflux-site-agent-runs' );
		expect( popup.url() ).toContain( 'goal=' );
		await popup.waitForSelector( '#specflux-site-agent-runs-root' );
		await popup.waitForSelector( '.specflux-site-agent-loading', { state: 'detached' } ).catch( () => {} );

		await expect( popup.locator( '.specflux-site-agent-message-box-input' ) ).toHaveValue( /keyboard palette smoke goal/ );
		await assertNoSeriousA11y( popup, 'runs screen opened from palette' );
		// Never started: no park, no ledger, no typing bubble, no run selected.
		await expect( popup.locator( '.specflux-site-agent-park-card' ) ).toHaveCount( 0 );
		await expect( popup.locator( '.specflux-site-agent-chat-bubble' ) ).toHaveCount( 0 );
	} );
} );
