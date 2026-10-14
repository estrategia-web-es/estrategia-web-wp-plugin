/**
 * estrategia-web/map — editor. Script CLÁSICO, sin compilar (ver blocks/carousel/edit.js
 * para el porqué). Existe solo por el selector de imagen de la mediateca, que
 * los controles automáticos de autoRegister no ofrecen. La vista previa la
 * pinta el servidor (ServerSideRender) con el mismo render.php del front.
 */
( function ( wp ) {
	const { registerBlockType } = wp.blocks;
	const { createElement: h, Fragment } = wp.element;
	const { useBlockProps, InspectorControls, MediaUpload, MediaUploadCheck } = wp.blockEditor;
	const { PanelBody, TextControl, TextareaControl, Button } = wp.components;
	const ServerSideRender = wp.serverSideRender;
	const { __ } = wp.i18n;

	registerBlockType( 'estrategia-web/map', {
		edit( { attributes, setAttributes } ) {
			const blockProps = useBlockProps();
			const text = ( label, key, help ) =>
				h( TextControl, {
					label,
					help,
					value: attributes[ key ],
					onChange: ( value ) => setAttributes( { [ key ]: value } ),
				} );

			return h(
				Fragment,
				null,
				h(
					InspectorControls,
					null,
					h(
						PanelBody,
						{ title: __( 'Map settings', 'estrategia-web' ) },
						h( wp.components.ToggleControl, {
							label: __( 'Show the place details', 'estrategia-web' ),
							help: __( 'Turn it off when the address is already next to the map.', 'estrategia-web' ),
							checked: attributes.showDetails,
							onChange: ( value ) => setAttributes( { showDetails: value } ),
						} ),
						text( __( 'Place name', 'estrategia-web' ), 'placeName' ),
						h( TextareaControl, {
							label: __( 'Address', 'estrategia-web' ),
							value: attributes.address,
							onChange: ( value ) => setAttributes( { address: value } ),
						} ),
						text( __( 'Phone', 'estrategia-web' ), 'phone' ),
						h( TextareaControl, {
							label: __( 'Opening hours', 'estrategia-web' ),
							value: attributes.hours,
							onChange: ( value ) => setAttributes( { hours: value } ),
						} ),
						h( TextareaControl, {
							label: __( 'Google Maps embed URL', 'estrategia-web' ),
							help: __( 'Google Maps › Share › Embed a map. You can paste the whole iframe code.', 'estrategia-web' ),
							value: attributes.embedUrl,
							onChange: ( value ) => setAttributes( { embedUrl: value } ),
						} ),
						text(
							__( 'Google Maps link', 'estrategia-web' ),
							'mapsUrl',
							__( 'Optional. Empty: a search for the address.', 'estrategia-web' )
						)
					),
					h(
						PanelBody,
						{ title: __( 'Static map', 'estrategia-web' ), initialOpen: false },
						h( 'p', null, __( 'An OpenStreetMap image made with tools/static-map.mjs, shown under the place details until the visitor loads Google Maps. It must be served by this site.', 'estrategia-web' ) ),
						text(
							__( 'Static map image', 'estrategia-web' ),
							'staticMapUrl',
							__( 'A theme path (assets/maps/place.webp) or a URL of this site.', 'estrategia-web' )
						),
						h(
							MediaUploadCheck,
							null,
							h( MediaUpload, {
								allowedTypes: [ 'image' ],
								value: attributes.staticMapId,
								onSelect: ( media ) => setAttributes( { staticMapId: media.id } ),
								render: ( { open } ) =>
									h(
										Button,
										{ variant: 'secondary', onClick: open },
										attributes.staticMapId ? __( 'Replace image', 'estrategia-web' ) : __( 'Choose from the media library', 'estrategia-web' )
									),
							} )
						),
						attributes.staticMapId
							? h(
									Button,
									{ variant: 'link', isDestructive: true, onClick: () => setAttributes( { staticMapId: 0 } ) },
									__( 'Remove image', 'estrategia-web' )
							  )
							: null
					),
					h(
						PanelBody,
						{ title: __( 'Static image', 'estrategia-web' ), initialOpen: false },
						h( 'p', null, __( 'Optional. Without an image the map shows a box with the place details.', 'estrategia-web' ) ),
						h( wp.components.ToggleControl, {
							label: __( 'Use the featured image of the post', 'estrategia-web' ),
							help: __( 'Inside a Query Loop.', 'estrategia-web' ),
							checked: attributes.useFeaturedImage,
							onChange: ( value ) => setAttributes( { useFeaturedImage: value } ),
						} ),
						h(
							MediaUploadCheck,
							null,
							h( MediaUpload, {
								allowedTypes: [ 'image' ],
								value: attributes.imageId,
								onSelect: ( media ) =>
									setAttributes( {
										imageId: media.id,
										imageUrl: media.sizes?.large?.url || media.url,
										imageAlt: attributes.imageAlt || media.alt || '',
									} ),
								render: ( { open } ) =>
									h(
										Button,
										{ variant: 'secondary', onClick: open },
										attributes.imageId ? __( 'Replace image', 'estrategia-web' ) : __( 'Choose image', 'estrategia-web' )
									),
							} )
						),
						attributes.imageId
							? h(
									Button,
									{
										variant: 'link',
										isDestructive: true,
										onClick: () => setAttributes( { imageId: 0, imageUrl: '' } ),
									},
									__( 'Remove image', 'estrategia-web' )
							  )
							: null,
						text( __( 'Image alternative text', 'estrategia-web' ), 'imageAlt' )
					)
				),
				h( 'div', blockProps, h( ServerSideRender, { block: 'estrategia-web/map', attributes } ) )
			);
		},
		save() {
			return null;
		},
	} );
} )( window.wp );
