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
		<ul className="specflux-site-agent-card-links">
			{ safe.map( ( l, i ) => (
				<li key={ i }>
					<a href={ safeUrl( l.url ) } target="_blank" rel="noopener noreferrer">
						{ text( l.label ) }
						<span className="screen-reader-text">{ ' ' + __( '(opens in a new tab)', 'specflux-site-agent' ) }</span>
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
		<span className="specflux-site-agent-card-thumb-wrap">
			<img className="specflux-site-agent-card-thumb" src={ src } alt={ altText } loading="lazy" />
			{ '' === altText && <span className="specflux-site-agent-card-noalt">{ __( 'No alt text', 'specflux-site-agent' ) }</span> }
		</span>
	);
}

function Fields( { fields } ) {
	const rows = ( Array.isArray( fields ) ? fields : [] ).filter( ( f ) => f && text( f.label ) );
	if ( ! rows.length ) {
		return null;
	}
	return (
		<dl className="specflux-site-agent-card-fields">
			{ rows.map( ( f, i ) => (
				<div key={ i } className="specflux-site-agent-card-field">
					<dt>{ text( f.label ) }</dt>
					<dd data-specflux-site-agent-content>{ text( f.value ) }</dd>
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
		<p className="specflux-site-agent-card-more">
			{ /* translators: %d: number of items not shown. */ sprintf( _n( '+ %d more not shown', '+ %d more not shown', n, 'specflux-site-agent' ), n ) }
		</p>
	);
}

function Body( { display } ) {
	const status = text( display.status );
	switch ( display.type ) {
		case 'error':
			return (
				<div className="specflux-site-agent-card-error">
					<p className="specflux-site-agent-card-title">
						<span aria-hidden="true">{ '⚠ ' }</span>
						<span className="specflux-site-agent-card-errorlabel">{ __( 'Error', 'specflux-site-agent' ) }</span>
						{ text( display.title ) && <span data-specflux-site-agent-content>{ ': ' + text( display.title ) }</span> }
					</p>
					{ text( display.message ) && <p className="specflux-site-agent-card-message" data-specflux-site-agent-content>{ text( display.message ) }</p> }
				</div>
			);
		case 'list':
			return (
				<>
					<p className="specflux-site-agent-card-title">
						<span data-specflux-site-agent-content>{ text( display.title ) }</span>{ ' ' }
						{ /* translators: %d: number of items in the list. */ sprintf( _n( '(%d item)', '(%d items)', Number( display.count ) || 0, 'specflux-site-agent' ), Number( display.count ) || 0 ) }
					</p>
					<ul className="specflux-site-agent-card-list">
						{ ( Array.isArray( display.items ) ? display.items : [] ).map( ( item, i ) => (
							<li key={ i }>
								{ item && item.thumbnail_url && <Thumb url={ item.thumbnail_url } alt={ item.label } /> }
								<span data-specflux-site-agent-content>{ text( item && item.label ) }</span>
								{ text( item && item.detail ) && <span className="specflux-site-agent-card-detail" data-specflux-site-agent-content>{ text( item.detail ) }</span> }
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
					{ text( display.title ) && <p className="specflux-site-agent-card-title" data-specflux-site-agent-content>{ text( display.title ) }</p> }
					<table className="specflux-site-agent-card-table">
						<thead>
							<tr>
								{ columns.map( ( c, i ) => (
									<th key={ i } scope="col" data-specflux-site-agent-content>{ text( c ) }</th>
								) ) }
							</tr>
						</thead>
						<tbody>
							{ rows.map( ( row, r ) => (
								<tr key={ r }>
									{ ( Array.isArray( row ) ? row : [] ).map( ( cell, c ) =>
										0 === c ? <th key={ c } scope="row" data-specflux-site-agent-content>{ text( cell ) }</th> : <td key={ c } data-specflux-site-agent-content>{ text( cell ) }</td>
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
					<p className="specflux-site-agent-card-title">
						<span data-specflux-site-agent-content>{ text( display.title ) }</span>
						{ status && <span className="specflux-site-agent-card-status" data-specflux-site-agent-content>{ status }</span> }
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
		return <span data-specflux-site-agent-content>{ raw }</span>;
	}
	return (
		<div className="specflux-site-agent-card" data-card-type={ display.type }>
			<Body display={ display } />
			{ raw && (
				<>
					<button
						type="button"
						className="specflux-site-agent-card-raw-toggle button-link"
						aria-expanded={ showRaw }
						onClick={ () => setShowRaw( ! showRaw ) }
					>
						{ showRaw ? __( 'Hide raw', 'specflux-site-agent' ) : __( 'Show raw', 'specflux-site-agent' ) }
					</button>
					{ showRaw && <pre className="specflux-site-agent-card-raw" data-specflux-site-agent-content>{ raw }</pre> }
				</>
			) }
		</div>
	);
}
