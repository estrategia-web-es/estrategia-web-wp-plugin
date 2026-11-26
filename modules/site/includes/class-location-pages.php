<?php
namespace EstrategiaWeb\Site;

defined( 'ABSPATH' ) || exit;

/**
 * WHICH PAGE STANDS FOR A LOCATION, and which locations a page is about
 * (2.18.0, moved here from modules/local-business/includes/class-data.php).
 *
 *   url( $location )        the page a visitor goes to for that location:
 *                           - a location type WITH public pages: its own permalink;
 *                           - WITHOUT them (Llar d'Estels, «public»: false): the published
 *                             page TIED to it, the one whose blocks bind to its slug;
 *                           - no tied page: the home URL, never a URL that does not resolve.
 *   tied_to( $post )        the locations a page's content binds to by slug.
 *   bound_slugs( $blocks )  the slugs of estrategia-web-site/location bindings in parsed blocks.
 *
 * Who uses it: the binding source (class-location.php, the DERIVED key
 * `estrategia_web_location_page_url`: the button of each card in a Query of
 * locations) and modules/local-business (the department url on the front
 * page; which location a page is about).
 *
 * Why HERE and not in local-business: the site module is core (always
 * loaded, module.php) and local-business requires it, so both can call it;
 * a binding that only worked with the schema module switched on would come
 * out empty on a site that does not want JSON-LD. And the link it reads —
 * a block bound to the location's slug — is THIS module's binding source.
 *
 * How to maintain:
 * - Who and when: whoever changes how a page is tied to a location changes
 *   it here, once; both users follow. The level 2 checks are
 *   modules/site/tests/location.test.mjs («page_url») and
 *   modules/local-business/tests/output.test.mjs (the department url).
 * - Edge case, with a name: a page tied only through a SYNCED PATTERN is not
 *   found by url() (the LIKE reads the page's content, and the binding is in
 *   the pattern): home. tied_to() does read patterns. Two tied pages: the
 *   oldest one (lowest ID).
 * - The trap that cost the English pages (measured 22-10-2026 with real
 *   WPML): a WP_Query by «name» on an /en/ request gets «AND ID = <the
 *   English copy's id>» added by WPML (its parse_query, which
 *   suppress_filters does not stop) next to «post_name = 'maioris'», and
 *   finds NOTHING. tied_to() looks the slug up with get_page_by_path(),
 *   plain SQL, and then takes the translation itself.
 * - The other one: url() runs one LIKE query over post_content per location;
 *   memoised per request (a Query of three locations, the editor's data and
 *   the front page's JSON-LD on the same request ask for the same ones).
 */
final class Location_Pages {

	/** @var array<int,string> url() per location id, this request. */
	private static array $urls = array();

	public static function url( \WP_Post $location ): string {
		if ( isset( self::$urls[ $location->ID ] ) ) {
			return self::$urls[ $location->ID ];
		}
		if ( is_post_type_viewable( $location->post_type ) ) {
			$url = (string) get_permalink( $location );
		} else {
			$page = self::page_tied_to( $location );
			$url  = $page ? (string) get_permalink( self::translated( $page ) ) : (string) apply_filters( 'wpml_home_url', home_url( '/' ) );
		}
		self::$urls[ $location->ID ] = $url;
		return $url;
	}

	/** The oldest published page of a viewable type whose blocks bind to this location, or null. */
	private static function page_tied_to( \WP_Post $location ): ?\WP_Post {
		global $wpdb;
		$slug = self::original( $location )->post_name;
		if ( '' === $slug ) {
			return null;
		}
		$types = array_values( array_filter( get_post_types( array(), 'names' ), 'is_post_type_viewable' ) );
		if ( ! $types ) {
			return null;
		}
		$in  = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- a LIKE over post_content has no API; once per location and request (memoised in url()).
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ($in) AND post_content LIKE %s AND post_content LIKE %s ORDER BY ID ASC LIMIT 20", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in is placeholders only.
				array_merge( $types, array( '%' . $wpdb->esc_like( Location::SOURCE ) . '%', '%' . $wpdb->esc_like( '"slug":"' . $slug . '"' ) . '%' ) )
			)
		);
		foreach ( $ids as $id ) {
			$post = get_post( (int) $id );
			// The LIKE is a sieve; tied_to() is the rule (the slug inside a location binding, not elsewhere).
			if ( $post && in_array( $location->ID, array_map( static fn( $l ) => $l->ID, self::tied_to( $post ) ), true ) ) {
				return $post;
			}
		}
		return null;
	}

	/** The locations a page's content binds to by slug, in the request's language. @return \WP_Post[] */
	public static function tied_to( \WP_Post $post ): array {
		$type  = Type_Names::name( Location::TYPE );
		$slugs = self::bound_slugs( parse_blocks( (string) $post->post_content ), array() );
		$out   = array();
		foreach ( array_unique( $slugs ) as $slug ) {
			// get_page_by_path() and not Location::by_slug(): see «The trap» (WPML and a slug query on /en/).
			$location = post_type_exists( $type ) ? get_page_by_path( sanitize_title( $slug ), OBJECT, $type ) : null;
			if ( $location instanceof \WP_Post && 'publish' === $location->post_status ) {
				$out[] = self::translated( $location );
			}
		}
		return $out;
	}

	/**
	 * The slugs of estrategia-web-site/location bindings in a list of parsed
	 * blocks, inner blocks and synced patterns included.
	 *
	 * @param int[] $seen Synced patterns already read (a pattern that includes itself).
	 * @return string[]
	 */
	public static function bound_slugs( array $blocks, array $seen ): array {
		$out = array();
		foreach ( $blocks as $block ) {
			foreach ( (array) ( $block['attrs']['metadata']['bindings'] ?? array() ) as $binding ) {
				if ( is_array( $binding ) && Location::SOURCE === ( $binding['source'] ?? '' ) && '' !== (string) ( $binding['args']['slug'] ?? '' ) ) {
					$out[] = (string) $binding['args']['slug'];
				}
			}
			$ref = (int) ( $block['attrs']['ref'] ?? 0 );
			if ( 'core/block' === ( $block['blockName'] ?? '' ) && $ref && ! in_array( $ref, $seen, true ) ) {
				$pattern = get_post( $ref );
				if ( $pattern && 'wp_block' === $pattern->post_type ) {
					$out = array_merge( $out, self::bound_slugs( parse_blocks( (string) $pattern->post_content ), array_merge( $seen, array( $ref ) ) ) );
				}
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				$out = array_merge( $out, self::bound_slugs( $block['innerBlocks'], $seen ) );
			}
		}
		return $out;
	}

	/** The post in the request's language (WPML), or itself. */
	public static function translated( \WP_Post $post ): \WP_Post {
		$id = (int) apply_filters( 'wpml_object_id', $post->ID, $post->post_type, true );
		return ( $id && $id !== $post->ID && get_post( $id ) ) ? get_post( $id ) : $post;
	}

	/** The post in the default language (WPML), or itself. */
	public static function original( \WP_Post $post ): \WP_Post {
		$lang = apply_filters( 'wpml_default_language', null );
		if ( ! is_string( $lang ) || '' === $lang ) {
			return $post;
		}
		$id = (int) apply_filters( 'wpml_object_id', $post->ID, $post->post_type, true, $lang );
		return ( $id && $id !== $post->ID && get_post( $id ) ) ? get_post( $id ) : $post;
	}
}
