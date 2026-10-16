/**
 * Guided setup: the "Set up Specflux Site Agent" panel. One test group per step state
 * (pass / fail / hidden), the collapse to a "Setup ✓" chip, the starter goals
 * and the deep link into Belayline Agent Governance.
 */

import { render, screen, fireEvent, waitFor, within } from '@testing-library/react';
import SetupPanel, { firstBlockingStep } from '../components/SetupPanel';

const BIND_URL =
	'https://example.test/wp-admin/tools.php?page=belayline-packs&belayline_bind_token=role%3Aadministrator&belayline_bind_pack=specflux-content';

const provider = ( over = {} ) => ( {
	id: 'provider',
	number: 1,
	title: 'Connect a model',
	why: 'Specflux Site Agent needs an AI model to plan and write for you.',
	status: 'pass',
	blocking: true,
	detail: null,
	action: null,
	ask: null,
	...over,
} );

const approval = ( over = {} ) => ( {
	id: 'approval',
	number: 2,
	title: 'Choose how changes are approved',
	why: 'Decide who signs off before Specflux Site Agent changes your site.',
	status: 'pass',
	blocking: false,
	mode: 'built_in',
	options: [
		{ id: 'built_in', title: 'Approve every change here', description: 'Specflux Site Agent asks you on this screen before each change.' },
		{
			id: 'belayline-agent-governance',
			title: 'Use Belayline Agent Governance rules',
			description: 'Belayline Agent Governance decides what needs approval by role and keeps an audit log.',
			state: 'missing',
			action: { label: 'Install Belayline Agent Governance', url: 'https://example.test/wp-admin/plugin-install.php?plugin=belayline-agent-governance' },
		},
	],
	action: null,
	...over,
} );

const oldPlugin = ( over = {} ) => ( {
	id: 'old-plugin',
	number: 3,
	title: 'Clear the old Agent Safety plugin',
	why: 'Belayline Agent Governance replaces Agent Safety and keeps its data, but the two cannot be active together.',
	status: 'fail',
	blocking: true,
	action: { label: 'Deactivate Agent Safety', url: 'https://example.test/wp-admin/plugins.php?action=deactivate' },
	ask: null,
	...over,
} );

const binding = ( over = {} ) => ( {
	id: 'role-binding',
	number: 3,
	title: 'Allow Specflux Site Agent for your role',
	why: 'Belayline Agent Governance only lets a role use Specflux Site Agent once someone binds that role to a pack.',
	status: 'fail',
	blocking: true,
	role: 'Administrator',
	action: { label: 'Turn on in Belayline Agent Governance', url: BIND_URL },
	ask: null,
	...over,
} );

const brief = ( over = {} ) => ( {
	id: 'site-brief',
	number: 4,
	title: 'Tell Specflux Site Agent about your site',
	why: 'A short note about your business and tone makes every run sound like you.',
	status: 'todo',
	blocking: false,
	brief: '',
	maxChars: 2000,
	canEdit: true,
	ask: null,
	action: null,
	...over,
} );

const firstRun = ( over = {} ) => ( {
	id: 'first-run',
	number: 5,
	title: 'Try a first run',
	why: 'Pick a starter goal to fill the goal box, then press Start run.',
	status: 'todo',
	blocking: false,
	goals: [
		{ pack: 'pages', text: 'Write an About page for my business' },
		{ pack: 'posts', text: 'Draft a blog post announcing what is new this month' },
		{ pack: 'pages', text: 'Build a Contact page with a short intro and my opening hours' },
	],
	action: null,
	...over,
} );

const stepEl = ( id ) => document.querySelector( `[data-step="${ id }"]` );

describe( 'SetupPanel step 1: Connect a model', () => {
	it( 'shows a failing step with its why, detail and the one action', () => {
		render(
			<SetupPanel
				steps={ [
					provider( {
						status: 'fail',
						detail: 'Configure a model provider in Settings → Connectors.',
						action: { label: 'Open Connectors', url: 'https://example.test/wp-admin/options-connectors.php' },
					} ),
				] }
			/>
		);

		expect( screen.getByRole( 'heading', { name: 'Set up Specflux Site Agent' } ) ).toBeInTheDocument();
		const step = within( stepEl( 'provider' ) );
		expect( step.getByText( 'Needs attention' ) ).toBeInTheDocument();
		expect( step.getByText( '1. Connect a model' ) ).toBeInTheDocument();
		expect( step.getByText( 'Specflux Site Agent needs an AI model to plan and write for you.' ) ).toBeInTheDocument();
		expect( step.getByText( 'Configure a model provider in Settings → Connectors.' ) ).toBeInTheDocument();
		expect( step.getByRole( 'link', { name: 'Open Connectors' } ) ).toHaveAttribute(
			'href',
			'https://example.test/wp-admin/options-connectors.php'
		);
	} );

	it( 'tells a person with no permission to ask an administrator, with no link', () => {
		render(
			<SetupPanel steps={ [ provider( { status: 'fail', ask: 'Ask an administrator to configure a model provider in Settings → Connectors.' } ) ] } />
		);

		expect( screen.getByText( /Ask an administrator to configure a model provider/ ) ).toBeInTheDocument();
		expect( screen.queryByRole( 'link' ) ).not.toBeInTheDocument();
	} );

	it( 'marks a passing step Done and shows no action', () => {
		render( <SetupPanel steps={ [ provider( { status: 'pass' } ), binding() ] } /> );

		const step = within( stepEl( 'provider' ) );
		expect( step.getByText( 'Done' ) ).toBeInTheDocument();
		expect( step.queryByRole( 'link' ) ).not.toBeInTheDocument();
	} );
} );

