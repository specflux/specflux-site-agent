import { useEffect, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import TierBadge from './TierBadge';
import ApprovalSummary from './ApprovalSummary';
import { humanizeVerb, planApprovalCount } from '../utils';

const HEADINGS = {
	question: __( 'Question for you', 'specflux-site-agent' ),
	plan: __( 'Plan: needs your OK', 'specflux-site-agent' ),
	approval: __( 'Approve this change?', 'specflux-site-agent' ),
};

/**
 * An inline park card (S10): a yellow top rule, a heading naming the kind,
 * focus moved to the heading. 17c wires Approve/Reject/Answer/Skip/Accept/
 * Veto to the tick call via `onResolve( resume )`, where `resume` is one of
 * the S5 shapes ({@see \Specflux\SiteAgent\Run\Resume}) — this component
 * never invents a shape the Runner doesn't already validate.
 *
 * The focus ring is scoped to the HEADING only (a named live-review finding:
 * "the park-heading focus ring must hug the heading, not span the whole
 * card") — the ring lives on `.specflux-site-agent-park-heading:focus-visible`, not on
 * `.specflux-site-agent-park-card`, and this component moves focus there itself so a
 * skeptic can observe `document.activeElement` after a park appears.
 *
 * @param {Object}   props
 * @param {string}   props.kind      'question' | 'plan' | 'approval'.
 * @param {Object}   props.payload   The park's message payload.
 * @param {string}   props.gateMode  'agent_safety' | 'built_in'.
 * @param {Function} [props.onResolve] `( resume ) => Promise` — submits a park
 *                                     resolution. Omitted renders the actions
 *                                     disabled (used by rendering-only tests).
 * @param {boolean}  [props.busy]    True while a resolution is in flight —
 *                                   disables every action to prevent a
 *                                   double-submit.
 */
export default function ParkCard( { kind, payload, gateMode, onResolve, busy } ) {
	const headingRef = useRef( null );

	useEffect( () => {
		if ( headingRef.current ) {
			headingRef.current.focus();
		}
		// Only re-focus when the park itself changes, not on every re-render.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ kind, payload ] );

	return (
		<section className="specflux-site-agent-park-card" aria-labelledby="specflux-site-agent-park-heading">
			<h3
				id="specflux-site-agent-park-heading"
				className="specflux-site-agent-park-heading"
				tabIndex={ -1 }
				ref={ headingRef }
			>
				{ HEADINGS[ kind ] || HEADINGS.approval }
			</h3>
			{ 'question' === kind && <QuestionBody payload={ payload } onResolve={ onResolve } busy={ busy } /> }
			{ 'plan' === kind && (
				<PlanBody payload={ payload } gateMode={ gateMode } onResolve={ onResolve } busy={ busy } />
			) }
			{ 'approval' === kind && (
				<ApprovalBody payload={ payload } gateMode={ gateMode } onResolve={ onResolve } busy={ busy } />
			) }
		</section>
	);
}

/**
 * S5 answer shape: `{ "answer": { "text"?, "choice"? } }` or `{ "skip": true }`.
 * A choice list (when the payload carries one) renders as radios plus an
 * "Other" option that reveals a free-text field — ported from the retired
 * PHP card's `assembleAnswerResume()` ("the 'Other' affordance… wins: a typed
 * answer is a text answer, never a choice"). No choices at all falls back to
 * a plain textarea.
 */
function QuestionBody( { payload, onResolve, busy } ) {
	const choices = Array.isArray( payload.choices ) ? payload.choices : [];
	const hasChoices = choices.length > 0;
	const [ choice, setChoice ] = useState( '' );
	const [ text, setText ] = useState( '' );

	const canAnswer = hasChoices
		? ( '__other__' === choice ? '' !== text.trim() : '' !== choice )
		: '' !== text.trim();

	const submit = ( resume ) => {
		if ( onResolve ) {
			onResolve( resume );
		}
	};

	return (
		<div className="specflux-site-agent-park-body">
			{ /* S22 pseudo-locale: model/user-authored data with no other markup
			 * hook to exclude it by — `data-specflux-site-agent-content` marks it as
			 * NOT translatable chrome. */ }
			<p data-specflux-site-agent-content dir="auto">{ payload.text }</p>
			{ payload.rationale && <p className="specflux-site-agent-rationale">{ payload.rationale }</p> }
			{ hasChoices ? (
				<div className="specflux-site-agent-answer-choices" role="radiogroup" aria-label={ __( 'Answer', 'specflux-site-agent' ) }>
					{ choices.map( ( option ) => (
						<label key={ option }>
							<input
								type="radio"
								name="specflux-site-agent-answer-choice"
								value={ option }
								checked={ choice === option }
								onChange={ () => setChoice( option ) }
								disabled={ busy }
							/>
							<span data-specflux-site-agent-content>{ option }</span>
						</label>
					) ) }
					<label>
						<input
							type="radio"
							name="specflux-site-agent-answer-choice"
							value="__other__"
							checked={ '__other__' === choice }
							onChange={ () => setChoice( '__other__' ) }
							disabled={ busy }
						/>
						{ __( 'Other', 'specflux-site-agent' ) }
					</label>
					{ '__other__' === choice && (
						<textarea
							className="specflux-site-agent-answer-other"
							value={ text }
							onChange={ ( e ) => setText( e.target.value ) }
							disabled={ busy }
							aria-label={ __( 'Your answer', 'specflux-site-agent' ) }
						/>
					) }
				</div>
			) : (
				<textarea
					className="specflux-site-agent-answer-text"
					value={ text }
					onChange={ ( e ) => setText( e.target.value ) }
					disabled={ busy }
					aria-label={ __( 'Your answer', 'specflux-site-agent' ) }
				/>
			) }
			<div className="specflux-site-agent-park-actions">
				<button
					type="button"
					className="button button-primary"
					disabled={ ! onResolve || busy || ! canAnswer }
					onClick={ () => {
						if ( hasChoices && '__other__' !== choice ) {
							submit( { answer: { choice } } );
							return;
						}
						submit( { answer: { text: text.trim() } } );
					} }
				>
					{ __( 'Answer', 'specflux-site-agent' ) }
				</button>
				<button
					type="button"
					className="button"
					disabled={ ! onResolve || busy }
					onClick={ () => submit( { skip: true } ) }
				>
					{ __( 'Skip', 'specflux-site-agent' ) }
				</button>
			</div>
		</div>
	);
}

/**
 * One existing object a plan step names: "Ceramic Mug (#12)", or just the id
 * when it has no title. Plain text only, like every other card line.
 */
function planObjectLabel( object ) {
	const id = String( object?.id ?? '' );
	const label = /^\d+$/.test( id ) ? `#${ id }` : id;
	const title = 'string' === typeof object?.title ? object.title : '';
	return '' !== title ? `${ title } (${ label })` : label;
}

const PLAN_STATUS_LABELS = () => ( {
	publish: __( 'published', 'specflux-site-agent' ),
	draft: __( 'draft', 'specflux-site-agent' ),
	pending: __( 'pending review', 'specflux-site-agent' ),
	private: __( 'private', 'specflux-site-agent' ),
	future: __( 'scheduled', 'specflux-site-agent' ),
	trash: __( 'in the trash', 'specflux-site-agent' ),
} );

/**
 * One adopted / left-alone page on the plan card: title, "#ID" and the
 * object's current status in words. The status is the server's lookup, not
 * the model's claim; an unknown status code is shown as-is.
 */
function PlanExistingList( { heading, rows } ) {
	if ( ! Array.isArray( rows ) || 0 === rows.length ) {
		return null;
	}
	const labels = PLAN_STATUS_LABELS();
	return (
		<>
			<h4>{ heading }</h4>
			<ul>
				{ rows.map( ( row, index ) => {
					const status = 'string' === typeof row?.status && '' !== row.status ? row.status : '';
					return (
						<li key={ `${ row?.id }-${ index }` } data-specflux-site-agent-content dir="auto">
							{ [ row?.title, `#${ row?.id }`, labels[ status ] ?? status ]
								.filter( ( part ) => 'string' === typeof part && '' !== part )
								.join( ' · ' ) }
						</li>
					);
				} ) }
			</ul>
		</>
	);
}

/**
 * S5 plan shape: `{ "plan": { "action": "accept" | "accept_preapprove" |
 * "veto", "note"? } }`. The pre-approve radio's visibility is decided
 * ENTIRELY by `payload.preapprove_available` (`Runner::planUi()` — true only
 * when the `specflux_site_agent_enable_preapproval` filter AND Agent Safety's grants
 * service are both on, S14) — this component never re-derives that decision
 * from `gateMode` or anything else, faithfully porting the three retired
 * `RunsScreenParkCardsTest` rules (hidden by default; offered once grants are
 * on; hidden again while Agent Safety grants are off).
 */
function PlanBody( { payload, gateMode, onResolve, busy } ) {
	const steps = Array.isArray( payload.steps ) ? payload.steps : [];
	const assumptions = Array.isArray( payload.assumptions ) ? payload.assumptions : [];
	const approvalCount = planApprovalCount( payload, gateMode );
	const preapproveAvailable = true === payload.preapprove_available;

	const [ action, setAction ] = useState( 'accept' );
	const [ note, setNote ] = useState( '' );

	// Ported from the retired PHP card's `assemblePlanResume()`: a veto needs
	// a note saying why. Resume::check itself only caps the note's length —
	// this is the same UX guard the PHP screen applied before ever posting,
	// kept for parity rather than relaxed just because the harness would
	// accept an empty one.
	const canSubmit = 'veto' !== action || '' !== note.trim();

	const submit = () => {
		if ( ! onResolve ) {
			return;
		}
		const plan = { action };
		if ( '' !== note.trim() ) {
			plan.note = note.trim();
		}
		onResolve( { plan } );
	};

	return (
		<div className="specflux-site-agent-park-body">
			<ol className="specflux-site-agent-plan-steps">
				{ steps.map( ( step, index ) => (
					<li key={ index }>
						<span data-specflux-site-agent-content dir="auto">{ step.text }</span>
						{ /* One badge per step: step.tier is the highest tier among its verbs. */ }
						<TierBadge gateMode={ gateMode } tier={ step.tier } />
						{ Array.isArray( step.verbs ) && step.verbs.length > 0 && (
							<span className="specflux-site-agent-plan-actions" data-testid="plan-step-actions">
								{ sprintf(
									/* translators: %s: comma-separated list of the actions a plan step will use, e.g. "Create post, Update post". */
									__( 'Uses: %s', 'specflux-site-agent' ),
									[ ...new Set( step.verbs.map( humanizeVerb ) ) ].join( ', ' )
								) }
							</span>
						) }
						{ Array.isArray( step.objects ) && step.objects.length > 0 && (
							<ul className="specflux-site-agent-plan-objects">
								{ step.objects.map( ( object ) => (
									<li key={ object.id } data-specflux-site-agent-content dir="auto">
										{ planObjectLabel( object ) }
									</li>
								) ) }
							</ul>
						) }
					</li>
				) ) }
			</ol>
			{ /*
			 * Disclosure, not cosmetics: the built-in gate's whole guarantee is
			 * that a human sees what they are agreeing to BEFORE accepting a
			 * plan. `planApprovalCount()` counts Tier >= 1 VERB OCCURRENCES
			 * across the plan, not steps — see its docblock for the live-run
			 * defect this exact count was the fix for.
			 */ }
			{ null !== approvalCount && (
				<p className="specflux-site-agent-plan-approval-count">
					{ sprintf(
						/* translators: %d: number of approvals this plan will ask for. */
						_n(
							'This plan will ask you to approve %d change.',
							'This plan will ask you to approve %d changes.',
							approvalCount,
							'specflux-site-agent'
						),
						approvalCount
					) }
				</p>
			) }
			<PlanExistingList heading={ __( 'Pages on the site now', 'specflux-site-agent' ) } rows={ payload.site_pages } />
			<PlanExistingList heading={ __( 'Existing pages kept', 'specflux-site-agent' ) } rows={ payload.adopted } />
			<PlanExistingList heading={ __( 'Left as they are', 'specflux-site-agent' ) } rows={ payload.left_for_you } />
			{ assumptions.length > 0 && (
				<>
					<h4>{ __( 'Assumptions', 'specflux-site-agent' ) }</h4>
					<ul>
						{ assumptions.map( ( a, index ) => (
							<li key={ index } data-specflux-site-agent-content>{ a }</li>
						) ) }
					</ul>
				</>
			) }
			<div className="specflux-site-agent-plan-decision" role="radiogroup" aria-label={ __( 'Decision', 'specflux-site-agent' ) }>
				<label>
					<input
						type="radio"
						name="specflux-site-agent-plan-action"
						value="accept"
						checked={ 'accept' === action }
						onChange={ () => setAction( 'accept' ) }
						disabled={ busy }
					/>
					{ __( 'Accept', 'specflux-site-agent' ) }
				</label>
				{ preapproveAvailable && (
					<label>
						<input
							type="radio"
							name="specflux-site-agent-plan-action"
							value="accept_preapprove"
							checked={ 'accept_preapprove' === action }
							onChange={ () => setAction( 'accept_preapprove' ) }
							disabled={ busy }
						/>
						{ __( 'Accept and pre-approve this plan\'s calls, without asking again (good for 24 hours)', 'specflux-site-agent' ) }
					</label>
				) }
				<label>
					<input
						type="radio"
						name="specflux-site-agent-plan-action"
						value="veto"
						checked={ 'veto' === action }
						onChange={ () => setAction( 'veto' ) }
						disabled={ busy }
					/>
					{ __( 'Veto', 'specflux-site-agent' ) }
				</label>
			</div>
			{ 'veto' === action && (
				<textarea
					className="specflux-site-agent-plan-veto-note"
					value={ note }
					onChange={ ( e ) => setNote( e.target.value ) }
					placeholder={ __( 'Say why (required)', 'specflux-site-agent' ) }
					disabled={ busy }
					aria-label={ __( 'Veto note', 'specflux-site-agent' ) }
				/>
			) }
			<div className="specflux-site-agent-park-actions">
				<button
					type="button"
					className={ 'veto' === action ? 'button specflux-site-agent-button-danger' : 'button button-primary' }
					disabled={ ! onResolve || busy || ! canSubmit }
					onClick={ submit }
				>
					{ 'veto' === action ? __( 'Veto', 'specflux-site-agent' ) : __( 'Accept plan', 'specflux-site-agent' ) }
				</button>
			</div>
		</div>
	);
}

/** S5 approval shape: `{ "action": "approve" | "reject" }`. */
function ApprovalBody( { payload, gateMode, onResolve, busy } ) {
	const args = payload.args && 'object' === typeof payload.args ? payload.args : {};
	const hasArgs = Object.keys( args ).length > 0;
	const hasSummary = 'string' === typeof payload.summary && '' !== payload.summary;

	return (
		<div className="specflux-site-agent-park-body">
			<p>
				<strong>{ __( 'Requested action', 'specflux-site-agent' ) }:</strong> <code>{ payload.verb }</code>
			</p>
			<TierBadge gateMode={ gateMode } tier={ payload.tier } />
			{ 'string' === typeof payload.notice && '' !== payload.notice && (
				<p className="specflux-site-agent-approval-notice" role="status">
					{ payload.notice }
				</p>
			) }
			{ hasSummary && (
				<div className="specflux-site-agent-approval-summary-block">
					<p>
						<strong>{ __( 'What will change', 'specflux-site-agent' ) }:</strong>
					</p>
					{ /*
					 * The pack's own summary is model-influenced DATA (titles,
					 * prices, notes), so it carries the pseudo-locale data
					 * marker; the labels around it stay translatable chrome.
					 * Plain text, so it reads without colour.
					 */ }
					<p className="specflux-site-agent-approval-summary" data-specflux-site-agent-content>
						<ApprovalSummary html={ payload.summary } />
					</p>
				</div>
			) }
			{ hasArgs && (
				<details className="specflux-site-agent-approval-args" open={ ! hasSummary }>
					<summary>{ __( 'Arguments', 'specflux-site-agent' ) }</summary>
					{ /*
					 * Live-review finding: approval-card arguments must wrap and
					 * show in full. No `overflow: hidden`, no `text-overflow:
					 * ellipsis` — the class below only sets `white-space:
					 * pre-wrap` and `overflow-wrap: anywhere`, verified by a
					 * rendered-DOM test (see components/__tests__).
					 */ }
					<pre className="specflux-site-agent-args" tabIndex={ 0 }>
						{ JSON.stringify( args, null, 2 ) }
					</pre>
				</details>
			) }
			<div className="specflux-site-agent-park-actions">
				<button
					type="button"
					className="button button-primary"
					disabled={ ! onResolve || busy }
					onClick={ () => onResolve && onResolve( { action: 'approve' } ) }
				>
					{ __( 'Approve', 'specflux-site-agent' ) }
				</button>
				<button
					type="button"
					className="button specflux-site-agent-button-danger"
					disabled={ ! onResolve || busy }
					onClick={ () => onResolve && onResolve( { action: 'reject' } ) }
				>
					{ __( 'Reject', 'specflux-site-agent' ) }
				</button>
			</div>
		</div>
	);
}
