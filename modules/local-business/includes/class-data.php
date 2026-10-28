<?php
/**
 * WHAT THE GRAPH IS MADE OF, read from WordPress: the company (site's
 * Company and the social module's links), the locations (the location type
 * of the content-types module, its template's fields) and which page is
 * being served. Everything comes out as the plain arrays Graph wants.
 *
 * WHICH LOCATION A PAGE IS ABOUT:
 *   - its own page (single eweb_location);
 *   - a page TIED to it: one whose content binds a block to it by slug with
 *     the source estrategia-web-site/location
 *     ({"source":"estrategia-web-site/location","args":{"slug":"maioris",…}}),
 *     also inside a synced pattern (core/block) it uses. Lavandería:
 *     /autoservicio/ → maioris, /lavanderia-industrial/ → son-oms.
 *     A page that names two locations gets both.
 *   - the front page gets none of its own: it is the Organization's, with
 *     every location as a department.
 *   NOT seen, on purpose: a binding in a TEMPLATE or a template part (the
 *   theme's, not the page's content) and a Query of locations (Contacto,
 *   «Quiénes somos»): a list of locations is not a page ABOUT one.
 *
 * WPML: the location is looked up by its slug in the original language (the
 * slug the binding carries) and then taken in the request's language
 * (wpml_object_id), so the name is the translated one and the URL the
 * page's in that language. A field empty in the translation is read from
 * the original (they are «copy» fields: empty means not copied yet).
 *
 * How to maintain:
 * - Who and when: whoever adds a property that comes from WordPress: its
 *   value here, its use in Graph. A new location field is first a field of
 *   modules/content-types/templates/location.json (and FIELDS below).
 * - Boundary case, with a name: a location whose «Type of business» is
 *   «The company's» (the template's default) takes the company's type
 *   («Company details › Type of business»).
 * - The trap that cost the English pages (measured 22-10-2026 with real
 *   WPML): a WP_Query by «name» on an /en/ request gets «AND ID = <the
 *   English copy's id>» added by WPML (its parse_query, which
 *   suppress_filters does not stop) next to «post_name = 'maioris'», and
 *   finds NOTHING. tied_to() looks the slug up with get_page_by_path(),
 *   plain SQL, and then takes the translation itself.
 * - The other one: the get_posts() of all_locations() uses
 *   suppress_filters, so WPML does not narrow them to the request's language
 *   — and the English copies (maioris-en) come back TOO in the list of all
 *   locations. all_locations() keeps one per original, in the request's
 *   language; without that the front page would list four departments.
 */

namespace EstrategiaWeb\LocalBusiness;

use EstrategiaWeb\Site\Company;
use EstrategiaWeb\Site\Location;
use EstrategiaWeb\Site\Type_Names;

defined( 'ABSPATH' ) || exit;

final class Data {

	/** The company's type of business (Company details). A Types::ALL name. */
	public const OPTION = 'estrategia_web_local_business_type';

	/** The location template's fields this module reads. */
	public const FIELDS = array(
		'phone'       => 'estrategia_web_location_phone',
		'email'       => 'estrategia_web_location_email',
		'street'      => 'estrategia_web_location_street',
		'postal_code' => 'estrategia_web_location_postal_code',
		'locality'    => 'estrategia_web_location_locality',
		'region'      => 'estrategia_web_location_region',
		'country'     => 'estrategia_web_location_country',
		'hours'       => 'estrategia_web_location_hours',
		'latitude'    => 'estrategia_web_location_latitude',
		'longitude'   => 'estrategia_web_location_longitude',
		'type'        => 'estrategia_web_location_business_type',
		'static_id'   => 'estrategia_web_location_static_map_id',
		'static_map'  => 'estrategia_web_location_static_map',
	);

	public static function business_type(): string {
		return Types::valid( get_option( self::OPTION ) ) ?? Types::DEFAULT;
	}

	/** <home>/#organization, the same in every language (the home of the default language). */
	public static function organization_id(): string {
		return trailingslashit( (string) get_option( 'home' ) ) . '#organization';
	}

