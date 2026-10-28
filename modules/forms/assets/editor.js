/**
 * The block editor side: each block's edit() and its sidebar panel.
 *
 * NO BUILD STEP, on purpose (see README, «Why the editor is not
 * compiled»): no JSX, no @wordpress/scripts, no node_modules. It is written
 * with h() = wp.element.createElement and reads from the wp.* globals the
 * editor already loads; the dependencies are declared in Blocks::register().
 *
 * It is not an ES module (import/export) but a classic script: the block
 * editor registers its blocks with classic scripts that run BEFORE it
 * starts, and a module is deferred. The public side is a module
 * (blocks/form/view.js), because the Interactivity API is served that way.
 *
 * The metadata (attributes, icon, title) comes from block.json via PHP;
 * only edit and save are added here.
 *
 * How to maintain:
 * - A new attribute: block.json + its control here + Definitions (if it
 *   affects the submission) + wpml-config.xml (if it is a text someone reads).
 * - A new marker in the emails: Markers (constant), Notifier::marker_values()
 *   and the FIXED_MARKERS list here, so that the editor help does not lie.
 *   The rules (aliases, a field named like a marker) are in class-markers.php.
 * - The trap: texts go with __( '…', 'estrategia-web' ) and are translated
 *   with the JSON in languages/ (tools/i18n.mjs). If you change this file,
 *   that JSON still applies: its name carries the md5 of the PATH, not of
 *   the content.
 */
