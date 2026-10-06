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
			return __( 'Restored', 'senroflux' );
		case 'restorable':
			return __( 'Will be restored', 'senroflux' );
		case 'skipped':
			return __( 'Skipped', 'senroflux' );
		case 'failed':
			return __( 'Stopped here', 'senroflux' );
		default:
			return __( 'Not attempted', 'senroflux' );
	}
}

/** The one-line outcome of an undo. */
function outcomeLabel( status ) {
	switch ( status ) {
		case 'complete':
			return __( 'Undone.', 'senroflux' );
		case 'partial':
			return __( 'Partly undone. It stopped before the end.', 'senroflux' );
		case 'refused':
			return __( 'Nothing was undone.', 'senroflux' );
		case 'forbidden':
			return __( 'This undo was refused.', 'senroflux' );
		default:
			return __( 'There was nothing to undo.', 'senroflux' );
	}
}

/** The three-way difference for a drifted field: before the run, after it, and now. */
function DriftTable( { drift } ) {
	return (
		<table className="senroflux-drift-table">
			<caption>{ __( 'Changed since the run', 'senroflux' ) }</caption>
			<thead>
				<tr>
					<th scope="col">{ __( 'Field', 'senroflux' ) }</th>
					<th scope="col">{ __( 'Before the run', 'senroflux' ) }</th>
					<th scope="col">{ __( 'After the run', 'senroflux' ) }</th>
					<th scope="col">{ __( 'Now', 'senroflux' ) }</th>
				</tr>
			</thead>
			<tbody>
				{ drift.map( ( row, index ) => (
					<tr key={ `${ row.field }-${ index }` }>
						<th scope="row" data-senroflux-content dir="auto">
							{ row.field }
						</th>
						<td data-senroflux-content dir="auto">
							{ row.before }
						</td>
						<td data-senroflux-content dir="auto">
							{ row.after }
						</td>
						<td data-senroflux-content dir="auto">
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
		<ul className="senroflux-undo-items">
			{ items.map( ( item ) => (
				<li key={ item.change_id } className={ `senroflux-undo-item is-${ item.status }` }>
					<span data-senroflux-content dir="auto">
						{ changeName( item ) }
					</span>{ ' ' }
					<span className="senroflux-undo-item-status">{ itemStatusLabel( item.status ) }</span>
					{ item.reason_label && <span className="senroflux-undo-item-reason"> { item.reason_label }</span> }
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
		<div className="senroflux-undo-result" role="status">
			<p className="senroflux-undo-result-line">{ outcomeLabel( result.status ) }</p>
			{ result.message && <p className="senroflux-undo-result-reason">{ result.message }</p> }
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
		<table className="senroflux-changes-diff">
			<thead>
				<tr>
					<th scope="col">{ __( 'Field', 'senroflux' ) }</th>
					<th scope="col">{ __( 'Before', 'senroflux' ) }</th>
					<th scope="col">{ __( 'After', 'senroflux' ) }</th>
				</tr>
			</thead>
			<tbody>
				{ diff.map( ( row, index ) => (
					<tr key={ `${ row.field }-${ index }` }>
						<th scope="row" data-senroflux-content dir="auto">
							{ row.field }
						</th>
						<td data-senroflux-content dir="auto">
							{ row.before }
						</td>
						<td data-senroflux-content dir="auto">
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
 * `run.changes_section`. With SenroGate 0.5 or later it lists every recorded
 * change and offers "Undo this run" and a per-object "Undo", each behind a
 * confirm dialog that shows what would happen first. Otherwise it shows the
 * one "Undo needs SenroGate" line. The model never sees any of this.
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
			<p className="senroflux-changes-note" dir="auto">
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
			.catch( ( e ) => setError( e.message || __( 'Could not load the preview.', 'senroflux' ) ) )
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
			.catch( ( e ) => setError( e.message || __( 'The undo failed.', 'senroflux' ) ) )
			.finally( () => {
				setBusy( false );
				setPending( null );
			} );
	};

	return (
		<section className="senroflux-changes" aria-labelledby="senroflux-changes-heading">
			<h3 id="senroflux-changes-heading" className="senroflux-report-subheading senroflux-changes-heading">
				{ __( 'Changes', 'senroflux' ) }
			</h3>
			<ul className="senroflux-changes-list">
				{ changes.map( ( change ) => (
					<li key={ change.id } className="senroflux-changes-row">
						<div className="senroflux-changes-head">
							<span className="senroflux-changes-name" data-senroflux-content dir="auto">
								{ changeName( change ) }
							</span>
							<span className="senroflux-changes-state">
								{ change.restored && __( 'Restored', 'senroflux' ) }
								{ ! change.restored && change.restorable && __( 'Can be undone', 'senroflux' ) }
								{ ! change.restored && ! change.restorable && change.reason_label }
							</span>
							{ change.restorable && ! change.restored && (
								<button
									type="button"
									className="button button-small senroflux-changes-undo"
									disabled={ busy }
									/* translators: %s: the name of the object whose change would be undone. */
									aria-label={ sprintf( __( 'Undo %s', 'senroflux' ), changeName( change ) ) }
									onClick={ () => askPreview( change.id ) }
								>
									{ __( 'Undo', 'senroflux' ) }
								</button>
							) }
						</div>
						<DiffTable diff={ change.diff } />
					</li>
				) ) }
			</ul>
			{ undoable && (
				<button type="button" className="button senroflux-changes-undo-run" disabled={ busy } onClick={ () => askPreview( null ) }>
					{ __( 'Undo this run', 'senroflux' ) }
				</button>
			) }
			{ error && (
				<p className="senroflux-changes-error" role="alert" dir="auto">
					{ error }
				</p>
			) }
			{ result && <UndoOutcome result={ result } /> }
			{ pending && (
				<Modal
					title={ pending.changeId ? __( 'Undo this change?', 'senroflux' ) : __( 'Undo this run?', 'senroflux' ) }
					onRequestClose={ () => setPending( null ) }
					className="senroflux-undo-dialog"
				>
					<p>{ __( 'This is what an undo would do right now. Anything changed since the run is left alone and shown to you.', 'senroflux' ) }</p>
					{ pending.items.length > 0 ? (
						<ItemList items={ pending.items } />
					) : (
						<p>{ __( 'There is nothing to restore.', 'senroflux' ) }</p>
					) }
					<div className="senroflux-undo-dialog-actions">
						<button type="button" className="button" onClick={ () => setPending( null ) }>
							{ __( 'Cancel', 'senroflux' ) }
						</button>
						<button type="button" className="button button-primary" disabled={ busy } onClick={ confirm }>
							{ __( 'Confirm undo', 'senroflux' ) }
						</button>
					</div>
				</Modal>
			) }
		</section>
	);
}
