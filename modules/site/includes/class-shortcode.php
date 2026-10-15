<?php
/**
 * Shortcode `[estrategia_web_company key="…"]` (0.4.0): a company detail INSIDE
 * a sentence. It is what the legal notice needs (Spanish LSSI, art. 10):
 *
 *   <!-- wp:paragraph -->
 *   <p>Owner: [estrategia_web_company key="legal_name"], tax ID [estrategia_web_company key="tax_id"],
 *   registered address [estrategia_web_company key="address"]. Phone [estrategia_web_company key="phone"],
 *   email [estrategia_web_company key="email"]. Registered in the [estrategia_web_company key="registry"].</p>
 *   <!-- /wp:paragraph -->
 *
 * Why a shortcode and not a binding or a block: a WP 7.1 binding replaces the
 * WHOLE content of the paragraph (there are no inline bindings), so a sentence
 * with six details would be six paragraphs. A custom inline block (format
 * type) needs compiled JS and saves markup the editor has to know about; the
 * shortcode is one line of PHP, it is written by hand or in the content load,
 * and it works inside any text block.
 *
 * How it renders: the same value as Company::value() (all its keys, including
 * `social.<network>` and the derived ones), escaped with esc_html and on ONE
 * line: multi-line keys (address, opening hours) are joined with «, », because
 * they sit inside a sentence. A key that does not exist or is empty renders
 * nothing.
 *
 * How to maintain:
 * - Who and when: nobody, when adding a key: it comes out of Company::value()
 *   on its own. If the way a value is escaped changes in Bindings::format,
 *   check whether it applies here too (no <br> here, on purpose).
 * - Edge case: in the EDITOR the shortcode shows as is, in brackets; the value
 *   only appears on the site. That is the accepted price for not compiling
 *   anything. For a detail ALONE in its paragraph, the binding is better, since
 *   it does show.
 * - The trap: do_shortcode runs on `the_content` (priority 11) and in block
 *   templates, but NOT in a block attribute or in the title. And no shortcode
 *   inside a link: the url is not printed escaped for an attribute; for `href`,
 *   use the button or link binding.
 */

namespace EstrategiaWeb\Site;

defined( 'ABSPATH' ) || exit;

final class Shortcode {

	public const TAG = 'estrategia_web_company';

	public static function register(): void {
		add_shortcode( self::TAG, array( self::class, 'render' ) );
	}

	/** @param array|string $atts {key: "legal_name"} */
	public static function render( $atts ): string {
		$key = is_array( $atts ) && isset( $atts['key'] ) ? (string) $atts['key'] : '';
		if ( '' === $key ) {
			return '';
		}
		return esc_html( self::inline( Company::value( $key ) ) );
	}

	/** Several lines into one: «Calle Mayor 1\n07001 Palma» → «Calle Mayor 1, 07001 Palma». */
	public static function inline( string $value ): string {
		// Without the trailing comma someone already wrote («Calle Mayor 1,»): no «,,» comes out.
		$lines = array_filter( array_map( static fn( $l ) => rtrim( trim( $l ), ',' ), preg_split( '/\R/', $value ) ), 'strlen' );
		return implode( ', ', $lines );
	}
}
