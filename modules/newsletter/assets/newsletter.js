/**
 * «Posts → Newsletter» screen: propose, choose and order posts, preview the
 * email, copy its code, create the Mailchimp draft. Classic script, not
 * compiled, no dependencies. The data and the texts come in
 * window.estrategiaWebNewsletter (includes/class-screen.php).
 *
 * How to maintain: the HTML of the email is NEVER built here — every change
 * of the chosen list asks /newsletter/preview and shows what it returns; the
 * draft is built again on the server from the ids. The trap: two quick
 * changes send two previews and the slower one could arrive last; each
 * answer carries its request number and only the latest one is painted.
 */
( function () {
	const data = window.estrategiaWebNewsletter;
	const root = document.querySelector( '.ew-newsletter' );
	if ( ! data || ! root ) {
		return;
	}
	const t = data.text || {};
	const $ = ( sel ) => root.querySelector( sel );
	const candidatesBox = $( '.ew-newsletter__candidates' );
	const chosenList = $( '.ew-newsletter__chosen' );
	const frame = $( '.ew-newsletter__frame' );
	const code = $( '.ew-newsletter__code textarea' );
	const copyButton = $( '.ew-newsletter__copy' );
	const draftButton = $( '.ew-newsletter__draft' );
	const status = $( '.ew-newsletter__status' );
	const warnings = $( '.ew-newsletter__warnings' );
	const subject = $( '.ew-newsletter__subject' );

	const known = new Map(); // id → { id, title, date, label }
	let chosen = [];
	let candidates = data.candidates || {};
	let searching = false;
	let html = '';
	let asked = 0;

	const el = ( tag, attrs = {}, text = '' ) => {
		const node = document.createElement( tag );
		Object.entries( attrs ).forEach( ( [ k, v ] ) => node.setAttribute( k, v ) );
		if ( text ) {
			node.textContent = text;
		}
		return node;
	};
	const format = ( s, ...args ) => args.reduce( ( out, a, i ) => out.replace( `%${ i + 1 }$s`, a ), s );

	// data.rest is …/wp-json/estrategia-web/v1/newsletter/, or ?rest_route=… without pretty permalinks.
	const url = ( path, query = {} ) => {
		const u = new URL( data.rest, window.location.href );
		if ( u.searchParams.has( 'rest_route' ) ) {
			u.searchParams.set( 'rest_route', u.searchParams.get( 'rest_route' ) + path );
		} else {
			u.pathname += path;
		}
		Object.entries( query ).forEach( ( [ k, v ] ) => u.searchParams.set( k, v ) );
		return u.toString();
	};

	const api = async ( path, body, query ) => {
		const res = await fetch( url( path, query ), {
			method: body ? 'POST' : 'GET',
			credentials: 'same-origin',
			headers: Object.assign( { 'X-WP-Nonce': data.nonce }, body ? { 'Content-Type': 'application/json' } : {} ),
			body: body ? JSON.stringify( body ) : undefined,
		} );
		const json = await res.json().catch( () => ( {} ) );
		if ( ! res.ok ) {
			throw new Error( json.message || res.status );
		}
		return json;
	};

	const say = ( nodes, kind = 'info' ) => {
		status.replaceChildren();
		if ( ! nodes.length ) {
			return;
		}
		const box = el( 'div', { class: `notice notice-${ kind } inline` } );
		nodes.forEach( ( n ) => box.append( typeof n === 'string' ? el( 'p', {}, n ) : n ) );
		status.append( box );
	};

	function renderCandidates() {
		candidatesBox.replaceChildren();
		// The proposal always carries an empty «results»: what tells a search apart is `searching`.
		const groups = searching ? [ [ t.results, candidates.results ] ] : [ [ t.featured, candidates.featured ], [ t.latest, candidates.latest ] ];
		groups.forEach( ( [ title, rows ] ) => {
			if ( ! rows || ( ! rows.length && ! searching ) ) {
				return;
			}
			candidatesBox.append( el( 'h3', {}, title ) );
			if ( ! rows.length ) {
				candidatesBox.append( el( 'p', {}, t.none ) );
				return;
			}
			const list = el( 'ul', { class: 'ew-newsletter__list' } );
			rows.forEach( ( row ) => {
				known.set( row.id, row );
				const li = el( 'li', { 'data-id': String( row.id ) } );
				const text = el( 'span', { class: 'ew-newsletter__title' }, row.title );
				text.append( el( 'small', {}, ` ${ row.date } · ${ row.label }` ) );
				const isIn = chosen.includes( row.id );
				const add = el( 'button', { type: 'button', class: 'button button-small ew-newsletter__add' }, isIn ? t.added : t.add );
				add.disabled = isIn;
				add.addEventListener( 'click', () => {
					if ( ! chosen.includes( row.id ) ) {
						chosen.push( row.id );
						changed();
					}
				} );
				li.append( text, add );
				list.append( li );
			} );
			candidatesBox.append( list );
		} );
	}

	function renderChosen() {
		chosenList.replaceChildren();
		if ( ! chosen.length ) {
			chosenList.append( el( 'li', { class: 'ew-newsletter__empty' }, t.empty ) );
			return;
		}
		chosen.forEach( ( id, i ) => {
			const row = known.get( id ) || { title: '#' + id, label: '' };
			const li = el( 'li', { 'data-id': String( id ) } );
			li.append( el( 'span', { class: 'ew-newsletter__title' }, row.title ) );
			const tools = el( 'span', { class: 'ew-newsletter__tools' } );
			[ [ t.up, '↑', -1 ], [ t.down, '↓', 1 ], [ t.remove, '×', 0 ] ].forEach( ( [ label, sign, step ] ) => {
				const b = el( 'button', { type: 'button', class: 'button button-small', 'aria-label': `${ label }: ${ row.title }`, title: label }, sign );
				b.disabled = ( step === -1 && i === 0 ) || ( step === 1 && i === chosen.length - 1 );
				b.addEventListener( 'click', () => {
					if ( step === 0 ) {
						chosen.splice( i, 1 );
					} else {
						[ chosen[ i ], chosen[ i + step ] ] = [ chosen[ i + step ], chosen[ i ] ];
					}
					changed();
				} );
				tools.append( b );
			} );
			li.append( tools );
			chosenList.append( li );
		} );
	}

	async function preview() {
		const n = ++asked;
		html = '';
		copyButton.disabled = true;
		draftButton.disabled = true;
		if ( ! chosen.length ) {
			frame.srcdoc = '';
			code.value = '';
			subject.textContent = '';
			warnings.replaceChildren();
			return;
		}
		try {
			const out = await api( 'preview', { ids: chosen } );
			if ( n !== asked ) {
				return;
			}
			html = out.html;
			frame.srcdoc = html;
			code.value = html;
			subject.textContent = `${ t.subject } ${ out.subject }`;
			warnings.replaceChildren();
			( out.warnings || [] ).forEach( ( w ) => {
				const box = el( 'div', { class: 'notice notice-warning inline' } );
				box.append( el( 'p', {}, w ) );
				warnings.append( box );
			} );
			copyButton.disabled = false;
			draftButton.disabled = ! data.hasKey;
		} catch ( e ) {
			if ( n === asked ) {
				say( [ `${ t.error } ${ e.message }` ], 'error' );
			}
		}
	}

	function changed() {
		say( [] );
		renderChosen();
		renderCandidates();
		preview();
	}

	$( '.ew-newsletter__search' ).addEventListener( 'submit', async ( event ) => {
		event.preventDefault();
		const q = $( '#ew-newsletter-search' ).value.trim();
		try {
			candidates = q ? { results: ( await api( 'posts', null, { search: q } ) ).results || [] } : data.candidates;
			searching = '' !== q;
			renderCandidates();
		} catch ( e ) {
			say( [ `${ t.error } ${ e.message }` ], 'error' );
		}
	} );

	copyButton.addEventListener( 'click', async () => {
		try {
			await navigator.clipboard.writeText( html );
			say( [ t.copied ], 'success' );
		} catch ( e ) {
			// Without a secure context (http) the clipboard API is not there: the old way.
			const details = $( '.ew-newsletter__code' );
			details.open = true;
			code.focus();
			code.select();
			const ok = document.execCommand && document.execCommand( 'copy' );
			say( [ ok ? t.copied : t.copyFailed ], ok ? 'success' : 'warning' );
		}
	} );

	draftButton.addEventListener( 'click', async () => {
		draftButton.disabled = true;
		say( [ t.working ] );
		try {
			const out = await api( 'draft', { ids: chosen } );
			const link = el( 'a', { href: out.url, target: '_blank', rel: 'noopener' }, t.open );
			const first = el( 'p', {}, format( t.created, out.subject, String( out.recipients ) ) + ' ' );
			first.append( link );
			const rule = el( 'p' );
			rule.append( el( 'strong', {}, t.rule ) );
			const lines = [ first, rule ];
			if ( out.model && out.model.subject ) {
				lines.push( format( t.model, out.model.subject, ( out.model.send_time || '' ).slice( 0, 10 ) ) );
			}
			say( lines, 'success' );
		} catch ( e ) {
			say( [ `${ t.error } ${ e.message }` ], 'error' );
			draftButton.disabled = ! data.hasKey;
		}
	} );

	renderCandidates();
	renderChosen();
} )();
