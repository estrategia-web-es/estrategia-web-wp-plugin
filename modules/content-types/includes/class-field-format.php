<?php
/**
 * HOW A STORED FIELD VALUE IS SHOWN: the formatting behind the Block Bindings
 * source `estrategia-web/content-field` (class-bindings.php), one rule per
 * field type and per kind of bound attribute.
 *
 * Pure PHP on purpose: everything that needs WordPress (the date format, the
 * locale's number separators, a post's title and permalink, an image's URL)
 * comes in $env as callables. Bindings::env() gives the real ones; the level
 * 1 test (tests/php/format.php) gives fakes and checks the rules with the
 * machine's PHP. The only WordPress functions called here are esc_html(),
 * esc_attr() and esc_url_raw(), which the test shims.
 *
 * THE RULES (attribute «url» or «href» = URL; «id» = ID; anything else, text):
 *
 *   type      text attribute (content, text, alt…)        url / href              id
 *   text      escaped                                      esc_url_raw (as core)   —
 *   textarea  escaped, line breaks as <br>                 esc_url_raw             —
 *   url       escaped; with "link": <a href>               the URL (http/https)    —
 *   email     escaped; with "link": <a href="mailto:">     mailto:…                —
 *   tel       escaped; with "link": <a href="tel:">        tel:… (digits and +)    —
 *   number    number_format_i18n, decimals as stored       —                       —
 *             (integer: 0; "decimals" arg overrides)
 *   date      the site's date format ("format" arg overrides)  —                   —
 *   select    the option's LABEL in the request locale     —                       —
 *             (a value no option has: the value itself)
 *   boolean   true_label / false_label of the definition,  —                       —
 *             or the translated «Yes» / «No»
 *   image     the alt text («title»: the image's title)    the image URL           the attachment ID
 *             ("size" arg: thumbnail, medium, large…; default full)
 *   relation  the related post's title; with "link": <a>  its permalink           its ID
 *
 * Empty, invalid or unpublished ⇒ "" (never the raw ID or "1").
 *
 * How to maintain:
 * - Who and when: whoever adds a FIELD TYPE (module.php, «A NEW FIELD TYPE»):
 *   its case goes in format() and in assets/bindings.js (the editor's copy of
 *   these rules), and a line in tests/format.test.mjs, in the same commit.
 * - Boundary case, with a name: a number field never filled in shows «0»,
 *   not "": the meta's registered default is 0 and «0 €» is a real answer
 *   (the same reason as Registrar::missing()). A select that was never saved
 *   shows its default's label, for the same reason.
 * - The trap, two sides of it (WP_Block::replace_html(), WP 7.1): an
 *   attribute whose source is html / rich-text (content, a button's text)
 *   goes into the block's HTML only through wp_kses_post, so it is escaped
 *   HERE ($html = true; «link» builds the only HTML this class returns); one
 *   whose source is an HTML attribute (core/image's alt and title, url) is
 *   escaped BY CORE with set_attribute, so here it goes plain ($html =
 *   false). Escaping both ways gave alt="Botella &amp;quot;Anima&amp;quot;"
 *   (measured 16-10-2026). Bindings::get_value() reads which one it is from
 *   the block type. And a link inside a BUTTON's text is invalid HTML: a
 *   button binds «url» to the field and «text» to it without «link».
 */

namespace EstrategiaWeb\ContentTypes;

defined( 'ABSPATH' ) || exit;

final class Field_Format {

	/** Block attributes that carry a URL (the rest are text, except «id»). */
	public const URL_ATTRIBUTES = array( 'url', 'href' );

