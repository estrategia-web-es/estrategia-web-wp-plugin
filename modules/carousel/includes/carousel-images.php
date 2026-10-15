<?php
/**
 * estrategia-web/carousel — priority, lazy loading and sizes of its images (0.7.4).
 *
 * What it solves (Lavandería audit, 06-10-2026): on desktop the LCP element
 * of the home page was slide 2 of the carousel and only slide 1 had
 * fetchpriority="high"; and every image had WordPress' default sizes,
 * "(max-width: 1920px) 100vw", so in a carousel with three in view the
 * browser downloaded a 1536 image for a ~500 slot.
 *
 * How it works, in two steps:
 * 1. render.php marks each <img> with data-estrategia-web-carousel="<id>:<n>" (n =
 *    slide, from 0) and records here, by id, how many slides can be visible
 *    on load and which sizes they get (images_register).
 * 2. The wp_content_img_tag filter, which runs AFTER WordPress has set
 *    srcset, sizes, loading and fetchpriority on each image, corrects them:
 *    - Slides visible on load: if WordPress gave fetchpriority "high" to one
 *      of them (i.e. it decided the carousel is the first thing on the page),
 *      ALL visible ones get "high" and none gets loading="lazy". If it did
 *      not (the carousel is further down), its decision stays: priority is
 *      not given away to a carousel that is out of view.
 *    - Those not visible on load: loading="lazy", no fetchpriority, and
 *      sizes="auto, …" (with lazy, Chrome uses the actual rendered width).
 *    - sizes: the carousel's, on those that have a srcset.
 *    Finally the mark is removed: it never reaches the page.
 *
 * How many are visible on load: ceil( the largest of perViewMobile/Tablet/Desktop ).
 * The server does not know the window width, so the widest case is counted.
 * Measured on Lavandería (06-10-2026), home carousel 1/2/3: at 402 slide 1 is
 * visible and at 1512 slides 1, 2 and 3 (3 cut off). The last one, in a
 * looping carousel, sits to the LEFT of the first (view.js order) and out of
 * view: it does not count. Changed with the filter
 * estrategia_web_carousel_eager_slides( $n, $attributes ).
 *
 * The default sizes comes from perView and the carousel width: 100vw if it is
 * align full; otherwise the wideSize or contentSize from theme.json when they
 * are px (if not, as if it were full). The gap between slides is ignored,
 * which overestimates by a few pixels: better slightly large than blurry.
 * With the 480 and 782 breakpoints (those of style.css), a full-width 1/2/3
 * gives:
 *     (max-width: 480px) 100vw, (max-width: 782px) calc(100vw / 2), calc(100vw / 3)
 *
 * How to maintain:
 * - Who: whoever touches the carousel. When: if the style.css breakpoints
 *   (480/782) change, change them in images_sizes() too.
 * - Edge case: a child theme that renders the slides at a width other than
 *   perView's (Lavandería: the home page gives each photo its own ratio, 442 /
 *   629 / 596 wide at 596 high) does NOT match the calculation: it writes its
 *   sizes in the block's imageSizes attribute. It is an ADJUSTMENT, not a new
 *   block. There is also the filter estrategia_web_carousel_image_sizes( $sizes, $attributes ).
 * - The trap: the filter only sees images that go through
 *   wp_filter_content_tags (post and template content, which is where a
 *   carousel lives). A carousel rendered some other way (do_blocks in a custom
 *   shortcode) keeps whatever WordPress sets, with the mark left in the HTML,
 *   where it does nothing.
 * - The other trap: WordPress only gives fetchpriority="high" to ONE image per
 *   page, the first large one (over 50,000 px²) it finds. If there is another
 *   large image before the carousel, the carousel is not treated as the hero.
 * - Test: tests/carousel-images.test.mjs (level 3).
 *
 * Since plugin 1.1.0 it lives here (before, inc/carousel-images.php in the
 * theme) and in the EstrategiaWeb\Carousel namespace: images_register(),
 * images_mark()… The FILTERS keep their original names.
 */

namespace EstrategiaWeb\Carousel;

defined( 'ABSPATH' ) || exit;

/**
 * Carousels rendered in this request: id => { eager: int, sizes: string, hero: bool }.
 *
 * @return array<string, array{eager: int, sizes: string, hero: bool}>
 */
function &images_registry(): array {
	static $registry = array();
	return $registry;
}

/**
 * Remembers how the images of one carousel have to load. Called by render.php.
 *
 * @param string $id         Carousel id (the track id).
 * @param array  $attributes Block attributes.
 */
