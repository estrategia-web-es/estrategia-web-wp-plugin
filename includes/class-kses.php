<?php
/**
 * What wp_kses() may keep when the plugin prints HTML it built itself.
 *
 * Every piece of generated HTML is escaped where it is printed, «escaping
 * late» (WordPress.org review of 05-11-2026): echo wp_kses( $html,
 * Kses::allowed() ). The call stays at the echo, in the open, because that is
 * what a reviewer and Plugin Check read; this class only holds the list.
 *
 *   allowed()  what a post may contain, plus the form controls, the
 *              Interactivity directives and the responsive attributes of
 *              <img>, which the post list leaves out.
 *   link()     the <link rel="preload"> of the carousel, for the <head>.
 *   svg()      the SVG the site accepts (Svg_Sanitizer's own lists).
 *
 * A string of ATTRIBUTES (get_block_wrapper_attributes(),
 * wp_interactivity_data_wp_context()) does not come here: it has no tags, so
 * it goes through wp_kses_data(), which leaves it as it is.
 *
 * How to maintain:
 * - Who: whoever adds a directive, an attribute or a tag to HTML that goes
 *   through allowed(). It goes in the list in the same commit.
 * - The trap: wp_kses() fails silently. It removes what is not listed and
 *   returns 0 errors, so the button simply stops working. «data-*» in the
 *   list does NOT cover the directives: WordPress refuses a double hyphen
 *   in that wildcard, so data-wp-on--click must be listed by name
 *   (DIRECTIVES). data-wp-interactive and data-wp-context have no double
 *   hyphen, and pass as data-*.
 * - Edge case: the attribute names of the lists are in lower case, even the
 *   ones SVG writes in camelCase, because wp_kses() lowers them: viewBox comes
 *   out as viewbox. In an HTML page that is harmless (the browser's parser puts
 *   the camelCase back), so inline SVG works; do not use svg() for a standalone
 *   .svg file, which is XML.
 * - What does not come here: the inner blocks ($content of a block), which
 *   WordPress already rendered and may hold anything a block prints, and the
 *   code of a code banner (see banner-zone/render.php).
 *
 * @package EstrategiaWeb
 */

namespace EstrategiaWeb;

defined( 'ABSPATH' ) || exit;

final class Kses {

	/** The directives printed in HTML that goes through allowed(). */
	private const DIRECTIVES = array(
		'data-wp-init',
		'data-wp-watch',
		'data-wp-bind--hidden',
		'data-wp-bind--checked',
		'data-wp-bind--aria-expanded',
		'data-wp-on--change',
		'data-wp-on--click',
		'data-wp-on--keydown',
		'data-wp-on--submit',
	);

	/** Tags allowed() adds to the ones of a post, with their own attributes. */
	private const FORM_TAGS = array(
		'form'     => array( 'action', 'method', 'novalidate' ),
		'input'    => array( 'type', 'name', 'value', 'checked', 'disabled', 'required', 'readonly', 'placeholder', 'form', 'min', 'max', 'step', 'autocomplete' ),
		'select'   => array( 'name', 'form', 'disabled', 'required', 'multiple' ),
		'option'   => array( 'value', 'selected', 'disabled' ),
		'textarea' => array( 'name', 'rows', 'cols', 'disabled', 'required', 'readonly', 'placeholder', 'form' ),
		'fieldset' => array( 'disabled' ),
		'legend'   => array(),
		'label'    => array( 'for' ),
		'button'   => array( 'type', 'name', 'value', 'disabled', 'form' ),
	);

	/** Attributes every tag may carry, besides WordPress's global ones. */
	private const COMMON = array( 'tabindex', 'aria-modal', 'aria-invalid', 'aria-required', 'aria-live', 'aria-atomic' );

	/** Attributes of <img> a post's list leaves out, printed by wp_get_attachment_image(). */
	private const IMAGE = array( 'srcset', 'sizes', 'decoding', 'fetchpriority' );

	/** The post's list plus the form controls, the directives and the rest of the header. */
	public static function allowed(): array {
		static $allowed = null;
		if ( null === $allowed ) {
			$allowed = wp_kses_allowed_html( 'post' );
			foreach ( self::FORM_TAGS as $tag => $attributes ) {
				$allowed[ $tag ] = ( $allowed[ $tag ] ?? array() ) + array_fill_keys( $attributes, true );
			}
			$allowed['img'] += array_fill_keys( self::IMAGE, true );
			$extra           = array_fill_keys( array_merge( self::DIRECTIVES, self::COMMON ), true ) + array(
				'id'     => true,
				'class'  => true,
				'style'  => true,
				'title'  => true,
				'role'   => true,
				'hidden' => true,
				'data-*' => true,
			);
			foreach ( $allowed as $tag => $attributes ) {
				$allowed[ $tag ] = ( is_array( $attributes ) ? $attributes : array() ) + $extra;
			}
		}
		return $allowed;
	}

	/** The carousel's preload link. Only what preload_record() writes. */
	public static function link(): array {
		return array( 'link' => array_fill_keys( array( 'rel', 'as', 'fetchpriority', 'media', 'imagesrcset', 'imagesizes', 'href' ), true ) );
	}

	/** Svg_Sanitizer's elements and attributes, in the form wp_kses() wants them. */
	public static function svg(): array {
		// The namespaced ones: Svg_Sanitizer lists them by local name, and wp_kses() reads them whole.
		$attributes = array_fill_keys( array_map( 'strtolower', Site\Svg_Sanitizer::ATTRIBUTES ), true ) + array_fill_keys( array( 'xmlns', 'xmlns:xlink', 'xlink:href', 'xml:space' ), true );
		return array_fill_keys( array_map( 'strtolower', Site\Svg_Sanitizer::ELEMENTS ), $attributes );
	}
}