describe( 'SetupPanel step 2: Choose how changes are approved', () => {
	const open = ( step2 ) => render( <SetupPanel steps={ [ provider( { status: 'fail' } ), step2 ] } /> );

	it( 'offers both choices and marks the one in use', () => {
		open( approval() );

		expect( screen.getByText( 'Approve every change here' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Use Belayline Agent Governance rules' ) ).toBeInTheDocument();
		expect( screen.getAllByText( 'In use ✓' ) ).toHaveLength( 1 );
		expect( screen.getByRole( 'link', { name: 'Install Belayline Agent Governance' } ) ).toBeInTheDocument();
	} );

	it( 'shows Belayline Agent Governance as in use, with no install link, when it is active', () => {
		const step = approval( { mode: 'belayline-agent-governance' } );
		step.options[ 1 ] = { ...step.options[ 1 ], state: 'active', action: null };
		open( step );

		const card = screen.getByText( 'Use Belayline Agent Governance rules' ).closest( '.specflux-site-agent-setup-card' );
		expect( within( card ).getByText( 'In use ✓' ) ).toBeInTheDocument();
		expect( screen.queryByRole( 'link', { name: /Install|Activate/ } ) ).not.toBeInTheDocument();
	} );

	it( 'offers activation when Belayline Agent Governance is installed but off', () => {
		const step = approval();
		step.options[ 1 ] = {
			...step.options[ 1 ],
			state: 'installed',
			action: { label: 'Activate Belayline Agent Governance', url: 'https://example.test/wp-admin/plugins.php?action=activate' },
		};
		open( step );

		expect( screen.getByRole( 'link', { name: 'Activate Belayline Agent Governance' } ) ).toBeInTheDocument();
	} );
} );

describe( 'SetupPanel step 3: Clear the old Agent Safety plugin', () => {
	it( 'is hidden when the server sends no such step', () => {
		render( <SetupPanel steps={ [ provider( { status: 'fail' } ), approval() ] } /> );

		expect( stepEl( 'old-plugin' ) ).toBeNull();
		expect( screen.queryByText( /old Agent Safety/ ) ).not.toBeInTheDocument();
	} );

	it( 'fails with a deactivate link', () => {
		render( <SetupPanel steps={ [ provider(), oldPlugin() ] } /> );

		const step = within( stepEl( 'old-plugin' ) );
		expect( step.getByText( 'Needs attention' ) ).toBeInTheDocument();
		expect( step.getByRole( 'link', { name: 'Deactivate Agent Safety' } ) ).toBeInTheDocument();
	} );

	it( 'passes quietly once the old plugin is off', () => {
		render( <SetupPanel steps={ [ provider(), oldPlugin( { status: 'pass', action: null } ), binding() ] } /> );

		expect( within( stepEl( 'old-plugin' ) ).getByText( 'Done' ) ).toBeInTheDocument();
	} );
} );

describe( 'SetupPanel step 4: Allow Specflux Site Agent for your role', () => {
	it( 'is hidden in built-in mode', () => {
		render( <SetupPanel steps={ [ provider( { status: 'fail' } ), approval() ] } /> );

		expect( stepEl( 'role-binding' ) ).toBeNull();
	} );

	it( 'gives an administrator a link with the role token and content pack url-encoded', () => {
		render( <SetupPanel steps={ [ provider(), approval( { mode: 'belayline-agent-governance' } ), binding() ] } /> );

		const link = within( stepEl( 'role-binding' ) ).getByRole( 'link', { name: 'Turn on in Belayline Agent Governance' } );
		const href = link.getAttribute( 'href' );
		expect( href ).toBe( BIND_URL );
		const params = new URL( href ).searchParams;
		expect( params.get( 'page' ) ).toBe( 'belayline-packs' );
		expect( params.get( 'belayline_bind_token' ) ).toBe( 'role:administrator' );
		expect( params.get( 'belayline_bind_pack' ) ).toBe( 'specflux-content' );
		expect( href ).toContain( 'role%3Aadministrator' );
		expect( href ).not.toContain( 'role:administrator' );
	} );

	it( 'tells a non-administrator which role and pack to ask for, with no link', () => {
		render(
			<SetupPanel
				steps={ [
					provider(),
					binding( {
						role: 'Editor',
						action: null,
						ask: 'Ask an administrator to bind Editor to Specflux Site Agent content in Belayline Agent Governance.',
					} ),
				] }
			/>
		);

		const step = within( stepEl( 'role-binding' ) );
		expect( step.getByText( 'Ask an administrator to bind Editor to Specflux Site Agent content in Belayline Agent Governance.' ) ).toBeInTheDocument();
		expect( step.queryByRole( 'link' ) ).not.toBeInTheDocument();
	} );

	it( 'passes when a content pack is runnable', () => {
		// Another blocking step still fails, so the panel stays open to show this one.
		render( <SetupPanel steps={ [ provider(), oldPlugin(), binding( { status: 'pass', action: null } ) ] } /> );

		expect( within( stepEl( 'role-binding' ) ).getByText( 'Done' ) ).toBeInTheDocument();
	} );
} );

describe( 'SetupPanel step 5: Tell Specflux Site Agent about your site', () => {
	const open = ( over, handlers = {} ) =>
		render( <SetupPanel steps={ [ provider( { status: 'fail' } ), brief( over ) ] } { ...handlers } /> );

	it( 'shows the inline form for an administrator and saves the text', async () => {
		const onSaveBrief = jest.fn().mockResolvedValue( {} );
		open( {}, { onSaveBrief } );

		fireEvent.change( screen.getByLabelText( 'Site brief' ), { target: { value: 'We sell bicycles in Leeds.' } } );
		expect( screen.getByText( '26 of 2000 characters' ) ).toBeInTheDocument();
		fireEvent.click( screen.getByRole( 'button', { name: 'Save brief' } ) );

		await waitFor( () => expect( onSaveBrief ).toHaveBeenCalledWith( 'We sell bicycles in Leeds.' ) );
	} );

	it( 'skips on request', async () => {
		const onSkipBrief = jest.fn().mockResolvedValue( {} );
		open( {}, { onSkipBrief } );

		fireEvent.click( screen.getByRole( 'button', { name: 'Skip for now' } ) );

		await waitFor( () => expect( onSkipBrief ).toHaveBeenCalledTimes( 1 ) );
	} );

	it( 'will not save an empty or over-long brief', () => {
		open( { maxChars: 10 } );
		const save = screen.getByRole( 'button', { name: 'Save brief' } );
		expect( save ).toBeDisabled();

		fireEvent.change( screen.getByLabelText( 'Site brief' ), { target: { value: 'x'.repeat( 11 ) } } );
		expect( save ).toBeDisabled();

		fireEvent.change( screen.getByLabelText( 'Site brief' ), { target: { value: 'x'.repeat( 10 ) } } );
		expect( save ).not.toBeDisabled();
	} );

	it( 'shows the server refusal and keeps the text', async () => {
		const onSaveBrief = jest.fn().mockRejectedValue( new Error( 'The site brief may be at most 2000 characters.' ) );
		open( {}, { onSaveBrief } );

		fireEvent.change( screen.getByLabelText( 'Site brief' ), { target: { value: 'Hello' } } );
		fireEvent.click( screen.getByRole( 'button', { name: 'Save brief' } ) );

		expect( await screen.findByRole( 'alert' ) ).toHaveTextContent( 'The site brief may be at most 2000 characters.' );
		expect( screen.getByLabelText( 'Site brief' ) ).toHaveValue( 'Hello' );
	} );

	it( 'shows a non-administrator who to ask instead of a form', () => {
		open( { canEdit: false, ask: 'Ask an administrator to write the site brief.' } );

		expect( screen.getByText( 'Ask an administrator to write the site brief.' ) ).toBeInTheDocument();
		expect( screen.queryByLabelText( 'Site brief' ) ).not.toBeInTheDocument();
	} );

	it( 'shows no form once the brief is saved or skipped', () => {
		open( { status: 'pass', brief: 'We sell bicycles.' } );

		expect( within( stepEl( 'site-brief' ) ).getByText( 'Done' ) ).toBeInTheDocument();
		expect( screen.queryByLabelText( 'Site brief' ) ).not.toBeInTheDocument();
	} );
} );

describe( 'SetupPanel step 6: Try a first run', () => {
	it( 'shows the starter goals and hands the picked one to the goal box', () => {
		const onPickGoal = jest.fn();
		render( <SetupPanel steps={ [ provider( { status: 'fail' } ), firstRun() ] } onPickGoal={ onPickGoal } /> );

		expect( screen.getAllByRole( 'button', { name: /page|post/ } ) ).toHaveLength( 3 );
		fireEvent.click( screen.getByRole( 'button', { name: 'Draft a blog post announcing what is new this month' } ) );

		expect( onPickGoal ).toHaveBeenCalledWith( { pack: 'posts', text: 'Draft a blog post announcing what is new this month' } );
	} );

	it( 'shows no goal buttons once a run has been started', () => {
		render( <SetupPanel steps={ [ provider( { status: 'fail' } ), firstRun( { status: 'pass' } ) ] } /> );

		expect( within( stepEl( 'first-run' ) ).getByText( 'Done' ) ).toBeInTheDocument();
		expect( screen.queryByRole( 'button', { name: /About page/ } ) ).not.toBeInTheDocument();
	} );

	it( 'shows no goals when nothing is runnable', () => {
		render( <SetupPanel steps={ [ provider( { status: 'fail' } ), firstRun( { goals: [] } ) ] } /> );

		expect( document.querySelector( '.specflux-site-agent-setup-goals' ) ).toBeNull();
	} );
} );

describe( 'SetupPanel collapse to the Setup chip', () => {
	const allClear = () => [ provider(), approval(), brief( { status: 'pass' } ), firstRun( { status: 'pass' } ) ];

	it( 'stays open while any blocking step fails', () => {
		render( <SetupPanel steps={ [ provider(), approval(), binding() ] } /> );

		expect( screen.getByRole( 'heading', { name: 'Set up Specflux Site Agent' } ) ).toBeInTheDocument();
		expect( screen.queryByRole( 'button', { name: 'Setup ✓' } ) ).not.toBeInTheDocument();
	} );

	it( 'collapses to a chip once every blocking step passes', () => {
		render( <SetupPanel steps={ allClear() } /> );

		expect( screen.getByRole( 'button', { name: 'Setup ✓' } ) ).toHaveAttribute( 'aria-expanded', 'false' );
		expect( screen.queryByRole( 'heading', { name: 'Set up Specflux Site Agent' } ) ).not.toBeInTheDocument();
	} );

	it( 'does not wait for optional steps to collapse', () => {
		render( <SetupPanel steps={ [ provider(), approval(), brief(), firstRun() ] } /> );

		expect( screen.getByRole( 'button', { name: 'Setup ✓' } ) ).toBeInTheDocument();
	} );

	it( 'keeps the starter goals under the chip until a first run exists', () => {
		render( <SetupPanel steps={ [ provider(), approval(), brief( { status: 'pass' } ), firstRun() ] } /> );

		expect( screen.getByRole( 'button', { name: 'Write an About page for my business' } ) ).toBeInTheDocument();
	} );

	it( 'opens the full list from the chip and hides it again', () => {
		render( <SetupPanel steps={ allClear() } /> );

		fireEvent.click( screen.getByRole( 'button', { name: 'Setup ✓' } ) );
		expect( screen.getByRole( 'heading', { name: 'Set up Specflux Site Agent' } ) ).toBeInTheDocument();

		fireEvent.click( screen.getByRole( 'button', { name: 'Hide setup' } ) );
		expect( screen.getByRole( 'button', { name: 'Setup ✓' } ) ).toBeInTheDocument();
	} );

	it( 're-opens by itself when a refresh finds a blocking step failing again', () => {
		const { rerender } = render( <SetupPanel steps={ allClear() } /> );
		expect( screen.getByRole( 'button', { name: 'Setup ✓' } ) ).toBeInTheDocument();

		rerender( <SetupPanel steps={ [ provider( { status: 'fail' } ), approval() ] } /> );

		expect( screen.getByRole( 'heading', { name: 'Set up Specflux Site Agent' } ) ).toBeInTheDocument();
	} );

	it( 'renders nothing without steps', () => {
		const { container } = render( <SetupPanel steps={ [] } /> );

		expect( container ).toBeEmptyDOMElement();
	} );
} );

describe( 'firstBlockingStep', () => {
	it( 'returns the first failing blocking step, ignoring optional and passing ones', () => {
		expect( firstBlockingStep( [ provider(), brief(), binding(), oldPlugin() ] ).id ).toBe( 'role-binding' );
		expect( firstBlockingStep( [ provider(), brief(), firstRun() ] ) ).toBeNull();
		expect( firstBlockingStep( undefined ) ).toBeNull();
	} );
} );
