/**
 * THE CONSTRUCTOR: «Estrategia Web → Content types». Lists the content
 * types, adds the plugin's templates, imports and exports a definition as
 * JSON, and edits one: names and address per language, supports, taxonomies
 * and fields. The whole explanation (why, what is kept, accessibility) is in
 * includes/admin/class-page.php; what the server checks, in
 * includes/class-rest.php.
 *
 * NO BUILD STEP, like the rest of the plugin: a classic script with plain
 * DOM and wp.apiFetch (its only dependency, in Page::enqueue()). Its texts
 * arrive translated from PHP in window.estrategiaWebConstructor.strings.
 *
 * HOW THE FORM WORKS: it edits a COPY of the raw definition (draft). Each
 * control writes its key into the draft; a key the form does not show
 * (description, menu_position, a reference to another file's taxonomy…)
 * stays as it was, and the «JSON» box edits anything. Saving sends the draft
 * as it is: the server validates and answers errors (400, shown in the alert
 * region) or warnings to confirm (409, «Save anyway»).
 *
 * How to maintain:
 * - A new key of the schema that a person should set: its control in the
 *   right section below (typeSection, taxonomySection, fieldBox), with its
 *   text in Page::strings().
 * - A new field type: its name in Page::strings().fieldTypes; if it has keys
 *   of its own, their controls in fieldBox() (like select's options).
 * - Boundary case, with a name: a label that is a plain string ("Restaurant")
 *   instead of an object per language. It is shown in the first language and
 *   becomes {"<first>": "…"} only if someone edits it.
 * - The trap: every control needs a unique id for its <label for>, and the
 *   sections are re-painted when a field or taxonomy is added, removed or
 *   moved. uid() gives a fresh one each time; never build an id from the
 *   position alone (two paints would repeat it and the label would point at
 *   the wrong input).
 */
