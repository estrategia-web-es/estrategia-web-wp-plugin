/**
 * The EDITOR half of the Block Bindings source `estrategia-web/content-field`
 * (the site half: includes/class-bindings.php, with the rules in
 * includes/class-field-format.php).
 *
 * What it does: a bound block SHOWS the formatted value in the editor canvas
 * (getValues) — the option's label, the image's URL, the related post's
 * title, «Yes»/«No», the date and the number formatted — and the source
 * appears in the block's «Attributes» panel with the fields of the type in
 * context (getFieldsList). The field definitions, already in the editor's
 * language, arrive from PHP in window.estrategiaWebContentField.
 *
 * NO BUILD STEP, like modules/site/assets/editor.js. No texts of its own:
 * labels, «Yes» and «No» come translated from PHP.
 *
 * How to maintain:
 * - Who and when: whoever changes a rule in Field_Format::format(), in the
 *   same commit: this is its copy for the editor, and the level 3 test
 *   (tests/bindings-editor.test.mjs) is what compares them.
 * - Boundary case, with a name: the value is not there yet (an image or a
 *   related post the editor is still fetching) or is empty. A text shows
 *   «[Field label]» so you can see what is bound; a URL or an ID gives ""
 *   (a bracketed URL would be a broken image).
 * - Differences with the site, on purpose: the date uses wp.date (the
 *   editor's locale: the user's language) and the number Intl.NumberFormat;
 *   the site uses the site's date format and number_format_i18n. Same
 *   format string, so in practice the same text.
 * - The traps: usesContext has to repeat PHP's, or core merges it as [null]
 *   and the Query row never arrives; and the panel filters fields by TYPE
 *   (string for content and url, number for core/image's «id»), so image
 *   and relation fields are listed twice, once as each.
 */
