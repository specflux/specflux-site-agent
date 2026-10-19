/**
 * Scripted turn builders fed to the fake provider's option-backed queue
 * (`specflux_site_agent_e2e_script`, consumed one step per model turn — S9). Every
 * ability call uses the real `wpab__<namespace>__<name>` tool-name shape a
 * live model would receive from ToolRegistry (mirrors the raw
 * `wpab__specflux__create-post` regression finding), never a shortcut form.
 */

const call = ( name, args = {} ) => ( { type: 'calls', calls: [ { name, args } ] } );
const text = ( value ) => ( { type: 'text', text: value } );

const PLAN_GOAL = 'Build the launch page';

/**
 * The "full tour" scenario (S10 + regression assertions):
 *   plan -> question -> three separate Tier-0 calls (ledger collapse,
 *   regression #1) -> a Tier-1 write (plan-fence + approval, the tier-badge
 *   assertion) -> a brief suggestion -> a final summary.
 */
function fullTour() {
	return [
		call( 'specflux__propose-plan', {
			goal: PLAN_GOAL,
			steps: [
				{ text: 'Create the launch post', verbs: [ 'specflux-e2e/create-thing' ] },
			],
			assumptions: [ 'Draft only, never published by this run.' ],
		} ),
		call( 'specflux__ask-user', {
			text: 'What should the launch post be titled?',
			choices: [ 'Launch Day', 'Big Launch' ],
			rationale: 'Need a title before drafting.',
		} ),
		call( 'wpab__specflux-e2e__read-thing', { id: 'thing-1' } ),
		call( 'wpab__specflux-e2e__list-things', {} ),
		call( 'wpab__specflux-e2e__search-things', { query: 'launch' } ),
		call( 'wpab__specflux-e2e__create-thing', { title: 'Launch Day' } ),
		// Stage 22b: re-read the new thing (the fixture's first created id is
		// `thing-3`), so the report's row is "verified".
		call( 'wpab__specflux-e2e__read-thing', { id: 'thing-3' } ),
		call( 'specflux__suggest-brief-addition', {
			text: 'Mention the launch post in the weekly newsletter.',
		} ),
		text( 'All done — created the launch post and left a suggestion for your site brief.' ),
		// One trailing buffer turn: after a Tier-1 approval resume, the
		// drive loop takes one more internal round-trip (a context re-seed)
		// than the plain step count suggests before it actually reads
		// "completed" — observed empirically driving this scenario live.
		// Without this the fake provider's queue underruns and the run
		// picks up an extra "No scripted response left." bubble.
		text( 'Done.' ),
	];
}

/**
 * The keyboard-only scenario (S22): question, then plan, then approval, in
 * that order, one park at a time.
 */
function keyboardTour() {
	return [
		call( 'specflux__ask-user', {
			text: 'Which section should this go under?',
			choices: [ 'News', 'Announcements' ],
			rationale: 'Need a section before drafting.',
		} ),
		call( 'specflux__propose-plan', {
			goal: PLAN_GOAL,
			steps: [
				{ text: 'Create the launch post', verbs: [ 'specflux-e2e/create-thing' ] },
			],
			assumptions: [],
		} ),
		call( 'wpab__specflux-e2e__create-thing', { title: 'Launch Day' } ),
		text( 'Done.' ),
	];
}

/**
 * A write that is never re-read (stage 22b, S12): plan, one Tier-1 create,
 * a closing summary, and a second text turn for the harness's own re-read
 * nudge, which this scripted model ignores. The report's one row is "Not
 * checked after the change".
 */
function unverifiedWrite() {
	return [
		call( 'specflux__propose-plan', {
			goal: PLAN_GOAL,
			steps: [ { text: 'Create the launch post', verbs: [ 'specflux-e2e/create-thing' ] } ],
			assumptions: [],
		} ),
		call( 'wpab__specflux-e2e__create-thing', { title: 'Launch Day' } ),
		text( 'Created the launch post.' ),
		text( 'Done.' ),
	];
}

/**
 * Plan, one approved write, then a question park — a run that can be
 * cancelled with a change on record (a follow-up's seed, J15). The trailing
 * plan park is the FOLLOW-UP run's first turn.
 */
function writeThenQuestion() {
	return [
		call( 'specflux__propose-plan', {
			goal: PLAN_GOAL,
			steps: [ { text: 'Create the launch post', verbs: [ 'specflux-e2e/create-thing' ] } ],
			assumptions: [],
		} ),
		call( 'wpab__specflux-e2e__create-thing', { title: 'Launch Day' } ),
		call( 'specflux__ask-user', {
			text: 'Which section next?',
			choices: [ 'News', 'Announcements' ],
			rationale: 'Need a section.',
		} ),
		...planOnly( 'Follow up on the launch page' ),
	];
}

/** A trivial one-turn scenario: no parks, completes immediately. */
function quickComplete() {
	return [ text( 'Nothing to do here — completed immediately.' ) ];
}

/** A run that parks on its very first turn (a plan) and nothing else. */
function planOnly( goal = PLAN_GOAL ) {
	return [
		call( 'specflux__propose-plan', {
			goal,
			steps: [ { text: 'Create the launch post', verbs: [ 'specflux-e2e/create-thing' ] } ],
			assumptions: [],
		} ),
	];
}

