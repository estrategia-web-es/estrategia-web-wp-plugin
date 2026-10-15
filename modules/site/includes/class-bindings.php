<?php
/**
 * Block Bindings source `estrategia-web-site/company`: a paragraph, a heading,
 * a button or an image bound to a company detail.
 *
 *   <!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"estrategia-web-site/company","args":{"key":"phone"}}}}} -->
 *   <p></p>
 *   <!-- /wp:paragraph -->
 *
 * Two halves, and both are needed:
 * - HERE (PHP) it is rendered on the site: get_value() returns the value for
 *   the bound attribute.
 * - assets/editor.js (JS, uncompiled) makes the EDITOR show the value and the
 *   source appear in the blocks' «Attributes» panel. Without it, the editor
 *   shows the block's placeholder text and does not let you pick the source.
 *   The values reach it through wp_add_inline_script (enqueue_editor), not
 *   through REST: /wp/v2/settings can only be read by an administrator, and an
 *   editor also has to see the phone number they are binding.
 *
 * How to maintain:
 * - A new key is added in Company (not here): this class has no list.
 * - Since 0.2.0 there is a second source, `estrategia-web-site/location` (the data of
 *   ONE location), in includes/class-location.php. It shares format() with this one
 *   and the same editor script; its data reaches it in window.estrategiaWebSiteLocation.
 *   If you change how a text or a URL is rendered, it changes in both: that is
 *   what we want.
 * - Edge case: address and opening hours have several lines. In TEXT
 *   attributes (content, text, alt…) line breaks come out as <br>; in URL
 *   attributes (url, href) the value goes as is. That is why $attribute_name
 *   is checked.
 * - The trap: core escapes on its own whatever goes into an HTML attribute
 *   (url), but rich content only goes through wp_kses_post. If it were not
 *   escaped here, a «<» in the company name would be HTML.
 */

namespace EstrategiaWeb\Site;

defined( 'ABSPATH' ) || exit;

final class Bindings {

	public const SOURCE = 'estrategia-web-site/company';

	/** Block attributes that carry a URL (the rest are treated as text). */
	private const URL_ATTRIBUTES = array( 'url', 'href' );

	public static function register(): void {
		register_block_bindings_source(
			self::SOURCE,
			array(
				'label'              => __( 'Company details', 'estrategia-web' ),
				'get_value_callback' => array( self::class, 'get_value' ),
			)
		);
	}

	/**
	 * @param array     $args           Binding args: {"key": "phone"}.
	 * @param \WP_Block $block_instance The block being rendered.
	 * @param string    $attribute_name The bound attribute (content, url…).
	 */
	public static function get_value( array $args, $block_instance, string $attribute_name ): ?string {
		$key = isset( $args['key'] ) ? (string) $args['key'] : '';
		if ( '' === $key ) {
			return null;
		}
		return self::format( Company::value( $key ), $attribute_name );
	}

	/**
	 * A plain-text value, ready for the bound attribute. Shared by this source
	 * and `estrategia-web-site/location` (Location): the same treatment in both,
	 * on purpose.
	 */
	public static function format( string $value, string $attribute_name ): string {
		if ( in_array( $attribute_name, self::URL_ATTRIBUTES, true ) ) {
			return '' === $value ? '' : esc_url_raw( $value, array( 'http', 'https', 'tel', 'mailto' ) );
		}
		return nl2br( esc_html( $value ), false );
	}

	/** Values and names for the editor (assets/editor.js). */
	public static function enqueue_editor(): void {
		wp_enqueue_script(
			'estrategia-web-site-editor',
			ESTRATEGIA_WEB_SITE_URL . 'assets/editor.js',
			array( 'wp-blocks', 'wp-data', 'wp-block-editor' ),
			ESTRATEGIA_WEB_SITE_VERSION,
			false
		);
		$data = array(
			'source' => self::SOURCE,
			'values' => Company::values(),
			'labels' => Company::labels(),
		);
		wp_add_inline_script( 'estrategia-web-site-editor', 'window.estrategiaWebSiteCompany = ' . wp_json_encode( $data ) . ';', 'before' );
		wp_add_inline_script( 'estrategia-web-site-editor', 'window.estrategiaWebSiteLocation = ' . wp_json_encode( Location::editor_data() ) . ';', 'before' );
	}
}
