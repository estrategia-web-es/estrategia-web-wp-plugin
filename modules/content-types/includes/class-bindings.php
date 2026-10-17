<?php
/**
 * Block Bindings source `estrategia-web/content-field`: a block bound to a
 * field of a declared type, shown WITH FORMAT. core/post-meta shows what is
 * stored — «€€» instead of the option's label, an image or a related post as
 * its ID, a boolean as 1 — and this source shows what a visitor should read
 * (the rules, type by type: class-field-format.php).
 *
 *   <!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"estrategia-web/content-field","args":{"key":"gment_price_range"}}}}} -->
 *   <!-- wp:image {"metadata":{"bindings":{"url":{"source":"estrategia-web/content-field","args":{"key":"gment_photo","size":"large"}},"alt":{"source":"estrategia-web/content-field","args":{"key":"gment_photo"}}}}} -->
 *   <!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"estrategia-web/content-field","args":{"key":"gment_winery","link":true}}}}} -->
 *
 * Args: key (the meta key, required); link (url, email, tel, relation: an
 * <a> in text); size (image); format (date, PHP date format); decimals
 * (number). The post is the one in CONTEXT, like core/post-meta: each row of
 * a Query, or the post of a single template.
 *
 * Two halves, like modules/site's sources:
 * - HERE (PHP) the site: get_value() → Field_Format::format() with env().
 * - assets/bindings.js the EDITOR: shows the formatted value in the canvas
 *   and lists the source and the type's fields in the «Attributes» panel.
 *   What it needs (the fields of every type, option labels in the user's
 *   language, the date format) arrives inline in window.estrategiaWebContentField.
 *
 * Languages: option labels and true_label/false_label are picked with
 * Plugin::locale() (on the front end with WPML, the language of the URL; not
 * determine_locale(), which can lag behind). A relation and an image are
 * resolved to their TRANSLATION with wpml_object_id (falls back to the
 * original when there is none): the title and permalink of the related post
 * are those of the request's language. Without WPML the filter returns the
 * same ID.
 *
 * How to maintain:
 * - Who and when: whoever adds a field type: the rule goes in Field_Format
 *   and assets/bindings.js, not here. Here only what needs WordPress (env()).
 * - Boundary case, with a name: the postId context ALWAYS exists (outside a
 *   Query it is the page being rendered). A key that is not a field of THAT
 *   post's type gives "": a paragraph bound to gment_price_range on a page
 *   shows nothing, it does not go looking for a restaurant.
 * - Visibility, as core/post-meta: a password-protected post, or one the
 *   visitor cannot read, gives "". A RELATED post or image that is not
 *   published (draft, trash) gives "" too: never a link to a 404.
 * - The trap: uses_context (postId, postType) is in this registration AND in
 *   assets/bindings.js. If the JS one lacks it, core merges it as [null] and
 *   the editor never gets the Query row (modules/site, class-location.php).
 */

namespace EstrategiaWeb\ContentTypes;

use EstrategiaWeb\Plugin;

defined( 'ABSPATH' ) || exit;

final class Bindings {

	public const SOURCE = 'estrategia-web/content-field';
	public const HANDLE = 'estrategia-web-content-field';

	public static function boot(): void {
		add_action( 'init', array( self::class, 'register' ), 20 ); // After the types (init 5).
		add_action( 'enqueue_block_editor_assets', array( self::class, 'enqueue_editor' ) );
	}

	public static function register(): void {
		register_block_bindings_source(
			self::SOURCE,
			array(
				'label'              => __( 'Content field', 'estrategia-web' ),
				'get_value_callback' => array( self::class, 'get_value' ),
				'uses_context'       => array( 'postId', 'postType' ),
			)
		);
	}

	/**
	 * @param array     $args           {"key": "gment_price_range", …}.
	 * @param \WP_Block $block_instance The block being rendered (the context comes from here).
	 * @param string    $attribute_name The bound attribute (content, url, alt, id…).
	 */
	public static function get_value( array $args, $block_instance, string $attribute_name ): ?string {
		$key = isset( $args['key'] ) ? (string) $args['key'] : '';
		if ( '' === $key ) {
			return null;
		}
		$context = ( is_object( $block_instance ) && isset( $block_instance->context ) ) ? (array) $block_instance->context : array();
		$post    = get_post( (int) ( $context['postId'] ?? 0 ) );
		if ( ! $post || post_password_required( $post ) || ( ! is_post_publicly_viewable( $post ) && ! current_user_can( 'read_post', $post->ID ) ) ) {
			return '';
		}
		$field = self::field( $post->post_type, $key );
		if ( ! $field ) {
			return '';
		}
		return Field_Format::format( $field, get_post_meta( $post->ID, $key, true ), $attribute_name, $args, self::env(), self::is_html( $block_instance, $attribute_name ) );
	}