/**
 * Runs-pack fix regression: a Tier-0 read BEFORE any plan exists. On a
 * pack-less run this is exactly the call that used to come back
 * `plan_required` (an empty verb map fails closed to tier 2 for every verb)
 * — see `RunsScreenStartProducesWorkableRunTest` for the PHP-level pin of
 * the same defect.
 */
function readBeforeAnyPlan() {
	return [
		call( 'wpab__specflux-e2e__read-thing', { id: 'thing-1' } ),
		text( 'Read thing-1. Nothing else to do.' ),
	];
}

/**
 * Plan with two Tier-2 publishes, then the two publishes and their re-reads
 * (the fixture's created ids are `thing-3` and `thing-4`). With pre-approval
 * accepted neither publish parks for approval; the trailing text is the
 * harness's own re-read nudge answer.
 */
function publishTwice() {
	return [
		call( 'specflux__propose-plan', {
			goal: PLAN_GOAL,
			steps: [
				{ text: 'Publish the first thing', verbs: [ 'specflux-e2e/publish-thing' ] },
				{ text: 'Publish the second thing', verbs: [ 'specflux-e2e/publish-thing' ] },
			],
			assumptions: [],
		} ),
		call( 'wpab__specflux-e2e__publish-thing', { title: 'First' } ),
		call( 'wpab__specflux-e2e__publish-thing', { title: 'Second' } ),
		call( 'wpab__specflux-e2e__read-thing', { id: 'thing-3' } ),
		call( 'wpab__specflux-e2e__read-thing', { id: 'thing-4' } ),
		text( 'Published both.' ),
		text( 'Done.' ),
	];
}

module.exports = { PLAN_GOAL, publishTwice, call, text, fullTour, keyboardTour, quickComplete, planOnly, readBeforeAnyPlan, unverifiedWrite, writeThenQuestion };

/**
 * E1: plan, one approved retitle of a REAL post (the fixture's reversible
 * `specflux-e2e/retitle-post`), a closing summary and the harness's re-read
 * nudge answer. The result is the `{id, status, post_type, title_raw}` shape
 * the post result card and the preview pane are built from, and, in Belayline
 * mode, a recorded change the report can undo.
 */
function retitlePost( postId, title ) {
	return [
		call( 'specflux__propose-plan', {
			goal: 'Retitle the launch post',
			steps: [ { text: 'Change the post title', verbs: [ 'specflux-e2e/retitle-post' ] } ],
			assumptions: [],
		} ),
		call( 'wpab__specflux-e2e__retitle-post', { post_id: postId, title } ),
		text( 'Retitled the post.' ),
		text( 'Done.' ),
	];
}

module.exports.retitlePost = retitlePost;

/**
 * E2: a run with many model turns and NO parks, so it is still mid-flight when
 * the page closes and can only finish if the queue drives it. Each turn is a
 * distinct Tier-0 read; the closing text is what the reopened run must show.
 */
const BACKGROUND_DONE = 'Checked everything in the background.';

/** A slow model turn: the fake provider sleeps this long first, so one turn fills a (shortened) tick slice. */
const SLOW_TURN_SECONDS = 11;
const slow = ( step ) => ( { ...step, delay: SLOW_TURN_SECONDS } );

function longReads() {
	return [
		slow( call( 'wpab__specflux-e2e__read-thing', { id: 'thing-1' } ) ),
		slow( call( 'wpab__specflux-e2e__search-things', { query: 'alpha' } ) ),
		slow( call( 'wpab__specflux-e2e__read-thing', { id: 'thing-2' } ) ),
		slow( call( 'wpab__specflux-e2e__search-things', { query: 'beta' } ) ),
		text( BACKGROUND_DONE ),
	];
}

/**
 * E2: plan (a screen park, answered on screen), a few Tier-0 reads the queue
 * will drive after the page closes, then a Tier-1 write that parks for approval.
 * Built-in gate: the park email. Belayline gate: a pending Belayline approval.
 */
function planReadsWrite() {
	return [
		call( 'specflux__propose-plan', {
			goal: PLAN_GOAL,
			steps: [ { text: 'Create the launch post', verbs: [ 'specflux-e2e/create-thing' ] } ],
			assumptions: [],
		} ),
		slow( call( 'wpab__specflux-e2e__read-thing', { id: 'thing-1' } ) ),
		slow( call( 'wpab__specflux-e2e__search-things', { query: 'alpha' } ) ),
		slow( call( 'wpab__specflux-e2e__read-thing', { id: 'thing-2' } ) ),
		call( 'wpab__specflux-e2e__create-thing', { title: 'Launch Day' } ),
		call( 'wpab__specflux-e2e__read-thing', { id: 'thing-3' } ),
		text( 'Created the launch post.' ),
		text( 'Done.' ),
	];
}

module.exports.BACKGROUND_DONE = BACKGROUND_DONE;
module.exports.longReads = longReads;
module.exports.planReadsWrite = planReadsWrite;
