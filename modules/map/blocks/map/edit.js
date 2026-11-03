/**
 * estrategia-web/map — editor. CLASSIC script, no build step (see blocks/carousel/edit.js
 * for why). It exists only for the media library image picker, which the
 * automatic autoRegister controls do not offer. The preview is rendered by
 * the server (ServerSideRender) with the same render.php as the front end.
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
						{ title: __( 'Map settings', 'estrategiaweb-toolkit' ) },
						h( wp.components.ToggleControl, {
							label: __( 'Show the place details', 'estrategiaweb-toolkit' ),
							help: __( 'Turn it off when the address is already next to the map.', 'estrategiaweb-toolkit' ),
							checked: attributes.showDetails,
							onChange: ( value ) => setAttributes( { showDetails: value } ),
						} ),
						text( __( 'Place name', 'estrategiaweb-toolkit' ), 'placeName' ),
						h( TextareaControl, {
							label: __( 'Address', 'estrategiaweb-toolkit' ),
							value: attributes.address,
							onChange: ( value ) => setAttributes( { address: value } ),
						} ),
						text( __( 'Phone', 'estrategiaweb-toolkit' ), 'phone' ),
						h( TextareaControl, {
							label: __( 'Opening hours', 'estrategiaweb-toolkit' ),
							value: attributes.hours,
							onChange: ( value ) => setAttributes( { hours: value } ),
						} ),
						h( TextareaControl, {
							label: __( 'Google Maps embed URL', 'estrategiaweb-toolkit' ),
							help: __( 'Google Maps › Share › Embed a map. You can paste the whole iframe code.', 'estrategiaweb-toolkit' ),
							value: attributes.embedUrl,
							onChange: ( value ) => setAttributes( { embedUrl: value } ),
						} ),
						text(
							__( 'Google Maps link', 'estrategiaweb-toolkit' ),
							'mapsUrl',
							__( 'Optional. Empty: a search for the address.', 'estrategiaweb-toolkit' )
						)
					),
					h(
						PanelBody,
						{ title: __( 'Static map', 'estrategiaweb-toolkit' ), initialOpen: false },
						h( 'p', null, __( 'An OpenStreetMap image made with tools/static-map.mjs, shown under the place details until the visitor loads Google Maps. It must be served by this site.', 'estrategiaweb-toolkit' ) ),
						text(
							__( 'Static map image', 'estrategiaweb-toolkit' ),
							'staticMapUrl',
							__( 'A theme path (assets/maps/place.webp) or a URL of this site.', 'estrategiaweb-toolkit' )
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
										attributes.staticMapId ? __( 'Replace image', 'estrategiaweb-toolkit' ) : __( 'Choose from the media library', 'estrategiaweb-toolkit' )
									),
							} )
						),
						attributes.staticMapId
							? h(
									Button,
									{ variant: 'link', isDestructive: true, onClick: () => setAttributes( { staticMapId: 0 } ) },
									__( 'Remove image', 'estrategiaweb-toolkit' )
							  )
							: null
					),
					h(
						PanelBody,
						{ title: __( 'Static image', 'estrategiaweb-toolkit' ), initialOpen: false },
						h( 'p', null, __( 'Optional. Without an image the map shows a box with the place details.', 'estrategiaweb-toolkit' ) ),
						h( wp.components.ToggleControl, {
							label: __( 'Use the featured image of the post', 'estrategiaweb-toolkit' ),
							help: __( 'Inside a Query Loop.', 'estrategiaweb-toolkit' ),
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
										attributes.imageId ? __( 'Replace image', 'estrategiaweb-toolkit' ) : __( 'Choose image', 'estrategiaweb-toolkit' )
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
									__( 'Remove image', 'estrategiaweb-toolkit' )
							  )
							: null,
						text( __( 'Image alternative text', 'estrategiaweb-toolkit' ), 'imageAlt' )
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