function images_register( string $id, array $attributes ): void {
	$per_view = max(
		(float) ( $attributes['perViewMobile'] ?? 1 ),
		(float) ( $attributes['perViewTablet'] ?? 2 ),
		(float) ( $attributes['perViewDesktop'] ?? 3 ),
		1
	);
	/**
	 * How many slides can be seen when the page loads (they get priority, never lazy).
	 *
	 * @param int   $eager      Default: ceil( largest perView ).
	 * @param array $attributes Block attributes.
	 */
	$eager = (int) apply_filters( 'estrategia_web_carousel_eager_slides', (int) ceil( $per_view ), $attributes );

	$registry        = &images_registry();
	$registry[ $id ] = array(
		'eager' => max( 0, $eager ),
		'sizes' => images_sizes( $attributes ),
		'hero'  => false,
		// 1.1.x: mobile crop (null if not requested). See carousel-mobile-crop.php.
		'crop'  => mobile_crop_config( $attributes ),
	);
}

/**
 * The sizes attribute for the slides of a carousel. See the header.
 *
 * @param array $attributes Block attributes.
 */
function images_sizes( array $attributes ): string {
	$sizes = trim( (string) ( $attributes['imageSizes'] ?? '' ) );
	if ( '' === $sizes ) {
		$cap = null;
		if ( 'full' !== ( $attributes['align'] ?? '' ) ) {
			$key    = 'wide' === ( $attributes['align'] ?? '' ) ? 'wideSize' : 'contentSize';
			$layout = (string) wp_get_global_settings( array( 'layout', $key ) );
			if ( preg_match( '/^(\d+(?:\.\d+)?)px$/', trim( $layout ), $m ) ) {
				$cap = (float) $m[1];
			}
		}
		$sizes = images_sizes_for(
			max( 1, (float) ( $attributes['perViewMobile'] ?? 1 ) ),
			max( 1, (float) ( $attributes['perViewTablet'] ?? 2 ) ),
			max( 1, (float) ( $attributes['perViewDesktop'] ?? 3 ) ),
			$cap
		);
	}
	/**
	 * The sizes attribute of the images in a carousel.
	 *
	 * @param string $sizes      Computed (or the block's imageSizes).
	 * @param array  $attributes Block attributes.
	 */
	return trim( (string) apply_filters( 'estrategia_web_carousel_image_sizes', $sizes, $attributes ) );
}

/**
 * Pure part of the sizes: per view at each container breakpoint, the carousel
 * as wide as the window up to $cap px.
 *
 * @param float      $mobile  Per view up to 480px.
 * @param float      $tablet  Per view up to 782px.
 * @param float      $desktop Per view above.
 * @param float|null $cap     Max width of the carousel in px, null = the window.
 */
function images_sizes_for( float $mobile, float $tablet, float $desktop, ?float $cap ): string {
	$fmt   = static fn( float $n ): string => rtrim( rtrim( number_format( $n, 2, '.', '' ), '0' ), '.' );
	$vw    = static fn( float $n ): string => 1.0 === $n ? '100vw' : 'calc(100vw / ' . $fmt( $n ) . ')';
	$steps = array( array( 480.0, $mobile ), array( 782.0, $tablet ), array( INF, $desktop ) );
	$parts = array();
	foreach ( $steps as list( $limit, $n ) ) {
		if ( null !== $cap && $cap <= $limit ) {
			$parts[] = array( $cap, $vw( $n ) );
			$parts[] = array( null, $fmt( round( $cap / $n ) ) . 'px' );
			break;
		}
		$parts[] = array( is_finite( $limit ) ? $limit : null, $vw( $n ) );
	}
	// Merge neighbours with the same value: 1/1/1 is just 100vw.
	$merged = array();
	foreach ( $parts as $part ) {
		$last = end( $merged );
		if ( $last && $last[1] === $part[1] ) {
			$merged[ count( $merged ) - 1 ] = $part;
			continue;
		}
		$merged[] = $part;
	}
	return implode(
		', ',
		array_map( static fn( $p ) => null === $p[0] ? $p[1] : '(max-width: ' . $fmt( $p[0] ) . 'px) ' . $p[1], $merged )
	);
}

/**
 * Marks every <img> of the rendered slides with data-estrategia-web-carousel="<id>:<slide>".
 * A slide is each top-level element, except a core/query wrapper, whose
 * <li class="wp-block-post"> are the slides. If the HTML API cannot parse the
 * content, it is returned untouched (WordPress' own defaults stay).
 *
 * @param string $content Inner blocks HTML.
 * @param string $id      Carousel id.
 */