( function ( wp ) {
	const { registerBlockType } = wp.blocks;
	const { InnerBlocks, InspectorControls, PlainText, useBlockProps, useInnerBlocksProps } = wp.blockEditor;
	const { PanelBody, TextControl, TextareaControl, ToggleControl, SelectControl } = wp.components;
	const { createElement: h, useEffect } = wp.element;
	const { useSelect } = wp.data;
	const { __ } = wp.i18n;

	// Only the current names: the old Spanish ones (Markers::ALIASES) still
	// work in saved forms until 2.0.0, but the help no longer offers them.
	const FIXED_MARKERS = [ 'all_fields', 'submission_date', 'page_url', 'site_name' ];

	const randomId = ( prefix ) => prefix + '-' + Math.random().toString( 36 ).slice( 2, 8 );

	const slug = ( text ) =>
		String( text || '' )
			.toLowerCase()
			.normalize( 'NFD' )
			.replace( /[̀-ͯ]/g, '' )
			.replace( /[^a-z0-9]+/g, '_' )
			.replace( /^_+|_+$/g, '' )
			.slice( 0, 40 );

	/* ------------------------------------------------------------------ */
	/* Form                                                                */
	/* ------------------------------------------------------------------ */

	const TEMPLATE = [
		[ 'estrategia-web-forms/text', { label: __( 'Name', 'estrategia-web' ), name: 'name', required: true, width: 'half' } ],
		[ 'estrategia-web-forms/email', { label: __( 'Email', 'estrategia-web' ), name: 'email', required: true, width: 'half' } ],
		[ 'estrategia-web-forms/textarea', { label: __( 'Message', 'estrategia-web' ), name: 'message', required: true } ],
		[ 'estrategia-web-forms/checkbox', { name: 'privacy', consent: true } ],
		[ 'estrategia-web-forms/submit', {} ],
		[
			'estrategia-web-forms/notification',
			{
				label: __( 'Notice to the site', 'estrategia-web' ),
				replyTo: '{{email}}',
				/* translators: {{name}} is a marker: do not translate it. */
				subject: __( 'New message from {{name}}', 'estrategia-web' ),
				/* translators: what goes between {{ }} are markers: do not translate them. */
				body: __( 'New message sent from {{page_url}} on {{submission_date}}:\n\n{{all_fields}}', 'estrategia-web' ),
			},
		],
		[
			'estrategia-web-forms/notification',
			{
				label: __( 'Acknowledgement to the sender', 'estrategia-web' ),
				to: '{{email}}',
				subject: __( 'We have received your message', 'estrategia-web' ),
				/* translators: what goes between {{ }} are markers: do not translate them. */
				body: __( 'Hello {{name}},\n\nThank you for writing to us. We have received your message and will reply soon.\n\n{{all_fields}}\n\n{{site_name}}', 'estrategia-web' ),
			},
		],
	];

	registerBlockType( 'estrategia-web-forms/form', {
		edit( { attributes, setAttributes } ) {
			useEffect( () => {
				if ( ! attributes.formId ) {
					setAttributes( { formId: randomId( 'form' ) } );
				}
			}, [] );
			const blockProps = useBlockProps( { className: 'estrategia-web-forms estrategia-web-forms--editor' } );
			const innerBlocksProps = useInnerBlocksProps( blockProps, { template: TEMPLATE } );

			return [
				h(
					InspectorControls,
					{ key: 'inspector' },
					h(
						PanelBody,
						{ title: __( 'Form', 'estrategia-web' ) },
						h( TextControl, {
							label: __( 'Name in the submissions log', 'estrategia-web' ),
							value: attributes.title,
							onChange: ( title ) => setAttributes( { title } ),
							__nextHasNoMarginBottom: true,
						} ),
						h( TextControl, {
							label: __( 'Form identifier', 'estrategia-web' ),
							help: __( 'Groups the submissions of this form in the log, in every language. Change it only if two copies of the form must be counted apart.', 'estrategia-web' ),
							value: attributes.formId,
							onChange: ( formId ) => setAttributes( { formId: slug( formId ).replace( /_/g, '-' ) } ),
							__nextHasNoMarginBottom: true,
						} )
					),
					h(
						PanelBody,
						{ title: __( 'Messages', 'estrategia-web' ), initialOpen: false },
						h( TextareaControl, {
							label: __( 'Success message', 'estrategia-web' ),
							help: __( 'Empty: the default message from Forms → Settings.', 'estrategia-web' ),
							value: attributes.successMessage,
							onChange: ( successMessage ) => setAttributes( { successMessage } ),
							__nextHasNoMarginBottom: true,
						} ),
						h( TextareaControl, {
							label: __( 'Error message', 'estrategia-web' ),
							help: __( 'Empty: the default message from Forms → Settings.', 'estrategia-web' ),
							value: attributes.errorMessage,
							onChange: ( errorMessage ) => setAttributes( { errorMessage } ),
							__nextHasNoMarginBottom: true,
						} )
					)
				),
				h( 'div', { key: 'blocks', ...innerBlocksProps } ),
			];
		},
		save() {
			return h( InnerBlocks.Content );
		},
	} );

	/* ------------------------------------------------------------------ */
	/* Fields                                                              */
	/* ------------------------------------------------------------------ */

	function fieldInspector( type, attributes, setAttributes ) {
		const controls = [
			h( TextControl, {
				key: 'label',
				label: __( 'Label', 'estrategia-web' ),
				help:
					type === 'checkbox' && attributes.consent
						? __( 'The text between braces becomes the link to the privacy policy. Empty: the default text.', 'estrategia-web' )
						: undefined,
				value: attributes.label,
				onChange: ( label ) => setAttributes( { label } ),
				__nextHasNoMarginBottom: true,
			} ),
			h( TextControl, {
				key: 'name',
				label: __( 'Name (key)', 'estrategia-web' ),
				help: __( 'Used in the email markers, e.g. {{name}}, and in the log. Lowercase letters, numbers, - and _.', 'estrategia-web' ),
				value: attributes.name,
				onChange: ( name ) => setAttributes( { name: slug( name ) } ),
				__nextHasNoMarginBottom: true,
			} ),
		];
		if ( ! ( type === 'checkbox' && attributes.consent ) ) {
			controls.push(
				h( ToggleControl, {
					key: 'required',
					label: __( 'Required', 'estrategia-web' ),
					checked: !! attributes.required,
					onChange: ( required ) => setAttributes( { required } ),
					__nextHasNoMarginBottom: true,
				} )
			);
		}
		if ( type !== 'checkbox' ) {
			controls.push(
				h( TextControl, {
					key: 'placeholder',
					label: type === 'select' ? __( 'First option (empty value)', 'estrategia-web' ) : __( 'Placeholder', 'estrategia-web' ),
					value: attributes.placeholder,
					onChange: ( placeholder ) => setAttributes( { placeholder } ),
					__nextHasNoMarginBottom: true,
				} ),
				// 0.2.2: autocomplete (WCAG 1.3.5). Rules in Blocks::autocomplete().
				h( TextControl, {
					key: 'autocomplete',
					label: __( 'Autocomplete', 'estrategia-web' ),
					help: __( 'What the browser may fill in: name, organization, email, tel, street-address… Empty: email and phone fields still get email and tel.', 'estrategia-web' ),
					value: attributes.autocomplete,
					onChange: ( autocomplete ) => setAttributes( { autocomplete } ),
					__nextHasNoMarginBottom: true,
				} )
			);
		}
		if ( type === 'select' ) {
			controls.push(
				h( TextareaControl, {
					key: 'options',
					label: __( 'Options', 'estrategia-web' ),
					help: __( 'One per line.', 'estrategia-web' ),
					value: attributes.options,
					onChange: ( options ) => setAttributes( { options, defaultOption: followOption( attributes.options, options, attributes.defaultOption ) } ),
					__nextHasNoMarginBottom: true,
				} ),
				h( SelectControl, {
					key: 'defaultOption',
					label: __( 'Preselected option', 'estrategia-web' ),
					help: __( 'In a synced pattern, set it per page in the pattern block instead. A link can also choose it with ?af_ followed by the field name.', 'estrategia-web' ),
					value: String( attributes.defaultOption || 0 ),
					options: [ { value: '0', label: __( 'None', 'estrategia-web' ) } ].concat(
						optionList( attributes.options ).map( ( o, i ) => ( { value: String( i + 1 ), label: o } ) )
					),
					onChange: ( v ) => setAttributes( { defaultOption: parseInt( v, 10 ) || 0 } ),
					__nextHasNoMarginBottom: true,
				} )
			);
		}
		if ( type === 'checkbox' ) {
			controls.push(
				h( ToggleControl, {
					key: 'consent',
					label: __( 'Privacy consent', 'estrategia-web' ),
					help: __( 'Always required, and links to the privacy policy.', 'estrategia-web' ),
					checked: !! attributes.consent,
					onChange: ( consent ) => setAttributes( { consent } ),
					__nextHasNoMarginBottom: true,
				} )
			);
			if ( attributes.consent ) {
				controls.push(
					h( TextControl, {
						key: 'privacyUrl',
						label: __( 'Privacy policy URL', 'estrategia-web' ),
						help: __( 'Empty: the privacy policy page set in Settings → Privacy (translated, with WPML).', 'estrategia-web' ),
						value: attributes.privacyUrl,
						onChange: ( privacyUrl ) => setAttributes( { privacyUrl } ),
						__nextHasNoMarginBottom: true,
					} )
				);
			}
		}
		controls.push(
			h( SelectControl, {
				key: 'width',
				label: __( 'Width', 'estrategia-web' ),
				value: attributes.width,
				options: [
					{ value: 'full', label: __( 'Full', 'estrategia-web' ) },
					{ value: 'half', label: __( 'Half', 'estrategia-web' ) },
				],
				onChange: ( width ) => setAttributes( { width } ),
				__nextHasNoMarginBottom: true,
			} )
		);
		return h( InspectorControls, { key: 'inspector' }, h( PanelBody, { title: __( 'Field', 'estrategia-web' ) }, controls ) );
	}

	/** The options as PHP sees them (Blocks::options): no empty or repeated ones. */
	function optionList( raw ) {
		return [ ...new Set( String( raw || '' ).split( '\n' ).map( ( o ) => o.trim() ).filter( Boolean ) ) ];
	}

	/**
	 * The preselection is stored as a POSITION (because of WPML, see
	 * includes/class-preselect.php), so when the options are edited it is
	 * relocated by its TEXT: if «Restaurantes» moves from 2nd to 3rd, the
	 * preselection goes with it; if it disappears, it falls back to «none»
	 * instead of landing on whatever option takes its place.
	 */
	function followOption( oldRaw, newRaw, position ) {
		const chosen = optionList( oldRaw )[ ( position || 0 ) - 1 ];
		return chosen === undefined ? 0 : optionList( newRaw ).indexOf( chosen ) + 1;
	}

	function fieldPreview( type, attributes ) {
		const options = optionList( attributes.options );
		switch ( type ) {
			case 'textarea':
				return h( 'textarea', { rows: 4, placeholder: attributes.placeholder, readOnly: true, tabIndex: -1 } );
			case 'select':
				return h(
					'select',
					{ tabIndex: -1, value: String( attributes.defaultOption || 0 ), onChange: () => {} },
					[ attributes.placeholder || __( 'Choose an option', 'estrategia-web' ) ].concat( options ).map( ( o, i ) => h( 'option', { key: i, value: String( i ) }, o ) )
				);
			case 'checkbox':
				return null;
			default:
				return h( 'input', { type, placeholder: attributes.placeholder, readOnly: true, tabIndex: -1 } );
		}
	}

	function fieldEdit( type ) {
		return function Edit( { attributes, setAttributes } ) {
			useEffect( () => {
				if ( ! attributes.name ) {
					setAttributes( { name: slug( attributes.label ) || randomId( type ).replace( /-/g, '_' ) } );
				}
			}, [] );
			const blockProps = useBlockProps( {
				className: `estrategia-web-forms__field estrategia-web-forms__field--${ type } estrategia-web-forms__field--${ attributes.width || 'full' }`,
			} );
			const required = attributes.required || ( type === 'checkbox' && attributes.consent );
			const defaultLabel =
				type === 'checkbox' && attributes.consent
					? __( 'I have read and accept the {privacy policy}.', 'estrategia-web' )
					: __( 'Label', 'estrategia-web' );

			const label = h(
				'div',
				{ className: 'estrategia-web-forms__label', key: 'label' },
				h( PlainText, {
					value: attributes.label,
					placeholder: defaultLabel,
					onChange: ( value ) => setAttributes( { label: value } ),
					'aria-label': __( 'Label', 'estrategia-web' ),
				} ),
				required ? h( 'span', { className: 'estrategia-web-forms__required', 'aria-hidden': true }, ' *' ) : null
			);
			const key = h( 'span', { className: 'estrategia-web-forms-editor__key', key: 'key' }, '{{' + ( attributes.name || '…' ) + '}}' );

			const body =
				type === 'checkbox'
					? [ h( 'div', { className: 'estrategia-web-forms-editor__check', key: 'check' }, h( 'input', { type: 'checkbox', readOnly: true, tabIndex: -1 } ), label ), key ]
					: [ label, h( 'div', { key: 'control' }, fieldPreview( type, attributes ) ), key ];

			return [ fieldInspector( type, attributes, setAttributes ), h( 'div', { key: 'field', ...blockProps }, body ) ];
		};
	}

	[ 'text', 'email', 'tel', 'textarea', 'select', 'checkbox' ].forEach( ( type ) => {
		registerBlockType( 'estrategia-web-forms/' + type, {
			edit: fieldEdit( type ),
			save: () => null,
		} );
	} );

	/* ------------------------------------------------------------------ */
	/* Button                                                              */
	/* ------------------------------------------------------------------ */

	registerBlockType( 'estrategia-web-forms/submit', {
		edit( { attributes, setAttributes } ) {
			const blockProps = useBlockProps( { className: 'estrategia-web-forms__field estrategia-web-forms__field--submit wp-block-button' } );
			return h(
				'div',
				blockProps,
				h(
					'div',
					{ className: 'wp-block-button__link wp-element-button' },
					h( PlainText, {
						value: attributes.text,
						placeholder: __( 'Send', 'estrategia-web' ),
						onChange: ( text ) => setAttributes( { text } ),
						'aria-label': __( 'Button text', 'estrategia-web' ),
					} )
				)
			);
		},
		save: () => null,
	} );

	/* ------------------------------------------------------------------ */
	/* Email notifications                                                 */
	/* ------------------------------------------------------------------ */

	function collectNames( blocks, out ) {
		blocks.forEach( ( block ) => {
			if ( /^estrategia-web-forms\/(text|email|tel|textarea|select|checkbox)$/.test( block.name ) && block.attributes.name ) {
				out.push( block.attributes.name );
			}
			collectNames( block.innerBlocks || [], out );
		} );
		return out;
	}

	registerBlockType( 'estrategia-web-forms/notification', {
		edit( { attributes, setAttributes, clientId } ) {
			const fieldNames = useSelect(
				( select ) => {
					const editor = select( 'core/block-editor' );
					const forms = editor.getBlockParentsByBlockName( clientId, 'estrategia-web-forms/form' );
					return forms.length ? collectNames( editor.getBlocks( forms[ forms.length - 1 ] ), [] ) : [];
				},
				[ clientId ]
			);
			const markers = fieldNames.concat( FIXED_MARKERS ).map( ( m ) => '{{' + m + '}}' ).join( '  ' );
			const blockProps = useBlockProps( { className: 'estrategia-web-forms-notification' } );
			const text = ( key, label, help ) =>
				h( TextControl, {
					key,
					label,
					help,
					value: attributes[ key ],
					onChange: ( value ) => setAttributes( { [ key ]: value } ),
					__nextHasNoMarginBottom: true,
				} );

			return [
				h(
					InspectorControls,
					{ key: 'inspector' },
					h(
						PanelBody,
						{ title: __( 'Email notification', 'estrategia-web' ) },
						text( 'label', __( 'Name of this notification', 'estrategia-web' ) ),
						text( 'to', __( 'To', 'estrategia-web' ), __( 'Addresses separated by commas, or a marker such as {{email}}. Empty: the site administrator email.', 'estrategia-web' ) ),
						text( 'replyTo', __( 'Reply to', 'estrategia-web' ), __( 'An address or a marker such as {{email}}. Optional.', 'estrategia-web' ) ),
						text( 'subject', __( 'Subject', 'estrategia-web' ) ),
						h( TextareaControl, {
							key: 'body',
							label: __( 'Message', 'estrategia-web' ),
							help: __( 'Available markers:', 'estrategia-web' ) + ' ' + markers,
							rows: 10,
							value: attributes.body,
							onChange: ( body ) => setAttributes( { body } ),
							__nextHasNoMarginBottom: true,
						} )
					)
				),
				h(
					'div',
					{ key: 'card', ...blockProps },
					h( 'strong', null, '✉ ' + ( attributes.label || __( 'Email notification', 'estrategia-web' ) ) ),
					h( 'span', null, __( 'To', 'estrategia-web' ) + ': ' + ( attributes.to || __( 'site administrator', 'estrategia-web' ) ) ),
					h( 'span', null, __( 'Subject', 'estrategia-web' ) + ': ' + ( attributes.subject || '—' ) ),
					h( 'em', null, __( 'Not shown to visitors. Edit it in the side panel.', 'estrategia-web' ) )
				),
			];
		},
		save: () => null,
	} );

	/* ------------------------------------------------------------------ */
	/* Form reference (2.0.0)                                              */
	/* ------------------------------------------------------------------ */

	// A page shows a form post (eweb_form) with this block: a picker, the
	// form as the site will render it (server side, disabled) and a link to
	// edit it. The form itself is edited in its own post, never here. Rules
	// and the render: includes/class-form-post-type.php and
	// Blocks::render_form_ref().
	const { Disabled, Placeholder, Spinner, ExternalLink } = wp.components;
	const ServerSideRender = wp.serverSideRender;
	const { addQueryArgs } = wp.url;

	registerBlockType( 'estrategia-web-forms/form-ref', {
		edit( { attributes, setAttributes } ) {
			const forms = useSelect(
				( select ) =>
					select( 'core' ).getEntityRecords( 'postType', 'eweb_form', {
						per_page: -1,
						status: 'publish,draft,pending,future,private',
						orderby: 'title',
						order: 'asc',
						_fields: 'id,title,status',
					} ),
				[]
			);
			const blockProps = useBlockProps();
			const titleOf = ( f ) => ( f.title && f.title.rendered ) || '#' + f.id;
			const options = [ { value: '0', label: __( 'Choose a form', 'estrategia-web' ) } ].concat(
				( forms || [] ).map( ( f ) => ( {
					value: String( f.id ),
					label: titleOf( f ) + ( f.status !== 'publish' ? ' (' + __( 'not published', 'estrategia-web' ) + ')' : '' ),
				} ) )
			);
			const picker = h( SelectControl, {
				key: 'picker',
				label: __( 'Form', 'estrategia-web' ),
				value: String( attributes.ref || 0 ),
				options,
				onChange: ( v ) => setAttributes( { ref: parseInt( v, 10 ) || 0 } ),
				__nextHasNoMarginBottom: true,
			} );
			const editLink = attributes.ref
				? h( ExternalLink, { key: 'edit', href: addQueryArgs( 'post.php', { post: attributes.ref, action: 'edit' } ) }, __( 'Edit this form', 'estrategia-web' ) )
				: null;
			const newLink = h( ExternalLink, { key: 'new', href: addQueryArgs( 'post-new.php', { post_type: 'eweb_form' } ) }, __( 'Add form', 'estrategia-web' ) );

			if ( ! attributes.ref ) {
				return h(
					'div',
					blockProps,
					h(
						Placeholder,
						{
							icon: 'feedback',
							label: __( 'Form', 'estrategia-web' ),
							instructions: __( 'Choose which of the site\'s forms to show here. Forms are created and edited in Forms → Forms.', 'estrategia-web' ),
						},
						forms === null ? h( Spinner ) : picker,
						h( 'p', { style: { width: '100%' } }, newLink )
					)
				);
			}
			return h(
				'div',
				blockProps,
				h(
					InspectorControls,
					{ key: 'inspector' },
					h( PanelBody, { title: __( 'Form', 'estrategia-web' ) }, picker, h( 'p', { key: 'links' }, editLink, ' · ', newLink ) )
				),
				h( Disabled, { key: 'preview' }, h( ServerSideRender, { block: 'estrategia-web-forms/form-ref', attributes: { ref: attributes.ref } } ) )
			);
		},
		save: () => null,
	} );
} )( window.wp );