	/**
	 * Whether core puts the value into the block's HTML (source html or
	 * rich-text: it only runs wp_kses_post, so we escape) or into an HTML
	 * attribute (it escapes it itself). Unknown: HTML, the safe side.
	 */
	public static function is_html( $block_instance, string $attribute_name ): bool {
		$type   = ( is_object( $block_instance ) && isset( $block_instance->block_type ) ) ? $block_instance->block_type : null;
		$source = $type && isset( $type->attributes[ $attribute_name ]['source'] ) ? $type->attributes[ $attribute_name ]['source'] : null;
		return 'attribute' !== $source;
	}

	/** The normalized field $key of the type $type, if this module registered that type. */
	public static function field( string $type, string $key ): ?array {
		$def = in_array( $type, Registrar::registered(), true ) ? Loader::type( $type ) : null;
		foreach ( $def ? $def['fields'] : array() as $field ) {
			if ( $field['name'] === $key ) {
				return $field;
			}
		}
		return null;
	}

	/** What Field_Format needs from WordPress (its header lists it). */
	public static function env(): array {
		return array(
			'locale' => Plugin::locale(),
			'yes'    => __( 'Yes', 'estrategia-web' ),
			'no'     => __( 'No', 'estrategia-web' ),
			// A date has no time: read and written in UTC so the day never shifts with the site's timezone.
			'date'   => static fn( string $ymd, string $format ): string => (string) wp_date( '' !== $format ? $format : (string) get_option( 'date_format' ), (int) strtotime( $ymd . ' 00:00:00 UTC' ), new \DateTimeZone( 'UTC' ) ),
			'number' => static fn( float $n, int $decimals ): string => number_format_i18n( $n, $decimals ),
			'post'   => array( self::class, 'related' ),
			'image'  => array( self::class, 'image' ),
			// local_image: a theme path, child first and then parent.
			'theme_file' => static fn( string $path ): string => get_theme_file_uri( $path ),
		);
	}

	/**
	 * The related post in the request's language, if it is published and of
	 * one of the field's types.
	 *
	 * @param string[] $types
	 * @return array{id:int,title:string,url:string}|null
	 */
	public static function related( int $id, array $types ): ?array {
		$type = get_post_type( $id );
		if ( ! $type || ! in_array( $type, $types, true ) ) {
			return null;
		}
		$id   = (int) apply_filters( 'wpml_object_id', $id, $type, true );
		$post = get_post( $id );
		if ( ! $post || 'publish' !== $post->post_status || post_password_required( $post ) ) {
			return null;
		}
		return array( 'id' => $id, 'title' => get_the_title( $post ), 'url' => (string) get_permalink( $post ) );
	}

	/**
	 * The image in the request's language (WPML Media can translate the alt).
	 *
	 * @return array{id:int,url:string,alt:string,title:string}|null
	 */
	public static function image( int $id, string $size ): ?array {
		$id  = (int) apply_filters( 'wpml_object_id', $id, 'attachment', true );
		$url = wp_attachment_is_image( $id ) ? wp_get_attachment_image_url( $id, $size ) : false;
		if ( ! $url ) {
			return null;
		}
		return array(
			'id'    => $id,
			'url'   => (string) $url,
			'alt'   => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
			'title' => get_the_title( $id ),
		);
	}

	/** The editor half (assets/bindings.js) and what it reads. */
	public static function enqueue_editor(): void {
		$types = array();
		foreach ( Loader::types() as $name => $def ) {
			if ( in_array( $name, Registrar::registered(), true ) && $def['fields'] ) {
				$types[ $name ] = array_map( array( self::class, 'editor_field' ), $def['fields'] );
			}
		}
		if ( ! $types ) {
			return;
		}
		wp_enqueue_script(
			self::HANDLE,
			ESTRATEGIA_WEB_CONTENT_TYPES_URL . 'assets/bindings.js',
			array( 'wp-blocks', 'wp-data', 'wp-core-data', 'wp-date' ),
			ESTRATEGIA_WEB_VERSION,
			false
		);
		$data = array(
			'source'     => self::SOURCE,
			'locale'     => str_replace( '_', '-', Plugin::locale() ),
			'dateFormat' => (string) get_option( 'date_format' ),
			// local_image: the CHILD theme (get_theme_file_uri() also tries the parent; the editor copy does not).
			'themeUrl'   => get_stylesheet_directory_uri(),
			'types'      => $types,
		);
		wp_add_inline_script( self::HANDLE, 'window.estrategiaWebContentField = ' . wp_json_encode( $data ) . ';', 'before' );
	}

	/** One field as assets/bindings.js wants it: everything already in the editor's language. */
	private static function editor_field( array $field ): array {
		$env    = self::env();
		$locale = $env['locale'];
		$labels = array();
		foreach ( $field['options'] as $option ) {
			$labels[ $option['value'] ] = Schema::text( $option['label'], $locale );
		}
		return array(
			'key'       => $field['name'],
			'kind'      => $field['type'],
			'label'     => Schema::text( $field['label'], $locale ),
			'options'   => (object) $labels,
			'yes'       => null === $field['true_label'] ? $env['yes'] : Schema::text( $field['true_label'], $locale ),
			'no'        => null === $field['false_label'] ? $env['no'] : Schema::text( $field['false_label'], $locale ),
			'integer'   => 'integer' === $field['meta_type'],
			'postTypes' => $field['post_type'],
		);
	}
}
