/**
 * Runs-pack fix: the message box's pack picker. A pack-less start deadlocks
 * every run (the Runs screen's own consumer has an empty verb map without
 * one — {@see \Specflux\SenroFlux\Tools\VerbTier}), so `MessageBox` must
 * never let `onSend` fire without a pack chosen whenever one is required.
 */

import { render, screen, fireEvent } from '@testing-library/react';
import MessageBox from '../components/MessageBox';

describe( 'MessageBox pack picker', () => {
	it( 'preselects the single pack and starts immediately with it', () => {
		const onSend = jest.fn();
		render(
			<MessageBox state="idle" onSend={ onSend } packs={ [ { name: 'pages', label: 'pages' } ] } />
		);

		fireEvent.change( screen.getByPlaceholderText( 'Describe what you want done…' ), {
			target: { value: 'Publish the spring page' },
		} );
		fireEvent.click( screen.getByRole( 'button', { name: 'Start run' } ) );

		expect( onSend ).toHaveBeenCalledWith( 'Publish the spring page', 'pages', null );
	} );

	it( 'disables Start until a pack is chosen when there are two or more packs', () => {
		const onSend = jest.fn();
		render(
			<MessageBox
				state="idle"
				onSend={ onSend }
				packs={ [
					{ name: 'pages', label: 'pages' },
					{ name: 'site', label: 'site' },
				] }
			/>
		);

		fireEvent.change( screen.getByPlaceholderText( 'Describe what you want done…' ), {
			target: { value: 'Do something' },
		} );

		const startButton = screen.getByRole( 'button', { name: 'Start run' } );
		expect( startButton ).toBeDisabled();

		fireEvent.click( startButton );
		expect( onSend ).not.toHaveBeenCalled();

		fireEvent.change( screen.getByLabelText( 'Pack' ), { target: { value: 'site' } } );
		expect( startButton ).not.toBeDisabled();

		fireEvent.click( startButton );
		expect( onSend ).toHaveBeenCalledWith( 'Do something', 'site', null );
	} );

	it( 'renders no pack picker and keeps Start disabled when there are no packs', () => {
		const onSend = jest.fn();
		render( <MessageBox state="idle" onSend={ onSend } packs={ [] } /> );

		expect( screen.queryByLabelText( 'Pack' ) ).not.toBeInTheDocument();

		fireEvent.change( screen.getByPlaceholderText( 'Describe what you want done…' ), {
			target: { value: 'Do something' },
		} );

		const startButton = screen.getByRole( 'button', { name: 'Start run' } );
		expect( startButton ).toBeDisabled();

		fireEvent.click( startButton );
		expect( onSend ).not.toHaveBeenCalled();
	} );
} );

describe( 'MessageBox unavailable-packs notice', () => {
	it( 'renders no notice when every pack is runnable', () => {
		render( <MessageBox state="idle" onSend={ jest.fn() } packs={ [ { name: 'pages', label: 'pages' } ] } /> );

		expect( screen.queryByText( /pack unavailable/i ) ).not.toBeInTheDocument();
	} );

	it( 'lists an unavailable pack and its reason alongside a runnable one', () => {
		render(
			<MessageBox
				state="idle"
				onSend={ jest.fn() }
				packs={ [ { name: 'pages', label: 'pages' } ] }
				unavailablePacks={ [ { name: 'widgets', reason: 'Bind `user:1` to the widgets pack.' } ] }
			/>
		);

		expect( screen.getByText( 'widgets pack unavailable: Bind `user:1` to the widgets pack.' ) ).toBeInTheDocument();
	} );

	it( 'zero-runnable case: renders no picker but still explains every pack via the notice', () => {
		const onSend = jest.fn();
		render(
			<MessageBox
				state="idle"
				onSend={ onSend }
				packs={ [] }
				unavailablePacks={ [
					{ name: 'pages', reason: 'The run\'s skills exceed the instruction ceiling.' },
					{ name: 'site', reason: 'Bind `user:1` to the site pack.' },
				] }
			/>
		);

		expect( screen.queryByLabelText( 'Pack' ) ).not.toBeInTheDocument();
		expect(
			screen.getByText( 'pages pack unavailable: The run\'s skills exceed the instruction ceiling.' )
		).toBeInTheDocument();
		expect( screen.getByText( 'site pack unavailable: Bind `user:1` to the site pack.' ) ).toBeInTheDocument();

		const startButton = screen.getByRole( 'button', { name: 'Start run' } );
		expect( startButton ).toBeDisabled();
	} );
} );

