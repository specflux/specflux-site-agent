import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

/**
 * The first blocking step that fails, or null. A blocking step that fails is
 * the reason the panel stays open and the reason Start can be greyed.
 *
 * @param {Array<Object>} steps The server's `setupSteps` ({@see \Specflux\SiteAgent\Setup\Onboarding::steps()}).
 * @return {?Object} The step, or null.
 */
export function firstBlockingStep( steps ) {
	if ( ! Array.isArray( steps ) ) {
		return null;
	}
	return steps.find( ( step ) => step.blocking && 'fail' === step.status ) || null;
}

/** Status text, for people who cannot see the colour of the marker. */
function statusLabel( status ) {
	if ( 'pass' === status ) {
		return __( 'Done', 'specflux-site-agent' );
	}
	if ( 'fail' === status ) {
		return __( 'Needs attention', 'specflux-site-agent' );
	}
	return __( 'To do', 'specflux-site-agent' );
}

/** A step's one action as a link (a navigation, never a write from this screen). */
function ActionLink( { action } ) {
	if ( ! action || ! action.url ) {
		return null;
	}
	return (
		<a className="button specflux-site-agent-setup-action" href={ action.url }>
			{ action.label }
		</a>
	);
}

/** Step "Choose how changes are approved": two cards, the current one marked. */
function ApprovalOptions( { step } ) {
	return (
		<div className="specflux-site-agent-setup-cards" role="group" aria-label={ step.title }>
			{ step.options.map( ( option ) => {
				const current = option.id === step.mode;
				return (
					<div
						key={ option.id }
						className={ 'specflux-site-agent-setup-card' + ( current ? ' specflux-site-agent-setup-card--current' : '' ) }
					>
						<strong>{ option.title }</strong>
						<p>{ option.description }</p>
						{ current && <p className="specflux-site-agent-setup-current">{ __( 'In use ✓', 'specflux-site-agent' ) }</p> }
						{ 'belayline-agent-governance' === option.id && 'active' === option.state && ! current && (
							<p className="specflux-site-agent-setup-current">{ __( 'Active ✓', 'specflux-site-agent' ) }</p>
						) }
						<ActionLink action={ option.action } />
					</div>
				);
			} ) }
		</div>
	);
}

/** Step "Tell Specflux Site Agent about your site": the inline site-brief form. */
function SiteBriefForm( { step, onSaveBrief, onSkipBrief } ) {
	const [ text, setText ] = useState( step.brief || '' );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );

	const run = ( action ) => {
		if ( busy || ! action ) {
			return;
		}
		setBusy( true );
		setError( '' );
		action()
			.catch( ( e ) => setError( e && e.message ? e.message : __( 'Could not save. Try again.', 'specflux-site-agent' ) ) )
			.finally( () => setBusy( false ) );
	};

	if ( 'pass' === step.status ) {
		return null;
	}

	if ( ! step.canEdit ) {
		return step.ask ? <p className="specflux-site-agent-setup-ask">{ step.ask }</p> : null;
	}

	const tooLong = text.length > step.maxChars;
	return (
		<div className="specflux-site-agent-setup-brief">
			<label htmlFor="specflux-site-agent-setup-brief-text">{ __( 'Site brief', 'specflux-site-agent' ) }</label>
			<textarea
				id="specflux-site-agent-setup-brief-text"
				className="specflux-site-agent-setup-brief-input"
				rows={ 4 }
				value={ text }
				onChange={ ( e ) => setText( e.target.value ) }
			/>
			<p className="specflux-site-agent-setup-count">
				{ sprintf(
					/* translators: 1: characters typed. 2: the limit. */
					__( '%1$d of %2$d characters', 'specflux-site-agent' ),
					text.length,
					step.maxChars
				) }
			</p>
			{ error && (
				<p className="specflux-site-agent-setup-error" role="alert">
					{ error }
				</p>
			) }
			<p>
				<button
					type="button"
					className="button button-primary"
					disabled={ busy || tooLong || '' === text.trim() }
					onClick={ () => run( () => onSaveBrief( text ) ) }
				>
					{ __( 'Save brief', 'specflux-site-agent' ) }
				</button>{ ' ' }
				<button type="button" className="button" disabled={ busy } onClick={ () => run( onSkipBrief ) }>
					{ __( 'Skip for now', 'specflux-site-agent' ) }
				</button>
			</p>
		</div>
	);
}

