import { __ } from '@wordpress/i18n';

const text = ( value ) => ( null === value || undefined === value ? '' : String( value ) );

/** Field keys the server sends, as translatable labels. Unknown keys are skipped. */
function fieldLabel( key ) {
	const labels = {
		title: __( 'Title', 'senroflux' ),
		status: __( 'Status', 'senroflux' ),
		slug: __( 'Slug', 'senroflux' ),
		excerpt: __( 'Excerpt', 'senroflux' ),
		regular_price: __( 'Regular price', 'senroflux' ),
		sale_price: __( 'Sale price', 'senroflux' ),
		amount: __( 'Coupon amount', 'senroflux' ),
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
		<p className="senroflux-diff-ops" aria-label={ label } data-senroflux-content dir="auto">
			{ list.map( ( op, i ) => {
				if ( 'del' === op.t ) {
					return (
						<del key={ i } className="senroflux-diff-del">
							<span className="screen-reader-text">{ __( 'Removed:', 'senroflux' ) + ' ' }</span>
							{ text( op.text ) }{ ' ' }
						</del>
					);
				}
				if ( 'ins' === op.t ) {
					return (
						<ins key={ i } className="senroflux-diff-ins">
							<span className="screen-reader-text">{ __( 'Added:', 'senroflux' ) + ' ' }</span>
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
		<div className="senroflux-approval-diff">
			<p>
				<strong>{ __( 'What changes', 'senroflux' ) }:</strong>
			</p>
			{ fields.length > 0 && (
				<dl className="senroflux-diff-fields">
					{ fields.map( ( f, i ) => (
						<div key={ i } className="senroflux-diff-field">
							<dt>{ fieldLabel( f.field ) }</dt>
							<dd>
								<span className="senroflux-diff-current">
									<span className="senroflux-diff-tag">{ __( 'Current', 'senroflux' ) }:</span>{ ' ' }
									<del data-senroflux-content dir="auto">{ text( f.current ) }</del>
								</span>
								<span className="senroflux-diff-proposed">
									<span className="senroflux-diff-tag">{ __( 'Proposed', 'senroflux' ) }:</span>{ ' ' }
									<ins data-senroflux-content dir="auto">{ text( f.proposed ) }</ins>
								</span>
							</dd>
						</div>
					) ) }
				</dl>
			) }
			{ content && (
				<div className="senroflux-diff-block">
					<p className="senroflux-diff-heading">{ __( 'Content (text only)', 'senroflux' ) }</p>
					<Ops ops={ content.ops } label={ __( 'Content changes', 'senroflux' ) } />
					{ content.truncated && (
						<p className="senroflux-diff-note">{ __( 'Long text: shown as removed, then added.', 'senroflux' ) }</p>
					) }
				</div>
			) }
			{ menu && (
				<div className="senroflux-diff-block">
					<p className="senroflux-diff-heading">{ __( 'Menu item labels', 'senroflux' ) }</p>
					<Ops ops={ menu.ops } label={ __( 'Menu label changes', 'senroflux' ) } />
				</div>
			) }
		</div>
	);
}
