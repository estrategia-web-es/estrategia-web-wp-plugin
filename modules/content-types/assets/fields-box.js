/**
 * The fields meta box (includes/class-fields-panel.php, render_meta_box()),
 * made usable — 2.0.0. Without this script the box still works: an image
 * is its media library number and the hours are one line per day.
 *
 *   - image: the number input becomes the picture plus «Choose image» /
 *     «Replace image» (WordPress's media library, wp.media) and «Remove».
 *     The input stays in the form, hidden, and is what is saved.
 *   - hours: a «Copy Monday to every day» button under the seven lines.
 *   - repeater: Add / Remove / Move up / Move down for its rows (without
 *     this script: the stored rows plus one empty row, one new row per save).
 *
 * NO BUILD STEP, a classic script; its texts come from PHP in
 * window.estrategiaWebCtBox (Fields_Panel::enqueue_box()).
 *
 * How to maintain: a control that needs JavaScript in the box goes here,
 * with its PHP markup in render_fields() working WITHOUT it first.
 * The trap: in the block editor this box is a «legacy» meta box whose form
 * is posted after the post's REST save — a value set here only travels if
 * it is in an input of the form (never only in the DOM or in a JS variable).
 */
( function () {
	const t = window.estrategiaWebCtBox || {};

	function imageField( wrap ) {
		const input = wrap.querySelector( 'input' );
		if ( ! input || ! window.wp || ! window.wp.media || input.disabled ) {
			return;
		}
		input.type = 'hidden';
		const img = document.createElement( 'img' );
		img.alt = '';
		img.className = 'estrategia-web-ct-image__preview';
		const choose = document.createElement( 'button' );
		choose.type = 'button';
		choose.className = 'button';
		const remove = document.createElement( 'button' );
		remove.type = 'button';
		remove.className = 'button-link button-link-delete';
		remove.textContent = t.remove || 'Remove';
		const show = ( src ) => {
			const has = parseInt( input.value, 10 ) > 0;
			img.hidden = ! ( has && src );
			if ( src ) {
				img.src = src;
			}
			choose.textContent = has ? t.replace || 'Replace image' : t.choose || 'Choose image';
			remove.hidden = ! has;
		};
		let frame = null;
		choose.addEventListener( 'click', () => {
			frame = frame || window.wp.media( { title: t.choose || 'Choose image', library: { type: 'image' }, multiple: false } );
			frame.off( 'select' ).on( 'select', () => {
				const m = frame.state().get( 'selection' ).first().toJSON();
				input.value = String( m.id );
				show( ( m.sizes && m.sizes.medium && m.sizes.medium.url ) || m.url );
			} );
			frame.open();
		} );
		remove.addEventListener( 'click', () => {
			input.value = '0';
			show( '' );
			choose.focus();
		} );
		wrap.append( img, choose, document.createTextNode( ' ' ), remove );
		show( wrap.dataset.src || '' );
	}

	function hoursField( fieldset ) {
		const inputs = Array.from( fieldset.querySelectorAll( 'input[data-day]' ) );
		if ( inputs.length !== 7 || inputs[ 0 ].disabled ) {
			return;
		}
		const button = document.createElement( 'button' );
		button.type = 'button';
		button.className = 'button';
		button.textContent = t.copy || 'Copy Monday to every day';
		const status = document.createElement( 'span' );
		status.className = 'screen-reader-text';
		status.setAttribute( 'role', 'status' );
		button.addEventListener( 'click', () => {
			inputs.slice( 1 ).forEach( ( i ) => ( i.value = inputs[ 0 ].value ) );
			status.textContent = t.copied || '';
		} );
		const p = document.createElement( 'p' );
		p.append( button, status );
		fieldset.append( p );
	}

	/*
	 * repeater (24-11-2026): the rows PHP painted (render_repeater()), plus
	 * Add / Remove / Move up / Move down. The spare empty row PHP adds for
	 * the form without JavaScript becomes the template of a new row. A new
	 * row gets a fresh number in its input names (estrategia_web_ct[key][n][sub]):
	 * the number is only an identity, the ORDER saved is the order of the form,
	 * so moving a row is moving its node. Each row's legend («Acte 2») and its
	 * buttons' labels («Remove Acte 2») follow its position.
	 */
	const fill = ( s, v ) => String( s || '' ).replace( /%(?:1\$)?[sd]/, v );
	function repeaterField( fieldset ) {
		const box = fieldset.querySelector( '.estrategia-web-ct-rows' );
		const key = fieldset.id.replace( /^estrategia-web-ct-/, '' );
		if ( ! box || fieldset.hasAttribute( 'data-locked' ) ) {
			return;
		}
		const spare = box.querySelector( '[data-spare]' );
		const model = spare || box.querySelector( '.estrategia-web-ct-row' );
		if ( ! model ) {
			return;
		}
		const template = model.cloneNode( true );
		template.removeAttribute( 'data-spare' );
		template.querySelectorAll( 'input, textarea' ).forEach( ( i ) => ( i.value = '' ) );
		template.querySelectorAll( 'select' ).forEach( ( s ) => ( s.selectedIndex = 0 ) );
		if ( spare ) {
			spare.remove();
		}
		const min = parseInt( fieldset.dataset.min, 10 ) || 0;
		const max = parseInt( fieldset.dataset.max, 10 ) || Infinity;
		const word = fieldset.dataset.itemLabel || '';
		const nameOf = ( i ) => ( word ? word + ' ' + ( i + 1 ) : fill( t.row || 'Row %d', i + 1 ) );
		let next = Math.max( -1, ...Array.from( box.children ).map( ( r ) => parseInt( r.dataset.row, 10 ) || 0 ), parseInt( template.dataset.row, 10 ) || 0 ) + 1;
		const status = document.createElement( 'span' );
		status.className = 'screen-reader-text';
		status.setAttribute( 'role', 'status' );
		const add = document.createElement( 'button' );
		add.type = 'button';
		add.className = 'button';
		add.dataset.action = 'add-row';
		add.textContent = word ? fill( t.addItem || 'Add %s', word ) : t.add || 'Add';
		const rows = () => Array.from( box.querySelectorAll( ':scope > .estrategia-web-ct-row' ) );
		const button = ( text, action, onclick ) => {
			const b = document.createElement( 'button' );
			b.type = 'button';
			b.className = action === 'remove-row' ? 'button-link button-link-delete' : 'button-link';
			b.dataset.action = action;
			b.textContent = text;
			b.addEventListener( 'click', () => onclick( b.closest( '.estrategia-web-ct-row' ) ) );
			return b;
		};
		const refresh = () => {
			const list = rows();
			list.forEach( ( row, i ) => {
				const name = nameOf( i );
				row.querySelector( 'legend' ).textContent = name;
				const [ up, down, remove ] = row.querySelectorAll( '.estrategia-web-ct-row-buttons button' );
				up.setAttribute( 'aria-label', fill( t.upRow || 'Move up %s', name ) );
				down.setAttribute( 'aria-label', fill( t.downRow || 'Move down %s', name ) );
				remove.setAttribute( 'aria-label', fill( t.removeRow || 'Remove %s', name ) );
				up.disabled = i === 0;
				down.disabled = i === list.length - 1;
				remove.disabled = list.length <= min;
			} );
			add.disabled = list.length >= max;
		};
		const move = ( row, by ) => {
			const list = rows();
			const at = list.indexOf( row ) + by;
			if ( at < 0 || at >= list.length ) {
				return;
			}
			if ( by < 0 ) {
				box.insertBefore( row, list[ at ] );
			} else {
				box.insertBefore( row, list[ at ].nextSibling );
			}
			refresh();
			const again = row.querySelector( '[data-action="' + ( by < 0 ? 'move-up' : 'move-down' ) + '"]' );
			( again && ! again.disabled ? again : row.querySelector( 'button' ) ).focus();
			status.textContent = fill( t.moved || 'Moved: it is now %s.', nameOf( rows().indexOf( row ) ) );
		};
		const decorate = ( row ) => {
			const p = document.createElement( 'p' );
			p.className = 'estrategia-web-ct-row-buttons';
			p.append(
				button( t.up || 'Move up', 'move-up', ( r ) => move( r, -1 ) ),
				document.createTextNode( ' ' ),
				button( t.down || 'Move down', 'move-down', ( r ) => move( r, 1 ) ),
				document.createTextNode( ' ' ),
				button( t.remove || 'Remove', 'remove-row', ( r ) => {
					const i = rows().indexOf( r );
					r.remove();
					refresh();
					const list = rows();
					const target = list[ Math.min( i, list.length - 1 ) ];
					( target ? target.querySelector( 'input, select, textarea' ) : add ).focus();
					status.textContent = t.removed || 'Row removed.';
				} )
			);
			row.append( p );
		};
		const newRow = () => {
			const row = template.cloneNode( true );
			const from = template.dataset.row;
			const n = next++;
			row.dataset.row = String( n );
			row.querySelectorAll( '[name], [id], label[for]' ).forEach( ( node ) => {
				if ( node.name ) {
					node.name = node.name.replace( '[' + key + '][' + from + ']', '[' + key + '][' + n + ']' );
				}
				if ( node.id ) {
					node.id = node.id.replace( 'estrategia-web-ct-' + key + '-' + from + '-', 'estrategia-web-ct-' + key + '-' + n + '-' );
				}
				if ( node.htmlFor ) {
					node.htmlFor = node.htmlFor.replace( 'estrategia-web-ct-' + key + '-' + from + '-', 'estrategia-web-ct-' + key + '-' + n + '-' );
				}
			} );
			decorate( row );
			box.append( row );
			return row;
		};
		add.addEventListener( 'click', () => {
			const row = newRow();
			refresh();
			const first = row.querySelector( 'input, select, textarea' );
			first && first.focus();
			status.textContent = fill( t.added || '%s added.', nameOf( rows().length - 1 ) );
		} );
		rows().forEach( decorate );
		while ( rows().length < min ) {
			newRow();
		}
		const p = document.createElement( 'p' );
		p.append( add, status );
		fieldset.append( p );
		refresh();
	}

	function init() {
		document.querySelectorAll( '.estrategia-web-ct-box .estrategia-web-ct-image' ).forEach( imageField );
		document.querySelectorAll( '.estrategia-web-ct-box .estrategia-web-ct-hours' ).forEach( hoursField );
		document.querySelectorAll( '.estrategia-web-ct-box .estrategia-web-ct-repeater' ).forEach( repeaterField );
	}
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