/** Step "Try a first run": starter goals that fill the goal box. */
function StarterGoals( { step, onPickGoal } ) {
	if ( 'pass' === step.status || ! Array.isArray( step.goals ) || 0 === step.goals.length ) {
		return null;
	}
	return (
		<ul className="specflux-site-agent-setup-goals">
			{ step.goals.map( ( goal ) => (
				<li key={ goal.text }>
					<button type="button" className="button-link" onClick={ () => onPickGoal && onPickGoal( goal ) }>
						{ goal.text }
					</button>
				</li>
			) ) }
		</ul>
	);
}

/**
 * The "Set up Specflux Site Agent" panel (guided setup). Replaces the old pile of setup
 * notices: each step has a status, one sentence of why, and one action.
 *
 * Open while a blocking step fails. Once every blocking step passes it
 * collapses to a "Setup ✓" chip; the chip opens the full list again. While the
 * first run is still to do, its starter goals stay visible under the chip.
 *
 * Specflux Site Agent never changes Belayline Agent Governance's settings. A step that needs one links
 * to Belayline Agent Governance with the change pre-filled; a person clicks Save there.
 *
 * @param {Object}   props
 * @param {Array}    props.steps       Server steps, refreshed on window focus.
 * @param {Function} [props.onSaveBrief] `( text ) => Promise` — saves the site brief.
 * @param {Function} [props.onSkipBrief] `() => Promise` — skips step "site brief".
 * @param {Function} [props.onPickGoal]  `( { pack, text } ) => void` — fills the goal box.
 */
export default function SetupPanel( { steps, onSaveBrief, onSkipBrief, onPickGoal } ) {
	const [ open, setOpen ] = useState( false );

	if ( ! Array.isArray( steps ) || 0 === steps.length ) {
		return null;
	}

	const complete = null === firstBlockingStep( steps );

	if ( complete && ! open ) {
		const firstRun = steps.find( ( step ) => 'first-run' === step.id );
		return (
			<div className="specflux-site-agent-setup-collapsed">
				<button type="button" className="specflux-site-agent-setup-chip" aria-expanded="false" onClick={ () => setOpen( true ) }>
					{ __( 'Setup ✓', 'specflux-site-agent' ) }
				</button>
				{ firstRun && 'pass' !== firstRun.status && firstRun.goals && firstRun.goals.length > 0 && (
					<div className="specflux-site-agent-setup-firstrun">
						<p>{ firstRun.why }</p>
						<StarterGoals step={ firstRun } onPickGoal={ onPickGoal } />
					</div>
				) }
			</div>
		);
	}

	return (
		<section className="specflux-site-agent-setup-steps" aria-label={ __( 'Set up Specflux Site Agent', 'specflux-site-agent' ) }>
			<div className="specflux-site-agent-setup-head">
				<h2>{ __( 'Set up Specflux Site Agent', 'specflux-site-agent' ) }</h2>
				{ complete && (
					<button type="button" className="button-link" aria-expanded="true" onClick={ () => setOpen( false ) }>
						{ __( 'Hide setup', 'specflux-site-agent' ) }
					</button>
				) }
			</div>
			<ol className="specflux-site-agent-setup-list">
				{ steps.map( ( step ) => (
					<li key={ step.id } className={ 'specflux-site-agent-setup-step specflux-site-agent-setup-step--' + step.status } data-step={ step.id }>
						<div className="specflux-site-agent-setup-step-head">
							<span className="specflux-site-agent-setup-status">{ statusLabel( step.status ) }</span>
							<strong>{ sprintf(
								/* translators: 1: step number. 2: step title. */
								__( '%1$d. %2$s', 'specflux-site-agent' ),
								step.number,
								step.title
							) }</strong>
						</div>
						<p className="specflux-site-agent-setup-why">{ step.why }</p>
						{ 'fail' === step.status && step.detail && <p className="specflux-site-agent-setup-detail">{ step.detail }</p> }
						{ 'approval' === step.id && <ApprovalOptions step={ step } /> }
						{ 'site-brief' === step.id && (
							<SiteBriefForm step={ step } onSaveBrief={ onSaveBrief } onSkipBrief={ onSkipBrief } />
						) }
						{ 'first-run' === step.id && <StarterGoals step={ step } onPickGoal={ onPickGoal } /> }
						{ 'fail' === step.status && <ActionLink action={ step.action } /> }
						{ 'fail' === step.status && step.ask && <p className="specflux-site-agent-setup-ask">{ step.ask }</p> }
					</li>
				) ) }
			</ol>
		</section>
	);
}
