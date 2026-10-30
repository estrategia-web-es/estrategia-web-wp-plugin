<?php
namespace EstrategiaWeb\FeaturedImages;

defined( 'ABSPATH' ) || exit;

/**
 * Module Name: Featured image tuning
 * Description: Featured images with a fixed width in px download a file of that size, and a logo whose alt text only repeats the title shown next to it is marked as decorative.
 * Default: on
 * Requires:
 * Category: Content
 *
 * Born with the client cards on Lavandería's home page (14-10-2026): a
 * core/post-featured-image inside a Query, at 82×54 px, served a 300×224
 * image with sizes="auto, (max-width:1384px) 100vw, 1384px" (PageSpeed:
 * improperly sized images) and its alt repeated the client name the card
 * already shows next to it (Lighthouse, accessibility: "redundant alt
 * text"). Three generic fixes:
 *
 * 1. sizes: if the block has a FIXED width in px (width attribute "82px"),
 *    the <img> gets sizes="82px" (with "auto, " in front if it is lazy).
 *    Other units (%, em, vw) or no width: left alone; there WordPress' sizes
 *    or the container's (the carousel) is better than a guess.
 *    The carousel respects a sizes that is already a fixed px width (see
 *    modules/carousel/includes/carousel-images.php).
 * 2. A small size, "estrategia-web-small": 200 px wide, free height, not
 *    cropped. Without it, between the thumbnail (150×150, CROPPED: it does
 *    not go into the srcset of a non-square image) and medium (300) there is
 *    no candidate for 82 px at 2x. It is generated on EVERY new upload (one
 *    more file of a few KB per photo). EXISTING UPLOADS DO NOT HAVE IT: on
 *    the server, run `wp media regenerate --only-missing --yes` once.
 * 3. alt="" (decorative) when the featured image is in the template of a
 *    Query (core/post-template) that ALSO shows the post title
 *    (core/post-title), and the image's alt IS that title (equal ignoring
 *    case, spaces and tags). Written criterion: only what is already read
 *    next to it, word for word, is removed; an alt that describes the photo
 *    ("Industrial dryers…") is never touched, even if there is a title. And
 *    never if the image is a LINK (isLink): without alt, the link would have
 *    no name.
 *
 * Why it ships on: Lavandería, already deployed, uses it, and it changes
 * nothing visible (the alt is only heard by a screen reader, and there it
 * stops repeating). It switches itself on when deployed.
 *
 * How to maintain:
 * - Who: whoever touches featured images or the carousel. When: if
 *   WordPress starts setting a sizes for the fixed width itself (check on
 *   every major version: the day it does, point 1 is redundant).
 * - Edge case, by name: the client card WITH a link to its website
 *   (featured image with isLink). There the alt stays even if it repeats the
 *   title: it is the link's name.
 * - Filter: estrategia_web_featured_image_decorative( $decorative, $post_id, $alt )
 *   to decide case by case.
 * - The pitfall: the carousel runs AFTER (wp_content_img_tag, over the whole
 *   template) and used to set its sizes on every photo with a srcset, these
 *   too. That is why it now respects a sizes that is already "Npx".
 * - Test: modules/featured-images/tests/ (level 2).
 */

const SMALL = 'estrategia-web-small';

add_action( 'after_setup_theme', static fn() => add_image_size( SMALL, 200, 0, false ), 20 );

/**
 * 1. sizes = the fixed width in px.
 */
add_filter(
	'render_block_core/post-featured-image',
	static function ( $html, $block ) {
		$width = trim( (string) ( $block['attrs']['width'] ?? '' ) );
		if ( ! preg_match( '/^\d+(?:\.\d+)?px$/', $width ) || ! str_contains( (string) $html, '<img' ) ) {
			return $html;
		}
		$tag = new \WP_HTML_Tag_Processor( (string) $html );
		while ( $tag->next_tag( 'IMG' ) ) {
			if ( null === $tag->get_attribute( 'srcset' ) ) {
				continue;
			}
			$lazy = 'lazy' === $tag->get_attribute( 'loading' );
			$tag->set_attribute( 'sizes', ( $lazy ? 'auto, ' : '' ) . $width );
		}
		return $tag->get_updated_html();
	},
	10,
	2
);

/**
 * Whether a parsed template (inner blocks of core/post-template) shows the
 * post title and a featured image that is not a link.
 *
 * @param array $blocks Parsed blocks.
 * @return array{title: bool, image: bool, link: bool}
 */
function scan( array $blocks ): array {
	$found = array(
		'title' => false,
		'image' => false,
		'link'  => false,
	);
	foreach ( $blocks as $block ) {
		$name = $block['blockName'] ?? '';
		if ( 'core/post-title' === $name ) {
			$found['title'] = true;
		} elseif ( 'core/post-featured-image' === $name ) {
			$found['image'] = true;
			$found['link']  = $found['link'] || ! empty( $block['attrs']['isLink'] );
		}
		if ( ! empty( $block['innerBlocks'] ) ) {
			$inner = scan( $block['innerBlocks'] );
			$found = array(
				'title' => $found['title'] || $inner['title'],
				'image' => $found['image'] || $inner['image'],
				'link'  => $found['link'] || $inner['link'],
			);
		}
	}
	return $found;
}

/**
 * Pure: two texts are «the same» for a reader (case, spaces, tags and entities ignored).
 *
 * @param string $a One text.
 * @param string $b The other.
 */
function same_text( string $a, string $b ): bool {
	$norm = static fn( string $s ): string => mb_strtolower( trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( $s ), ENT_QUOTES, 'UTF-8' ) ) ) );
	return '' !== $norm( $a ) && $norm( $a ) === $norm( $b );
}

/**
 * 3. alt="" when the image only repeats the title shown in the same entry.
 */
add_filter(
	'render_block_core/post-template',
	static function ( $html, $block ) {
		if ( ! str_contains( (string) $html, 'wp-post-image' ) ) {
			return $html;
		}
		$found = scan( (array) ( $block['innerBlocks'] ?? array() ) );
		if ( ! $found['title'] || ! $found['image'] || $found['link'] ) {
			return $html;
		}
		$tag  = new \WP_HTML_Tag_Processor( (string) $html );
		$post = 0;
		while ( $tag->next_tag() ) {
			if ( 'LI' === $tag->get_tag() && $tag->has_class( 'wp-block-post' ) ) {
				$post = 0;
				foreach ( $tag->class_list() as $class ) {
					if ( preg_match( '/^post-(\d+)$/', $class, $m ) ) {
						$post = (int) $m[1];
					}
				}
				continue;
			}
			if ( 'IMG' !== $tag->get_tag() || ! $tag->has_class( 'wp-post-image' ) || ! $post ) {
				continue;
			}
			$alt        = (string) $tag->get_attribute( 'alt' );
			$decorative = same_text( $alt, get_the_title( $post ) );
			/**
			 * Whether a featured image in a query is decorative (alt="") because the
			 * entry already shows its title. See modules/featured-images/module.php.
			 *
			 * @param bool   $decorative Default: its alt text is the post title.
			 * @param int    $post_id    Post id.
			 * @param string $alt        Current alt text.
			 */
			if ( apply_filters( 'estrategia_web_featured_image_decorative', $decorative, $post, $alt ) ) {
				$tag->set_attribute( 'alt', '' );
			}
		}
		return $tag->get_updated_html();
	},
	10,
	2
);