( function ( wp, config ) {
	const root = document.getElementById( 'estrategia-web-constructor' );
	if ( ! root || ! wp || ! wp.apiFetch || ! config ) {
		return;
	}
	const t = config.strings;
	const api = ( path, options = {} ) => wp.apiFetch( { path: config.route + path, ...options } );

	// «%s», «%d», «%1$s»: the placeholders of the PHP texts.
	const fill = ( text, ...args ) => {
		let i = 0;
		return String( text ).replace( /%(?:(\d)\$)?[sd]/g, ( m, n ) => String( n ? args[ n - 1 ] : args[ i++ ] ) );
	};

	let counter = 0;
	const uid = ( base ) => 'ewct-' + base + '-' + ++counter;

	/** el( 'p', { class: 'x', onclick: fn }, 'text', node ) */
	function el( tag, attrs = {}, ...children ) {
		const node = document.createElement( tag );
		for ( const [ key, value ] of Object.entries( attrs ) ) {
			if ( value === undefined || value === null || value === false ) {
				continue;
			}
			if ( key.startsWith( 'on' ) ) {
				node.addEventListener( key.slice( 2 ), value );
			} else if ( key === 'value' ) {
				node.value = value;
			} else if ( key === 'checked' ) {
				node.checked = Boolean( value );
			} else {
				node.setAttribute( key, value === true ? '' : value );
			}
		}
		for ( const child of children.flat() ) {
			if ( child !== null && child !== undefined && child !== false ) {
				node.append( child instanceof Node ? child : document.createTextNode( String( child ) ) );
			}
		}
		return node;
	}

	const button = ( text, onclick, attrs = {} ) => el( 'button', { type: 'button', class: 'button', onclick, ...attrs }, text );

	// The two regions: progress (polite) and errors / confirmations (alert, takes the focus).
	const status = el( 'div', { class: 'ewct-status', role: 'status', 'aria-live': 'polite' } );
	const alertBox = el( 'div', { class: 'ewct-alert', role: 'alert', tabindex: '-1', hidden: true } );
	const view = el( 'div', { class: 'ewct-view' } );
	root.removeAttribute( 'data-loading' );
	root.replaceChildren( status, alertBox, view );

	const say = ( text ) => ( status.textContent = text );
	function alarm( title, items = [], actions = [] ) {
		alertBox.replaceChildren(
			el( 'h2', {}, title ),
			items.length ? el( 'ul', { class: 'ul-disc' }, items.map( ( i ) => el( 'li', {}, i ) ) ) : null,
			actions.length ? el( 'p', {}, actions ) : null
		);
		alertBox.hidden = false;
		alertBox.className = 'ewct-alert notice notice-' + ( actions.length ? 'warning' : 'error' ) + ' inline';
		alertBox.focus();
	}
	function quiet() {
		alertBox.hidden = true;
		alertBox.replaceChildren();
	}

	let data = null;

	/** A label in a language: exact, then «en», then the first one (Schema::text). */
	function text( value, lang ) {
		if ( typeof value === 'string' ) {
			return value;
		}
		if ( ! value || typeof value !== 'object' ) {
			return '';
		}
		const keys = Object.keys( value );
		const hit = keys.find( ( k ) => k === lang ) || keys.find( ( k ) => k.split( '_' )[ 0 ] === lang ) || keys.find( ( k ) => k === 'en' ) || keys[ 0 ];
		return hit ? value[ hit ] : '';
	}
	const languages = () => ( data && data.languages.length ? data.languages : [ 'en' ] );
	const mainLang = () => languages()[ 0 ];

	/** Writes one language of a text key («label», «singular»…) into an object of the draft. */
	function setText( holder, key, lang, value ) {
		let current = holder[ key ];
		if ( typeof current === 'string' ) {
			current = { [ mainLang() ]: current };
		}
		current = current && typeof current === 'object' ? { ...current } : {};
		// The exact key that already holds this language (es or es_ES), or the two-letter code.
		const existing = Object.keys( current ).find( ( k ) => k === lang || k.split( '_' )[ 0 ] === lang ) || lang;
		if ( value === '' ) {
			delete current[ existing ];
		} else {
			current[ existing ] = value;
		}
		if ( Object.keys( current ).length ) {
			holder[ key ] = current;
		} else {
			delete holder[ key ];
		}
	}
	/** The value of one language for the input: the plain string goes in the first language. */
	function getText( holder, key, lang ) {
		const value = holder[ key ];
		if ( typeof value === 'string' ) {
			return lang === mainLang() ? value : '';
		}
		if ( ! value || typeof value !== 'object' ) {
			return '';
		}
		const k = Object.keys( value ).find( ( x ) => x === lang || x.split( '_' )[ 0 ] === lang );
		return k ? value[ k ] : '';
	}

	/** A text input (or textarea) with its label and help. */
	function control( label, { value = '', oninput, help, type = 'text', multiline = false, readonly = false, attrs = {} } = {} ) {
		const id = uid( 'f' );
		const helpId = help ? id + '-help' : null;
		const input = multiline
			? el( 'textarea', { id, rows: 3, class: 'large-text', 'aria-describedby': helpId, readonly, ...attrs, oninput: ( e ) => oninput && oninput( e.target.value ) } )
			: el( 'input', { id, type, class: type === 'number' ? 'small-text' : 'regular-text', 'aria-describedby': helpId, readonly, ...attrs, oninput: ( e ) => oninput && oninput( e.target.value ) } );
		input.value = value === undefined || value === null ? '' : String( value );
		return el( 'div', { class: 'ewct-control' }, el( 'label', { for: id }, label ), input, help ? el( 'p', { class: 'description', id: helpId }, help ) : null );
	}
	function checkbox( label, checked, onchange, attrs = {} ) {
		const id = uid( 'c' );
		return el( 'div', { class: 'ewct-check' }, el( 'input', { id, type: 'checkbox', checked, ...attrs, onchange: ( e ) => onchange( e.target.checked ) } ), ' ', el( 'label', { for: id }, label ) );
	}
	function choice( label, value, options, onchange, attrs = {} ) {
		const id = uid( 's' );
		const select = el( 'select', { id, ...attrs, onchange: ( e ) => onchange( e.target.value ) }, options.map( ( [ v, l ] ) => el( 'option', { value: v }, l ) ) );
		select.value = value;
		return el( 'div', { class: 'ewct-control' }, el( 'label', { for: id }, label ), select );
	}
	/** One input per site language for a text key. */
	const perLanguage = ( holder, key, labelText, opts = {} ) =>
		el( 'div', { class: 'ewct-languages' }, languages().map( ( lang ) => control( fill( labelText, lang ), { ...opts, value: getText( holder, key, lang ), oninput: ( v ) => { setText( holder, key, lang, v ); opts.after && opts.after(); }, attrs: { 'data-lang': lang, 'data-key': key, ...( opts.attrs || {} ) } } ) ) );

	// ------------------------------------------------------------------ list
	async function reload() {
		data = await api( '' );
	}

	function list() {
		quiet();
		const lang = mainLang();
		const types = data.types;
		const rows = types.map( ( item ) => {
			const def = item.definition || {};
			const template = item.template && data.templates.find( ( x ) => x.key === item.template );
			const source = item.source === 'file' ? fill( t.sourceFile, item.file ) : template ? fill( t.sourceTemplate, template.label ) : t.sourceDatabase;
			const state = item.errors.length ? t.withErrors : item.active ? t.active : t.inactive;
			const actions = [];
			if ( item.source === 'database' ) {
				actions.push( button( t.edit, () => editor( item, structuredClone( def ) ), { 'data-action': 'edit' } ) );
			} else {
				actions.push( button( t.copyToPanel, () => editor( null, structuredClone( def ) ), { 'data-action': 'copy' } ) );
			}
			actions.push( button( t.export, () => download( def ), { 'data-action': 'export' } ) );
			if ( item.source === 'database' ) {
				actions.push(
					button( item.active ? t.switchOff : t.switchOn, () => toggle( item ), { 'data-action': 'toggle' } ),
					button( t.delete, () => remove( item ), { 'data-action': 'delete', class: 'button button-link-delete' } )
				);
			}
			return el(
				'tr',
				{ 'data-type': item.name },
				el( 'th', { scope: 'row' }, text( def.labels && def.labels.plural, lang ) || item.name ),
				el( 'td', {}, el( 'code', {}, item.name ) ),
				el( 'td', {}, def.slug ? el( 'code', {}, '/' + text( def.slug, lang ) + '/' ) : '' ),
				el( 'td', {}, source ),
				el( 'td', {}, state, item.errors.length ? el( 'ul', { class: 'ul-disc' }, item.errors.map( ( e ) => el( 'li', {}, e ) ) ) : null ),
				el( 'td', {}, String( item.entries ) ),
				el( 'td', { class: 'ewct-actions' }, actions )
			);
		} );
		const table = types.length
			? el(
					'table',
					{ class: 'widefat striped ewct-types' },
					el( 'thead', {}, el( 'tr', {}, [ t.colName, t.colInternal, t.colUrl, t.colSource, t.colStatus, t.colEntries, t.colActions ].map( ( c ) => el( 'th', { scope: 'col' }, c ) ) ) ),
					el( 'tbody', {}, rows )
			  )
			: el( 'p', {}, t.none );

		const templates = data.templates.map( ( tpl ) =>
			el(
				'li',
				{},
				button( fill( t.addTemplate, tpl.label ), () => addTemplate( tpl ), { disabled: tpl.added || tpl.taken, 'data-template': tpl.key } ),
				tpl.added ? ' ' + t.templateAdded : tpl.taken ? ' ' + t.templateTaken : ''
			)
		);

		const fileInput = el( 'input', { type: 'file', id: uid( 'import' ), accept: '.json,application/json' } );
		const paste = el( 'textarea', { id: uid( 'paste' ), rows: 4, class: 'large-text code' } );
		const importBox = el(
			'details',
			{ class: 'ewct-import' },
			el( 'summary', {}, t.import ),
			el( 'div', { class: 'ewct-control' }, el( 'label', { for: fileInput.id }, t.importFile ), fileInput ),
			el( 'div', { class: 'ewct-control' }, el( 'label', { for: paste.id }, t.importPaste ), paste ),
			button( t.importDo, () => importJson( fileInput, paste ), { 'data-action': 'import' } )
		);

		view.replaceChildren(
			el( 'h2', { tabindex: '-1', id: 'ewct-list-heading' }, t.types ),
			table,
			el( 'p', {}, button( t.add, () => editor( null, { prefix: '', name: '', labels: { singular: {}, plural: {} }, fields: [] } ), { class: 'button button-primary', 'data-action': 'add' } ) ),
			importBox,
			el( 'h2', {}, t.templates ),
			el( 'p', {}, t.templatesHelp ),
			el( 'ul', { class: 'ewct-templates' }, templates ),
			data.broken.length ? el( 'h2', {}, t.broken ) : null,
			// Spread: replaceChildren() is the DOM's, not el(), and would print an array as text.
			...data.broken.map( ( b ) => el( 'div', { class: 'notice notice-error inline', 'data-file': b.source.split( '/' ).pop() },el( 'p', {}, el( 'code', {}, b.source ) ), el( 'ul', { class: 'ul-disc' }, b.errors.map( ( e ) => el( 'li', {}, e ) ) ) ) ),
			el( 'h2', {}, t.dirs ),
			el( 'ul', { class: 'ul-disc' }, data.dirs.map( ( d ) => el( 'li', {}, el( 'code', {}, d ) ) ) )
		);
	}

	function download( def ) {
		const blob = new Blob( [ JSON.stringify( def, null, 2 ) + '\n' ], { type: 'application/json' } );
		const a = el( 'a', { href: URL.createObjectURL( blob ), download: ( def.name || 'content-type' ) + '.json' } );
		document.body.append( a );
		a.click();
		a.remove();
	}

	async function importJson( fileInput, paste ) {
		let raw = paste.value.trim();
		if ( fileInput.files && fileInput.files[ 0 ] ) {
			raw = ( await fileInput.files[ 0 ].text() ).trim();
		}
		let def;
		try {
			def = JSON.parse( raw.replace( /^﻿/, '' ) );
		} catch ( e ) {
			alarm( t.importBad );
			return;
		}
		if ( ! def || typeof def !== 'object' || Array.isArray( def ) ) {
			alarm( t.importBad );
			return;
		}
		delete def.$schema;
		const existing = data.types.find( ( x ) => x.name === def.name && x.source === 'database' );
		editor( existing || null, def );
	}

	async function addTemplate( tpl ) {
		quiet();
		try {
			await api( '/templates/' + tpl.key, { method: 'POST' } );
			await reload();
			list();
			say( t.saved );
		} catch ( e ) {
			alarm( t.errorsTitle, [ e.message ].concat( ( e.data && e.data.errors ) || [] ) );
		}
	}

	async function toggle( item ) {
		const name = text( item.definition && item.definition.labels && item.definition.labels.plural, mainLang() ) || item.name;
		if ( item.active && ! window.confirm( fill( t.confirmOff, name ) ) ) {
			return;
		}
		try {
			await api( '/' + item.name, { method: 'POST', data: { active: ! item.active } } );
			await reload();
			list();
			say( t.saved );
		} catch ( e ) {
			alarm( t.errorsTitle, [ e.message ] );
		}
	}

	async function remove( item ) {
		const name = text( item.definition && item.definition.labels && item.definition.labels.plural, mainLang() ) || item.name;
		if ( ! window.confirm( fill( t.confirmDelete, name ) ) ) {
			return;
		}
		try {
			await api( '/' + item.name, { method: 'DELETE' } );
			await reload();
			list();
			say( t.saved );
		} catch ( e ) {
			alarm( t.errorsTitle, [ e.message ] );
		}
	}

	// ---------------------------------------------------------------- editor
	/**
	 * @param {object|null} item  The stored type (null: a new one, or a copy of a file's).
	 * @param {object}      draft The raw definition being edited.
	 */
	function editor( item, draft ) {
		quiet();
		const stored = ( item && item.definition ) || {};
		const fromTemplate = Boolean( draft.template );
		const lockedName = fromTemplate || ( item && item.entries > 0 );
		// Fields whose data may exist: the stored ones (and the template's): their names cannot change here.
		const lockedFields = new Set( ( stored.fields || [] ).map( ( f ) => f.name ) );
		let nameTouched = Boolean( draft.name );
		const jsonBox = el( 'textarea', { id: uid( 'json' ), rows: 12, class: 'large-text code', 'aria-describedby': 'ewct-json-help' } );
		const refreshJson = () => {
			if ( document.activeElement !== jsonBox ) {
				jsonBox.value = JSON.stringify( draft, null, 2 );
			}
		};
		const sections = el( 'div', {} );

		const suggestName = () => {
			if ( nameTouched || lockedName ) {
				return;
			}
			const base = text( draft.labels && draft.labels.singular, 'en' ) || text( draft.labels && draft.labels.singular, mainLang() );
			const slugged = base.normalize( 'NFD' ).replace( /[̀-ͯ]/g, '' ).toLowerCase().replace( /[^a-z0-9]+/g, '_' ).replace( /^_+|_+$/g, '' );
			draft.name = ( draft.prefix || '' ) + slugged;
			const input = view.querySelector( '[data-key="name"]' );
			if ( input ) {
				input.value = draft.name;
			}
		};

		function typeSection() {
			const supports = draft.supports || [ 'title', 'editor', 'thumbnail', 'excerpt', 'revisions' ];
			const slugControl =
				draft.slug && typeof draft.slug === 'object'
					? perLanguage( draft, 'slug', t.slug + ' (%s)', { help: t.slugHelp, after: refreshJson } )
					: control( t.slug, { value: draft.slug || '', help: t.slugHelp, attrs: { 'data-key': 'slug' }, oninput: ( v ) => { if ( v ) { draft.slug = v; } else { delete draft.slug; } refreshJson(); } } );
			return el(
				'fieldset',
				{ class: 'ewct-section' },
				el( 'legend', {}, t.sectionType ),
				perLanguage( draft.labels, 'plural', t.plural, { after: refreshJson } ),
				perLanguage( draft.labels, 'singular', t.singular, { after: () => { suggestName(); refreshJson(); } } ),
				control( t.prefix, { value: draft.prefix, help: t.prefixHelp, readonly: fromTemplate, attrs: { 'data-key': 'prefix' }, oninput: ( v ) => { draft.prefix = v; suggestName(); refreshJson(); } } ),
				control( t.internal, { value: draft.name, help: t.internalHelp, readonly: lockedName, attrs: { 'data-key': 'name', maxlength: 20 }, oninput: ( v ) => { draft.name = v; nameTouched = true; refreshJson(); } } ),
				slugControl,
				control( t.icon, { value: draft.icon || '', help: t.iconHelp, attrs: { 'data-key': 'icon' }, oninput: ( v ) => { if ( v ) { draft.icon = v; } else { delete draft.icon; } refreshJson(); } } ),
				el(
					'fieldset',
					{ class: 'ewct-supports' },
					el( 'legend', {}, t.supports ),
					data.supports.map( ( s ) =>
						checkbox( t.supportNames[ s ] || s, supports.includes( s ), ( on ) => {
							const now = new Set( draft.supports || supports );
							if ( on ) {
								now.add( s );
							} else {
								now.delete( s );
							}
							draft.supports = data.supports.filter( ( x ) => now.has( x ) );
							refreshJson();
						}, { 'data-support': s } )
					)
				),
				checkbox( t.public, draft.public !== false, ( on ) => { if ( on ) { delete draft.public; } else { draft.public = false; } refreshJson(); } ),
				checkbox( t.hasArchive, draft.has_archive !== false, ( on ) => { if ( on ) { delete draft.has_archive; } else { draft.has_archive = false; } refreshJson(); } ),
				choice( t.wpmlType, draft.wpml || 'display-as-translated', [ [ 'display-as-translated', t.wpmlDisplay ], [ 'translate', t.wpmlTranslate ], [ 'ignore', t.wpmlIgnore ] ], ( v ) => { draft.wpml = v; refreshJson(); } )
			);
		}

		/** Moves item i of a list of the draft by d (−1, +1) and repaints. */
		const move = ( listKey, i, d ) => {
			const items = draft[ listKey ];
			const j = i + d;
			if ( j < 0 || j >= items.length ) {
				return;
			}
			[ items[ i ], items[ j ] ] = [ items[ j ], items[ i ] ];
			paint();
		};
		const rowButtons = ( listKey, i, last ) =>
			el(
				'p',
				{ class: 'ewct-row-buttons' },
				button( t.moveUp, () => move( listKey, i, -1 ), { disabled: i === 0 } ),
				' ',
				button( t.moveDown, () => move( listKey, i, 1 ), { disabled: last } ),
				' ',
				button( t.remove, () => { draft[ listKey ].splice( i, 1 ); paint(); }, { class: 'button button-link-delete', 'data-action': 'remove' } )
			);

		function taxonomySection() {
			draft.taxonomies = draft.taxonomies || [];
			const items = draft.taxonomies.map( ( tax, i ) => {
				const last = i === draft.taxonomies.length - 1;
				if ( typeof tax === 'string' ) {
					return el( 'div', { class: 'ewct-item' }, el( 'p', {}, fill( t.taxonomyRef, tax ) ), rowButtons( 'taxonomies', i, last ) );
				}
				tax.labels = tax.labels || { singular: {}, plural: {} };
				const locked = ( stored.taxonomies || [] ).some( ( x ) => x && x.name === tax.name ) && tax.name;
				return el(
					'fieldset',
					{ class: 'ewct-item', 'data-taxonomy': i },
					el( 'legend', {}, fill( t.taxonomy, i + 1 ) ),
					perLanguage( tax.labels, 'plural', t.plural, { after: refreshJson } ),
					perLanguage( tax.labels, 'singular', t.singular, { after: refreshJson } ),
					control( t.internal, { value: tax.name || '', readonly: Boolean( locked && fromTemplate ), attrs: { 'data-key': 'tax-name', maxlength: 32 }, oninput: ( v ) => { tax.name = v; refreshJson(); } } ),
					tax.slug && typeof tax.slug === 'object'
						? perLanguage( tax, 'slug', t.slug + ' (%s)', { after: refreshJson } )
						: control( t.slug, { value: tax.slug || '', attrs: { 'data-key': 'tax-slug' }, oninput: ( v ) => { if ( v ) { tax.slug = v; } else { delete tax.slug; } refreshJson(); } } ),
					checkbox( t.hierarchical, tax.hierarchical !== false, ( on ) => { tax.hierarchical = on; refreshJson(); } ),
					rowButtons( 'taxonomies', i, last )
				);
			} );
			return el(
				'fieldset',
				{ class: 'ewct-section' },
				el( 'legend', {}, t.sectionTaxonomies ),
				items,
				el( 'p', {}, button( t.addTaxonomy, () => { draft.taxonomies.push( { name: draft.prefix || '', labels: { singular: {}, plural: {} } } ); paint( 'taxonomy' ); }, { 'data-action': 'add-taxonomy' } ) )
			);
		}

		/** The keys a field type has of its own: removed when the type changes, or the server refuses them. */
		const OWN = { select: [ 'options' ], relation: [ 'post_type' ], number: [ 'min', 'max', 'integer' ], boolean: [ 'true_label', 'false_label' ] };

		function optionsText( field ) {
			return ( field.options || [] )
				.map( ( o ) => ( typeof o === 'string' ? o : [ o.value ].concat( languages().map( ( lang ) => getText( o, 'label', lang ) ) ).join( ' | ' ) ) )
				.join( '\n' );
		}
		function parseOptions( value ) {
			return value
				.split( '\n' )
				.map( ( line ) => line.split( '|' ).map( ( s ) => s.trim() ) )
				.filter( ( parts ) => parts[ 0 ] )
				.map( ( parts ) => {
					if ( parts.length === 1 ) {
						return parts[ 0 ];
					}
					const option = { value: parts[ 0 ] };
					languages().forEach( ( lang, i ) => parts[ i + 1 ] && setText( option, 'label', lang, parts[ i + 1 ] ) );
					if ( ! option.label ) {
						option.label = parts[ 0 ];
					}
					return option;
				} );
		}
		function defaultValue( field, v ) {
			if ( v === '' ) {
				return undefined;
			}
			if ( field.type === 'number' ) {
				return isNaN( Number( v ) ) ? v : Number( v );
			}
			if ( field.type === 'rating' ) {
				return parseInt( v, 10 );
			}
			if ( field.type === 'boolean' ) {
				return v === 'true' || v === '1';
			}
			return v;
		}

		function fieldBox( field, i ) {
			const last = i === draft.fields.length - 1;
			const lockedField = lockedFields.has( field.name ) && field.name;
			const own = [];
			if ( field.type === 'select' ) {
				own.push( control( t.options, { multiline: true, value: optionsText( field ), help: 'value | ' + languages().join( ' | ' ), attrs: { 'data-key': 'options' }, oninput: ( v ) => { field.options = parseOptions( v ); refreshJson(); } } ) );
			} else if ( field.type === 'relation' ) {
				const types = Array.isArray( field.post_type ) ? field.post_type.join( ', ' ) : field.post_type || '';
				own.push( control( t.postTypes, { value: types, attrs: { 'data-key': 'post_type' }, oninput: ( v ) => { const list = v.split( ',' ).map( ( s ) => s.trim() ).filter( Boolean ); field.post_type = list.length === 1 ? list[ 0 ] : list; refreshJson(); } } ) );
			} else if ( field.type === 'number' ) {
				own.push(
					control( t.min, { type: 'number', value: field.min ?? '', oninput: ( v ) => { if ( v === '' ) { delete field.min; } else { field.min = Number( v ); } refreshJson(); } } ),
					control( t.max, { type: 'number', value: field.max ?? '', oninput: ( v ) => { if ( v === '' ) { delete field.max; } else { field.max = Number( v ); } refreshJson(); } } ),
					checkbox( t.integer, Boolean( field.integer ), ( on ) => { if ( on ) { field.integer = true; } else { delete field.integer; } refreshJson(); } )
				);
			} else if ( field.type === 'boolean' ) {
				own.push( perLanguage( field, 'true_label', t.trueLabel + ' (%s)', { after: refreshJson } ), perLanguage( field, 'false_label', t.falseLabel + ' (%s)', { after: refreshJson } ) );
			}
			const noDefault = [ 'image', 'relation' ].includes( field.type );
			return el(
				'fieldset',
				{ class: 'ewct-item', 'data-field': i },
				el( 'legend', {}, fill( t.field, i + 1, text( field.label, mainLang() ) || field.name || '' ) ),
				perLanguage( field, 'label', t.label, { after: refreshJson } ),
				control( t.fieldName, { value: field.name || '', readonly: Boolean( lockedField ), help: lockedField ? t.fieldNameLocked : null, attrs: { 'data-key': 'field-name', maxlength: 64 }, oninput: ( v ) => { field.name = v; refreshJson(); } } ),
				choice( t.fieldType, field.type || 'text', data.fieldTypes.map( ( ft ) => [ ft, t.fieldTypes[ ft ] || ft ] ), ( v ) => {
					for ( const key of OWN[ field.type ] || [] ) {
						delete field[ key ];
					}
					field.type = v;
					delete field.default;
					if ( v === 'select' ) {
						field.options = [];
					}
					if ( v === 'boolean' ) {
						delete field.required;
					}
					paint( 'field-' + i );
				}, { 'data-key': 'field-type' } ),
				perLanguage( field, 'help', t.help, { after: refreshJson } ),
				field.type === 'boolean' ? null : checkbox( t.required, Boolean( field.required ), ( on ) => { if ( on ) { field.required = true; } else { delete field.required; } refreshJson(); } ),
				noDefault ? null : control( t.default, { value: field.default ?? '', attrs: { 'data-key': 'default' }, oninput: ( v ) => { const d = defaultValue( field, v ); if ( d === undefined ) { delete field.default; } else { field.default = d; } refreshJson(); } } ),
				choice( t.wpmlField, field.wpml || '', [ [ '', t.wpmlAuto ], [ 'copy', t.wpmlCopy ], [ 'translate', t.wpmlFieldTranslate ], [ 'ignore', t.wpmlFieldIgnore ] ], ( v ) => { if ( v ) { field.wpml = v; } else { delete field.wpml; } refreshJson(); } ),
				own,
				rowButtons( 'fields', i, last )
			);
		}

		function fieldSection() {
			draft.fields = draft.fields || [];
			return el(
				'fieldset',
				{ class: 'ewct-section' },
				el( 'legend', {}, t.sectionFields ),
				draft.fields.map( fieldBox ),
				el( 'p', {}, button( t.addField, () => { draft.fields.push( { name: draft.prefix || '', type: 'text', label: {} } ); paint( 'field-' + ( draft.fields.length - 1 ) ); }, { 'data-action': 'add-field' } ) )
			);
		}

		/** Repaints the sections; focus goes to the first control of what was just added or changed. */
		function paint( focus ) {
			draft.labels = draft.labels || { singular: {}, plural: {} };
			sections.replaceChildren( typeSection(), taxonomySection(), fieldSection() );
			refreshJson();
			if ( focus === 'taxonomy' ) {
				const boxes = sections.querySelectorAll( '[data-taxonomy]' );
				boxes.length && boxes[ boxes.length - 1 ].querySelector( 'input' ).focus();
			} else if ( focus && focus.startsWith( 'field-' ) ) {
				const box = sections.querySelector( '[data-field="' + focus.slice( 6 ) + '"]' );
				box && box.querySelector( 'input, select' ).focus();
			}
		}

		const title = item ? fill( t.editing, text( stored.labels && stored.labels.plural, mainLang() ) || item.name ) : t.creating;
		view.replaceChildren(
			el( 'p', {}, button( t.back, () => list(), { 'data-action': 'back' } ) ),
			el( 'h2', { tabindex: '-1', id: 'ewct-editor-heading' }, title ),
			el(
				'form',
				{ class: 'ewct-form', novalidate: true, onsubmit: ( e ) => { e.preventDefault(); save( item, draft, false ); } },
				sections,
				el(
					'details',
					{ class: 'ewct-json' },
					el( 'summary', {}, t.json ),
					el( 'p', { class: 'description', id: 'ewct-json-help' }, t.jsonHelp ),
					el( 'label', { for: jsonBox.id, class: 'screen-reader-text' }, t.json ),
					jsonBox,
					el( 'p', {}, button( t.jsonApply, () => {
						try {
							const parsed = JSON.parse( jsonBox.value );
							for ( const k of Object.keys( draft ) ) {
								delete draft[ k ];
							}
							Object.assign( draft, parsed );
							quiet();
							paint();
						} catch ( e ) {
							alarm( t.importBad );
						}
					}, { 'data-action': 'json-apply' } ) )
				),
				el( 'p', { class: 'submit' }, el( 'button', { type: 'submit', class: 'button button-primary', 'data-action': 'save' }, t.save ) )
			)
		);
		paint();
		view.querySelector( '#ewct-editor-heading' ).focus();
	}

	async function save( item, draft, confirm ) {
		quiet();
		say( t.saving );
		try {
			const res = await api( item ? '/' + item.name : '', { method: 'POST', data: { definition: draft, confirm } } );
			await reload();
			list();
			const made = res.redirects && res.redirects.created ? ' ' + fill( t.redirectsMade, res.redirects.created ) : '';
			say( t.saved + made );
			document.getElementById( 'ewct-list-heading' ).focus();
		} catch ( e ) {
			say( '' );
			if ( e.code === 'estrategia_web_ct_confirm' ) {
				alarm( t.confirmTitle, ( e.data && e.data.warnings ) || [], [
					button( t.confirmSave, () => save( item, draft, true ), { class: 'button button-primary', 'data-action': 'confirm' } ),
					' ',
					button( t.cancel, () => quiet(), { 'data-action': 'cancel' } ),
				] );
				return;
			}
			alarm( t.errorsTitle, [ e.message ].concat( ( e.data && e.data.errors ) || [] ) );
		}
	}

	reload()
		.then( list )
		.catch( ( e ) => alarm( t.loadError, [ e.message ] ) );
} )( window.wp, window.estrategiaWebConstructor );
