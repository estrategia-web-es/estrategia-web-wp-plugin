/**
 * THE CONSTRUCTOR: «Estrategia Web → Content types», its two screens (1.8.0,
 * after ACF's field groups). The whole explanation — the URLs, why two
 * screens, accessibility — is in includes/admin/class-page.php; what the
 * server checks, in includes/class-rest.php.
 *
 * - LIST (data-view="list"): the table is PHP's (class-types-table.php).
 *   This script only wires what writes: the row actions Deactivate /
 *   Activate, Delete and Export, and the «Add from template» menu. After a
 *   write it reloads the list with ?ewct_done=… (PHP shows the notice).
 * - EDIT (data-view="edit", data-type="<name>" or "" for a new one): paints
 *   the settings in tabs and the fields as collapsed rows, and saves.
 *
 * NO BUILD STEP, like the rest of the plugin: a classic script with plain
 * DOM, wp.apiFetch and wp.a11y (its dependencies, in Page::enqueue()), and
 * the pure part in assets/constructor-model.js (window.
 * estrategiaWebConstructorModel). Its texts arrive translated from PHP in
 * window.estrategiaWebConstructor.strings.
 *
 * HOW THE FORM WORKS: it edits a COPY of the raw definition (draft). Each
 * control writes its key into the draft; a key the form does not show
 * (description, show_in_rest, a reference to another file's taxonomy…)
 * stays as it was, and the JSON box (Advanced tab) edits anything. Saving
 * sends the draft as it is: the server validates and answers errors (400,
 * shown in the alert region) or warnings to confirm (409, «Save anyway»).
 * After saving, the screen STAYS on the type (repainted from the server,
 * a notice [data-saved] that takes the focus), as ACF does.
 *
 * How to maintain:
 * - A new key of the schema that a person should set: its control in the
 *   tab where a person would look for it (generalPanel, urlsPanel,
 *   taxonomiesPanel, advancedPanel) or, for a field's key, in fieldBody(),
 *   with its text in Page::strings(). A rule that decides what is WRITTEN
 *   (not how it is shown) goes in constructor-model.js, with its level 1 case.
 * - A new field type: its name in Page::strings().fieldTypes; if it has keys
 *   of its own, their controls in fieldBody() and its line in OWN.
 * - Boundary case, with a name: a field whose «group» is a key the
 *   definition no longer declares (the JSON box, or a group removed in
 *   another tab). Its select keeps that key as an option (groupChoices())
 *   instead of silently moving the field to «No group»; the server names the
 *   error on save. Renaming a group's key renames it in its fields too.
 * - The traps:
 *   · Every control needs a unique id for its <label for>, and the rows are
 *     re-painted when a field is added, removed, moved or changes type.
 *     uid() gives a fresh one each time; never build an id from the
 *     position alone.
 *   · Which rows are OPEN is kept by the field OBJECT (the «open» Set), not
 *     by its position: a move or a removal would otherwise open the wrong
 *     row. Same for the drag: the index is read at drop time.
 *   · Only the HANDLE makes a row draggable (mousedown sets draggable): a
 *     draggable <li> would not let anyone select the text of its inputs.
 */
