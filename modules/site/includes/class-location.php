<?php
/**
 * Block Bindings source `estrategia-web-site/location`: a block bound to a
 * detail of ONE location (`location` type). Added in 0.2.0.
 *
 * Two ways of saying which location, through the binding args:
 *
 *   {"slug": "maioris", "key": "estrategia_web_location_phone"}   that location, from any page
 *   {"key": "estrategia_web_location_address"}                    the location in CONTEXT: inside
 *                                                  a Query of locations, the one of each row
 *
 *   <!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"estrategia-web-site/location","args":{"slug":"maioris","key":"estrategia_web_location_address"}}}}} -->
 *   <p></p>
 *   <!-- /wp:paragraph -->
 *
 * Keys: `title`, the type's location_* fields (Content_Types) and the derived
 * `estrategia_web_location_phone_href` (tel:) and `estrategia_web_location_email_href` (mailto:).
 * Text and URLs are rendered the same as in the company source
 * (Bindings::format): line breaks to <br> in text, esc_url_raw with
 * tel/mailto/http(s) in url/href.
 *
 * Why it exists: a page shows the phone and map of ONE specific location (the
 * Maioris one on the Self-service page). Before, that could only be done with
 * a Query searching by title, which breaks when the location is renamed.
 *
 * How to maintain:
 * - Who and when: whoever adds a field to `location` in Content_Types, in the
 *   same commit. Meta fields come in ON THEIR OWN (they come from
 *   Content_Types::meta_fields()); a new derived key goes in DERIVED, value()
 *   and labels(), with its README row and its case in tests/location.test.mjs.
 * - The stable identifier is the post SLUG (post_name), not the title or the
 *   id: the title changes, and the id changes when migrating servers.
 *   Edge case: renaming the location in the editor does NOT change the slug,
 *   but editing its «permalink» by hand does, and the blocks that name it go
 *   empty. That is what to check if a page loses the phone number.
 * - PUBLISHED locations only. One in draft, private or in the trash returns ''
 *   (nothing shows on the site; in the editor the detail name shows in
 *   brackets). And if the `location` type is not enabled, also ''.
 * - The trap: the postId context ALWAYS exists, also outside a Query (it is
 *   the page being rendered). That is why, without a slug, it is checked that
 *   the post really is a `location`: otherwise a standalone paragraph with
 *   {"key":"title"} would show the page title.
 * - And the other one: uses_context (postId, postType) has to be in the PHP
 *   registration AND in the assets/editor.js one; if it is missing in the JS
 *   one, core leaves it as [null] when merging and the context never reaches
 *   the editor.
 */

namespace EstrategiaWeb\Site;

defined( 'ABSPATH' ) || exit;

final class Location {

	public const SOURCE = 'estrategia-web-site/location';

	/** The type's KEY; in wp_posts it is called Type_Names::name( TYPE ) (1.2.0). */
	public const TYPE = 'location';

	public const DERIVED = array( 'estrategia_web_location_phone_href', 'estrategia_web_location_email_href' );

	public static function register(): void {
		register_block_bindings_source(
			self::SOURCE,
			array(
				'label'              => __( 'Location details', 'estrategia-web' ),
				'get_value_callback' => array( self::class, 'get_value' ),
				'uses_context'       => array( 'postId', 'postType' ),
			)
		);
	}

	/**
	 * @param array     $args           {"key": "…"} or {"slug": "…", "key": "…"}.
	 * @param \WP_Block $block_instance The block being rendered (the context comes from here).
	 * @param string    $attribute_name The bound attribute (content, url…).
	 */
	public static function get_value( array $args, $block_instance, string $attribute_name ): ?string {
		$key = isset( $args['key'] ) ? (string) $args['key'] : '';
		if ( '' === $key ) {
			return null;
		}
		$slug    = isset( $args['slug'] ) ? (string) $args['slug'] : '';
		$context = ( is_object( $block_instance ) && isset( $block_instance->context ) ) ? (array) $block_instance->context : array();
		$post    = '' !== $slug ? self::by_slug( $slug ) : self::by_id( (int) ( $context['postId'] ?? 0 ) );
		return Bindings::format( $post ? self::value( $post, $key ) : '', $attribute_name );
	}

	/** The published location with that slug, or null. */
	public static function by_slug( string $slug ): ?\WP_Post {
		if ( ! self::enabled() ) {
			return null;
		}
		$found = get_posts(
			array(
				'post_type'        => Type_Names::name( self::TYPE ),
				'name'           => sanitize_title( $slug ),
				'post_status'      => 'publish',
				'numberposts'      => 1,
				'no_found_rows'    => true,
				'suppress_filters' => true,
			)
		);
		return $found ? $found[0] : null;
	}

	/** The location with that id if it is a published `location`; otherwise (a page, a draft), null. */
	public static function by_id( int $id ): ?\WP_Post {
		if ( $id <= 0 || ! self::enabled() ) {
			return null;
		}
		$post = get_post( $id );
		return ( $post && Type_Names::name( self::TYPE ) === $post->post_type && 'publish' === $post->post_status ) ? $post : null;
	}

	/** The value as plain text (unescaped). '' if the key does not exist or is empty. */
	public static function value( \WP_Post $post, string $key ): string {
		$meta = static fn( string $k ): string => (string) get_post_meta( $post->ID, $k, true );
		switch ( $key ) {
			case 'title':
				return (string) $post->post_title;
			case 'estrategia_web_location_phone_href':
				return Links::tel( $meta( 'estrategia_web_location_phone' ), Company::country_code() );
			case 'estrategia_web_location_email_href':
				return Links::mailto( $meta( 'estrategia_web_location_email' ) );
		}
		return in_array( $key, Content_Types::meta_keys( self::TYPE ), true ) ? $meta( $key ) : '';
	}

	/** @return array<string,string> Human-readable name of each key, in order: the editor builds the list from this. */
	public static function labels(): array {
		return array( 'title' => __( 'Location name', 'estrategia-web' ) )
			+ Content_Types::meta_fields( self::TYPE )
			+ array(
				'estrategia_web_location_phone_href' => __( 'Phone (link)', 'estrategia-web' ),
				'estrategia_web_location_email_href' => __( 'Email (link)', 'estrategia-web' ),
			);
	}

	/**
	 * What assets/editor.js receives: the PUBLISHED locations with their values,
	 * by id (for the context) and with their slug (for the args). A site has few
	 * locations; if they ever were hundreds, this would switch to fetching them
	 * over REST.
	 */
	public static function editor_data(): array {
		$locations = array();
		if ( self::enabled() ) {
			$posts = get_posts(
				array(
					'post_type'        => Type_Names::name( self::TYPE ),
					'post_status'      => 'publish',
					'numberposts'      => 200,
					'orderby'          => 'title',
					'order'            => 'ASC',
					'suppress_filters' => true,
				)
			);
			foreach ( $posts as $post ) {
				$values = array();
				foreach ( array_keys( self::labels() ) as $key ) {
					$values[ $key ] = self::value( $post, $key );
				}
				$locations[] = array(
					'id'     => $post->ID,
					'slug'   => $post->post_name,
					'values' => $values,
				);
			}
		}
		return array(
			'source'    => self::SOURCE,
			'labels'    => self::labels(),
			'locations' => $locations,
		);
	}

	private static function enabled(): bool {
		// Registered by the content-types module (its location template) since 17-10-2026, not by «enabled_types».
		return post_type_exists( Type_Names::name( self::TYPE ) );
	}
}
