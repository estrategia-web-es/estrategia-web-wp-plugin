<?php
/**
 * estrategia-web/carousel — horizontally scrolling carousel.
 *
 * What it is: a scrollable row (scroll-snap) holding whatever blocks are put
 * inside (images, groups, cards: each direct child is a slide), previous/next
 * arrows, optional dots and autoplay ONLY when asked for.
 *
 * With a Query inside (0.2.0): if a direct child is a core/query, each entry
 * of its post-template (<li>) is a slide. That way a list that lives in the
 * database (clients, reviews) becomes a carousel without copying its cards by
 * hand. The query and its <ul> are flattened with display: contents
 * (style.css) and view.js walks the <li> elements.
 * - Edge case: the query pagination does NOT go inside the carousel (it would
 *   be one more slide); the carousel shows whatever perPage the query brings.
 * - The trap: the post-template must be a DIRECT child of the query. If it is
 *   wrapped in a group, the group is the only slide.
 * - In the editor the query keeps its normal layout (a column): the
 *   flattening only applies to the published page.
 *
 * Looping (0.5.0, loop attribute, default YES): "next" on the last slide goes
 * to the first and "previous" on the first goes to the last (also with the
 * ← → keys). Both arrows are
 * always active: without looping, "previous" showed greyed out on load and on
 * a live site it looked like a broken carousel. With a Query inside it works
 * the same (it counts entries). Why YES by default: it is what a visitor of a
 * business site expects from a carousel, and the grey arrow on load looked
 * like a bug; a carousel that must end (steps of a process) sets loop: false.
 *
 * INFINITE (0.6.0): the loop no longer rewinds. From the last slide it moves
 * to the first CONTINUING in the same direction (and vice versa), with arrows,
 * keyboard, dots, autoplay and by finger (scroll-snap). David asked for it:
 * the 0.5.0 rewind ran through every slide backwards.
 * - Technique: NO CLONES. view.js gives each slide a flex `order` that
 *   ROTATES the row without touching the DOM, so that around the visible one
 *   there are always slides on both sides. When the track stops (scrollend;
 *   on Safari, without scrollend, after 120 ms still) it rotates again around
 *   the edge slide and corrects scrollLeft in the same frame, without
 *   animation: the slide does not move on screen and the jump is invisible.
 *   Before each arrow or dot step it rotates as much as needed so that the
 *   target ends up on the side it is moving towards.
 * - Why no clones: they would duplicate focusable links, ids and what a
 *   screen reader reads (they would need inert/aria-hidden and their ids
 *   removed), they would load the images again, and above all they would
 *   change the :nth-child of the slides, which child themes rely on
 *   (Lavandería gives each photo of the hero-carousel its aspect ratio via
 *   nth-child(3n+…)). `order` does not change the DOM: not the nth-child, not
 *   the reading order, not the tab order, which stay logical (1, 2, 3…).
 * - Grid: if the child theme makes the track a multi-row grid filled by rows
 *   (Lavandería: the clients strip, two rows of 12), what rotates are the
 *   COLUMNS, every row alike; rotating card by card moved cards from one row
 *   to another. Rows are measured with the natural order on load and on width
 *   change. ANY number of cards (1.4.3): the last row may be short (23
 *   clients in rows of 12 = 12 + 11); then each card is placed in its grid
 *   cell and the hole of the short row travels with its column, also across
 *   the seam. An irregular layout (a short row that is not the last one,
 *   flex with wrap and a short last row) is not rotated: it wraps around by
 *   rewinding, like 0.5.0. Test:
 *   modules/carousel/tests/carousel.test.mjs (./any-count.mjs).
 *   With several rows the dots do not match (they count cards, not columns):
 *   those carousels go without dots.
 * - Edge case: with ONE slide, or all of them in view, there is nothing to
 *   scroll: the row stays still (is-static) and its order is not touched. With
 *   only ONE extra (e.g. 3 photos and 2 in view) the finger only finds a
 *   neighbour on one side at any moment; the arrows still wrap around, and
 *   since 0.7.2 by SLIDING: as the row cannot scroll a whole slide, view.js
 *   (glide) animates each slide with transform from where it was, and the one
 *   that wraps around leaves on one side with an inert, temporary copy while
 *   it enters on the other. At rest no copy remains.
 * - Rapid clicks do not jump (0.7.2): during its own animation the track has
 *   no scroll-snap, and steps arriving while glide is animating are queued.
 * - Dots: the active one is the slide at the left edge (also with several in
 *   view); a dot goes to its slide by the shortest path. Home and End go to
 *   the first and the last.
 * - With "reduce motion", steps are instant; the wrap-around still happens.
 * - The trap (for a child theme): a rule with `order` on the slides loses
 *   against view.js's inline style, and a style that depends on the VISUAL
 *   position (:first-child for "the first one you see") does not work: the
 *   first in the DOM is no longer the one on the left. :nth-child rules stay
 *   attached to their slide, which is what we want.
 * - Tests: tests/carousel-infinite.test.mjs: every step slides, with several
 *   intermediate frames and no jumps, and frame by frame the track never
 *   goes backwards (the glide and loop checks were merged into it, 28-10-2026).
 *
 * PHOTOS (0.7.4): the slides visible on load get fetchpriority="high" (if the
 * carousel is the first thing on the page), the rest loading="lazy", and all
 * of them a sizes equal to the slide width, or the one from the imageSizes
 * attribute. All of it in modules/carousel/includes/carousel-images.php, with its edge case
 * (Lavandería) and its traps. Test: tests/carousel.test.mjs (./images.mjs).
 *
 * Decisions:
 * - Without JavaScript it is a row scrollable by finger, wheel or arrow keys:
 *   the behaviour is an enhancement, not a requirement. The controls render
 *   hidden and view.js unhides them (is-ready class).
 * - Behaviour with the Interactivity API (view.js, native ES module).
 * - Editor: InnerBlocks needs JavaScript in the editor, and WordPress 7.1 does
 *   not accept ES modules as editorScript (there is no editorScriptModule). So
 *   edit.js is a CLASSIC script using the wp.element and wp.blockEditor
 *   globals, with no JSX and no build. Its dependency list is in
 *   edit.asset.php, written by hand.
 * - How many are visible: by CAROUSEL WIDTH (container queries), not the
 *   viewport. A carousel in a narrow column shows the mobile count even on a
 *   large screen, which is what we want.
 * - Accessibility: region with aria-roledescription "carousel" and a name; the
 *   track is focusable (tabindex=0) and responds to ← → Home End; buttons with
 *   a name and aria-controls; prefers-reduced-motion removes smooth scrolling
 *   and disables autoplay. Autoplay, if enabled, stops on mouse hover or when
 *   focus enters, and has a pause button (WCAG 2.2.2).
 *
 * How to maintain:
 * - Who: whoever touches the carousel module (until 1.0.0, the parent theme). When: on changing the markup or the
 *   attributes; a new attribute gets a default so that already saved
 *   carousels stay the same (parent condition 2: never force a child to change).
 * - Edge case: "I want the carousel with the arrows over the images" is an
 *   ADJUSTMENT (child CSS on .estrategia-web-carousel__controls);
 *   "I want a carousel that syncs two rows" is SOMETHING ELSE: a child block.
 * - The container query breakpoints (480px / 782px) copy settings.viewport
 *   from theme.json. If they change there, change them in style.css: CSS does
 *   not allow variables inside @container.
 * - The trap: dots are counted from the inner blocks at render time; an inner
 *   block that renders nothing (an empty paragraph half deleted) counts as an
 *   invisible slide.
 *
 * @package estrategia-web
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Inner blocks HTML.
 * @var WP_Block $block      Block instance.
 */

