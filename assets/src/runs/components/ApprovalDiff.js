import { __ } from '@wordpress/i18n';

const text = ( value ) => ( null === value || undefined === value ? '' : String( value ) );

/** Field keys the server sends, as translatable labels. Unknown keys are skipped. */
function fieldLabel( key ) {
	const labels = {
		title: __( 'Title', 'specflux-site-agent' ),
		status: __( 'Status', 'specflux-site-agent' ),
		slug: __( 'Slug', 'specflux-site-agent' ),
		excerpt: __( 'Excerpt', 'specflux-site-agent' ),
		regular_price: __( 'Regular price', 'specflux-site-agent' ),
		sale_price: __( 'Sale price', 'specflux-site-agent' ),
		amount: __( 'Coupon amount', 'specflux-site-agent' ),
	};
	return labels[ key ] || '';
}

/** A run of words: kept as is, removed (struck through) or added (underlined). Never colour alone. */
function Ops( { ops, label } ) {
	const list = ( Array.isArray( ops ) ? ops : [] ).filter( ( op ) => op && text( op.text ) );
	if ( ! list.length ) {
		return null;
	}
	return (
		<p className="specflux-site-agent-diff-ops" aria-label={ label } data-specflux-site-agent-content dir="auto">
			{ list.map( ( op, i ) => {
				if ( 'del' === op.t ) {
					return (
						<del key={ i } className="specflux-site-agent-diff-del">
							<span className="screen-reader-text">{ __( 'Removed:', 'specflux-site-agent' ) + ' ' }</span>
							{ text( op.text ) }{ ' ' }
						</del>
					);
				}
				if ( 'ins' === op.t ) {
					return (
						<ins key={ i } className="specflux-site-agent-diff-ins">
							<span className="screen-reader-text">{ __( 'Added:', 'specflux-site-agent' ) + ' ' }</span>
							{ text( op.text ) }{ ' ' }
						</ins>
					);
				}
				return <span key={ i }>{ text( op.text ) + ' ' }</span>;
			} ) }
		</p>
	);
}

/**
 * "What changes": current against proposed, from the server's `diff`
 * payload. Explanation only. It renders as text, it is never read back, and
 * the Approve button submits the same `{ action: 'approve' }` whether this
 * renders, is malformed or is missing: the approval binds to the arguments
 * shown below it, not to this.
 *
 * @param {Object} props
 * @param {Object} props.diff `{ fields?, content?, menu? }`.
 */
export default function ApprovalDiff( { diff } ) {
	if ( ! diff || 'object' !== typeof diff ) {
		return null;
	}
	const fields = ( Array.isArray( diff.fields ) ? diff.fields : [] ).filter( ( f ) => f && fieldLabel( f.field ) );
	const content = diff.content && Array.isArray( diff.content.ops ) ? diff.content : null;
	const menu = diff.menu && Array.isArray( diff.menu.ops ) ? diff.menu : null;
	if ( ! fields.length && ! content && ! menu ) {
		return null;
	}

	return (
		<div className="specflux-site-agent-approval-diff">
			<p>
				<strong>{ __( 'What changes', 'specflux-site-agent' ) }:</strong>
			</p>
			{ fields.length > 0 && (
				<dl className="specflux-site-agent-diff-fields">
					{ fields.map( ( f, i ) => (
						<div key={ i } className="specflux-site-agent-diff-field">
							<dt>{ fieldLabel( f.field ) }</dt>
							<dd>
								<span className="specflux-site-agent-diff-current">
									<span className="specflux-site-agent-diff-tag">{ __( 'Current', 'specflux-site-agent' ) }:</span>{ ' ' }
									<del data-specflux-site-agent-content dir="auto">{ text( f.current ) }</del>
								</span>
								<span className="specflux-site-agent-diff-proposed">
									<span className="specflux-site-agent-diff-tag">{ __( 'Proposed', 'specflux-site-agent' ) }:</span>{ ' ' }
									<ins data-specflux-site-agent-content dir="auto">{ text( f.proposed ) }</ins>
								</span>
							</dd>
						</div>
					) ) }
				</dl>
			) }
			{ content && (
				<div className="specflux-site-agent-diff-block">
					<p className="specflux-site-agent-diff-heading">{ __( 'Content (text only)', 'specflux-site-agent' ) }</p>
					<Ops ops={ content.ops } label={ __( 'Content changes', 'specflux-site-agent' ) } />
					{ content.truncated && (
						<p className="specflux-site-agent-diff-note">{ __( 'Long text: shown as removed, then added.', 'specflux-site-agent' ) }</p>
					) }
				</div>
			) }
			{ menu && (
				<div className="specflux-site-agent-diff-block">
					<p className="specflux-site-agent-diff-heading">{ __( 'Menu item labels', 'specflux-site-agent' ) }</p>
					<Ops ops={ menu.ops } label={ __( 'Menu label changes', 'specflux-site-agent' ) } />
				</div>
			) }
		</div>
	);
}
