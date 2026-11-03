/**
 * estrategia-web/carousel — editor. CLASSIC script, no build step.
 *
 * Why: InnerBlocks needs JavaScript in the editor and WordPress 7.1 does not
 * accept ES modules as editorScript. It uses the wp.* globals (their handles
 * are in edit.asset.php) and createElement instead of JSX.
 * Strings go through wp.i18n.__( ..., 'estrategia-web' ); the translations are
 * injected by the plugin core (includes/class-blocks.php) from
 * languages/estrategia-web-<locale>.l10n.php.
 *
 * How to maintain: a new control is a new attribute in block.json with a
 * default; save() returns only InnerBlocks.Content and is NOT touched: the
 * markup is rendered by render.php, so changing it does not invalidate saved
 * carousels.
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
						{ title: __( 'Carousel settings', 'estrategiaweb-toolkit' ) },
						h( TextControl, {
							label: __( 'Accessible name', 'estrategiaweb-toolkit' ),
							help: __( 'Read by screen readers. Defaults to “Carousel”.', 'estrategiaweb-toolkit' ),
							value: attributes.label,
							onChange: ( value ) => setAttributes( { label: value } ),
						} ),
						h( ToggleControl, {
							label: __( 'Show arrows', 'estrategiaweb-toolkit' ),
							checked: attributes.showArrows,
							onChange: ( value ) => setAttributes( { showArrows: value } ),
						} ),
						h( ToggleControl, {
							label: __( 'Show dots', 'estrategiaweb-toolkit' ),
							checked: attributes.showDots,
							onChange: ( value ) => setAttributes( { showDots: value } ),
						} ),
						h( ToggleControl, {
							label: __( 'Loop', 'estrategiaweb-toolkit' ),
							help: __( 'After the last slide, go back to the first one (and from the first to the last).', 'estrategiaweb-toolkit' ),
							checked: attributes.loop,
							onChange: ( value ) => setAttributes( { loop: value } ),
						} ),
						range( __( 'Visible on mobile', 'estrategiaweb-toolkit' ), 'perViewMobile' ),
						range( __( 'Visible on tablet', 'estrategiaweb-toolkit' ), 'perViewTablet' ),
						range( __( 'Visible on desktop', 'estrategiaweb-toolkit' ), 'perViewDesktop' ),
						h( ToggleControl, {
							label: __( 'Autoplay', 'estrategiaweb-toolkit' ),
							help: __( 'Off by default for accessibility. Ignored when the visitor asks for reduced motion.', 'estrategiaweb-toolkit' ),
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
