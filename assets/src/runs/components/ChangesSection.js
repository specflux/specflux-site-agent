import { useState } from '@wordpress/element';
import { Modal } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { previewUndo, undoChanges } from '../api';

/** The object's own name when the server knows it, else its type and id (both data). */
function changeName( item ) {
	return item.label ? item.label : `${ item.object_type } #${ item.object_id }`;
}

/** Words for an UndoItem status. */
function itemStatusLabel( status ) {
	switch ( status ) {
		case 'restored':
			return __( 'Restored', 'specflux-site-agent' );
		case 'restorable':
			return __( 'Will be restored', 'specflux-site-agent' );
		case 'skipped':
			return __( 'Skipped', 'specflux-site-agent' );
		case 'failed':
			return __( 'Stopped here', 'specflux-site-agent' );
		default:
			return __( 'Not attempted', 'specflux-site-agent' );
	}
}

/** The one-line outcome of an undo. */
function outcomeLabel( status ) {
	switch ( status ) {
		case 'complete':
			return __( 'Undone.', 'specflux-site-agent' );
		case 'partial':
			return __( 'Partly undone. It stopped before the end.', 'specflux-site-agent' );
		case 'refused':
			return __( 'Nothing was undone.', 'specflux-site-agent' );
		case 'forbidden':
			return __( 'This undo was refused.', 'specflux-site-agent' );
		default:
			return __( 'There was nothing to undo.', 'specflux-site-agent' );
	}
}

/** The three-way difference for a drifted field: before the run, after it, and now. */
function DriftTable( { drift } ) {
	return (
		<table className="specflux-site-agent-drift-table">
			<caption>{ __( 'Changed since the run', 'specflux-site-agent' ) }</caption>
			<thead>
				<tr>
					<th scope="col">{ __( 'Field', 'specflux-site-agent' ) }</th>
					<th scope="col">{ __( 'Before the run', 'specflux-site-agent' ) }</th>
					<th scope="col">{ __( 'After the run', 'specflux-site-agent' ) }</th>
					<th scope="col">{ __( 'Now', 'specflux-site-agent' ) }</th>
				</tr>
			</thead>
			<tbody>
				{ drift.map( ( row, index ) => (
					<tr key={ `${ row.field }-${ index }` }>
						<th scope="row" data-specflux-site-agent-content dir="auto">
							{ row.field }
						</th>
						<td data-specflux-site-agent-content dir="auto">
							{ row.before }
						</td>
						<td data-specflux-site-agent-content dir="auto">
							{ row.after }
						</td>
						<td data-specflux-site-agent-content dir="auto">
							{ row.current }
						</td>
					</tr>
				) ) }
			</tbody>
		</table>
	);
}

/** A list of UndoItems with their reasons and any drift table. */
function ItemList( { items } ) {
	return (
		<ul className="specflux-site-agent-undo-items">
			{ items.map( ( item ) => (
				<li key={ item.change_id } className={ `specflux-site-agent-undo-item is-${ item.status }` }>
					<span data-specflux-site-agent-content dir="auto">
						{ changeName( item ) }
					</span>{ ' ' }
					<span className="specflux-site-agent-undo-item-status">{ itemStatusLabel( item.status ) }</span>
					{ item.reason_label && <span className="specflux-site-agent-undo-item-reason"> { item.reason_label }</span> }
					{ Array.isArray( item.drift ) && item.drift.length > 0 && <DriftTable drift={ item.drift } /> }
				</li>
			) ) }
		</ul>
	);
}

/** What an undo did: the outcome line, then every item in the order it was processed. */
function UndoOutcome( { result } ) {
	const items = Array.isArray( result.items ) ? result.items : [];
	return (
		<div className="specflux-site-agent-undo-result" role="status">
			<p className="specflux-site-agent-undo-result-line">{ outcomeLabel( result.status ) }</p>
			{ result.message && <p className="specflux-site-agent-undo-result-reason">{ result.message }</p> }
			{ items.length > 0 && <ItemList items={ items } /> }
		</div>
	);
}

/** The field diff of one recorded change. */
function DiffTable( { diff } ) {
	if ( ! Array.isArray( diff ) || 0 === diff.length ) {
		return null;
	}
	return (
		<table className="specflux-site-agent-changes-diff">
			<thead>
				<tr>
					<th scope="col">{ __( 'Field', 'specflux-site-agent' ) }</th>
					<th scope="col">{ __( 'Before', 'specflux-site-agent' ) }</th>
					<th scope="col">{ __( 'After', 'specflux-site-agent' ) }</th>
				</tr>
			</thead>
			<tbody>
				{ diff.map( ( row, index ) => (
					<tr key={ `${ row.field }-${ index }` }>
						<th scope="row" data-specflux-site-agent-content dir="auto">
							{ row.field }
						</th>
						<td data-specflux-site-agent-content dir="auto">
							{ row.before }
						</td>
						<td data-specflux-site-agent-content dir="auto">
							{ row.after }
						</td>
					</tr>
				) ) }
			</tbody>
		</table>
	);
}