	/**
	 * @param array  $field     A NORMALIZED field (Schema::normalize_field()).
	 * @param mixed  $raw       What get_post_meta( …, true ) returned.
	 * @param string $attribute The bound attribute: content, url, alt, id…
	 * @param array  $args      The binding args: link, size, format, decimals.
	 * @param array  $env       Callables and the locale: see Bindings::env().
	 *   locale  string
	 *   yes, no string                                 the translated defaults
	 *   date    fn( string $ymd, string $format ): string
	 *   number  fn( float $n, int $decimals ): string
	 *   post    fn( int $id, string[] $types ): ?array{id:int,title:string,url:string}
	 *   image   fn( int $id, string $size ): ?array{id:int,url:string,alt:string,title:string}
	 * @param bool   $html      true: the value goes into HTML (escaped here); false: into an HTML attribute (plain).
	 */
	public static function format( array $field, $raw, string $attribute, array $args, array $env, bool $html = true ): string {
		$kind = in_array( $attribute, self::URL_ATTRIBUTES, true ) ? 'url' : ( 'id' === $attribute ? 'id' : 'text' );
		$link = ! empty( $args['link'] ) && 'text' === $kind && $html;
		$str  = is_scalar( $raw ) ? trim( (string) $raw ) : '';
		// Into HTML: escaped here. Into an HTML ATTRIBUTE (core/image's alt): plain, core escapes it.
		$e = static fn( string $text ): string => $html ? esc_html( $text ) : $text;

		switch ( $field['type'] ) {
			case 'text':
			case 'textarea':
				if ( 'url' === $kind ) {
					return '' === $str ? '' : esc_url_raw( $str, array( 'http', 'https', 'tel', 'mailto' ) );
				}
				if ( 'id' === $kind ) {
					return '';
				}
				return 'textarea' === $field['type'] && $html ? nl2br( esc_html( $str ), false ) : $e( $str );

			case 'rating':
				// The number, like core/post-meta: «★★★★» would be read out as «black star» four times. 0 is «no rating».
				return 'text' === $kind && (int) $str > 0 ? $e( (string) min( 5, (int) $str ) ) : '';

			case 'local_image':
				if ( 'id' === $kind || '' === $str ) {
					return '';
				}
				if ( 'url' !== $kind ) {
					return $e( $str );
				}
				// Stored already checked (Sanitize::local_image): a same-site URL, a site path or a THEME path.
				if ( preg_match( '#^/[^/]#', $str ) ) {
					return $str; // A site path: the sanitizer only lets URL-safe characters through.
				}
				if ( preg_match( '#^(https?:)?//#i', $str ) ) {
					return esc_url_raw( $str, array( 'http', 'https' ) );
				}
				return isset( $env['theme_file'] ) ? esc_url_raw( (string) call_user_func( $env['theme_file'], $str ) ) : '';

			case 'url':
			case 'embed':
			case 'email':
			case 'tel':
				$href = self::href( $field['type'], $str );
				if ( 'url' === $kind ) {
					return $href;
				}
				if ( 'id' === $kind || '' === $href ) {
					return '';
				}
				return $link ? '<a href="' . esc_attr( $href ) . '">' . esc_html( $str ) . '</a>' : $e( $str );

			case 'number':
				if ( 'text' !== $kind || ! is_numeric( $str ) ) {
					return '';
				}
				return $e( (string) call_user_func( $env['number'], (float) $str, self::decimals( $field, $str, $args ) ) );

			case 'date':
				if ( 'text' !== $kind || ! Schema::is_date( $str ) ) {
					return '';
				}
				$format = isset( $args['format'] ) && is_string( $args['format'] ) ? $args['format'] : '';
				return $e( (string) call_user_func( $env['date'], $str, $format ) );

			case 'select':
				if ( 'text' !== $kind || '' === $str ) {
					return '';
				}
				foreach ( $field['options'] as $option ) {
					if ( $option['value'] === $str ) {
						return $e( Schema::text( $option['label'], $env['locale'] ) );
					}
				}
				return $e( $str ); // The option was removed from the file after it was saved.

			case 'boolean':
				if ( 'text' !== $kind ) {
					return '';
				}
				$on    = in_array( $raw, array( true, 1, '1', 'true' ), true );
				$label = $on ? $field['true_label'] : $field['false_label'];
				return $e( null === $label ? ( $on ? $env['yes'] : $env['no'] ) : Schema::text( $label, $env['locale'] ) );

			case 'image':
				$size  = isset( $args['size'] ) && is_string( $args['size'] ) && '' !== $args['size'] ? $args['size'] : 'full';
				$image = (int) $str > 0 ? call_user_func( $env['image'], (int) $str, $size ) : null;
				if ( ! $image ) {
					return '';
				}
				if ( 'url' === $kind ) {
					return esc_url_raw( $image['url'] );
				}
				if ( 'id' === $kind ) {
					return (string) $image['id'];
				}
				return $e( 'title' === $attribute ? $image['title'] : $image['alt'] );

			case 'relation':
				$post = (int) $str > 0 ? call_user_func( $env['post'], (int) $str, $field['post_type'] ) : null;
				if ( ! $post ) {
					return '';
				}
				if ( 'url' === $kind ) {
					return esc_url_raw( $post['url'] );
				}
				if ( 'id' === $kind ) {
					return (string) $post['id'];
				}
				// get_the_title() is already HTML (entities, texturized): plain text decodes it.
				$title = $html ? esc_html( $post['title'] ) : html_entity_decode( $post['title'], ENT_QUOTES, 'UTF-8' );
				return $link ? '<a href="' . esc_attr( esc_url_raw( $post['url'] ) ) . '">' . $title . '</a>' : $title;
		}
		return '';
	}

	/** The URL a url, email or tel value links to; "" if it cannot be one. */
	public static function href( string $type, string $value ): string {
		if ( '' === $value ) {
			return '';
		}
		return match ( $type ) {
			'email' => str_contains( $value, '@' ) ? esc_url_raw( 'mailto:' . $value, array( 'mailto' ) ) : '',
			// tel: wants digits and a leading +; spaces, dots and brackets are for people.
			'tel'   => '' === ( $digits = (string) preg_replace( '/[^0-9+]/', '', $value ) ) ? '' : 'tel:' . $digits,
			default => esc_url_raw( $value, array( 'http', 'https' ) ),
		};
	}

	/** Decimals to show: 0 for an integer field, the "decimals" arg, or as many as were stored (at most 6). */
	public static function decimals( array $field, string $stored, array $args ): int {
		if ( isset( $args['decimals'] ) && is_numeric( $args['decimals'] ) ) {
			return max( 0, min( 6, (int) $args['decimals'] ) );
		}
		if ( 'integer' === $field['meta_type'] ) {
			return 0;
		}
		$fraction = str_contains( $stored, '.' ) ? rtrim( substr( $stored, strpos( $stored, '.' ) + 1 ), '0' ) : '';
		return min( 6, strlen( $fraction ) );
	}
}