defined( 'ABSPATH' ) || exit;

/*
 * Slides: each direct inner block, EXCEPT a core/query, which contributes one
 * per <li> of its post-template (0.2.0). The query ones are counted in the
 * already rendered HTML: before rendering, the number of entries it brings is
 * unknown.
 */
$estrategia_web_count = 0;
foreach ( $block->inner_blocks as $estrategia_web_inner ) {
	if ( 'core/query' !== $estrategia_web_inner->name ) {
		++$estrategia_web_count;
	}
}
if ( str_contains( $content, 'wp-block-post-template' ) ) {
	$estrategia_web_tags = new WP_HTML_Tag_Processor( $content );
	while ( $estrategia_web_tags->next_tag( array( 'tag_name' => 'LI', 'class_name' => 'wp-block-post' ) ) ) {
		++$estrategia_web_count;
	}
}
$estrategia_web_track_id = wp_unique_id( 'estrategia-web-carousel-' );

// Photos (0.7.4): priority for those visible on load, the rest deferred, and
// a sizes equal to the slide width. How and why: modules/carousel/includes/carousel-images.php.
\EstrategiaWeb\Carousel\images_register( $estrategia_web_track_id, (array) $attributes );
$content = \EstrategiaWeb\Carousel\images_mark( (string) $content, $estrategia_web_track_id );

$estrategia_web_loop    = ! empty( $attributes['loop'] );
$estrategia_web_label    = '' !== trim( (string) $attributes['label'] ) ? $attributes['label'] : __( 'Carousel', 'estrategia-web' );

$estrategia_web_per_view = array(
	'mobile'  => max( 1, (float) $attributes['perViewMobile'] ),
	'tablet'  => max( 1, (float) $attributes['perViewTablet'] ),
	'desktop' => max( 1, (float) $attributes['perViewDesktop'] ),
);

$estrategia_web_context = array(
	'ready'      => false,
	'atStart'    => true,
	'atEnd'      => false,
	'current'    => 0,
	'count'      => $estrategia_web_count,
	'loop'       => $estrategia_web_loop,
	'autoplay'   => (bool) $attributes['autoplay'],
	'interval'   => max( 2000, (int) $attributes['autoplayInterval'] ),
	'playing'    => false,
	'hover'      => false,
	'focused'    => false,
	'labelPause' => __( 'Pause carousel', 'estrategia-web' ),
	'labelPlay'  => __( 'Play carousel', 'estrategia-web' ),
);