/**
 * The report's Changes area (0.4 S3.2), built server-side and handed here as
 * `run.changes_section`. With Belayline Agent Governance 0.5 or later it lists every recorded
 * change and offers "Undo this run" and a per-object "Undo", each behind a
 * confirm dialog that shows what would happen first. Otherwise it shows the
 * one "Undo needs Belayline Agent Governance" line. The model never sees any of this.
 *
 * @param {Object} props
 * @param {number} props.runId
 * @param {Object} props.section `run.changes_section`.
 */
export default function ChangesSection( { runId, section: initial } ) {
	const [ section, setSection ] = useState( initial );
	const [ pending, setPending ] = useState( null ); // { changeId, items }
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ result, setResult ] = useState( null );

	if ( ! section || 'object' !== typeof section ) {
		return null;
	}
	if ( 'undo' !== section.mode ) {
		return section.note ? (
			<p className="specflux-site-agent-changes-note" dir="auto">
				{ section.note }
			</p>
		) : null;
	}

	const changes = Array.isArray( section.changes ) ? section.changes : [];
	if ( 0 === changes.length ) {
		return null;
	}
	const undoable = changes.some( ( change ) => change.restorable && ! change.restored );

	const askPreview = ( changeId ) => {
		setError( '' );
		setBusy( true );
		previewUndo( runId, section.nonce, changeId )
			.then( ( data ) => setPending( { changeId, items: Array.isArray( data.items ) ? data.items : [] } ) )
			.catch( ( e ) => setError( e.message || __( 'Could not load the preview.', 'specflux-site-agent' ) ) )
			.finally( () => setBusy( false ) );
	};

	const confirm = () => {
		const { changeId } = pending;
		setBusy( true );
		undoChanges( runId, section.nonce, changeId )
			.then( ( data ) => {
				setResult( data );
				if ( data.section ) {
					setSection( data.section );
				}
			} )
			.catch( ( e ) => setError( e.message || __( 'The undo failed.', 'specflux-site-agent' ) ) )
			.finally( () => {
				setBusy( false );
				setPending( null );
			} );
	};

	return (
		<section className="specflux-site-agent-changes" aria-labelledby="specflux-site-agent-changes-heading">
			<h3 id="specflux-site-agent-changes-heading" className="specflux-site-agent-report-subheading specflux-site-agent-changes-heading">
				{ __( 'Changes', 'specflux-site-agent' ) }
			</h3>
			<ul className="specflux-site-agent-changes-list">
				{ changes.map( ( change ) => (
					<li key={ change.id } className="specflux-site-agent-changes-row">
						<div className="specflux-site-agent-changes-head">
							<span className="specflux-site-agent-changes-name" data-specflux-site-agent-content dir="auto">
								{ changeName( change ) }
							</span>
							<span className="specflux-site-agent-changes-state">
								{ change.restored && __( 'Restored', 'specflux-site-agent' ) }
								{ ! change.restored && change.restorable && __( 'Can be undone', 'specflux-site-agent' ) }
								{ ! change.restored && ! change.restorable && change.reason_label }
							</span>
							{ change.restorable && ! change.restored && (
								<button
									type="button"
									className="button button-small specflux-site-agent-changes-undo"
									disabled={ busy }
									/* translators: %s: the name of the object whose change would be undone. */
									aria-label={ sprintf( __( 'Undo %s', 'specflux-site-agent' ), changeName( change ) ) }
									onClick={ () => askPreview( change.id ) }
								>
									{ __( 'Undo', 'specflux-site-agent' ) }
								</button>
							) }
						</div>
						<DiffTable diff={ change.diff } />
					</li>
				) ) }
			</ul>
			{ undoable && (
				<button type="button" className="button specflux-site-agent-changes-undo-run" disabled={ busy } onClick={ () => askPreview( null ) }>
					{ __( 'Undo this run', 'specflux-site-agent' ) }
				</button>
			) }
			{ error && (
				<p className="specflux-site-agent-changes-error" role="alert" dir="auto">
					{ error }
				</p>
			) }
			{ result && <UndoOutcome result={ result } /> }
			{ pending && (
				<Modal
					title={ pending.changeId ? __( 'Undo this change?', 'specflux-site-agent' ) : __( 'Undo this run?', 'specflux-site-agent' ) }
					onRequestClose={ () => setPending( null ) }
					className="specflux-site-agent-undo-dialog"
				>
					<p>{ __( 'This is what an undo would do right now. Anything changed since the run is left alone and shown to you.', 'specflux-site-agent' ) }</p>
					{ pending.items.length > 0 ? (
						<ItemList items={ pending.items } />
					) : (
						<p>{ __( 'There is nothing to restore.', 'specflux-site-agent' ) }</p>
					) }
					<div className="specflux-site-agent-undo-dialog-actions">
						<button type="button" className="button" onClick={ () => setPending( null ) }>
							{ __( 'Cancel', 'specflux-site-agent' ) }
						</button>
						<button type="button" className="button button-primary" disabled={ busy } onClick={ confirm }>
							{ __( 'Confirm undo', 'specflux-site-agent' ) }
						</button>
					</div>
				</Modal>
			) }
		</section>
	);
}
