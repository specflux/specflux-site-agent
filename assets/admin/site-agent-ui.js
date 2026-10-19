/*
 * Site Agent admin screens: the small glue the shared kit leaves to the plugin.
 * Vanilla ES2018, no dependencies. The kit (aui.js) does tabs, switches,
 * filters, copy and the dirty save bar; this adds what needs the plugin's own
 * form fields.
 */
( function ( d ) {
	'use strict';

	function $$( selector, root ) {
		return [].slice.call( ( root || d ).querySelectorAll( selector ) );
	}

	function ready( fn ) {
		if ( 'loading' === d.readyState ) {
			d.addEventListener( 'DOMContentLoaded', fn );
		} else {
			fn();
		}
	}

	/* Switches that carry their state in a hidden input ( data-sync="#input-id" ). */
	function syncSwitch( button ) {
		var input = d.querySelector( button.getAttribute( 'data-sync' ) );
		if ( input ) {
			input.value = 'true' === button.getAttribute( 'aria-pressed' ) ? '1' : '0';
		}
	}

	/* "n / max characters" under a textarea. */
	function updateCount( area ) {
		var out = d.querySelector( area.getAttribute( 'data-count-for' ) );
		if ( ! out ) {
			return;
		}
		var used = Array.from( area.value ).length,
			max = parseInt( out.getAttribute( 'data-max' ), 10 ) || 0,
			text = ( out.getAttribute( 'data-template' ) || '%1$d / %2$d' ).replace( '%1$d', used ).replace( '%2$d', max ),
			mono = out.querySelector( '.aui-mono' );
		( mono || out ).textContent = text;
		out.style.color = max && used > max ? 'var(--aui-bad)' : '';
	}

	/* "n / m enabled · x read, y write" on every group header, and the group checkbox state. */
	function updateGroups( list ) {
		var template = list.getAttribute( 'data-meta-template' ) || '';
		$$( '.aui-group', list ).forEach( function ( group ) {
			var items = $$( '.aui-item', group ),
				on = $$( 'input[data-ability]:checked', group ).length,
				reads = $$( '[data-kind="read"]', group ).length,
				meta = group.querySelector( '[data-meta]' ),
				box = group.querySelector( '[data-group]' ),
				values = [ on, items.length, reads, items.length - reads ];
			if ( meta && template ) {
				meta.textContent = template.replace( /%(\d)\$d/g, function ( match, n ) {
					return values[ parseInt( n, 10 ) - 1 ];
				} );
			}
			if ( box ) {
				box.checked = on === items.length;
				box.indeterminate = on > 0 && on < items.length;
			}
		} );
	}

	function updateOverCap( card ) {
		var counter = card.querySelector( '[data-aui-counter]' ),
			badge = counter && counter.closest( '.aui-badge' ),
			max = parseInt( card.getAttribute( 'data-aui-max' ), 10 ) || 0,
			on = $$( 'input[data-ability]:checked', card ).length;
		if ( badge ) {
			badge.classList.toggle( 'aui-badge--bad', max > 0 && on > max );
		}
	}

	ready( function () {
		if ( window.AUI ) {
			window.AUI.init( d );
		}

		$$( 'textarea[data-count-for]' ).forEach( updateCount );
		$$( '[data-meta-template]' ).forEach( updateGroups );
		$$( '[data-aui-counted]' ).forEach( updateOverCap );

		d.addEventListener( 'input', function ( event ) {
			if ( event.target.matches && event.target.matches( 'textarea[data-count-for]' ) ) {
				updateCount( event.target );
			}
		} );

		d.addEventListener( 'change', function ( event ) {
			var target = event.target,
				list = target.closest && target.closest( '[data-meta-template]' ),
				card = target.closest && target.closest( '[data-aui-counted]' );
			if ( target.matches && target.matches( 'select[data-kind-select]' ) ) {
				var row = target.closest( '.aui-item' );
				if ( row ) {
					row.setAttribute( 'data-kind', 'read' === target.value ? 'read' : 'write' );
				}
			}
			if ( list ) {
				updateGroups( list );
			}
			if ( card ) {
				updateOverCap( card );
			}
		} );

		d.addEventListener( 'aui:change', function ( event ) {
			var control = event.detail && event.detail.control;
			if ( control && control.hasAttribute && control.hasAttribute( 'data-sync' ) ) {
				syncSwitch( control );
			}
		} );

		d.addEventListener( 'aui:discard', function ( event ) {
			$$( '[data-sync]', event.detail.form ).forEach( syncSwitch );
			$$( 'textarea[data-count-for]', event.detail.form ).forEach( updateCount );
			$$( '[data-meta-template]', event.detail.form ).forEach( updateGroups );
		} );
	} );
}( document ) );
