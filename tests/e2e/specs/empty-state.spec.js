// @ts-check
const { test, expect } = require( '@playwright/test' );
const { resetRuns } = require( '../support/wp-cli' );
const { gotoRuns, waitLoaded } = require( '../support/actions' );
const { assertNoSeriousA11y } = require( '../support/a11y' );

test.describe( 'S10 empty state (built-in mode)', () => {
	test( 'shows "Nothing has run yet" with a built-in-mode promise and no tier vocabulary', async ( { page } ) => {
		resetRuns();
		await gotoRuns( page );
		await waitLoaded( page );

		await expect( page.locator( '.specflux-site-agent-empty-state h2' ) ).toHaveText( 'Nothing has run yet' );
		// Built-in mode's promise sentence never mentions the tier vocabulary.
		await expect( page.locator( '.specflux-site-agent-empty-state' ) ).not.toContainText( 'Tier' );

		await assertNoSeriousA11y( page, 'empty state' );
	} );

	// Guided setup replaced the dismissible "install Belayline Agent Governance" notice: the
	// choice of approval mode is step 2 of the Set up Specflux Site Agent panel, and a
	// finished setup collapses to a chip. (Updated without a wp-env run.)
	test( 'the setup panel offers Belayline Agent Governance as a choice instead of a dismissible notice', async ( { page } ) => {
		await gotoRuns( page );
		await waitLoaded( page );

		await expect( page.locator( '.specflux-site-agent-setup-check[data-check-id="specflux/agent-safety"]' ) ).toHaveCount( 0 );
		await expect( page.locator( '#specflux-site-agent-setup-panel' ) ).toHaveCount( 1 );

		const panel = page.locator( '.specflux-site-agent-setup-steps, .specflux-site-agent-setup-collapsed' );
		await expect( panel ).toBeVisible();
		if ( await page.getByRole( 'button', { name: 'Setup ✓' } ).count() ) {
			await page.getByRole( 'button', { name: 'Setup ✓' } ).click();
		}
		await expect( page.getByText( 'Use Belayline Agent Governance rules' ) ).toBeVisible();
		await expect( page.getByText( 'Approve every change here' ) ).toBeVisible();
	} );
} );
