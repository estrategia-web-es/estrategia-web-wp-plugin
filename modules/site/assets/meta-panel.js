/**
 * El panel «Datos de la sede» / «Datos del cliente» / «Datos de la opinión»
 * de la barra lateral del documento. La explicación entera (por qué, WPML,
 * cómo se mantiene) está en includes/class-meta-panel.php.
 *
 * SIN PASO DE COMPILACIÓN, como assets/editor.js: script clásico que lee los
 * globales wp.*. Las dependencias están en Meta_Panel::enqueue(); un wp.x
 * nuevo aquí va también allí, o en el editor sale «wp.x is undefined».
 * Los campos (clave, nombre, control, ayuda) los manda PHP en
 * window.estrategiaWebMetaPanel; los textos propios de aquí usan wp.i18n con
 * el dominio estrategia-web (class-blocks.php les pasa las traducciones).
 *
 * La trampa: las metas enteras (valoración, ids) tienen que viajar como
 * número. Un "3" de texto lo rechaza la API REST por el tipo del esquema
 * («no es de tipo integer») y el guardado entero falla.
 */
( function ( wp, config ) {
	if ( ! wp || ! wp.plugins || ! config ) {
		return;
	}
	const { createElement: h } = wp.element;
	const { useSelect, useDispatch } = wp.data;
	const { TextControl, TextareaControl, SelectControl, Button, BaseControl } = wp.components;
	const { MediaUpload, MediaUploadCheck } = wp.blockEditor;
	const { __ } = wp.i18n;
	const Panel = ( wp.editor && wp.editor.PluginDocumentSettingPanel ) || ( wp.editPost && wp.editPost.PluginDocumentSettingPanel );
	if ( ! Panel ) {
		return;
	}

	function ImageField( { field, value, onChange } ) {
		const media = useSelect( ( select ) => ( value ? select( 'core' ).getMedia( value ) : null ), [ value ] );
		return h(
			BaseControl,
			{ id: 'estrategia-web-meta-' + field.key, label: field.label, help: field.help, __nextHasNoMarginBottom: true },
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
							render: ( { open } ) =>
								h( Button, { variant: 'secondary', onClick: open, id: 'estrategia-web-meta-' + field.key }, value ? __( 'Replace image', 'estrategia-web' ) : __( 'Choose image', 'estrategia-web' ) ),
						} ),
						value ? h( Button, { variant: 'link', isDestructive: true, onClick: () => onChange( 0 ), style: { marginLeft: '8px' } }, __( 'Remove', 'estrategia-web' ) ) : null
				  )
		);
	}

	function ClientField( { field, value, onChange } ) {
		const clients = useSelect( ( select ) => select( 'core' ).getEntityRecords( 'postType', 'client', { per_page: 100, orderby: 'title', order: 'asc', _fields: 'id,title' } ), [] );
		const options = [ { value: '0', label: __( '— None —', 'estrategia-web' ) } ].concat(
			( clients || [] ).map( ( c ) => ( { value: String( c.id ), label: ( c.title && c.title.rendered ) || '#' + c.id } ) )
		);
		return h( SelectControl, { label: field.label, help: field.help, disabled: field.disabled, value: String( value || 0 ), options, onChange: ( v ) => onChange( parseInt( v, 10 ) || 0 ), __nextHasNoMarginBottom: true } );
	}

	function Field( { field, value, onChange } ) {
		const common = { label: field.label, help: field.help, disabled: field.disabled, __nextHasNoMarginBottom: true };
		switch ( field.kind ) {
			case 'textarea':
				return h( TextareaControl, { ...common, value: value || '', rows: 3, onChange } );
			case 'rating':
				return h( SelectControl, {
					...common,
					value: String( value || 0 ),
					options: [ { value: '0', label: __( 'No rating', 'estrategia-web' ) } ].concat( [ 1, 2, 3, 4, 5 ].map( ( n ) => ( { value: String( n ), label: '★'.repeat( n ) + ' (' + n + ')' } ) ) ),
					onChange: ( v ) => onChange( parseInt( v, 10 ) || 0 ),
				} );
			case 'fit':
				return h( SelectControl, {
					...common,
					value: value || 'cover',
					options: [
						{ value: 'cover', label: __( 'Photo (fills the box, may be cropped)', 'estrategia-web' ) },
						{ value: 'contain', label: __( 'Logo (whole, never cropped)', 'estrategia-web' ) },
					],
					onChange,
				} );
			case 'client':
				return h( ClientField, { field, value, onChange } );
			case 'image':
				return h( ImageField, { field, value, onChange } );
			default:
				return h( TextControl, { ...common, type: field.kind === 'email' || field.kind === 'url' ? field.kind : 'text', value: value || '', onChange, __next40pxDefaultSize: true } );
		}
	}

	let openedOnce = false;

	function MetaPanel() {
		const { type, meta } = useSelect( ( select ) => ( {
			type: select( 'core/editor' ).getCurrentPostType(),
			meta: select( 'core/editor' ).getEditedPostAttribute( 'meta' ) || {},
		} ) );
		const { editPost } = useDispatch( 'core/editor' );
		wp.element.useEffect( () => {
			const PANEL = 'estrategia-web-meta-panel/estrategia-web-meta';
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
			{ name: 'estrategia-web-meta', title: config.title, className: 'estrategia-web-meta-panel' },
			config.note ? h( 'p', { className: 'estrategia-web-meta-panel__note' }, config.note ) : null,
			config.fields.map( ( field ) =>
				h(
					'div',
					{ key: field.key, style: { marginBottom: '16px' }, 'data-meta-key': field.key },
					h( Field, { field, value: meta[ field.key ], onChange: ( v ) => editPost( { meta: { [ field.key ]: v } } ) } )
				)
			)
		);
	}

	wp.plugins.registerPlugin( 'estrategia-web-meta-panel', { render: MetaPanel } );

	// Abierto al entrar. Un panel de plugin nace CERRADO (solo se ve su
	// título), y estos campos son el dato principal de una sede: cerrado es
	// justo lo que pasó el 07-10-2026, no encontrarlo. Se abre una vez por
	// carga; si alguien lo cierra, sigue cerrado hasta recargar.
	// Va en el primer pintado (MetaPanel), no aquí suelto: al cargar este
	// script las preferencias del usuario aún no están y lo cerrarían.
} )( window.wp, window.estrategiaWebMetaPanel );