/**
 * The model picker in the message box: hidden when there is nothing to
 * choose, grouped by provider when there is, and its choice reaches `onSend`
 * as the documented `{ provider, id } | null` shape (the third argument,
 * after `goal` and `pack`).
 */
const modelChoices = {
	openai: {
		name: 'OpenAI',
		models: [
			{ id: 'gpt-5', name: 'GPT-5' },
			{ id: 'gpt-5-mini', name: 'GPT-5 Mini' },
		],
	},
	anthropic: {
		name: 'Anthropic',
		models: [ { id: 'claude-opus', name: 'Claude Opus' } ],
	},
};

describe( 'model picker visibility', () => {
	it( 'renders no model select at all when modelChoices is empty', () => {
		render( <MessageBox state="idle" onSend={ () => {} } packs={ [ { name: 'pages', label: 'pages' } ] } modelChoices={ {} } /> );
		expect( screen.queryByLabelText( 'Model' ) ).not.toBeInTheDocument();
	} );

	it( 'renders no model select at all when modelChoices is omitted', () => {
		render( <MessageBox state="idle" onSend={ () => {} } packs={ [ { name: 'pages', label: 'pages' } ] } /> );
		expect( screen.queryByLabelText( 'Model' ) ).not.toBeInTheDocument();
	} );

	it( 'renders a select grouped by provider, Automatic first', () => {
		render(
			<MessageBox
				state="idle"
				onSend={ () => {} }
				packs={ [ { name: 'pages', label: 'pages' } ] }
				modelChoices={ modelChoices }
			/>
		);

		const select = screen.getByLabelText( 'Model' );
		const options = Array.from( select.querySelectorAll( 'option' ) );
		expect( options[ 0 ] ).toHaveTextContent( 'Automatic (let WordPress choose)' );

		const groups = Array.from( select.querySelectorAll( 'optgroup' ) ).map( ( g ) => g.label );
		expect( groups ).toEqual( [ 'OpenAI', 'Anthropic' ] );

		expect( screen.getByText( 'GPT-5' ) ).toBeInTheDocument();
		expect( screen.getByText( 'GPT-5 Mini' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Claude Opus' ) ).toBeInTheDocument();
	} );
} );

describe( 'model picker selection reaches onSend', () => {
	it( 'calls onSend with null when left on Automatic', () => {
		const onSend = jest.fn();
		render(
			<MessageBox
				state="idle"
				onSend={ onSend }
				packs={ [ { name: 'pages', label: 'pages' } ] }
				modelChoices={ modelChoices }
			/>
		);

		fireEvent.change( screen.getByPlaceholderText( 'Describe what you want done…' ), {
			target: { value: 'Do the thing' },
		} );
		fireEvent.click( screen.getByRole( 'button', { name: /start run/i } ) );

		expect( onSend ).toHaveBeenCalledWith( 'Do the thing', 'pages', null );
	} );

	it( 'calls onSend with the chosen {provider, id} pair', () => {
		const onSend = jest.fn();
		render(
			<MessageBox
				state="idle"
				onSend={ onSend }
				packs={ [ { name: 'pages', label: 'pages' } ] }
				modelChoices={ modelChoices }
			/>
		);

		fireEvent.change( screen.getByLabelText( 'Model' ), { target: { value: '2' } } );
		fireEvent.change( screen.getByPlaceholderText( 'Describe what you want done…' ), {
			target: { value: 'Do the thing' },
		} );
		fireEvent.click( screen.getByRole( 'button', { name: /start run/i } ) );

		expect( onSend ).toHaveBeenCalledWith( 'Do the thing', 'pages', { provider: 'anthropic', id: 'claude-opus' } );
	} );
} );

describe( 'MessageBox blocked by a blocking setup check (stage 22b, J1)', () => {
	const packs = [ { name: 'pages', label: 'pages' } ];

	it( 'disables Start while startBlocked, even with a goal typed and a pack chosen', () => {
		const onSend = jest.fn();
		render( <MessageBox state="idle" onSend={ onSend } packs={ packs } startBlocked /> );

		fireEvent.change( screen.getByPlaceholderText( 'Describe what you want done…' ), {
			target: { value: 'Publish the spring page' },
		} );
		const start = screen.getByRole( 'button', { name: 'Start run' } );
		expect( start ).toBeDisabled();

		fireEvent.keyDown( screen.getByPlaceholderText( 'Describe what you want done…' ), { key: 'Enter' } );
		fireEvent.click( start );
		expect( onSend ).not.toHaveBeenCalled();
	} );

	it( 'says why, and ties the explanation to the button for assistive tech', () => {
		render( <MessageBox state="idle" onSend={ jest.fn() } packs={ packs } startBlocked /> );

		const start = screen.getByRole( 'button', { name: 'Start run' } );
		const hint = screen.getByText( /Finish the setup steps above/ );
		expect( start.getAttribute( 'aria-describedby' ) ).toBe( hint.id );
	} );

	it( 'enables Start again once startBlocked clears', () => {
		const { rerender } = render( <MessageBox state="idle" onSend={ jest.fn() } packs={ packs } startBlocked /> );
		fireEvent.change( screen.getByPlaceholderText( 'Describe what you want done…' ), { target: { value: 'Go' } } );
		expect( screen.getByRole( 'button', { name: 'Start run' } ) ).toBeDisabled();

		rerender( <MessageBox state="idle" onSend={ jest.fn() } packs={ packs } startBlocked={ false } /> );

		expect( screen.getByRole( 'button', { name: 'Start run' } ) ).not.toBeDisabled();
		expect( screen.queryByText( /Finish the setup steps above/ ) ).not.toBeInTheDocument();
	} );
} );

describe( 'MessageBox follow-up mode (stage 22b, S20)', () => {
	const packs = [
		{ name: 'pages', label: 'pages' },
		{ name: 'site', label: 'site' },
	];

	it( 'starts the follow-up with the source run id and the source pack, whatever the picker held', () => {
		const onSend = jest.fn();
		render(
			<MessageBox
				state="idle"
				onSend={ onSend }
				packs={ packs }
				followUp={ { runId: 7, pack: 'site' } }
			/>
		);

		fireEvent.change( screen.getByPlaceholderText( 'Describe what you want done…' ), {
			target: { value: 'Tidy the copy' },
		} );
		fireEvent.click( screen.getByRole( 'button', { name: 'Start run' } ) );

		expect( onSend ).toHaveBeenCalledWith( 'Tidy the copy', 'site', null, 7 );
	} );

	it( 'says it is a follow-up, locks the pack, and lets the user cancel it', () => {
		const onCancel = jest.fn();
		render(
			<MessageBox
				state="idle"
				onSend={ jest.fn() }
				packs={ packs }
				followUp={ { runId: 7, pack: 'site' } }
				onCancelFollowUp={ onCancel }
			/>
		);

		expect( screen.getByText( /Follow-up to run #7/ ) ).toBeInTheDocument();
		expect( screen.getByLabelText( 'Pack' ) ).toBeDisabled();
		expect( screen.getByLabelText( 'Pack' ) ).toHaveValue( 'site' );

		fireEvent.click( screen.getByRole( 'button', { name: 'Cancel follow-up' } ) );
		expect( onCancel ).toHaveBeenCalled();
	} );

	it( 'starts the goal EMPTY: nothing is pre-filled, so Start stays disabled until the user types', () => {
		render( <MessageBox state="idle" onSend={ jest.fn() } packs={ packs } followUp={ { runId: 7, pack: 'site' } } /> );

		expect( screen.getByPlaceholderText( 'Describe what you want done…' ) ).toHaveValue( '' );
		expect( screen.getByRole( 'button', { name: 'Start run' } ) ).toBeDisabled();
	} );
} );

describe( 'MessageBox Start tooltip names the blocking setup step', () => {
	const onePack = [ { name: 'pages', label: 'pages' } ];
	const startButton = () => screen.getByRole( 'button', { name: 'Start run' } );
	const type = () =>
		fireEvent.change( screen.getByPlaceholderText( 'Describe what you want done…' ), { target: { value: 'Write a page' } } );

	it( 'names the model step when no provider is connected', () => {
		render(
			<MessageBox
				state="idle"
				onSend={ jest.fn() }
				packs={ onePack }
				startBlocked
				blockingStep={ { number: 1, title: 'Connect a model' } }
			/>
		);
		type();

		const reason = 'Finish step 1, Connect a model, in Set up SenroFlux to start a run.';
		expect( startButton() ).toBeDisabled();
		expect( startButton() ).toHaveAttribute( 'title', reason );
		expect( startButton().parentElement ).toHaveAttribute( 'title', reason );
		expect( screen.getByText( reason ) ).toBeInTheDocument();
	} );

	it( 'names the old-plugin step', () => {
		render(
			<MessageBox
				state="idle"
				onSend={ jest.fn() }
				packs={ onePack }
				startBlocked
				blockingStep={ { number: 3, title: 'Clear the old Agent Safety plugin' } }
			/>
		);
		type();

		expect( startButton() ).toHaveAttribute(
			'title',
			'Finish step 3, Clear the old Agent Safety plugin, in Set up SenroFlux to start a run.'
		);
	} );

	it( 'names the role step when no pack is runnable', () => {
		render(
			<MessageBox
				state="idle"
				onSend={ jest.fn() }
				packs={ [] }
				blockingStep={ { number: 4, title: 'Allow SenroFlux for your role' } }
			/>
		);
		type();

		expect( startButton() ).toBeDisabled();
		expect( startButton() ).toHaveAttribute(
			'title',
			'Finish step 4, Allow SenroFlux for your role, in Set up SenroFlux to start a run.'
		);
	} );

	it( 'has no tooltip when Start is only waiting for text or a pack choice', () => {
		render(
			<MessageBox
				state="idle"
				onSend={ jest.fn() }
				packs={ [
					{ name: 'pages', label: 'pages' },
					{ name: 'site', label: 'site' },
				] }
				blockingStep={ null }
			/>
		);
		type();

		expect( startButton() ).toBeDisabled();
		expect( startButton() ).not.toHaveAttribute( 'title' );
	} );

	it( 'has no tooltip when a blocking step exists but Start is not blocked and a pack is runnable', () => {
		render(
			<MessageBox state="idle" onSend={ jest.fn() } packs={ onePack } blockingStep={ { number: 1, title: 'Connect a model' } } />
		);
		type();

		expect( startButton() ).not.toBeDisabled();
		expect( startButton() ).not.toHaveAttribute( 'title' );
	} );
} );

describe( 'MessageBox starter goal prefill', () => {
	const packs = [
		{ name: 'pages', label: 'pages' },
		{ name: 'posts', label: 'posts' },
	];

	it( 'fills the goal box and picks the goal\'s pack without starting a run', () => {
		const onSend = jest.fn();
		render( <MessageBox state="idle" onSend={ onSend } packs={ packs } prefill={ { key: 1, text: 'Write an About page', pack: 'posts' } } /> );

		expect( screen.getByPlaceholderText( 'Describe what you want done…' ) ).toHaveValue( 'Write an About page' );
		expect( screen.getByLabelText( 'Pack' ) ).toHaveValue( 'posts' );
		expect( onSend ).not.toHaveBeenCalled();
	} );

	it( 'refills on a new pick but keeps later typing otherwise', () => {
		const { rerender } = render(
			<MessageBox state="idle" onSend={ jest.fn() } packs={ packs } prefill={ { key: 1, text: 'First', pack: 'pages' } } />
		);
		const box = screen.getByPlaceholderText( 'Describe what you want done…' );
		fireEvent.change( box, { target: { value: 'First, edited' } } );

		rerender( <MessageBox state="idle" onSend={ jest.fn() } packs={ packs } prefill={ { key: 1, text: 'First', pack: 'pages' } } /> );
		expect( box ).toHaveValue( 'First, edited' );

		rerender( <MessageBox state="idle" onSend={ jest.fn() } packs={ packs } prefill={ { key: 2, text: 'Second', pack: 'pages' } } /> );
		expect( box ).toHaveValue( 'Second' );
	} );
} );

describe( 'MessageBox pack-unavailable notices name the fix', () => {
	it( 'merges packs refused for the same reason into one line', () => {
		const reason = 'Bind Administrator to SenroFlux content in SenroGate (Tools > Agent Capability Packs), then come back here.';
		render(
			<MessageBox
				state="idle"
				onSend={ jest.fn() }
				packs={ [] }
				unavailablePacks={ [
					{ name: 'pages', reason },
					{ name: 'posts', reason },
					{ name: 'site', reason },
				] }
			/>
		);

		expect( screen.getByText( `pages, posts, site packs unavailable: ${ reason }` ) ).toBeInTheDocument();
		expect( screen.getAllByRole( 'listitem' ) ).toHaveLength( 1 );
	} );
} );
