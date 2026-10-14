/**
 * estrategia-web/carousel — editor. Script CLÁSICO, sin compilar.
 *
 * Por qué así: InnerBlocks necesita JavaScript en el editor y WordPress 7.1
 * no acepta módulos ES como editorScript. Se usan los globales wp.* (sus
 * handles están en edit.asset.php) y createElement en vez de JSX.
 * Los textos van con wp.i18n.__( ..., 'estrategia-web' ); las traducciones las
 * inyecta el núcleo del plugin (includes/class-blocks.php) desde
 * languages/estrategia-web-<locale>.l10n.php.
 *
 * Cómo se mantiene: un control nuevo es un atributo nuevo en block.json con
 * default; save() devuelve solo InnerBlocks.Content y NO se toca: el marcado
 * lo pinta render.php, así que cambiarlo no invalida carruseles guardados.
 */
( function ( wp ) {
	const { registerBlockType } = wp.blocks;
	const { createElement: h, Fragment } = wp.element;
	const { useBlockProps, useInnerBlocksProps, InnerBlocks, InspectorControls } = wp.blockEditor;
	const { PanelBody, ToggleControl, RangeControl, TextControl } = wp.components;
	const { __ } = wp.i18n;

	registerBlockType( 'estrategia-web/carousel', {
		edit( { attributes, setAttributes } ) {
			const blockProps = useBlockProps( {
				className: 'estrategia-web-carousel is-editing',
				style: {
					'--estrategia-web-carousel-per-view-mobile': attributes.perViewMobile,
					'--estrategia-web-carousel-per-view-tablet': attributes.perViewTablet,
					'--estrategia-web-carousel-per-view-desktop': attributes.perViewDesktop,
				},
			} );
			const innerBlocksProps = useInnerBlocksProps(
				{ className: 'estrategia-web-carousel__track' },
				{
					orientation: 'horizontal',
					template: [ [ 'core/image' ], [ 'core/image' ], [ 'core/image' ] ],
				}
			);
			const range = ( label, key ) =>
				h( RangeControl, {
					label,
					value: attributes[ key ],
					min: 1,
					max: 6,
					step: 0.5,
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
						{ title: __( 'Carousel settings', 'estrategia-web' ) },
						h( TextControl, {
							label: __( 'Accessible name', 'estrategia-web' ),
							help: __( 'Read by screen readers. Defaults to “Carousel”.', 'estrategia-web' ),
							value: attributes.label,
							onChange: ( value ) => setAttributes( { label: value } ),
						} ),
						h( ToggleControl, {
							label: __( 'Show arrows', 'estrategia-web' ),
							checked: attributes.showArrows,
							onChange: ( value ) => setAttributes( { showArrows: value } ),
						} ),
						h( ToggleControl, {
							label: __( 'Show dots', 'estrategia-web' ),
							checked: attributes.showDots,
							onChange: ( value ) => setAttributes( { showDots: value } ),
						} ),
						h( ToggleControl, {
							label: __( 'Loop', 'estrategia-web' ),
							help: __( 'After the last slide, go back to the first one (and from the first to the last).', 'estrategia-web' ),
							checked: attributes.loop,
							onChange: ( value ) => setAttributes( { loop: value } ),
						} ),
						range( __( 'Visible on mobile', 'estrategia-web' ), 'perViewMobile' ),
						range( __( 'Visible on tablet', 'estrategia-web' ), 'perViewTablet' ),
						range( __( 'Visible on desktop', 'estrategia-web' ), 'perViewDesktop' ),
						h( ToggleControl, {
							label: __( 'Autoplay', 'estrategia-web' ),
							help: __( 'Off by default for accessibility. Ignored when the visitor asks for reduced motion.', 'estrategia-web' ),
							checked: attributes.autoplay,
							onChange: ( value ) => setAttributes( { autoplay: value } ),
						} )
					)
				),
				h( 'div', blockProps, h( 'div', innerBlocksProps ) )
			);
		},
		save() {
			return h( InnerBlocks.Content );
		},
	} );
} )( window.wp );
