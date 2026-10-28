/**
 * THE CONSTRUCTOR'S PURE PART (1.8.0): what assets/constructor.js does to a
 * definition without touching the page — texts per language, the address
 * per language, moving a field, the choices of a field's «group», the URL
 * of a type's edit screen. Split out so it is tested at level 1 without a
 * browser (tests/constructor-model.test.mjs); the page uses it through
 * window.estrategiaWebConstructorModel (a classic script, NO BUILD STEP,
 * enqueued before constructor.js by Page::enqueue()).
 *
 * How to maintain:
 * - Who and when: whoever adds to the constructor a rule that decides WHAT
 *   is written into the draft (not how it is shown). It goes here, with its
 *   case in tests/constructor-model.test.mjs; the DOM stays in constructor.js.
 * - Boundary case, with a name: a «slug» that is a plain string
 *   ("restaurantes") when someone types the address of a SECOND language.
 *   It becomes {"<first>": "restaurantes", "<second>": "…"}; the first
 *   language alone keeps it a string (setSlug()). Never the other way round:
 *   an object is not turned back into a string by emptying the others.
 * - The trap: this file is loaded by WordPress as a classic script AND by
 *   Node with require() (the plugin has no package.json, so .js is
 *   CommonJS). No import/export, no DOM, no wp.* here: the last lines pick
 *   module.exports or the window global.
 */
( function ( root ) {
	// «%s», «%d», «%1$s»: the placeholders of the PHP texts.
	const fill = ( textValue, ...args ) => {
		let i = 0;
		return String( textValue ).replace( /%(?:(\d)\$)?[sd]/g, ( m, n ) => String( n ? args[ n - 1 ] : args[ i++ ] ) );
	};

	/** A label in a language: exact, then the two-letter code, then «en», then the first one (Schema::text). */
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

	/** Writes one language of a text key («label», «singular»…) into holder; a plain string becomes {main: string}. */
	function setText( holder, key, lang, value, main ) {
		let current = holder[ key ];
		if ( typeof current === 'string' ) {
			current = { [ main ]: current };
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

	/** The value of one language for an input: a plain string belongs to the first language. */
	function getText( holder, key, lang, main ) {
		const value = holder[ key ];
		if ( typeof value === 'string' ) {
			return lang === main ? value : '';
		}
		if ( ! value || typeof value !== 'object' ) {
			return '';
		}
		const k = Object.keys( value ).find( ( x ) => x === lang || x.split( '_' )[ 0 ] === lang );
		return k ? value[ k ] : '';
	}

	/** The address of a type or taxonomy in one language (see the boundary case in the header). */
	function setSlug( holder, lang, value, main ) {
		if ( lang === main && ( holder.slug === undefined || typeof holder.slug === 'string' ) ) {
			if ( value ) {
				holder.slug = value;
			} else {
				delete holder.slug;
			}
			return;
		}
		setText( holder, 'slug', lang, value, main );
	}

	/** Moves list[from] to position «to» in place. False (and nothing moved) when either is out of the list. */
	function move( list, from, to ) {
		if ( ! Array.isArray( list ) || from === to || from < 0 || to < 0 || from >= list.length || to >= list.length ) {
			return false;
		}
		const [ item ] = list.splice( from, 1 );
		list.splice( to, 0, item );
		return true;
	}

	/** A group key from its label: «Dirección y mapa» → «direccion_y_mapa»; never repeats one in «taken». */
	function keyFrom( label, taken = [] ) {
		let base = String( label || '' )
			.normalize( 'NFD' )
			.replace( /[̀-ͯ]/g, '' )
			.toLowerCase()
			.replace( /[^a-z0-9]+/g, '_' )
			.replace( /^[^a-z]+|_+$/g, '' );
		base = base || 'group';
		let key = base;
		for ( let n = 2; taken.includes( key ); n++ ) {
			key = base + '_' + n;
		}
		return key;
	}

	/**
	 * The options of a field's «group» select: none, then the definition's
	 * groups in order; a key the definition no longer declares is kept as an
	 * option of its own (the server will name the error; the form must not
	 * silently change the value).
	 *
	 * @return {Array<[string,string]>}
	 */
	function groupChoices( groups, current, lang, noneLabel ) {
		const list = Array.isArray( groups ) ? groups.filter( ( g ) => g && typeof g.key === 'string' && g.key ) : [];
		const out = [ [ '', noneLabel ] ].concat( list.map( ( g ) => [ g.key, text( g.label, lang ) || g.key ] ) );
		if ( current && ! list.some( ( g ) => g.key === current ) ) {
			out.push( [ current, current ] );
		}
		return out;
	}

	/** The edit screen of one type: the list's URL plus &type=<name>. */
	function editUrl( listUrl, name ) {
		return listUrl + ( listUrl.includes( '?' ) ? '&' : '?' ) + 'type=' + encodeURIComponent( name );
	}

	const api = { fill, text, setText, getText, setSlug, move, keyFrom, groupChoices, editUrl };
	if ( typeof module === 'object' && module.exports ) {
		module.exports = api;
	} else {
		root.estrategiaWebConstructorModel = api;
	}
} )( typeof window !== 'undefined' ? window : globalThis );
