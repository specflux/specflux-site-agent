import { useState } from '@wordpress/element';
import { __, sprintf, _n } from '@wordpress/i18n';

const KNOWN_TYPES = [ 'post', 'link', 'media', 'product', 'coupon', 'list', 'table', 'error' ];

/**
 * A URL is rendered as a link/image only when it is a parseable http(s) URL.
 * javascript:, data:, vbscript: and friends are dropped, not escaped.
 *
 * @param {*} url Candidate URL.
 * @return {string} The URL, or '' when unsafe.
 */
export function safeUrl( url ) {
	if ( 'string' !== typeof url || '' === url.trim() ) {
		return '';
	}
	try {
		const parsed = new URL( url.trim() );
		return 'http:' === parsed.protocol || 'https:' === parsed.protocol ? parsed.href : '';
	} catch ( e ) {
		return '';
	}
}

/** Whether a `display` payload is one this component knows how to render. */
export function isRenderableDisplay( display ) {
	return !! display && 'object' === typeof display && KNOWN_TYPES.includes( display.type );
}

const text = ( value ) => ( null === value || undefined === value ? '' : String( value ) );

function Links( { links } ) {
	const safe = ( Array.isArray( links ) ? links : [] ).filter( ( l ) => l && safeUrl( l.url ) && text( l.label ) );
	if ( ! safe.length ) {
		return null;
	}
	return (
		<ul className="senroflux-card-links">
			{ safe.map( ( l, i ) => (
				<li key={ i }>
					<a href={ safeUrl( l.url ) } target="_blank" rel="noopener noreferrer">
						{ text( l.label ) }
						<span className="screen-reader-text">{ ' ' + __( '(opens in a new tab)', 'senroflux' ) }</span>
					</a>
				</li>
			) ) }
		</ul>
	);
}

function Thumb( { url, alt } ) {
	const src = safeUrl( url );
	if ( ! src ) {
		return null;
	}
	const altText = text( alt );
	return (
		<span className="senroflux-card-thumb-wrap">
			<img className="senroflux-card-thumb" src={ src } alt={ altText } loading="lazy" />
			{ '' === altText && <span className="senroflux-card-noalt">{ __( 'No alt text', 'senroflux' ) }</span> }
		</span>
	);
}

function Fields( { fields } ) {
	const rows = ( Array.isArray( fields ) ? fields : [] ).filter( ( f ) => f && text( f.label ) );
	if ( ! rows.length ) {
		return null;
	}
	return (
		<dl className="senroflux-card-fields">
			{ rows.map( ( f, i ) => (
				<div key={ i } className="senroflux-card-field">
					<dt>{ text( f.label ) }</dt>
					<dd data-senroflux-content>{ text( f.value ) }</dd>
				</div>
			) ) }
		</dl>
	);
}

function More( { count } ) {
	const n = Number( count );
	if ( ! Number.isFinite( n ) || n < 1 ) {
		return null;
	}
	return (
		<p className="senroflux-card-more">
			{ /* translators: %d: number of items not shown. */ sprintf( _n( '+ %d more not shown', '+ %d more not shown', n, 'senroflux' ), n ) }
		</p>
	);
}

function Body( { display } ) {
	const status = text( display.status );
	switch ( display.type ) {
		case 'error':
			return (
				<div className="senroflux-card-error">
					<p className="senroflux-card-title">
						<span aria-hidden="true">{ '⚠ ' }</span>
						<span className="senroflux-card-errorlabel">{ __( 'Error', 'senroflux' ) }</span>
						{ text( display.title ) && <span data-senroflux-content>{ ': ' + text( display.title ) }</span> }
					</p>
					{ text( display.message ) && <p className="senroflux-card-message" data-senroflux-content>{ text( display.message ) }</p> }
				</div>
			);
		case 'list':
			return (
				<>
					<p className="senroflux-card-title">
						<span data-senroflux-content>{ text( display.title ) }</span>{ ' ' }
						{ /* translators: %d: number of items in the list. */ sprintf( _n( '(%d item)', '(%d items)', Number( display.count ) || 0, 'senroflux' ), Number( display.count ) || 0 ) }
					</p>
					<ul className="senroflux-card-list">
						{ ( Array.isArray( display.items ) ? display.items : [] ).map( ( item, i ) => (
							<li key={ i }>
								{ item && item.thumbnail_url && <Thumb url={ item.thumbnail_url } alt={ item.label } /> }
								<span data-senroflux-content>{ text( item && item.label ) }</span>
								{ text( item && item.detail ) && <span className="senroflux-card-detail" data-senroflux-content>{ text( item.detail ) }</span> }
							</li>
						) ) }
					</ul>
					<More count={ display.more } />
				</>
			);
		case 'table': {
			const columns = Array.isArray( display.columns ) ? display.columns : [];
			const rows = Array.isArray( display.rows ) ? display.rows : [];
			return (
				<>
					{ text( display.title ) && <p className="senroflux-card-title" data-senroflux-content>{ text( display.title ) }</p> }
					<table className="senroflux-card-table">
						<thead>
							<tr>
								{ columns.map( ( c, i ) => (
									<th key={ i } scope="col" data-senroflux-content>{ text( c ) }</th>
								) ) }
							</tr>
						</thead>
						<tbody>
							{ rows.map( ( row, r ) => (
								<tr key={ r }>
									{ ( Array.isArray( row ) ? row : [] ).map( ( cell, c ) =>
										0 === c ? <th key={ c } scope="row" data-senroflux-content>{ text( cell ) }</th> : <td key={ c } data-senroflux-content>{ text( cell ) }</td>
									) }
								</tr>
							) ) }
						</tbody>
					</table>
					<More count={ display.more } />
				</>
			);
		}
		default:
			// post, link, media, product, coupon.
			return (
				<>
					{ 'media' === display.type && <Thumb url={ display.thumbnail_url } alt={ display.alt } /> }
					<p className="senroflux-card-title">
						<span data-senroflux-content>{ text( display.title ) }</span>
						{ status && <span className="senroflux-card-status" data-senroflux-content>{ status }</span> }
					</p>
					<Fields fields={ display.fields } />
					<Links links={ display.links } />
				</>
			);
	}
}

/**
 * The result of one tool call, rendered from the server's `display` payload
 * as plain text (never HTML). `raw` is the old JSON text, kept behind a
 * collapsed "Show raw" disclosure.
 *
 * @param {Object} props
 * @param {Object} props.display Payload from the REST step.
 * @param {string} props.raw     The previous raw result text.
 */
export default function ResultCard( { display, raw } ) {
	const [ showRaw, setShowRaw ] = useState( false );
	if ( ! isRenderableDisplay( display ) ) {
		return <span data-senroflux-content>{ raw }</span>;
	}
	return (
		<div className="senroflux-card" data-card-type={ display.type }>
			<Body display={ display } />
			{ raw && (
				<>
					<button
						type="button"
						className="senroflux-card-raw-toggle button-link"
						aria-expanded={ showRaw }
						onClick={ () => setShowRaw( ! showRaw ) }
					>
						{ showRaw ? __( 'Hide raw', 'senroflux' ) : __( 'Show raw', 'senroflux' ) }
					</button>
					{ showRaw && <pre className="senroflux-card-raw" data-senroflux-content>{ raw }</pre> }
				</>
			) }
		</div>
	);
}
