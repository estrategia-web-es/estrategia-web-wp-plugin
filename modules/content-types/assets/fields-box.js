/**
 * The fields meta box (includes/class-fields-panel.php, render_meta_box()),
 * made usable — 1.8.0. Without this script the box still works: an image
 * is its media library number and the hours are one line per day.
 *
 *   - image: the number input becomes the picture plus «Choose image» /
 *     «Replace image» (WordPress's media library, wp.media) and «Remove».
 *     The input stays in the form, hidden, and is what is saved.
 *   - hours: a «Copy Monday to every day» button under the seven lines.
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

	function init() {
		document.querySelectorAll( '.estrategia-web-ct-box .estrategia-web-ct-image' ).forEach( imageField );
		document.querySelectorAll( '.estrategia-web-ct-box .estrategia-web-ct-hours' ).forEach( hoursField );
	}
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
