import { __, _n, sprintf } from '@wordpress/i18n';
import { reportCounts, storeReportFromSteps } from '../utils';
import ChangesSection from './ChangesSection';
import { AuiBadge } from './ui';

/** Only http(s) links render as links: the edit url is server-built, but a stored report is read back as untrusted. */
function safeUrl( url ) {
	return 'string' === typeof url && /^https?:\/\//i.test( url ) ? url : null;
}

/**
 * The words for an object type. Posts and pages report their post type, a
 * term its taxonomy, the rest a fixed kind; a type with no entry (a custom
 * post type, say) is shown as the site reports it.
 */
function typeLabels() {
	return {
		post: __( 'Post', 'specflux-site-agent' ),
		page: __( 'Page', 'specflux-site-agent' ),
		attachment: __( 'Media', 'specflux-site-agent' ),
		category: __( 'Category', 'specflux-site-agent' ),
		post_tag: __( 'Tag', 'specflux-site-agent' ),
		product: __( 'Product', 'specflux-site-agent' ),
		shop_coupon: __( 'Coupon', 'specflux-site-agent' ),
		shop_order: __( 'Order', 'specflux-site-agent' ),
		shipping_zone: __( 'Shipping zone', 'specflux-site-agent' ),
		tax_rate: __( 'Tax rate', 'specflux-site-agent' ),
	};
}

/** The object's own name: its title, else `#id` (without the pack's `term:`-style qualifier). */
function objectName( object ) {
	return object.title ? object.title : `#${ String( object.object_id ).replace( /^[a-z]+:/, '' ) }`;
}

/** "Edit <name>" link for a row, or nothing when there is no usable url. */
function EditLink( { object } ) {
	const url = safeUrl( object.edit_url );
	if ( ! url ) {
		return null;
	}
	return (
		<a
			className="specflux-site-agent-report-edit"
			href={ url }
			/* translators: %s: the title of the object to edit. */
			aria-label={ sprintf( __( 'Edit %s', 'specflux-site-agent' ), objectName( object ) ) }
		>
			{ __( 'Edit', 'specflux-site-agent' ) }
		</a>
	);
}

/** The "Built-in approvals" / "Agent Safety" half of the gate-mode line. */
function gateModeLabel( gateMode ) {
	return 'agent_safety' === gateMode
		? __( 'Belayline Agent Governance', 'specflux-site-agent' )
		: __( 'Built-in approvals', 'specflux-site-agent' );
}

/** A grant's expiry as the viewer's own local date/time; the ISO string stays on `<time>`. */
function formatExpiry( iso ) {
	const date = new Date( iso );
	return Number.isNaN( date.getTime() ) ? iso : date.toLocaleString( undefined, { dateStyle: 'medium', timeStyle: 'short' } );
}

/** A money amount in the store's currency; a currency code the browser rejects falls back to a plain number. */
function formatMoney( amount, currency ) {
	try {
		return new Intl.NumberFormat( undefined, { style: 'currency', currency } ).format( amount );
	} catch ( e ) {
		return String( amount );
	}
}

/** The "3 changes, 1 not checked" line (S12). */
function summaryLine( counts ) {
	if ( 0 === counts.total ) {
		return __( 'No changes', 'specflux-site-agent' );
	}
	const changes = sprintf(
		/* translators: %d: number of changes the run made. */
		_n( '%d change', '%d changes', counts.total, 'specflux-site-agent' ),
		counts.total
	);
	return 0 === counts.notChecked
		? changes
		: sprintf(
				/* translators: 1: "N changes", 2: how many of them were not re-read after the change. */
				__( '%1$s, %2$d not checked', 'specflux-site-agent' ),
				changes,
				counts.notChecked
		  );
}

/**
 * One change row. The state is said in TEXT ("Verified" / "Not checked after
 * the change") and the mark is decoration (`aria-hidden`): the two states
 * differ without colour, for sighted and assistive-tech users alike (S12).
 */