$estrategia_web_wrapper = get_block_wrapper_attributes(
	array(
		'class'                => 'estrategia-web-carousel',
		'role'                 => 'region',
		'aria-roledescription' => _x( 'carousel', 'ARIA role description', 'estrategia-web' ),
		'aria-label'           => $estrategia_web_label,
		'style'                => sprintf(
			'--estrategia-web-carousel-per-view-mobile:%s;--estrategia-web-carousel-per-view-tablet:%s;--estrategia-web-carousel-per-view-desktop:%s;',
			$estrategia_web_per_view['mobile'],
			$estrategia_web_per_view['tablet'],
			$estrategia_web_per_view['desktop']
		),
	)
);
?>
<div
	<?php echo $estrategia_web_wrapper; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped by core. ?>
	data-wp-interactive="estrategia-web/carousel"
	<?php echo wp_interactivity_data_wp_context( $estrategia_web_context ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
	data-wp-init="callbacks.init"
	data-wp-class--is-ready="context.ready"
	data-wp-class--is-static="state.isStatic"
	data-wp-on-async--mouseenter="actions.hoverIn"
	data-wp-on-async--mouseleave="actions.hoverOut"
	data-wp-on-async--focusin="actions.focusIn"
	data-wp-on-async--focusout="actions.focusOut"
>
	<div
		class="estrategia-web-carousel__track"
		id="<?php echo esc_attr( $estrategia_web_track_id ); ?>"
		tabindex="0"
		aria-label="<?php echo esc_attr( $estrategia_web_label ); ?>"
		data-wp-on-async--scroll="callbacks.onScroll"
		data-wp-on--keydown="actions.onKeydown"
	>
		<?php echo $content; // phpcs:ignore WordPress.Security.EscapeOutput -- inner blocks. ?>
	</div>

	<?php if ( $attributes['showArrows'] || $attributes['autoplay'] ) : ?>
	<div class="estrategia-web-carousel__controls">
		<?php if ( $attributes['autoplay'] ) : ?>
		<button
			type="button"
			class="estrategia-web-carousel__toggle"
			aria-controls="<?php echo esc_attr( $estrategia_web_track_id ); ?>"
			data-wp-on--click="actions.toggleAutoplay"
			aria-label="<?php echo esc_attr( $estrategia_web_context['labelPause'] ); ?>"
			data-wp-bind--aria-label="state.toggleLabel"
		>
			<span class="estrategia-web-carousel__toggle-icon" aria-hidden="true" data-wp-class--is-playing="context.playing"></span>
		</button>
		<?php endif; ?>
		<?php if ( $attributes['showArrows'] ) : ?>
		<button
			type="button"
			class="estrategia-web-carousel__arrow estrategia-web-carousel__prev"
			aria-controls="<?php echo esc_attr( $estrategia_web_track_id ); ?>"
			aria-label="<?php echo esc_attr__( 'Previous slide', 'estrategia-web' ); ?>"
			data-wp-on--click="actions.prev"
			<?php echo $estrategia_web_loop ? '' : 'data-wp-bind--disabled="context.atStart"'; ?>
		>
			<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false"><path d="M15 5l-7 7 7 7" fill="none" stroke="currentColor" stroke-width="2"/></svg>
		</button>
		<button
			type="button"
			class="estrategia-web-carousel__arrow estrategia-web-carousel__next"
			aria-controls="<?php echo esc_attr( $estrategia_web_track_id ); ?>"
			aria-label="<?php echo esc_attr__( 'Next slide', 'estrategia-web' ); ?>"
			data-wp-on--click="actions.next"
			<?php echo $estrategia_web_loop ? '' : 'data-wp-bind--disabled="context.atEnd"'; ?>
		>
			<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false"><path d="M9 5l7 7-7 7" fill="none" stroke="currentColor" stroke-width="2"/></svg>
		</button>
		<?php endif; ?>
	</div>
	<?php endif; ?>

	<?php if ( $attributes['showDots'] && $estrategia_web_count > 1 ) : ?>
	<div class="estrategia-web-carousel__dots" role="group" aria-label="<?php echo esc_attr__( 'Choose slide', 'estrategia-web' ); ?>">
		<?php for ( $estrategia_web_i = 0; $estrategia_web_i < $estrategia_web_count; $estrategia_web_i++ ) : ?>
		<button
			type="button"
			class="estrategia-web-carousel__dot"
			aria-controls="<?php echo esc_attr( $estrategia_web_track_id ); ?>"
			aria-label="<?php echo esc_attr( sprintf( /* translators: %d: slide number. */ __( 'Go to slide %d', 'estrategia-web' ), $estrategia_web_i + 1 ) ); ?>"
			<?php echo wp_interactivity_data_wp_context( array( 'index' => $estrategia_web_i ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			data-wp-on--click="actions.goTo"
			data-wp-bind--aria-current="state.isCurrentDot"
		></button>
		<?php endfor; ?>
	</div>
	<?php endif; ?>
</div>
