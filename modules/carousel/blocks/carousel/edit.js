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
