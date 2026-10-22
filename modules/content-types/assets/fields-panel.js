/**
 * The «<Singular> details» panel in the document sidebar for the types
 * declared in JSON. The whole explanation (why, WPML, the classic screen) is
 * in includes/class-fields-panel.php.
 *
 * NO BUILD STEP, like modules/site/assets/meta-panel.js: a classic script
 * that reads the wp.* globals. Its dependencies are in Fields_Panel::enqueue();
 * a new wp.x here goes there too, or the editor says «wp.x is undefined».
 * The fields come from PHP in window.estrategiaWebContentTypesPanel.
 *
 * How to add a field type: a `case` in Field() below, the same in
 * Fields_Panel::render_meta_box() (classic screen), and the rest in the
 * header of module.php.
 *
 * The trap: an EMPTIED number must travel as null (which deletes the meta,
 * so it goes back to its default). An empty string is refused by the REST
 * API («is not of type number») and the whole save fails, with the post left
 * unsaved. (Measured 15-10-2026 on WP 7.1: a numeric string such as "2019"
 * IS accepted and converted; it is the "" that breaks. Numbers are still
 * sent as numbers.)
 */
( function ( wp, config ) {
	if ( ! wp || ! wp.plugins || ! config ) {
		return;
	}
	const { createElement: h } = wp.element;
	const { useSelect, useDispatch } = wp.data;
	const { TextControl, TextareaControl, SelectControl, CheckboxControl, Button, BaseControl } = wp.components;
	const { MediaUpload, MediaUploadCheck } = wp.blockEditor;
	const { __ } = wp.i18n;
	const Panel = ( wp.editor && wp.editor.PluginDocumentSettingPanel ) || ( wp.editPost && wp.editPost.PluginDocumentSettingPanel );
	if ( ! Panel ) {
		return;
	}
	const idOf = ( field ) => 'estrategia-web-ct-' + field.key;
	const labelOf = ( field ) => field.label + ( field.required ? ' *' : '' );

	function ImageField( { field, value, onChange } ) {
		const media = useSelect( ( select ) => ( value ? select( 'core' ).getMedia( value ) : null ), [ value ] );
		return h(
			BaseControl,
			{ id: idOf( field ), label: labelOf( field ), help: field.help, __nextHasNoMarginBottom: true },
			media && media.source_url ? h( 'img', { src: media.source_url, alt: '', style: { display: 'block', maxWidth: '100%', marginBottom: '8px' } } ) : null,
			field.disabled
				? null
				: h(
						MediaUploadCheck,
						null,
						h( MediaUpload, {
							allowedTypes: [ 'image' ],
							value,
							onSelect: ( m ) => onChange( m && m.id ? m.id : 0 ),
							render: ( { open } ) => h( Button, { variant: 'secondary', onClick: open, id: idOf( field ) }, value ? __( 'Replace image', 'estrategia-web' ) : __( 'Choose image', 'estrategia-web' ) ),
						} ),
						value ? h( Button, { variant: 'link', isDestructive: true, onClick: () => onChange( 0 ), style: { marginLeft: '8px' } }, __( 'Remove', 'estrategia-web' ) ) : null
				  )
		);
	}

	function RelationField( { field, value, onChange } ) {
		const posts = useSelect(
			( select ) =>
				field.postTypes.flatMap( ( type ) => select( 'core' ).getEntityRecords( 'postType', type, { per_page: 100, orderby: 'title', order: 'asc', status: 'publish,draft,private', _fields: 'id,title' } ) || [] ),
			[]
		);
		const options = [ { value: '0', label: __( '— None —', 'estrategia-web' ) } ].concat(
			posts.map( ( p ) => ( { value: String( p.id ), label: ( p.title && p.title.rendered ) || '#' + p.id } ) )
		);
		return h( SelectControl, { label: labelOf( field ), help: field.help, disabled: field.disabled, value: String( value || 0 ), options, onChange: ( v ) => onChange( parseInt( v, 10 ) || 0 ), __nextHasNoMarginBottom: true, __next40pxDefaultSize: true } );
	}

	/*
	 * hours (22-10-2026): one group per day, each range two time inputs. The
	 * value travels as the JSON text that is stored ({"mo":["08:00-14:00"]});
	 * the server sanitizes it on save (includes/class-hours.php), so a range
	 * that would be dropped there is marked HERE before saving, with the same
	 * rule as Hours::error(): HH:MM-HH:MM and opening ≠ closing.
	 * Accessibility: each day is a fieldset with its name as legend; each
	 * input says its day and range number in its label; the warning is tied
	 * to the range with aria-describedby.
	 */
	const DAYS = [ 'mo', 'tu', 'we', 'th', 'fr', 'sa', 'su' ];
	const TIME = /^([01]\d|2[0-3]):[0-5]\d$/;
	const parseWeek = ( value ) => {
		try {
			const week = value ? JSON.parse( value ) : {};
			return week && typeof week === 'object' && ! Array.isArray( week ) ? week : {};
		} catch ( e ) {
			return {};
		}
	};
	const rangeError = ( range ) => {
		const [ open, close ] = String( range ).split( '-' );
		if ( ! TIME.test( open || '' ) || ! TIME.test( close || '' ) ) {
			return __( 'Incomplete: this range will not be saved.', 'estrategia-web' );
		}
		return open === close ? __( 'Opening and closing are the same time: this range will not be saved.', 'estrategia-web' ) : '';
	};

	function HoursField( { field, value, onChange } ) {
		const week = parseWeek( value );
		const names = config.days || DAYS;
		const save = ( next ) => {
			const out = {};
			DAYS.forEach( ( d ) => {
				if ( Array.isArray( next[ d ] ) && next[ d ].length ) {
					out[ d ] = next[ d ];
				}
			} );
			onChange( Object.keys( out ).length ? JSON.stringify( out ) : '' );
		};
		const setDay = ( day, ranges ) => save( { ...week, [ day ]: ranges } );
		const timeInput = ( day, i, part, ranges ) => {
			const [ open, close ] = String( ranges[ i ] ).split( '-' );
			const id = idOf( field ) + '-' + day + '-' + i + '-' + part;
			/* translators: 1: a day of the week, 2: the number of the range in that day */
			const label = ( part === 0 ? __( '%1$s, range %2$d: opens', 'estrategia-web' ) : __( '%1$s, range %2$d: closes', 'estrategia-web' ) ).replace( '%1$s', names[ DAYS.indexOf( day ) ] ).replace( '%2$d', i + 1 );
			return h(
				'span',
				{ style: { display: 'inline-flex', flexDirection: 'column', marginRight: '8px' } },
				h( 'label', { htmlFor: id, className: 'screen-reader-text' }, label ),
				h( 'input', {
					type: 'time',
					id,
					value: ( part === 0 ? open : close ) || '',
					disabled: field.disabled,
					'aria-describedby': rangeError( ranges[ i ] ) ? id.replace( /-\d$/, '' ) + '-error' : undefined,
					onChange: ( e ) => {
						const next = ranges.slice();
						next[ i ] = part === 0 ? e.target.value + '-' + ( close || '' ) : ( open || '' ) + '-' + e.target.value;
						setDay( day, next );
					},
				} )
			);
		};
		return h(
			'fieldset',
			{ id: idOf( field ), className: 'estrategia-web-hours' },
			h( 'legend', { style: { fontWeight: 600, marginBottom: '4px' } }, labelOf( field ) ),
			field.help ? h( 'p', { className: 'components-base-control__help', style: { marginTop: 0 } }, field.help ) : null,
			DAYS.map( ( day, d ) => {
				const ranges = Array.isArray( week[ day ] ) ? week[ day ] : [];
				return h(
					'fieldset',
					{ key: day, 'data-day': day, style: { border: 0, padding: 0, margin: '0 0 8px' } },
					h( 'legend', { style: { fontWeight: 500 } }, names[ d ] ),
					ranges.length ? null : h( 'p', { style: { margin: '2px 0', color: '#757575' } }, __( 'Closed', 'estrategia-web' ) ),
					ranges.map( ( range, i ) => {
						const error = rangeError( range );
						return h(
							'div',
							{ key: i, 'data-range': i, style: { marginBottom: '4px' } },
							timeInput( day, i, 0, ranges ),
							timeInput( day, i, 1, ranges ),
							field.disabled
								? null
								: h( Button, {
										variant: 'link',
										isDestructive: true,
										/* translators: 1: a day of the week, 2: the number of the range in that day */
										label: __( 'Remove %1$s, range %2$d', 'estrategia-web' ).replace( '%1$s', names[ d ] ).replace( '%2$d', i + 1 ),
										onClick: () => setDay( day, ranges.filter( ( x, j ) => j !== i ) ),
								  }, __( 'Remove', 'estrategia-web' ) ),
							error ? h( 'p', { id: idOf( field ) + '-' + day + '-' + i + '-error', role: 'status', style: { color: '#cc1818', margin: '2px 0' } }, error ) : null
						);
					} ),
					field.disabled
						? null
						: h(
								'div',
								null,
								h( Button, {
									variant: 'secondary',
									size: 'small',
									'data-action': 'add-range',
									/* translators: %s: a day of the week. Keep the words of «Add hours» in it: it is the button's visible text. */
									label: __( 'Add hours on %s', 'estrategia-web' ).replace( '%s', names[ d ] ),
									onClick: () => setDay( day, ranges.concat( [ ranges.length ? '16:00-20:00' : '09:00-14:00' ] ) ),
								}, __( 'Add hours', 'estrategia-web' ) ),
								d > 0
									? h( Button, {
											variant: 'tertiary',
											size: 'small',
											'data-action': 'copy-previous',
											/* translators: %s: a day of the week. Keep the words of «Same as the day before» in it: it is the button's visible text. */
											label: __( '%s: same as the day before', 'estrategia-web' ).replace( '%s', names[ d ] ),
											onClick: () => setDay( day, ( Array.isArray( week[ DAYS[ d - 1 ] ] ) ? week[ DAYS[ d - 1 ] ] : [] ).slice() ),
									  }, __( 'Same as the day before', 'estrategia-web' ) )
									: null
						  )
				);
			} )
		);
	}

	function Field( { field, value, onChange } ) {
		const common = { label: labelOf( field ), help: field.help, disabled: field.disabled, __nextHasNoMarginBottom: true };
		switch ( field.kind ) {
			case 'textarea':
			case 'embed':
				return h( TextareaControl, { ...common, value: value || '', rows: 3, onChange } );
			case 'rating':
				// The choices (No rating, ★ … ★★★★★) come from PHP: Fields_Panel::rating_options().
				return h( SelectControl, { ...common, __next40pxDefaultSize: true, value: String( value || 0 ), options: field.options, onChange: ( v ) => onChange( parseInt( v, 10 ) || 0 ) } );
			case 'select': {
				const none = ! field.required && field.default === '' ? [ { value: '', label: __( '— None —', 'estrategia-web' ) } ] : [];
				return h( SelectControl, { ...common, __next40pxDefaultSize: true, value: value === undefined || value === null ? field.default : String( value ), options: none.concat( field.options ), onChange } );
			}
			case 'boolean':
				return h( CheckboxControl, { ...common, checked: !! value, onChange: ( v ) => onChange( !! v ) } );
			case 'number':
				return h( TextControl, {
					...common,
					__next40pxDefaultSize: true,
					type: 'number',
					min: field.min === null ? undefined : field.min,
					max: field.max === null ? undefined : field.max,
					step: field.integer ? 1 : 'any',
					value: value === undefined || value === null ? '' : String( value ),
					onChange: ( v ) => onChange( v === '' ? null : field.integer ? parseInt( v, 10 ) : parseFloat( v ) ),
				} );
			case 'relation':
				return h( RelationField, { field, value, onChange } );
			case 'image':
				return h( ImageField, { field, value, onChange } );
			case 'hours':
				return h( HoursField, { field, value, onChange } );
			default: {
				const types = { url: 'url', email: 'email', tel: 'tel', date: 'date' };
				return h( TextControl, { ...common, __next40pxDefaultSize: true, type: types[ field.kind ] || 'text', value: value || '', onChange } );
			}
		}
	}

	let openedOnce = false;

	function FieldsPanel() {
		const { type, meta } = useSelect( ( select ) => ( {
			type: select( 'core/editor' ).getCurrentPostType(),
			meta: select( 'core/editor' ).getEditedPostAttribute( 'meta' ) || {},
		} ) );
		const { editPost } = useDispatch( 'core/editor' );
		// Open on arrival: a plugin panel is born CLOSED, and these fields are
		// the main data of the post (modules/site, 07-10-2026). Here and not
		// loose: when this script loads, the user's preferences are not there yet.
		wp.element.useEffect( () => {
			const PANEL = 'estrategia-web-content-types/estrategia-web-content-types';
			const editor = wp.data.select( 'core/editor' );
			if ( ! openedOnce && editor.isEditorPanelOpened && ! editor.isEditorPanelOpened( PANEL ) ) {
				wp.data.dispatch( 'core/editor' ).toggleEditorPanelOpened( PANEL );
			}
			openedOnce = true;
		}, [] );
		if ( type !== config.postType ) {
			return null;
		}
		return h(
			Panel,
			{ name: 'estrategia-web-content-types', title: config.title, className: 'estrategia-web-content-types-panel' },
			config.note ? h( 'p', null, config.note ) : null,
			config.fields.map( ( field ) =>
				h( 'div', { key: field.key, style: { marginBottom: '16px' }, 'data-meta-key': field.key }, h( Field, { field, value: meta[ field.key ], onChange: ( v ) => editPost( { meta: { [ field.key ]: v } } ) } ) )
			)
		);
	}

	wp.plugins.registerPlugin( 'estrategia-web-content-types', { render: FieldsPanel } );
} )( window.wp, window.estrategiaWebContentTypesPanel );