	/** @return array{hours:string,type:string,id:string,name:string,url:string,logo:string,telephone:string,email:string,same_as:string[]} */
	public static function company(): array {
		$logo_id = (int) get_option( 'site_logo' ) ?: (int) get_theme_mod( 'custom_logo' );
		$same_as = array();
		if ( class_exists( \EstrategiaWeb\Social\Links::class ) ) {
			foreach ( \EstrategiaWeb\Social\Links::items() as $item ) {
				$same_as[] = (string) $item['url'];
			}
		}
		$name = Company::value( 'company_name' );
		// 2.0.0: the company's hours (Company details) speak for the business only on a site with no
		// locations — with locations, each has its own and the company's field is hidden.
		$hours = self::all_locations() ? '' : (string) \EstrategiaWeb\Site\Settings::get( 'hours' );
		return array(
			'hours'     => $hours,
			'type'      => self::business_type(),
			'id'        => self::organization_id(),
			'name'      => '' !== $name ? $name : wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
			'url'       => (string) apply_filters( 'wpml_home_url', home_url( '/' ) ),
			'logo'      => $logo_id ? (string) wp_get_attachment_image_url( $logo_id, 'full' ) : '',
			'telephone' => Company::value( 'phone' ),
			'email'     => Company::value( 'email' ),
			'same_as'   => $same_as,
		);
	}

	/**
	 * What Graph builds for the page being served: [Organization or null,
	 * LocalBusiness nodes]. Both empty: nothing to say on this page.
	 *
	 * @return array{0: ?array, 1: array[]}
	 */
	public static function current(): array {
		$company = self::company();
		if ( is_front_page() ) {
			$departments = array();
			foreach ( self::all_locations() as $location ) {
				$node = self::location_node( $location, (string) get_permalink( $location ), '' );
				if ( $node ) {
					$departments[] = $node;
				}
			}
			return array( Graph::organization( $company, $departments ), array() );
		}
		if ( ! is_singular() ) {
			return array( null, array() );
		}
		$post = get_queried_object();
		if ( ! $post instanceof \WP_Post ) {
			return array( null, array() );
		}
		$locations = self::location_type() === $post->post_type ? array( $post ) : self::tied_to( $post );
		$nodes     = array();
		foreach ( $locations as $location ) {
			$node = self::location_node( $location, (string) get_permalink( $post ), $company['id'] );
			if ( $node ) {
				$nodes[] = $node;
			}
		}
		return $nodes ? array( Graph::organization( $company ), $nodes ) : array( null, array() );
	}

	/** One location (in the request's language) as a LocalBusiness node, or null. */
	public static function location_node( \WP_Post $location, string $url, string $parent ): ?array {
		$original = self::original( $location );
		$meta     = static function ( string $key ) use ( $location, $original ): string {
			$value = get_post_meta( $location->ID, self::FIELDS[ $key ], true );
			if ( ( '' === $value || null === $value ) && $original->ID !== $location->ID ) {
				$value = get_post_meta( $original->ID, self::FIELDS[ $key ], true );
			}
			return is_scalar( $value ) ? trim( (string) $value ) : '';
		};
		$own_type = $meta( 'type' );
		return Graph::local_business(
			array(
				'id'          => self::location_id( $location ),
				'type'        => Types::valid( $own_type ) ?? self::business_type(),
				'name'        => wp_specialchars_decode( get_the_title( $location ), ENT_QUOTES ),
				'url'         => $url,
				'image'       => self::image( $location, $meta ),
				'telephone'   => '' !== $meta( 'phone' ) ? $meta( 'phone' ) : Company::value( 'phone' ),
				'email'       => $meta( 'email' ),
				// 2.0.0: the street may have two lines (what the page shows); Google gets them on one.
				'street'      => \EstrategiaWeb\ContentTypes\Location_Data::street_line( $meta( 'street' ) ),
				'postal_code' => $meta( 'postal_code' ),
				'locality'    => $meta( 'locality' ),
				'region'      => $meta( 'region' ),
				'country'     => $meta( 'country' ),
				'hours'       => $meta( 'hours' ),
				'latitude'    => $meta( 'latitude' ),
				'longitude'   => $meta( 'longitude' ),
				'parent'      => $parent,
			)
		);
	}

