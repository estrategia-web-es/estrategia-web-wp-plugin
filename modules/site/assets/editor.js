/**
 * The EDITOR half of the plugin's two Block Bindings sources (the site half
 * is in PHP):
 *
 *   estrategia-web-site/company   company details       includes/class-bindings.php
 *   estrategia-web-site/location  details of ONE location  includes/class-location.php  (0.2.0)
 *
 * What it does: makes a bound block SHOW the value in the editor (getValues)
 * and makes the source appear in the block's «Attributes» panel with the list
 * of details (getFieldsList). The values and their names are set by PHP in
 * window.estrategiaWebSiteCompany and window.estrategiaWebSiteLocation before this script.
 *
 * NO BUILD STEP, like the forms plugin: a classic script that reads the
 * editor's wp.* globals. It has no texts of its own (the name of the source
 * and of each detail come from PHP already translated), so it needs no
 * translations JSON.
 *
 * How to maintain:
 * - Who and when: whoever touches one of the two sources in PHP, in the same
 *   commit, and with the level 3 test (tests/editor.test.mjs) green.
 * - A new key is not touched here: it comes from Company::keys()/labels() and
 *   from Location::labels().
 * - Edge case: the detail is empty, or the location does not exist or is a
 *   draft. «[Detail name]» is shown in the editor so you can see what is
 *   bound; on the site it comes out empty. A location created AFTER opening
 *   the editor is not in window.estrategiaWebSiteLocation until reload: it
 *   shows in brackets, and on the site it does show.
 * - The trap: there is no setValues and canUserEditValue is false on purpose.
 *   If it were editable, the editor would let you type in the bound paragraph
 *   and would save it… nowhere: the data is changed in «Company details» or in
 *   the location. (Measured on WP 7.1: without setValues core does not even
 *   keep canUserEditValue; it is left for clarity.)
 * - And the other one: usesContext has to repeat what PHP says. Without it
 *   core leaves it as [null] when merging with the PHP registration, and the
 *   locations source would not receive the Query's postId.
 */
( function ( wp, company, location ) {
	if ( ! wp || ! wp.blocks || ! wp.blocks.registerBlockBindingsSource ) {
		return;
	}

	// A value for the attribute: in a URL the bracketed placeholder is not shown
	// (it would be a broken link); in a text it is, to see what is bound.
	const show = ( attribute, value, labels, key ) => {
		if ( attribute === 'url' ) {
			return value || '';
		}
		return value || '[' + ( labels[ key ] || key ) + ']';
	};

	const fields = ( labels ) =>
		Object.keys( labels ).map( ( key ) => ( {
			label: labels[ key ],
			type: 'string',
			args: { key },
		} ) );

	if ( company ) {
		wp.blocks.registerBlockBindingsSource( {
			name: company.source,
			usesContext: [],
			getValues( { bindings } ) {
				const out = {};
				for ( const [ attribute, binding ] of Object.entries( bindings || {} ) ) {
					const key = binding && binding.args ? binding.args.key : '';
					out[ attribute ] = show( attribute, company.values[ key ], company.labels, key );
				}
				return out;
			},
			canUserEditValue() {
				return false;
			},
			getFieldsList() {
				return fields( company.labels );
			},
		} );
	}

	if ( location ) {
		// With a slug, that location; without one, the one in context (a Query row).
		const find = ( args, context ) => {
			if ( args.slug ) {
				return location.locations.find( ( l ) => l.slug === args.slug );
			}
			const id = Number( context && context.postId );
			return location.locations.find( ( l ) => l.id === id );
		};
		wp.blocks.registerBlockBindingsSource( {
			name: location.source,
			usesContext: [ 'postId', 'postType' ],
			getValues( { bindings, context } ) {
				const out = {};
				for ( const [ attribute, binding ] of Object.entries( bindings || {} ) ) {
					const args = ( binding && binding.args ) || {};
					const place = find( args, context );
					out[ attribute ] = show( attribute, place ? place.values[ args.key ] : '', location.labels, args.key );
				}
				return out;
			},
			canUserEditValue() {
				return false;
			},
			getFieldsList() {
				return fields( location.labels );
			},
		} );
	}
} )( window.wp, window.estrategiaWebSiteCompany, window.estrategiaWebSiteLocation );