function ChangeRow( { change, revisionUrl } ) {
	const verified = Boolean( change.verified );
	// `unknown` is Report's fail-closed placeholder, not something to show.
	const labels = typeLabels();
	const type = 'unknown' === change.object_type ? '' : labels[ change.object_type ] || change.object_type;
	return (
		<li className={ `specflux-site-agent-report-change ${ verified ? 'is-verified' : 'is-unchecked' }` }>
			<span className="specflux-site-agent-report-mark" aria-hidden="true">
				{ verified ? '✓' : '⚠' }
			</span>
			{ /* S22 pseudo-locale: title, type and status are the object's own data. */ }
			<span className="specflux-site-agent-report-object" data-specflux-site-agent-content dir="auto">
				{ objectName( change ) }
				{ ( type || change.status ) && (
					<span className="specflux-site-agent-report-object-meta">
						{ ' ' }
						{ [ type, change.status ].filter( Boolean ).join( ' · ' ) }
					</span>
				) }
			</span>
			<EditLink object={ change } />
			{ revisionUrl && (
				<a
					className="specflux-site-agent-report-revisions"
					href={ revisionUrl }
					/* translators: %s: the title of the object whose revisions open. */
					aria-label={ sprintf( __( 'Revisions of %s', 'specflux-site-agent' ), objectName( change ) ) }
				>
					{ __( 'Revisions', 'specflux-site-agent' ) }
				</a>
			) }
			<AuiBadge tone={ verified ? 'ok' : 'warn' } className="specflux-site-agent-report-state">
				{ verified ? __( 'Verified', 'specflux-site-agent' ) : __( 'Not checked after the change', 'specflux-site-agent' ) }
			</AuiBadge>
		</li>
	);
}

/** Adopted / left-for-you rows: the object (data), its status, and an edit link. */
function ObjectList( { title, objects } ) {
	return (
		<>
			<h3 className="specflux-site-agent-report-subheading">{ title }</h3>
			<ul className="specflux-site-agent-report-objects">
				{ objects.map( ( object ) => (
					<li key={ object.object_id } className="specflux-site-agent-report-object-row">
						<span className="specflux-site-agent-report-object" data-specflux-site-agent-content dir="auto">
							{ objectName( object ) }
							{ object.status && <span className="specflux-site-agent-report-object-meta"> { object.status }</span> }
						</span>
						<EditLink object={ object } />
					</li>
				) ) }
			</ul>
		</>
	);
}

/** The J13 store report, as the run's own on-demand read of the store. */
function StoreReport( { data } ) {
	const money = ( amount ) => formatMoney( amount, data.currency );
	const rows = [
		[ __( 'Window', 'specflux-site-agent' ), `${ data.from } – ${ data.to }` ],
		[ __( 'Orders', 'specflux-site-agent' ), String( data.order_count ) ],
		[ __( 'Gross sales', 'specflux-site-agent' ), money( data.gross_sales ) ],
		[ __( 'Net sales', 'specflux-site-agent' ), money( data.net_sales ) ],
		[ __( 'Refunds', 'specflux-site-agent' ), money( data.refunds_total ) ],
		[
			__( 'Low stock products', 'specflux-site-agent' ),
			String( Array.isArray( data.low_stock_products ) ? data.low_stock_products.length : 0 ),
		],
	];
	return (
		<>
			<h3 className="specflux-site-agent-report-subheading">{ __( 'Store report', 'specflux-site-agent' ) }</h3>
			<dl className="aui-kv specflux-site-agent-report-store">
				{ rows.map( ( [ label, value ] ) => (
					<div key={ label } className="specflux-site-agent-report-store-row">
						<dt>{ label }</dt>
						<dd data-specflux-site-agent-content>{ value }</dd>
					</div>
				) ) }
			</dl>
		</>
	);
}