	/** The locations a page's content binds to by slug, in the request's language. @return \WP_Post[] */
	public static function tied_to( \WP_Post $post ): array {
		$slugs = self::bound_slugs( parse_blocks( (string) $post->post_content ), array() );
		$out   = array();
		foreach ( array_unique( $slugs ) as $slug ) {
			// get_page_by_path() and not Location::by_slug(): see «The trap» (WPML and a slug query on /en/).
			$location = post_type_exists( self::location_type() ) ? get_page_by_path( sanitize_title( $slug ), OBJECT, self::location_type() ) : null;
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

	/** Every published location, ONE per original, in the request's language (see «The trap»). @return \WP_Post[] */
	public static function all_locations(): array {
		if ( ! post_type_exists( self::location_type() ) ) {
			return array();
		}
		$posts = get_posts(
			array(
				'post_type'        => self::location_type(),
				'post_status'      => 'publish',
				'numberposts'      => 100,
				'orderby'          => array( 'menu_order' => 'ASC', 'title' => 'ASC' ),
				'suppress_filters' => true,
			)
		);
		$out = array();
		foreach ( $posts as $post ) {
			$here = self::translated( $post );
			if ( 'publish' === $here->post_status ) {
				$out[ $here->ID ] = $here;
			}
		}
		return array_values( $out );
	}

	public static function location_type(): string {
		return class_exists( Type_Names::class ) ? Type_Names::name( 'location' ) : 'eweb_location';
	}

	/** <its permalink>#localbusiness; a type without public pages: <home>/#location-<slug>. */
	private static function location_id( \WP_Post $location ): string {
		return is_post_type_viewable( $location->post_type )
			? (string) get_permalink( $location ) . '#localbusiness'
			: trailingslashit( (string) get_option( 'home' ) ) . '#location-' . self::original( $location )->post_name;
	}

	/** Its featured image, the static map (media library, then path), or the logo. */
	private static function image( \WP_Post $location, callable $meta ): string {
		$url = (string) get_the_post_thumbnail_url( $location, 'full' );
		if ( '' === $url && (int) $meta( 'static_id' ) > 0 ) {
			$url = (string) wp_get_attachment_image_url( (int) $meta( 'static_id' ), 'full' );
		}
		$path = $meta( 'static_map' );
		if ( '' === $url && '' !== $path ) {
			// A site path is from the domain's root (Sanitize::local_image); not home_url(), which WPML gives an /en/.
			$home = wp_parse_url( (string) get_option( 'home' ) );
			$root = ( $home['scheme'] ?? 'https' ) . '://' . ( $home['host'] ?? '' ) . ( isset( $home['port'] ) ? ':' . $home['port'] : '' );
			$url  = preg_match( '#^https?://#i', $path ) ? $path : ( str_starts_with( $path, '/' ) ? $root . $path : get_theme_file_uri( $path ) );
		}
		return '' !== $url ? $url : self::company()['logo'];
	}

	private static function translated( \WP_Post $post ): \WP_Post {
		$id = (int) apply_filters( 'wpml_object_id', $post->ID, $post->post_type, true );
		return ( $id && $id !== $post->ID && get_post( $id ) ) ? get_post( $id ) : $post;
	}

	private static function original( \WP_Post $post ): \WP_Post {
		$lang = apply_filters( 'wpml_default_language', null );
		if ( ! is_string( $lang ) || '' === $lang ) {
			return $post;
		}
		$id = (int) apply_filters( 'wpml_object_id', $post->ID, $post->post_type, true, $lang );
		return ( $id && $id !== $post->ID && get_post( $id ) ) ? get_post( $id ) : $post;
	}
}