( function ( wp, config, M ) {
	const root = document.getElementById( 'estrategia-web-constructor' );
	if ( ! root || ! wp || ! wp.apiFetch || ! config || ! M ) {
		return;
	}
	const t = config.strings;
	const api = ( path, options = {} ) => wp.apiFetch( { path: config.route + path, ...options } );
	const fill = M.fill;
	const speak = ( message ) => wp.a11y && wp.a11y.speak && wp.a11y.speak( message, 'polite' );

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
		// Any depth: a section returns lists that hold lists (a field's own keys).
		for ( const child of children.flat( Infinity ) ) {
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
	root.removeAttribute( 'data-loading' );
	root.replaceChildren( status, alertBox );

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

	function download( def ) {
		const blob = new Blob( [ JSON.stringify( def, null, 2 ) + '\n' ], { type: 'application/json' } );
		const a = el( 'a', { href: URL.createObjectURL( blob ), download: ( def.name || 'content-type' ) + '.json' } );
		document.body.append( a );
		a.click();
		a.remove();
	}

	// ================================================================== LIST
	function listScreen() {
		const done = ( what, name ) => window.location.assign( config.listUrl + '&ewct_done=' + what + '&ewct_name=' + encodeURIComponent( name ) );

		const table = document.querySelector( '.wp-list-table' );
		table &&
			table.addEventListener( 'click', async ( e ) => {
				const b = e.target.closest( 'button[data-action]' );
				if ( ! b ) {
					return;
				}
				const name = b.dataset.type;
				const label = b.dataset.label || name;
				quiet();
				try {
					if ( b.dataset.action === 'toggle' ) {
						const active = b.dataset.active === '1';
						if ( active && ! window.confirm( fill( t.confirmOff, label ) ) ) {
							return;
						}
						await api( '/' + name, { method: 'POST', data: { active: ! active } } );
						done( active ? 'deactivated' : 'activated', name );
					} else if ( b.dataset.action === 'delete' ) {
						if ( ! window.confirm( fill( t.confirmDelete, label ) ) ) {
							return;
						}
						await api( '/' + name, { method: 'DELETE' } );
						done( 'deleted', name );
					} else if ( b.dataset.action === 'export' ) {
						const item = await api( '/' + name );
						download( item.definition || {} );
					}
				} catch ( err ) {
					alarm( t.errorsTitle, [ err.message ] );
				}
			} );

		// «Add from template»: a disclosure (button + list), closed by Escape or a click outside.
		const toggle = document.querySelector( '[data-action="templates"]' );
		const menu = document.getElementById( 'ewct-templates' );
		if ( ! toggle || ! menu ) {
			return;
		}
		const setOpen = ( open, focusBack ) => {
			menu.hidden = ! open;
			toggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
			if ( open ) {
				const first = menu.querySelector( 'button:not([disabled])' );
				first && first.focus();
			} else if ( focusBack ) {
				toggle.focus();
			}
		};
		toggle.addEventListener( 'click', () => setOpen( menu.hidden ) );
		menu.addEventListener( 'keydown', ( e ) => {
			if ( e.key === 'Escape' ) {
				e.preventDefault();
				setOpen( false, true );
			}
		} );
		document.addEventListener( 'click', ( e ) => {
			if ( ! menu.hidden && ! menu.contains( e.target ) && e.target !== toggle ) {
				setOpen( false );
			}
		} );
		menu.addEventListener( 'click', async ( e ) => {
			const b = e.target.closest( 'button[data-template]' );
			if ( ! b || b.disabled ) {
				return;
			}
			quiet();
			b.disabled = true;
			try {
				await api( '/templates/' + b.dataset.template, { method: 'POST' } );
				window.location.assign( M.editUrl( config.listUrl, b.dataset.name ) + '&ewct_done=added&ewct_name=' + encodeURIComponent( b.dataset.name ) );
			} catch ( err ) {
				b.disabled = false;
				setOpen( false );
				alarm( t.errorsTitle, [ err.message ].concat( ( err.data && err.data.errors ) || [] ) );
			}
		} );
	}

	// ================================================================== EDIT
	let data = null;
	const languages = () => ( data && data.languages.length ? data.languages : [ 'en' ] );
	const mainLang = () => languages()[ 0 ];
	const text = M.text;
	const setText = ( holder, key, lang, value ) => M.setText( holder, key, lang, value, mainLang() );
	const getText = ( holder, key, lang ) => M.getText( holder, key, lang, mainLang() );
	const clone = ( value ) => JSON.parse( JSON.stringify( value || {} ) );

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
	function choice( label, value, options, onchange, attrs = {}, help = null ) {
		const id = uid( 's' );
		const helpId = help ? id + '-help' : null;
		const select = el( 'select', { id, 'aria-describedby': helpId, ...attrs, onchange: ( e ) => onchange( e.target.value ) }, options.map( ( [ v, l ] ) => el( 'option', { value: v }, l ) ) );
		select.value = value;
		return el( 'div', { class: 'ewct-control' }, el( 'label', { for: id }, label ), select, help ? el( 'p', { class: 'description', id: helpId }, help ) : null );
	}
	/** One input per site language for a text key. */
	const perLanguage = ( holder, key, labelText, opts = {} ) =>
		el( 'div', { class: 'ewct-languages' }, languages().map( ( lang ) => control( fill( labelText, lang ), { ...opts, value: getText( holder, key, lang ), oninput: ( v ) => { setText( holder, key, lang, v ); opts.after && opts.after( lang, v ); }, attrs: { 'data-lang': lang, 'data-key': opts.dataKey || key, ...( opts.attrs || {} ) } } ) ) );

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

	async function editScreen() {
		const name = root.dataset.type || '';
		try {
			data = await api( '' );
		} catch ( e ) {
			alarm( t.loadError, [ e.message ] );
			return;
		}
		const item = name ? data.types.find( ( x ) => x.name === name ) : null;
		if ( item && item.source === 'database' ) {
			editor( item, clone( item.definition ) );
		} else if ( item ) {
			editor( null, clone( item.definition ), { file: true } );
		} else {
			editor( null, { prefix: '', name: '', labels: { singular: {}, plural: {} }, fields: [] }, { tab: root.dataset.import ? 'advanced' : 'general', importing: Boolean( root.dataset.import ) } );
		}
	}

	/**
	 * @param {object|null} item  The stored type (null: a new one, or a copy of a file's).
	 * @param {object}      draft The raw definition being edited.
	 * @param {object}      opts  file (a copy of a file's type), tab (the one to open), saved (the message after a save), importing.
	 */
	function editor( item, draft, opts = {} ) {
		quiet();
		const stored = ( item && item.definition ) || {};
		const fromTemplate = Boolean( draft.template );
		const lockedName = fromTemplate || ( item && item.entries > 0 );
		// Fields whose data may exist: the stored ones (and the template's): their names cannot change here.
		const lockedFields = new Set( ( stored.fields || [] ).map( ( f ) => f && f.name ) );
		const open = new Set();
		// Groups added on this screen whose key still follows their title (never written into the draft: the server refuses unknown keys).
		const followTitle = new Set();
		let nameTouched = Boolean( draft.name );
		let activeTab = opts.tab || 'general';
		let dirty = false;
		const touch = () => {
			dirty = true;
			refreshJson();
		};
		draft.labels = draft.labels || { singular: {}, plural: {} };
		draft.fields = draft.fields || [];

		const jsonBox = el( 'textarea', { id: uid( 'json' ), rows: 14, class: 'large-text code', 'aria-describedby': 'ewct-json-help', 'data-key': 'json' } );
		function refreshJson() {
			if ( document.activeElement !== jsonBox ) {
				jsonBox.value = JSON.stringify( draft, null, 2 );
			}
		}

		const suggestName = () => {
			if ( nameTouched || lockedName ) {
				return;
			}
			const base = text( draft.labels && draft.labels.singular, 'en' ) || text( draft.labels && draft.labels.singular, mainLang() );
			const slugged = base.normalize( 'NFD' ).replace( /[̀-ͯ]/g, '' ).toLowerCase().replace( /[^a-z0-9]+/g, '_' ).replace( /^_+|_+$/g, '' );
			draft.name = ( draft.prefix || '' ) + slugged;
			const input = root.querySelector( '[data-key="name"]' );
			if ( input ) {
				input.value = draft.name;
			}
		};

		// ------------------------------------------------------------ tabs
		function generalPanel() {
			const main = mainLang();
			const many = languages().length > 1;
			const slugValue = typeof draft.slug === 'string' ? draft.slug : getText( draft, 'slug', main );
			return [
				perLanguage( draft.labels, 'plural', t.plural, { after: touch } ),
				perLanguage( draft.labels, 'singular', t.singular, { after: () => { suggestName(); touch(); } } ),
				control( t.prefix, { value: draft.prefix, help: t.prefixHelp, readonly: fromTemplate, attrs: { 'data-key': 'prefix' }, oninput: ( v ) => { draft.prefix = v; suggestName(); touch(); } } ),
				control( t.internal, { value: draft.name, help: t.internalHelp, readonly: lockedName, attrs: { 'data-key': 'name', maxlength: 20 }, oninput: ( v ) => { draft.name = v; nameTouched = true; touch(); } } ),
				control( many ? t.slug + ' (' + main + ')' : t.slug, { value: slugValue, help: t.slugHelp, attrs: { 'data-key': 'slug', 'data-lang': main }, oninput: ( v ) => { M.setSlug( draft, main, v, main ); touch(); } } ),
			];
		}

		function urlsPanel() {
			const main = mainLang();
			const others = languages().slice( 1 );
			return [
				others.length
					? el(
							'div',
							{ class: 'ewct-languages' },
							others.map( ( lang ) => control( t.slug + ' (' + lang + ')', { value: getText( draft, 'slug', lang ), help: t.urlsHelp, attrs: { 'data-key': 'slug', 'data-lang': lang }, oninput: ( v ) => { M.setSlug( draft, lang, v, main ); touch(); } } ) )
					  )
					: el( 'p', { class: 'description' }, t.urlsOneLanguage ),
				choice( t.wpmlType, draft.wpml || 'display-as-translated', [ [ 'display-as-translated', t.wpmlDisplay ], [ 'translate', t.wpmlTranslate ], [ 'ignore', t.wpmlIgnore ] ], ( v ) => { draft.wpml = v; touch(); }, { 'data-key': 'wpml' } ),
			];
		}

		/** Up / down / remove for an item of a small list (taxonomies, groups): plain buttons, then repaint. */
		const listButtons = ( list, i, repaint, onremove ) =>
			el(
				'p',
				{ class: 'ewct-row-buttons' },
				button( t.moveUp, () => { M.move( list, i, i - 1 ); touch(); repaint(); }, { disabled: i === 0 } ),
				' ',
				button( t.moveDown, () => { M.move( list, i, i + 1 ); touch(); repaint(); }, { disabled: i === list.length - 1 } ),
				' ',
				button( t.remove, () => { const [ gone ] = list.splice( i, 1 ); onremove && onremove( gone ); touch(); repaint(); }, { class: 'button button-link-delete', 'data-action': 'remove' } )
			);

		function taxonomiesPanel() {
			draft.taxonomies = draft.taxonomies || [];
			const repaint = () => paintPanel( 'taxonomies' );
			const items = draft.taxonomies.map( ( tax, i ) => {
				if ( typeof tax === 'string' ) {
					return el( 'div', { class: 'ewct-item' }, el( 'p', {}, fill( t.taxonomyRef, tax ) ), listButtons( draft.taxonomies, i, repaint ) );
				}
				tax.labels = tax.labels || { singular: {}, plural: {} };
				const locked = ( stored.taxonomies || [] ).some( ( x ) => x && x.name === tax.name ) && tax.name;
				return el(
					'fieldset',
					{ class: 'ewct-item', 'data-taxonomy': i },
					el( 'legend', {}, fill( t.taxonomy, i + 1 ) ),
					perLanguage( tax.labels, 'plural', t.plural, { after: touch } ),
					perLanguage( tax.labels, 'singular', t.singular, { after: touch } ),
					control( t.internal, { value: tax.name || '', readonly: Boolean( locked && fromTemplate ), attrs: { 'data-key': 'tax-name', maxlength: 32 }, oninput: ( v ) => { tax.name = v; touch(); } } ),
					tax.slug && typeof tax.slug === 'object'
						? perLanguage( tax, 'slug', t.slug + ' (%s)', { after: touch, dataKey: 'tax-slug' } )
						: control( t.slug, { value: tax.slug || '', attrs: { 'data-key': 'tax-slug' }, oninput: ( v ) => { if ( v ) { tax.slug = v; } else { delete tax.slug; } touch(); } } ),
					checkbox( t.hierarchical, tax.hierarchical !== false, ( on ) => { tax.hierarchical = on; touch(); } ),
					listButtons( draft.taxonomies, i, repaint )
				);
			} );
			return [
				items,
				el( 'p', {}, button( t.addTaxonomy, () => {
					draft.taxonomies.push( { name: draft.prefix || '', labels: { singular: {}, plural: {} } } );
					touch();
					repaint();
					const boxes = panels.taxonomies.querySelectorAll( '[data-taxonomy]' );
					boxes.length && boxes[ boxes.length - 1 ].querySelector( 'input' ).focus();
				}, { 'data-action': 'add-taxonomy' } ) ),
			];
		}

		/** «groups» (1.8.0): the sections of the box under the content. Their keys feed each field's «Group». */
		function groupsFieldset() {
			const groups = draft.groups || [];
			const repaint = () => {
				if ( ! groups.length ) {
					delete draft.groups;
				}
				paintPanel( 'advanced', '[data-key="groups"]' );
				paintFields();
			};
			const items = groups.map( ( group, i ) => {
				let previous = group.key;
				const rename = ( v ) => {
					for ( const f of draft.fields ) {
						if ( f && f.group === previous ) {
							f.group = v;
						}
					}
					previous = v;
					group.key = v;
					paintFields();
				};
				return el(
					'fieldset',
					{ class: 'ewct-item', 'data-group': i },
					el( 'legend', {}, fill( t.group, i + 1 ) ),
					perLanguage( group, 'label', t.groupLabel, {
						dataKey: 'group-label',
						after: ( lang, v ) => {
							// A new group's key follows its title until someone types the key.
							if ( followTitle.has( group ) && lang === mainLang() && v ) {
								const taken = groups.filter( ( g ) => g !== group ).map( ( g ) => g.key );
								rename( M.keyFrom( v, taken ) );
								const keyInput = root.querySelector( '[data-group="' + i + '"] [data-key="group-key"]' );
								keyInput && ( keyInput.value = group.key );
							}
							paintFields();
							touch();
						},
					} ),
					control( t.groupKey, { value: group.key || '', help: t.groupKeyHelp, attrs: { 'data-key': 'group-key' }, oninput: ( v ) => { followTitle.delete( group ); rename( v ); touch(); } } ),
					checkbox( t.groupCollapsed, Boolean( group.collapsed ), ( on ) => { if ( on ) { group.collapsed = true; } else { delete group.collapsed; } touch(); }, { 'data-key': 'group-collapsed' } ),
					listButtons( groups, i, repaint, ( gone ) => {
						for ( const f of draft.fields ) {
							if ( f && f.group === gone.key ) {
								delete f.group;
							}
						}
					} )
				);
			} );
			return el(
				'fieldset',
				{ class: 'ewct-groups', 'data-key': 'groups', tabindex: '-1' },
				el( 'legend', {}, t.groups ),
				el( 'p', { class: 'description' }, t.groupsHelp ),
				items,
				el( 'p', {}, button( t.addGroup, () => {
					draft.groups = draft.groups || [];
					const key = M.keyFrom( '', draft.groups.map( ( g ) => g.key ) );
					const group = { key, label: {} };
					draft.groups.push( group );
					followTitle.add( group );
					touch();
					paintPanel( 'advanced' );
					paintFields();
					const boxes = panels.advanced.querySelectorAll( '[data-group]' );
					boxes.length && boxes[ boxes.length - 1 ].querySelector( 'input' ).focus();
				}, { 'data-action': 'add-group' } ) )
			);
		}

		function advancedPanel() {
			const supports = draft.supports || [ 'title', 'editor', 'thumbnail', 'excerpt', 'revisions' ];
			const fileInput = el( 'input', { type: 'file', id: uid( 'import' ), accept: '.json,application/json', 'data-key': 'import-file', onchange: async ( e ) => {
				const file = e.target.files && e.target.files[ 0 ];
				if ( file ) {
					jsonBox.value = ( await file.text() ).replace( /^﻿/, '' );
					applyJson();
				}
			} } );
			return [
				control( t.icon, { value: draft.icon || '', help: t.iconHelp, attrs: { 'data-key': 'icon' }, oninput: ( v ) => { if ( v ) { draft.icon = v; } else { delete draft.icon; } touch(); } } ),
				control( t.menuPosition, { type: 'number', value: draft.menu_position ?? '', help: t.menuPositionHelp, attrs: { 'data-key': 'menu_position', min: 0, step: 1 }, oninput: ( v ) => { if ( v === '' ) { delete draft.menu_position; } else { draft.menu_position = parseInt( v, 10 ); } touch(); } } ),
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
							touch();
						}, { 'data-support': s } )
					)
				),
				checkbox( t.public, draft.public !== false, ( on ) => { if ( on ) { delete draft.public; } else { draft.public = false; } touch(); }, { 'data-key': 'public' } ),
				checkbox( t.hasArchive, draft.has_archive !== false, ( on ) => { if ( on ) { delete draft.has_archive; } else { draft.has_archive = false; } touch(); }, { 'data-key': 'has_archive' } ),
				choice( t.fieldsBox, draft.fields_box || 'sidebar', [ [ 'sidebar', t.fieldsBoxSidebar ], [ 'main', t.fieldsBoxMain ] ], ( v ) => { if ( v === 'main' ) { draft.fields_box = v; } else { delete draft.fields_box; } touch(); }, { 'data-key': 'fields_box' }, t.fieldsBoxHelp ),
				groupsFieldset(),
				el(
					'div',
					{ class: 'ewct-json' },
					el( 'h3', {}, t.json ),
					el( 'p', { class: 'description', id: 'ewct-json-help' }, t.jsonHelp ),
					el( 'div', { class: 'ewct-control' }, el( 'label', { for: fileInput.id }, t.importFile ), fileInput ),
					el( 'div', { class: 'ewct-control' }, el( 'label', { for: jsonBox.id }, t.json ), jsonBox ),
					el( 'p', {}, button( t.jsonApply, applyJson, { 'data-action': 'json-apply' } ) )
				),
			];
		}

		function applyJson() {
			let parsed;
			try {
				parsed = JSON.parse( jsonBox.value.replace( /^﻿/, '' ) );
			} catch ( e ) {
				alarm( t.importBad );
				return;
			}
			if ( ! parsed || typeof parsed !== 'object' || Array.isArray( parsed ) ) {
				alarm( t.importBad );
				return;
			}
			delete parsed.$schema;
			// Importing over a type of this panel edits THAT type (as the list's «Import» did before 1.8.0).
			const existing = ! item && data.types.find( ( x ) => x.name === parsed.name && x.source === 'database' );
			if ( existing ) {
				window.history.replaceState( null, '', M.editUrl( config.listUrl, existing.name ) );
				editor( existing, parsed, { tab: 'advanced' } );
				return;
			}
			for ( const k of Object.keys( draft ) ) {
				delete draft[ k ];
			}
			Object.assign( draft, parsed );
			draft.labels = draft.labels || { singular: {}, plural: {} };
			draft.fields = draft.fields || [];
			nameTouched = Boolean( draft.name );
			open.clear();
			dirty = true;
			quiet();
			paintAll();
		}

		const TABS = [
			[ 'general', t.tabGeneral, generalPanel ],
			[ 'urls', t.tabUrls, urlsPanel ],
			[ 'taxonomies', t.sectionTaxonomies, taxonomiesPanel ],
			[ 'advanced', t.tabAdvanced, advancedPanel ],
		];
		const tabButtons = {};
		const panels = {};
		for ( const [ key, label ] of TABS ) {
			tabButtons[ key ] = el( 'button', { type: 'button', role: 'tab', id: 'ewct-tab-' + key, class: 'nav-tab', 'aria-controls': 'ewct-panel-' + key, 'data-tab': key, onclick: () => selectTab( key ) }, label );
			panels[ key ] = el( 'div', { role: 'tabpanel', id: 'ewct-panel-' + key, class: 'ewct-panel', 'aria-labelledby': 'ewct-tab-' + key, tabindex: '0' } );
		}
		function selectTab( key, focus = false ) {
			activeTab = key;
			for ( const [ k ] of TABS ) {
				const on = k === key;
				tabButtons[ k ].setAttribute( 'aria-selected', on ? 'true' : 'false' );
				tabButtons[ k ].tabIndex = on ? 0 : -1;
				tabButtons[ k ].classList.toggle( 'nav-tab-active', on );
				panels[ k ].hidden = ! on;
			}
			focus && tabButtons[ key ].focus();
		}
		const tablist = el( 'div', { role: 'tablist', class: 'nav-tab-wrapper ewct-tabs', 'aria-label': t.settings } , TABS.map( ( [ k ] ) => tabButtons[ k ] ) );
		tablist.addEventListener( 'keydown', ( e ) => {
			const keys = TABS.map( ( [ k ] ) => k );
			const i = keys.indexOf( activeTab );
			const next = { ArrowRight: i + 1, ArrowLeft: i - 1, Home: 0, End: keys.length - 1 }[ e.key ];
			if ( next === undefined ) {
				return;
			}
			e.preventDefault();
			selectTab( keys[ ( next + keys.length ) % keys.length ], true );
		} );
		/** Repaints one tab; focus (a selector) goes back to what had it. */
		function paintPanel( key, focus ) {
			const builder = TABS.find( ( [ k ] ) => k === key )[ 2 ];
			panels[ key ].replaceChildren( ...[ builder() ].flat( 2 ).filter( Boolean ) );
			refreshJson();
			if ( focus ) {
				const target = panels[ key ].querySelector( focus );
				target && target.focus();
			}
		}

		// ---------------------------------------------------------- fields
		const fieldsList = el( 'ol', { class: 'ewct-fields', 'data-fields': '' } );
		const addFieldButton = button( t.addField, () => {
			const field = { name: draft.prefix || '', type: 'text', label: {} };
			draft.fields.push( field );
			open.add( field );
			touch();
			paintFields();
			focusIn( draft.fields.length - 1, '.ewct-field-body input, .ewct-field-body select' );
		}, { class: 'button button-primary', 'data-action': 'add-field' } );
		const noFields = el( 'p', { class: 'ewct-no-fields' }, t.noFields );

		const fieldLabel = ( field ) => text( field.label, mainLang() ) || field.name || t.noLabel;
		const rowOf = ( i ) => fieldsList.querySelector( '[data-field="' + i + '"]' );
		function focusIn( i, selector ) {
			const row = rowOf( i );
			const target = row && row.querySelector( selector );
			target && target.focus();
		}

		function moveField( from, to, focusSelector = '.ewct-field-toggle' ) {
			const field = draft.fields[ from ];
			if ( ! M.move( draft.fields, from, to ) ) {
				return;
			}
			touch();
			paintFields();
			const row = rowOf( to );
			const wanted = row && row.querySelector( focusSelector );
			( wanted && ! wanted.disabled ? wanted : row && row.querySelector( '.ewct-field-toggle' ) ).focus();
			speak( fill( t.moved, fieldLabel( field ), to + 1, draft.fields.length ) );
		}

		function removeField( i ) {
			const [ field ] = draft.fields.splice( i, 1 );
			open.delete( field );
			touch();
			paintFields();
			const next = rowOf( Math.min( i, draft.fields.length - 1 ) );
			( next ? next.querySelector( '.ewct-field-toggle' ) : addFieldButton ).focus();
			speak( fill( t.removed, fieldLabel( field ) ) );
		}

		function fieldBody( field, i, refreshHead ) {
			const lockedField = lockedFields.has( field.name ) && field.name;
			const own = [];
			if ( field.type === 'select' ) {
				own.push( control( t.options, { multiline: true, value: optionsText( field ), help: 'value | ' + languages().join( ' | ' ), attrs: { 'data-key': 'options' }, oninput: ( v ) => { field.options = parseOptions( v ); touch(); } } ) );
			} else if ( field.type === 'relation' ) {
				const types = Array.isArray( field.post_type ) ? field.post_type.join( ', ' ) : field.post_type || '';
				own.push( control( t.postTypes, { value: types, attrs: { 'data-key': 'post_type' }, oninput: ( v ) => { const list = v.split( ',' ).map( ( s ) => s.trim() ).filter( Boolean ); field.post_type = list.length === 1 ? list[ 0 ] : list; touch(); } } ) );
			} else if ( field.type === 'number' ) {
				own.push(
					control( t.min, { type: 'number', value: field.min ?? '', oninput: ( v ) => { if ( v === '' ) { delete field.min; } else { field.min = Number( v ); } touch(); } } ),
					control( t.max, { type: 'number', value: field.max ?? '', oninput: ( v ) => { if ( v === '' ) { delete field.max; } else { field.max = Number( v ); } touch(); } } ),
					checkbox( t.integer, Boolean( field.integer ), ( on ) => { if ( on ) { field.integer = true; } else { delete field.integer; } touch(); } )
				);
			} else if ( field.type === 'boolean' ) {
				own.push( perLanguage( field, 'true_label', t.trueLabel + ' (%s)', { after: touch } ), perLanguage( field, 'false_label', t.falseLabel + ' (%s)', { after: touch } ) );
			}
			const noDefault = [ 'image', 'relation' ].includes( field.type );
			return [
				perLanguage( field, 'label', t.label, { after: () => { refreshHead(); touch(); } } ),
				control( t.fieldName, { value: field.name || '', readonly: Boolean( lockedField ), help: lockedField ? t.fieldNameLocked : null, attrs: { 'data-key': 'field-name', maxlength: 64 }, oninput: ( v ) => { field.name = v; refreshHead(); touch(); } } ),
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
					touch();
					paintFields();
					focusIn( i, '[data-key="field-type"]' );
				}, { 'data-key': 'field-type' } ),
				perLanguage( field, 'help', t.help, { after: touch } ),
				field.type === 'boolean' ? null : checkbox( t.required, Boolean( field.required ), ( on ) => { if ( on ) { field.required = true; } else { delete field.required; } touch(); }, { 'data-key': 'required' } ),
				noDefault ? null : control( t.default, { value: field.default ?? '', attrs: { 'data-key': 'default' }, oninput: ( v ) => { const d = defaultValue( field, v ); if ( d === undefined ) { delete field.default; } else { field.default = d; } touch(); } } ),
				choice( t.wpmlField, field.wpml || '', [ [ '', t.wpmlAuto ], [ 'copy', t.wpmlCopy ], [ 'translate', t.wpmlFieldTranslate ], [ 'ignore', t.wpmlFieldIgnore ] ], ( v ) => { if ( v ) { field.wpml = v; } else { delete field.wpml; } touch(); }, { 'data-key': 'field-wpml' } ),
				choice( t.fieldGroup, field.group || '', M.groupChoices( draft.groups, field.group, mainLang(), t.noGroup ), ( v ) => { if ( v ) { field.group = v; } else { delete field.group; } touch(); }, { 'data-key': 'field-group' } ),
				own,
			];
		}

		let dragFrom = null;
		function fieldRow( field, i ) {
			const last = i === draft.fields.length - 1;
			const bodyId = uid( 'field-body' );
			const isOpen = open.has( field );
			const labelCell = el( 'span', { class: 'ewct-col-label' } );
			const nameCell = el( 'code', { class: 'ewct-col-name' } );
			const typeCell = el( 'span', { class: 'ewct-col-type' } );
			const refreshHead = () => {
				labelCell.textContent = text( field.label, mainLang() ) || t.noLabel;
				nameCell.textContent = field.name || '';
				typeCell.textContent = t.fieldTypes[ field.type ] || field.type || '';
			};
			refreshHead();
			const body = el( 'div', { class: 'ewct-field-body', id: bodyId, hidden: ! isOpen }, fieldBody( field, i, refreshHead ) );
			const toggle = el( 'button', { type: 'button', class: 'ewct-field-toggle', 'aria-expanded': isOpen ? 'true' : 'false', 'aria-controls': bodyId, 'data-action': 'toggle-field' }, labelCell, nameCell, typeCell );
			const setOpen = ( on ) => {
				if ( on ) {
					open.add( field );
				} else {
					open.delete( field );
				}
				body.hidden = ! on;
				toggle.setAttribute( 'aria-expanded', on ? 'true' : 'false' );
				row.classList.toggle( 'is-open', on );
				if ( on ) {
					const first = body.querySelector( 'input:not([readonly]), select, textarea' );
					first && first.focus();
				}
			};
			toggle.addEventListener( 'click', () => setOpen( ! open.has( field ) ) );
			toggle.addEventListener( 'keydown', ( e ) => {
				if ( e.altKey && ( e.key === 'ArrowUp' || e.key === 'ArrowDown' ) ) {
					e.preventDefault();
					moveField( i, e.key === 'ArrowUp' ? i - 1 : i + 1 );
				}
			} );
			const label = fieldLabel( field );
			const handle = el( 'span', { class: 'ewct-handle dashicons dashicons-menu', 'aria-hidden': 'true', title: t.dragHandle, 'data-action': 'drag' } );
			const head = el(
				'div',
				{ class: 'ewct-field-head' },
				handle,
				toggle,
				el(
					'span',
					{ class: 'ewct-field-buttons' },
					el( 'button', { type: 'button', class: 'button-link ewct-icon', 'aria-label': fill( t.moveUpField, label ), title: t.moveUp, disabled: i === 0, 'data-action': 'move-up', onclick: () => moveField( i, i - 1, '[data-action="move-up"]' ) }, el( 'span', { class: 'dashicons dashicons-arrow-up-alt2', 'aria-hidden': 'true' } ) ),
					el( 'button', { type: 'button', class: 'button-link ewct-icon', 'aria-label': fill( t.moveDownField, label ), title: t.moveDown, disabled: last, 'data-action': 'move-down', onclick: () => moveField( i, i + 1, '[data-action="move-down"]' ) }, el( 'span', { class: 'dashicons dashicons-arrow-down-alt2', 'aria-hidden': 'true' } ) ),
					el( 'button', { type: 'button', class: 'button-link button-link-delete ewct-icon', 'aria-label': fill( t.removeField, label ), title: t.remove, 'data-action': 'remove', onclick: () => removeField( i ) }, el( 'span', { class: 'dashicons dashicons-trash', 'aria-hidden': 'true' } ) )
				)
			);
			// A click on the row's empty space opens it too (ACF); the buttons and the handle do their own thing.
			head.addEventListener( 'click', ( e ) => {
				if ( e.target === head ) {
					setOpen( ! open.has( field ) );
				}
			} );
			const row = el( 'li', { class: 'ewct-field' + ( isOpen ? ' is-open' : '' ), 'data-field': i }, head, body );

			// Drag and drop, by the handle only (the trap in the header).
			handle.addEventListener( 'mousedown', () => ( row.draggable = true ) );
			handle.addEventListener( 'mouseup', () => ( row.draggable = false ) );
			const clear = () => row.classList.remove( 'drop-before', 'drop-after' );
			const after = ( e ) => {
				const box = row.getBoundingClientRect();
				return e.clientY > box.top + box.height / 2;
			};
			row.addEventListener( 'dragstart', ( e ) => {
				dragFrom = i;
				e.dataTransfer.effectAllowed = 'move';
				e.dataTransfer.setData( 'text/plain', String( i ) );
				row.classList.add( 'is-dragging' );
			} );
			row.addEventListener( 'dragover', ( e ) => {
				if ( dragFrom === null ) {
					return;
				}
				e.preventDefault();
				e.dataTransfer.dropEffect = 'move';
				const a = after( e );
				row.classList.toggle( 'drop-after', a );
				row.classList.toggle( 'drop-before', ! a );
			} );
			row.addEventListener( 'dragleave', clear );
			row.addEventListener( 'drop', ( e ) => {
				e.preventDefault();
				clear();
				if ( dragFrom === null ) {
					return;
				}
				const at = after( e ) ? i + 1 : i;
				const to = dragFrom < at ? at - 1 : at;
				const from = dragFrom;
				dragFrom = null;
				moveField( from, to );
			} );
			row.addEventListener( 'dragend', () => {
				dragFrom = null;
				row.draggable = false;
				row.classList.remove( 'is-dragging' );
				fieldsList.querySelectorAll( '.drop-before, .drop-after' ).forEach( ( r ) => r.classList.remove( 'drop-before', 'drop-after' ) );
			} );
			return row;
		}

		function paintFields() {
			fieldsList.replaceChildren( ...draft.fields.map( fieldRow ) );
			noFields.hidden = draft.fields.length > 0;
			refreshJson();
		}

		/*
		 * NEW FIELDS OF THE TEMPLATE (22-10-2026): a type copied from a template
		 * before the template got more fields is OFFERED them, never given them
		 * by itself (why: Templates::missing_fields()). The button puts them
		 * into the draft after the field that precedes each in the template,
		 * as Templates::add_missing() does; the person saves as always.
		 */
		const offer = el( 'div', { 'data-template-fields': '' } );
		function offerSection() {
			const missing = ( ( item && item.missingFields ) || [] ).filter( ( m ) => ! draft.fields.some( ( f ) => f && f.name === m.field.name ) );
			if ( ! missing.length ) {
				return [];
			}
			const names = missing.map( ( m ) => text( m.field.label, mainLang() ) || m.field.name ).join( ', ' );
			return [
				el(
					'div',
					{ class: 'notice notice-info inline' },
					el( 'p', {}, fill( t.templateNewFields, missing.length, names ) ),
					el( 'p', {}, button( t.templateAddFields, () => {
						let first = null;
						for ( const m of missing ) {
							const after = m.after === null ? -1 : draft.fields.findIndex( ( f ) => f && f.name === m.after );
							const at = m.after === null ? 0 : after >= 0 ? after + 1 : draft.fields.length;
							draft.fields.splice( at, 0, JSON.parse( JSON.stringify( m.field ) ) );
							first = first === null ? at : Math.min( first, at );
						}
						touch();
						offer.replaceChildren( ...offerSection() );
						paintFields();
						focusIn( first, '.ewct-field-toggle' );
					}, { 'data-action': 'add-template-fields' } ) )
				),
			];
		}

		function paintAll() {
			offer.replaceChildren( ...offerSection() );
			for ( const [ key ] of TABS ) {
				paintPanel( key );
			}
			selectTab( activeTab );
			paintFields();
		}

		const savedNotice = opts.saved ? el( 'div', { class: 'notice notice-success inline', tabindex: '-1', 'data-saved': '' }, el( 'p', {}, opts.saved ) ) : null;
		const form = el(
			'form',
			{ class: 'ewct-form', novalidate: true, onsubmit: ( e ) => { e.preventDefault(); save( item, draft, false, () => activeTab, ( ok ) => ( dirty = ! ok ) ); } },
			offer,
			opts.file ? el( 'div', { class: 'notice notice-info inline', 'data-file-notice': '' }, el( 'p', {}, t.fileNotice ) ) : null,
			el( 'section', { class: 'ewct-box', 'aria-labelledby': 'ewct-settings-title' }, el( 'h2', { id: 'ewct-settings-title', class: 'ewct-box-title' }, t.settings ), tablist, TABS.map( ( [ k ] ) => panels[ k ] ) ),
			el(
				'section',
				{ class: 'ewct-box', 'aria-labelledby': 'ewct-fields-title' },
				el( 'h2', { id: 'ewct-fields-title', class: 'ewct-box-title' }, t.sectionFields ),
				el( 'p', { class: 'description', id: 'ewct-reorder-help' }, t.reorderHelp ),
				el( 'div', { class: 'ewct-fields-head', 'aria-hidden': 'true' }, el( 'span', {}, t.colLabel ), el( 'span', {}, t.colFieldName ), el( 'span', {}, t.colFieldType ) ),
				fieldsList,
				noFields,
				el( 'p', { class: 'ewct-add-field' }, addFieldButton )
			),
			el( 'p', { class: 'submit' }, el( 'button', { type: 'submit', class: 'button button-primary button-large', 'data-action': 'save' }, t.save ) )
		);
		form.addEventListener( 'input', () => ( dirty = true ) );
		root.replaceChildren( status, alertBox, savedNotice, form );
		paintAll();
		window.onbeforeunload = ( e ) => {
			if ( dirty ) {
				e.preventDefault();
				e.returnValue = '';
				return '';
			}
			return undefined;
		};
		if ( savedNotice ) {
			savedNotice.focus();
		} else if ( opts.importing ) {
			const input = panels.advanced.querySelector( '[data-key="import-file"]' );
			input && input.focus();
		}
	}

	async function save( item, draft, confirm, tab, after ) {
		quiet();
		say( t.saving );
		try {
			const res = await api( item ? '/' + item.name : '', { method: 'POST', data: { definition: draft, confirm } } );
			data = await api( '' );
			const saved = data.types.find( ( x ) => x.name === res.name ) || res;
			after( true );
			const made = res.redirects && res.redirects.created ? ' ' + fill( t.redirectsMade, res.redirects.created ) : '';
			window.history.replaceState( null, '', M.editUrl( config.listUrl, saved.name ) );
			const title = document.getElementById( 'ewct-title' );
			if ( title ) {
				title.textContent = fill( t.editing, text( saved.definition && saved.definition.labels && saved.definition.labels.plural, mainLang() ) || saved.name );
			}
			say( '' );
			editor( saved, clone( saved.definition ), { tab: tab(), saved: t.saved + made } );
		} catch ( e ) {
			say( '' );
			if ( e.code === 'estrategia_web_ct_confirm' ) {
				alarm( t.confirmTitle, ( e.data && e.data.warnings ) || [], [
					button( t.confirmSave, () => save( item, draft, true, tab, after ), { class: 'button button-primary', 'data-action': 'confirm' } ),
					' ',
					button( t.cancel, () => quiet(), { 'data-action': 'cancel' } ),
				] );
				return;
			}
			alarm( t.errorsTitle, [ e.message ].concat( ( e.data && e.data.errors ) || [] ) );
		}
	}

	if ( root.dataset.view === 'list' ) {
		listScreen();
	} else {
		editScreen();
	}
} )( window.wp, window.estrategiaWebConstructor, window.estrategiaWebConstructorModel );
