/**
 * “Social networks” screen: Move up / Move down / Remove, “Add network”, the
 * icon preview and the custom SVG from the media library. Classic script, not
 * compiled. What the screen does without this, and how to maintain it: header
 * of includes/class-admin.php.
 *
 * The trap: the name="estrategia_web_social_links[n][field]" carry the
 * POSITION. Moving rows leaves the n out of order and PHP would save by n,
 * not by what is on screen; that is why they are renumbered right before submit.
 */
( function () {
	const table = document.querySelector( '.estrategia-web-social__rows' );
	if ( ! table ) {
		return;
	}
	const icons = window.estrategiaWebSocialIcons || {};
	const t = window.estrategiaWebSocialText || {};
	const addButton = document.querySelector( '.estrategia-web-social__add' );
	const template = table.lastElementChild.cloneNode( true ); // The empty row.

	// Same as Icons::guess() in PHP, in short: only for the preview.
	const guess = ( url ) => {
		const u = String( url || '' ).toLowerCase();
		if ( u.startsWith( 'mailto:' ) || /^[^\s@]+@[^\s@]+$/.test( u ) ) return 'email';
		const host = ( u.match( /^https?:\/\/([^/?#]+)/ ) || [] )[ 1 ] || '';
		const pairs = [
			[ /instagram\.com$/, 'instagram' ], [ /(facebook|fb)\.com$/, 'facebook' ], [ /linkedin\.com$/, 'linkedin' ],
			[ /(^|\.)(x|twitter)\.com$/, 'x' ], [ /(youtube\.com|youtu\.be)$/, 'youtube' ], [ /tiktok\.com$/, 'tiktok' ],
			[ /(wa\.me|whatsapp\.com)$/, 'whatsapp' ], [ /(^|\.)(t|telegram)\.me$/, 'telegram' ], [ /pinterest\./, 'pinterest' ],
			[ /maps\.app\.goo\.gl$/, 'google-maps' ], [ /g\.page$/, 'google' ], [ /tripadvisor\./, 'tripadvisor' ],
			[ /threads\.(net|com)$/, 'threads' ], [ /bsky\.app$/, 'bluesky' ], [ /vimeo\.com$/, 'vimeo' ],
		];
		for ( const [ re, slug ] of pairs ) if ( re.test( host ) ) return slug;
		if ( /(^|\.)google\./.test( host ) ) return /^https?:\/\/[^/]+\/maps/.test( u ) ? 'google-maps' : 'google';
		return host ? 'website' : '';
	};

	const field = ( row, name ) => row.querySelector( '[data-field="' + name + '"]' );

	function preview( row ) {
		const box = row.querySelector( '.estrategia-web-social__preview' );
		if ( row.dataset.svgUrl ) {
			box.innerHTML = '';
			const img = document.createElement( 'img' );
			img.src = row.dataset.svgUrl;
			img.alt = '';
			box.appendChild( img );
			return;
		}
		if ( Number( field( row, 'icon_id' ).value ) > 0 ) {
			return; // The custom SVG PHP rendered: leave it.
		}
		const slug = field( row, 'icon' ).value || guess( field( row, 'url' ).value );
		box.innerHTML = icons[ slug ] || '';
	}

	function button( label, cls, onClick ) {
		const b = document.createElement( 'button' );
		b.type = 'button';
		b.className = 'button button-small ' + cls;
		b.textContent = label;
		b.addEventListener( 'click', onClick );
		return b;
	}

	function pickSvg( row ) {
		if ( ! window.wp || ! window.wp.media ) return;
		const frame = window.wp.media( { title: t.pick, button: { text: t.use }, library: { type: 'image/svg+xml' }, multiple: false } );
		frame.on( 'select', () => {
			const att = frame.state().get( 'selection' ).first().toJSON();
			field( row, 'icon_id' ).value = String( att.id || 0 );
			row.dataset.svgUrl = att.url || '';
			setup( row );
			preview( row );
		} );
		frame.open();
	}

	function setup( row ) {
		const cell = row.querySelector( '.estrategia-web-social__actions' );
		cell.innerHTML = '';
		const own = Number( field( row, 'icon_id' ).value ) > 0;
		cell.append(
			button( t.up, 'is-up', () => {
				if ( row.previousElementSibling ) row.parentNode.insertBefore( row, row.previousElementSibling );
				row.querySelector( '.is-up' ).focus();
			} ),
			button( t.down, 'is-down', () => {
				if ( row.nextElementSibling ) row.parentNode.insertBefore( row.nextElementSibling, row );
				row.querySelector( '.is-down' ).focus();
			} ),
			button( own ? t.svgOff : t.svg, 'is-svg', () => {
				if ( own ) {
					field( row, 'icon_id' ).value = '0';
					delete row.dataset.svgUrl;
					setup( row );
					preview( row );
				} else {
					pickSvg( row );
				}
			} ),
			button( t.remove, 'is-remove', () => {
				const next = row.nextElementSibling || row.previousElementSibling;
				row.remove();
				if ( ! table.children.length ) table.appendChild( fresh() );
				( next ? next.querySelector( 'input[data-field="url"]' ) : addButton ).focus();
			} )
		);
		field( row, 'icon' ).onchange = () => preview( row );
		field( row, 'url' ).oninput = () => preview( row );
	}

	let counter = 1000;
	function fresh() {
		const row = template.cloneNode( true );
		const id = 'estrategia-web-social-n' + counter++;
		row.querySelectorAll( '[id]' ).forEach( ( el ) => {
			const suffix = el.id.replace( /^.*-(icon|name|url)$/, '$1' );
			const label = row.querySelector( 'label[for="' + el.id + '"]' );
			el.id = id + '-' + suffix;
			if ( label ) label.htmlFor = el.id;
		} );
		row.querySelectorAll( 'input, select' ).forEach( ( el ) => {
			el.value = el.dataset.field === 'icon_id' ? '0' : '';
		} );
		setup( row );
		preview( row );
		return row;
	}

	Array.from( table.children ).forEach( setup );
	addButton.hidden = false;
	addButton.addEventListener( 'click', () => {
		const row = fresh();
		table.appendChild( row );
		field( row, 'url' ).focus();
	} );

	table.closest( 'form' ).addEventListener( 'submit', () => {
		Array.from( table.children ).forEach( ( row, n ) => {
			row.querySelectorAll( '[data-field]' ).forEach( ( el ) => {
				el.name = el.name.replace( /\[\d+\](\[[a-z_]+\])$/, '[' + n + ']$1' );
			} );
		} );
	} );
} )();