( function ( wp, config ) {
	if ( ! wp || ! wp.blocks || ! wp.blocks.registerBlockBindingsSource || ! config ) {
		return;
	}
	const URL_ATTRIBUTES = [ 'url', 'href' ];
	const escapeHtml = ( s ) =>
		String( s ).replace( /[&<>"']/g, ( c ) => ( { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' } )[ c ] );

	const fieldOf = ( postType, key ) => ( config.types[ postType ] || [] ).find( ( f ) => f.key === key );

	const href = ( kind, value ) => {
		if ( ! value ) {
			return '';
		}
		if ( kind === 'email' ) {
			return value.includes( '@' ) ? 'mailto:' + value : '';
		}
		if ( kind === 'tel' ) {
			const digits = value.replace( /[^0-9+]/g, '' );
			return digits ? 'tel:' + digits : '';
		}
		return /^https?:\/\//i.test( value ) ? value : '';
	};

	const decimals = ( field, stored, args ) => {
		if ( args.decimals !== undefined && ! isNaN( Number( args.decimals ) ) ) {
			return Math.max( 0, Math.min( 6, parseInt( args.decimals, 10 ) ) );
		}
		if ( field.integer ) {
			return 0;
		}
		const fraction = String( stored ).includes( '.' ) ? String( stored ).split( '.' )[ 1 ].replace( /0+$/, '' ) : '';
		return Math.min( 6, fraction.length );
	};

	// The same rules as Field_Format::format(). undefined = «not known (yet)».
	// html: the attribute is the block's HTML (escaped here) or an HTML attribute (plain): see PHP's is_html().
	const format = ( select, field, raw, attribute, args, html ) => {
		const kind = URL_ATTRIBUTES.includes( attribute ) ? 'url' : attribute === 'id' ? 'id' : 'text';
		const link = Boolean( args.link ) && kind === 'text' && html;
		const str = raw === undefined || raw === null || typeof raw === 'object' ? '' : String( raw ).trim();
		const core = select( 'core' );
		const escape = html ? escapeHtml : String;		switch ( field.kind ) {
			case 'text':
			case 'textarea':
				if ( kind !== 'text' ) {
					return kind === 'url' ? str : '';
				}
				return field.kind === 'textarea' && html ? escape( str ).replace( /\r?\n/g, '<br>' ) : escape( str );
			case 'rating':
				// The number, not stars (Field_Format: a screen reader reads «black star» four times). 0 = no rating.
				return kind === 'text' && parseInt( str, 10 ) > 0 ? escape( String( Math.min( 5, parseInt( str, 10 ) ) ) ) : '';
			case 'local_image':
				if ( kind === 'id' || ! str ) {
					return '';
				}
				if ( kind !== 'url' ) {
					return escape( str );
				}
				return /^(https?:)?\//i.test( str ) ? str : config.themeUrl + '/' + str.replace( /^\.\//, '' );
			case 'url':
			case 'embed':
			case 'email':
			case 'tel': {
				const h = href( field.kind, str );
				if ( kind === 'url' ) {
					return h;
				}
				if ( kind === 'id' || ! h ) {
					return '';
				}
				return link ? '<a href="' + escapeHtml( h ) + '">' + escapeHtml( str ) + '</a>' : escape( str );
			}
			case 'number': {
				if ( kind !== 'text' || str === '' || isNaN( Number( str ) ) ) {
					return '';
				}
				const d = decimals( field, str, args );
				try {
					return escape( new Intl.NumberFormat( config.locale, { minimumFractionDigits: d, maximumFractionDigits: d } ).format( Number( str ) ) );
				} catch ( e ) {
					return escape( Number( str ).toFixed( d ) );
				}
			}
			case 'date':
				if ( kind !== 'text' || ! /^\d{4}-\d{2}-\d{2}$/.test( str ) ) {
					return '';
				}
				// Noon UTC: whatever the browser's timezone, it is still that day.
				return escape( wp.date.dateI18n( args.format || config.dateFormat, str + 'T12:00:00Z', 'UTC' ) );
			case 'select':
				if ( kind !== 'text' || str === '' ) {
					return '';
				}
				return escape( field.options[ str ] !== undefined ? field.options[ str ] : str );
			case 'boolean':
				if ( kind !== 'text' ) {
					return '';
				}
				return escape( raw === true || raw === 1 || raw === '1' || raw === 'true' ? field.yes : field.no );
			case 'image': {
				const id = parseInt( str, 10 );
				if ( ! ( id > 0 ) ) {
					return '';
				}
				if ( kind === 'id' ) {
					return id;
				}
				const media = core.getMedia( id, { context: 'view' } );
				if ( ! media ) {
					return undefined;
				}
				if ( kind === 'url' ) {
					const size = args.size && media.media_details && media.media_details.sizes && media.media_details.sizes[ args.size ];
					return size ? size.source_url : media.source_url;
				}
				return escape( attribute === 'title' ? ( media.title && media.title.rendered ) || '' : media.alt_text || '' );
			}
			case 'relation': {
				const id = parseInt( str, 10 );
				if ( ! ( id > 0 ) ) {
					return '';
				}
				if ( kind === 'id' ) {
					return id;
				}
				let post;
				for ( const type of field.postTypes ) {
					post = core.getEntityRecord( 'postType', type, id );
					if ( post ) {
						break;
					}
				}
				if ( ! post ) {
					return undefined;
				}
				if ( kind === 'url' ) {
					return post.link || '';
				}
				// The title comes rendered (HTML entities): not escaped again in HTML, decoded for an attribute.
				let title = ( post.title && ( post.title.rendered ?? post.title ) ) || '';
				if ( ! html ) {
					const decoder = document.createElement( 'textarea' );
					decoder.innerHTML = title;
					title = decoder.value;
				}
				return link ? '<a href="' + escapeHtml( post.link || '' ) + '">' + title + '</a>' : title;
			}
		}
		return '';
	};

	wp.blocks.registerBlockBindingsSource( {
		name: config.source,
		usesContext: [ 'postId', 'postType' ],
		getValues( { select, context, bindings, clientId } ) {
			const out = {};
			const name = clientId && select( 'core/block-editor' ).getBlockName( clientId );
			const type = name && wp.blocks.getBlockType( name );
			for ( const [ attribute, binding ] of Object.entries( bindings || {} ) ) {
				const args = ( binding && binding.args ) || {};
				const field = fieldOf( context && context.postType, args.key );
				const spec = type && type.attributes && type.attributes[ attribute ];
				const html = ! ( spec && spec.source === 'attribute' );
				let value = '';
				if ( field && context.postId ) {
					const record = select( 'core' ).getEditedEntityRecord( 'postType', context.postType, context.postId );
					value = format( select, field, record && record.meta ? record.meta[ args.key ] : undefined, attribute, args, html );
				}
				if ( ( value === '' || value === undefined ) && ! URL_ATTRIBUTES.includes( attribute ) && attribute !== 'id' ) {
					value = '[' + ( field ? field.label : args.key || config.source ) + ']';
				}
				out[ attribute ] = value === undefined ? '' : value;
			}
			return out;
		},
		canUserEditValue() {
			return false;
		},
		getFieldsList( { context } ) {
			const fields = config.types[ context && context.postType ] || [];
			const list = fields.map( ( f ) => ( { label: f.label, type: 'string', args: { key: f.key } } ) );
			for ( const f of fields ) {
				if ( f.kind === 'image' || f.kind === 'relation' ) {
					list.push( { label: f.label, type: 'number', args: { key: f.key } } );
				}
			}
			return list;
		},
	} );
} )( window.wp, window.estrategiaWebContentField );