function images_mark( string $content, string $id ): string {
	if ( ! str_contains( $content, '<img' ) || ! class_exists( 'WP_HTML_Processor' ) ) {
		return $content;
	}
	$html = \WP_HTML_Processor::create_fragment( $content );
	if ( ! $html ) {
		return $content;
	}
	$slide    = -1;
	$top      = null;
	$in_query = false;
	while ( $html->next_tag() ) {
		$depth = $html->get_current_depth();
		$top ??= $depth;
		if ( $depth === $top ) {
			$in_query = $html->has_class( 'wp-block-query' );
			if ( ! $in_query ) {
				++$slide;
			}
		} elseif ( $in_query && 'LI' === $html->get_tag() && $html->has_class( 'wp-block-post' ) ) {
			++$slide;
		}
		if ( 'IMG' === $html->get_tag() && $slide >= 0 ) {
			$html->set_attribute( 'data-estrategia-web-carousel', $id . ':' . $slide );
		}
	}
	if ( $html->get_last_error() ) {
		return $content;
	}
	return $html->get_updated_html();
}

/**
 * Fixes loading, fetchpriority and sizes of a marked carousel image. See the header.
 *
 * @param string $image         The img tag, already with WordPress' attributes.
 * @param string $context       Filter context (unused).
 * @param int    $attachment_id Attachment id, 0 if WordPress did not find it (wp-image-ID class).
 */
function images_filter( $image, $context = '', $attachment_id = 0 ) {
	if ( ! is_string( $image ) || ! str_contains( $image, 'data-estrategia-web-carousel' ) ) {
		return $image;
	}
	$tag = new \WP_HTML_Tag_Processor( $image );
	if ( ! $tag->next_tag( 'IMG' ) ) {
		return $image;
	}
	$mark = (string) $tag->get_attribute( 'data-estrategia-web-carousel' );
	$tag->remove_attribute( 'data-estrategia-web-carousel' );
	$registry = &images_registry();
	$at       = strrpos( $mark, ':' );
	$id       = false === $at ? '' : substr( $mark, 0, $at );
	if ( ! isset( $registry[ $id ] ) ) {
		return $tag->get_updated_html();
	}
	$carousel = &$registry[ $id ];
	$slide    = (int) substr( $mark, $at + 1 );
	$eager    = $slide < $carousel['eager'];

	if ( $eager ) {
		if ( 'high' === $tag->get_attribute( 'fetchpriority' ) ) {
			$carousel['hero'] = true;
		}
		if ( $carousel['hero'] ) {
			// High priority ONLY for the first one (0.7.9). With all three visible
			// ones at "high", on mobile ~500 KB were requested with priority while
			// only one is visible: holding back 2 and 3, local LCP dropped from 6.6
			// to 4.6 s (Lavandería, 06-10-2026). The other visible ones load right
			// away (no lazy), but without getting ahead of the first.
			if ( 0 === $slide ) {
				$tag->set_attribute( 'fetchpriority', 'high' );
			} else {
				$tag->remove_attribute( 'fetchpriority' );
			}
			$tag->remove_attribute( 'loading' );
		}
	} else {
		$tag->set_attribute( 'loading', 'lazy' );
		$tag->remove_attribute( 'fetchpriority' );
	}

	$sizes = (string) $tag->get_attribute( 'sizes' );
	// A sizes that is already a fixed width ("82px", set by the
	// featured-images module on a fixed-width featured image) is deliberate and
	// more accurate than the slide's: it is kept (1.1.x).
	$fixed = (bool) preg_match( '/^\s*(?:auto\s*,\s*)?\d+(?:\.\d+)?px\s*$/i', $sizes );
	if ( ! $fixed && null !== $tag->get_attribute( 'srcset' ) && '' !== $carousel['sizes'] ) {
		$sizes = $carousel['sizes'];
	}
	$sizes = (string) preg_replace( '/^\s*auto\s*,\s*/i', '', $sizes );
	if ( '' !== $sizes ) {
		$lazy = 'lazy' === $tag->get_attribute( 'loading' );
		$tag->set_attribute( 'sizes', ( $lazy ? 'auto, ' : '' ) . $sizes );
	}
	$html = $tag->get_updated_html();
	// 1.1.x: on mobile, a crop of the image shaped like the box (carousel-mobile-crop.php).
	if ( ! empty( $carousel['crop'] ) && (int) $attachment_id > 0 ) {
		$html = mobile_picture( $html, $carousel['crop'], (int) $attachment_id, (string) $tag->get_attribute( 'style' ) );
	}
	return $html;
}
add_filter( 'wp_content_img_tag', __NAMESPACE__ . '\images_filter', 20, 3 );
