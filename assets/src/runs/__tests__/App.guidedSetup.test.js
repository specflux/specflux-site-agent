/**
 * Guided setup wired into the Runs screen: the panel renders from the page's
 * steps, re-checks on window focus, fills the goal box from a starter goal,
 * saves the site brief, and Start's tooltip names the blocking step.
 */

import { render, screen, fireEvent, waitFor, act } from '@testing-library/react';
import App from '../components/App';
import { listRuns, fetchSetupState, saveSetupBrief } from '../api';

jest.mock( '../api' );

const packs = [ { name: 'pages', label: 'pages' } ];

const step = ( over ) => ( { blocking: false, status: 'pass', why: 'why', action: null, ask: null, ...over } );
const providerFail = step( {
	id: 'provider',
	number: 1,
	title: 'Connect a model',
	blocking: true,
	status: 'fail',
	action: { label: 'Open Connectors', url: 'https://example.test/wp-admin/options-connectors.php' },
} );
const providerPass = { ...providerFail, status: 'pass', action: null };
const brief = step( { id: 'site-brief', number: 2, title: 'Tell Specflux Site Agent about your site', status: 'todo', brief: '', maxChars: 2000, canEdit: true } );
const firstRun = step( {
	id: 'first-run',
	number: 3,
	title: 'Try a first run',
	status: 'todo',
	goals: [ { pack: 'pages', text: 'Write an About page for my business' } ],
} );

const baseConfig = { nonce: 'n', consumer: 'specflux-site-agent-admin', gateMode: 'built_in', packs, examples: [] };

describe( 'Guided setup on the Runs screen', () => {
	beforeEach( () => {
		jest.clearAllMocks();
		listRuns.mockResolvedValue( [] );
	} );

	it( 'shows the panel and a Start tooltip naming the failing step', async () => {
		render( <App config={ { ...baseConfig, startBlocked: true, setupSteps: [ providerFail, brief, firstRun ] } } /> );

		expect( await screen.findByRole( 'heading', { name: 'Set up Specflux Site Agent' } ) ).toBeInTheDocument();
		fireEvent.change( screen.getByPlaceholderText( 'Describe what you want done…' ), { target: { value: 'Write a page' } } );

		expect( screen.getByRole( 'button', { name: 'Start run' } ) ).toHaveAttribute(
			'title',
			'Finish step 1, Connect a model, in Set up Specflux Site Agent to start a run.'
		);
	} );

	it( 'collapses to the chip and enables Start after a focus re-check finds the step passing', async () => {
		fetchSetupState.mockResolvedValue( {
			html: '',
			start_enabled: true,
			packs,
			unavailable_packs: [],
			steps: [ providerPass, brief, firstRun ],
		} );
		render( <App config={ { ...baseConfig, startBlocked: true, setupSteps: [ providerFail, brief, firstRun ] } } /> );
		await screen.findByRole( 'heading', { name: 'Set up Specflux Site Agent' } );

		await act( async () => {
			window.dispatchEvent( new Event( 'focus' ) );
		} );

		expect( await screen.findByRole( 'button', { name: 'Setup ✓' } ) ).toBeInTheDocument();
		expect( screen.queryByRole( 'heading', { name: 'Set up Specflux Site Agent' } ) ).not.toBeInTheDocument();
		fireEvent.change( screen.getByPlaceholderText( 'Describe what you want done…' ), { target: { value: 'Write a page' } } );
		expect( screen.getByRole( 'button', { name: 'Start run' } ) ).not.toBeDisabled();
	} );

	it( 'keeps the last known steps when a refresh fails', async () => {
		fetchSetupState.mockRejectedValue( new Error( 'offline' ) );
		render( <App config={ { ...baseConfig, startBlocked: true, setupSteps: [ providerFail ] } } /> );
		await screen.findByRole( 'heading', { name: 'Set up Specflux Site Agent' } );

		await act( async () => {
			window.dispatchEvent( new Event( 'focus' ) );
		} );

		expect( screen.getByRole( 'heading', { name: 'Set up Specflux Site Agent' } ) ).toBeInTheDocument();
	} );

	it( 'fills the goal box from a starter goal without starting a run', async () => {
		render( <App config={ { ...baseConfig, setupSteps: [ providerPass, brief, firstRun ] } } /> );

		fireEvent.click( await screen.findByRole( 'button', { name: 'Write an About page for my business' } ) );

		expect( screen.getByPlaceholderText( 'Describe what you want done…' ) ).toHaveValue( 'Write an About page for my business' );
		expect( screen.getByRole( 'button', { name: 'Start run' } ) ).not.toBeDisabled();
	} );

	it( 'saves the site brief and shows the step done from the refreshed steps', async () => {
		saveSetupBrief.mockResolvedValue( {
			steps: [ providerFail, { ...brief, status: 'pass', brief: 'We sell bicycles.' }, firstRun ],
		} );
		render( <App config={ { ...baseConfig, startBlocked: true, setupSteps: [ providerFail, brief, firstRun ] } } /> );

		fireEvent.change( await screen.findByLabelText( 'Site brief' ), { target: { value: 'We sell bicycles.' } } );
		fireEvent.click( screen.getByRole( 'button', { name: 'Save brief' } ) );

		await waitFor( () =>
			expect( saveSetupBrief ).toHaveBeenCalledWith( 'save', 'We sell bicycles.', expect.objectContaining( { nonce: 'n' } ) )
		);
		await waitFor( () => expect( screen.queryByLabelText( 'Site brief' ) ).not.toBeInTheDocument() );
	} );
} );