/**
 * The run report at a terminal state (0.3 S12): the harness-built
 * `Report::build()` payload, which until stage 22b the screen never showed.
 * Summary line, one row per change, then — each only when it has something
 * to say — the gate mode (with the built-in line and any withheld roles next
 * to it), the pre-approval grants with their expiry, the adopted objects, the
 * ones left for the human to remove, and the store report if the run made one.
 *
 * @param {Object} props
 * @param {Object} props.report `run.report` (`result_json`).
 * @param {Array}  props.steps  The run's steps, for the J13 store report.
 * @param {number} [props.runId]  The run, for undo requests.
 * @param {Object} [props.changesSection] `run.changes_section` (0.4 S3.2): undo with Belayline Agent Governance 0.5+, otherwise Revisions links and one note.
 */
export default function ReportView( { report, steps, runId, changesSection } ) {
	const revisions =
		changesSection && 'revisions' === changesSection.mode && changesSection.revisions ? changesSection.revisions : {};
	const changes = Array.isArray( report.changes ) ? report.changes : [];
	const counts = reportCounts( report );
	const withheld = Array.isArray( report.withheld_roles ) ? report.withheld_roles : [];
	const grants = Array.isArray( report.grants ) ? report.grants : [];
	const adopted = Array.isArray( report.adopted ) ? report.adopted : [];
	const leftForYou = Array.isArray( report.left_for_you ) ? report.left_for_you : [];
	const storeReport = storeReportFromSteps( steps );

	return (
		<section className="aui-card specflux-site-agent-report" aria-labelledby="specflux-site-agent-report-heading">
			<h2 id="specflux-site-agent-report-heading" className="specflux-site-agent-report-heading">
				{ __( 'Report', 'specflux-site-agent' ) }
			</h2>
			<p className="specflux-site-agent-report-summary">{ summaryLine( counts ) }</p>
			{ changes.length > 0 && (
				<ul className="specflux-site-agent-report-changes">
					{ changes.map( ( change, index ) => (
						<ChangeRow
							key={ `${ change.object_id }-${ index }` }
							change={ change }
							revisionUrl={ safeUrl( revisions[ change.object_id ] ) }
						/>
					) ) }
				</ul>
			) }
			<ChangesSection runId={ runId } section={ changesSection } />
			<ul className="aui-muted specflux-site-agent-report-meta">
				<li className="specflux-site-agent-report-gate">
					{ __( 'Gate mode:', 'specflux-site-agent' ) } { gateModeLabel( report.gate_mode ) }
				</li>
				{ report.gate_mode_note && <li className="specflux-site-agent-report-gate-note">{ report.gate_mode_note }</li> }
				{ withheld.length > 0 && (
					<li className="specflux-site-agent-report-withheld">
						{ __( 'Withheld roles:', 'specflux-site-agent' ) }{ ' ' }
						<span data-specflux-site-agent-content>{ withheld.join( ', ' ) }</span>
					</li>
				) }
			</ul>
			{ grants.length > 0 && (
				<>
					<h3 className="specflux-site-agent-report-subheading">{ __( 'Pre-approvals', 'specflux-site-agent' ) }</h3>
					<ul className="specflux-site-agent-report-grants">
						{ grants.map( ( grant, index ) => (
							<li key={ `${ grant.verb }-${ index }` } className="specflux-site-agent-report-grant">
								<code>{ grant.verb }</code>{ ' ' }
								{ __( 'Expires', 'specflux-site-agent' ) }{ ' ' }
								<time dateTime={ grant.expires_at } data-specflux-site-agent-content>
									{ formatExpiry( grant.expires_at ) }
								</time>
							</li>
						) ) }
					</ul>
				</>
			) }
			{ adopted.length > 0 && <ObjectList title={ __( 'Adopted', 'specflux-site-agent' ) } objects={ adopted } /> }
			{ leftForYou.length > 0 && (
				<ObjectList title={ __( 'Left for you to remove', 'specflux-site-agent' ) } objects={ leftForYou } />
			) }
			{ storeReport && <StoreReport data={ storeReport } /> }
		</section>
	);
}
