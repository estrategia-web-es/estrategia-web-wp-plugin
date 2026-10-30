<?php
namespace EstrategiaWeb\ContentTypes;

defined( 'ABSPATH' ) || exit;

/**
 * A LOCATION'S DERIVED VALUES, read from WordPress (2.0.0). The rules are
 * pure and live in class-location-data.php; this class only knows WHICH
 * post, WHICH language and WHICH meta:
 *
 *   address_text()   the address the page shows    ← street + postal code + locality + region (+ country)
 *   hours_text()     the hours the page shows      ← the «hours» field
 *   maps_url()       «Open in Google Maps»         ← map_query
 *   embed_url()      the map's embed URL           ← map_query
 *   static_map_url() the static map's picture URL  ← static_map_id (media library)
 *   street_line()    schema.org's streetAddress    ← street, on one line
 *
 * Who shows them: the binding source estrategia-web-site/location
 * (modules/site/includes/class-location.php) under the OLD keys
 * (estrategia_web_location_address, _schedule, _map_url, _map_embed,
 * _static_map), so a page bound to them through THAT source keeps working
 * without a change; modules/local-business (street, image); the location
 * meta box's «Shown on the site» previews (preview(), on the filter
 * estrategia_web_content_types_box_preview of class-fields-panel.php).
 * core/post-meta CANNOT show them — it reads stored meta, and a derived
 * value is not stored —: a theme that bound core/post-meta to those keys
 * moves to estrategia-web-site/location (CHANGELOG 2.0.0).
 *
 * THE FORMAT of the texts: Location_Data::FORMATS for the post's language
 * (WPML) or the site's, changed per site with the filter
 *
 *     add_filter( 'estrategia_web_location_formats', function ( array $format, string $lang ) {
 *         if ( 'es' === $lang ) {
 *             $format['open'] = 'Abrimos {days}';
 *         }
 *         return $format;
 *     }, 10, 2 );
 *
 * ($lang is the two-letter code; the keys are documented in
 * class-location-data.php). The company's hours (modules/site, «hours» key
 * of Company details) use the same format: company_hours_text().
 *
 * BEFORE A SITE MIGRATES (wp estrategia-web migrate-location-data,
 * class-location-migration.php) its locations still carry the OLD meta.
 * While it is there, IT is what is shown — the address text, the hours
 * text, the link and the embed, the static map path —, so updating the
 * plugin changes nothing a visitor sees until the migration runs. The
 * migration removes them; from then on, only the derived values.
 *
 * How to maintain:
 * - Who and when: whoever adds a derived value adds it here, in
 *   Location::value()/labels() (modules/site) and in the README table.
 * - Boundary case, with a name: a WPML translation with a «copy» part
 *   still empty (not copied yet). The original's value is read, as
 *   modules/local-business always did. The street is «translate» (an
 *   English «Maioris Shopping Centre»): an empty street in a translation
 *   ALSO falls back to the original's, never to nothing.
 * - THE TRAP: the old meta wins ONLY when it is there. A location whose old
 *   address text is "" (never filled in) shows the derived one at once; one
 *   whose text was filled in shows the text until the migration, even if
 *   its parts were corrected in the meantime. That is why the migration
 *   lists every DIFFERENCE before it removes a text.
 */

final class Location_Fields {

	public const STREET      = 'estrategia_web_location_street';
	public const POSTAL_CODE = 'estrategia_web_location_postal_code';
	public const LOCALITY    = 'estrategia_web_location_locality';
	public const REGION      = 'estrategia_web_location_region';
	public const COUNTRY     = 'estrategia_web_location_country';
	public const HOURS       = 'estrategia_web_location_hours';
	public const QUERY       = 'estrategia_web_location_map_query';
	public const STATIC_ID   = 'estrategia_web_location_static_map_id';

	/** The old stored texts (1.7.0), now derived. Their names are still the binding keys. */
	public const LEGACY = array(
		'address'    => 'estrategia_web_location_address',
		'schedule'   => 'estrategia_web_location_schedule',
		'map_url'    => 'estrategia_web_location_map_url',
		'map_embed'  => 'estrategia_web_location_map_embed',
		'static_map' => 'estrategia_web_location_static_map',
	);

	public static function boot(): void {
		add_filter( 'estrategia_web_content_types_box_preview', array( self::class, 'preview' ), 10, 3 );
	}

	/** The post type of the location template (eweb_location). */
	public static function type(): string {
		return (string) ( Templates::get( 'location' )['name'] ?? 'eweb_location' );
	}

	/** Two-letter language of a post (WPML), or of the site / request. */
	public static function lang( ?\WP_Post $post = null ): string {
		if ( $post ) {
			$details = apply_filters( 'wpml_post_language_details', null, $post->ID );
			if ( is_array( $details ) && ! empty( $details['language_code'] ) ) {
				return strtolower( (string) $details['language_code'] );
			}
		}
		return strtolower( explode( '_', determine_locale() )[0] );
	}

	/** The format of a language, after the site's filter. */
	public static function formats( string $lang ): array {
		$default = Location_Data::default_format( $lang );
		/**
		 * The wording of a location's derived texts (and the company's hours)
		 * in one language. Keys: class-location-data.php's header.
		 *
		 * @param array  $format Location_Data::FORMATS[ $lang ] (English for a language without one).
		 * @param string $lang   Two-letter code.
		 */
		$format = apply_filters( 'estrategia_web_location_formats', $default, $lang );
		return is_array( $format ) ? $format + $default : $default;
	}

	/** A meta value; empty in a WPML translation → the original's (see «Boundary case»). */
	public static function meta( \WP_Post $post, string $key ): string {
		$value = get_post_meta( $post->ID, $key, true );
		if ( ( '' === $value || null === $value || false === $value ) ) {
			$original = self::original( $post );
			if ( $original->ID !== $post->ID ) {
				$value = get_post_meta( $original->ID, $key, true );
			}
		}
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	/** @return array{street:string,postal_code:string,locality:string,region:string,country:string} */
	public static function parts( \WP_Post $post ): array {
		return array(
			'street'      => self::meta( $post, self::STREET ),
			'postal_code' => self::meta( $post, self::POSTAL_CODE ),
			'locality'    => self::meta( $post, self::LOCALITY ),
			'region'      => self::meta( $post, self::REGION ),
			'country'     => self::meta( $post, self::COUNTRY ),
		);
	}

	/** The old stored value while the site has not migrated (the post's own, never the original's: it was «translate»). */
	private static function legacy( \WP_Post $post, string $which ): string {
		$value = get_post_meta( $post->ID, self::LEGACY[ $which ], true );
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	public static function derived_address( \WP_Post $post, ?string $lang = null ): string {
		return Location_Data::address_text( self::parts( $post ), self::formats( $lang ?? self::lang( $post ) ) );
	}

	public static function derived_hours( \WP_Post $post, ?string $lang = null ): string {
		return Location_Data::hours_text( self::meta( $post, self::HOURS ), self::formats( $lang ?? self::lang( $post ) ) );
	}

	public static function address_text( \WP_Post $post ): string {
		$old = self::legacy( $post, 'address' );
		return '' !== $old ? $old : self::derived_address( $post );
	}

	public static function hours_text( \WP_Post $post ): string {
		$old = self::legacy( $post, 'schedule' );
		return '' !== $old ? $old : self::derived_hours( $post );
	}

	public static function maps_url( \WP_Post $post ): string {
		$old = self::legacy( $post, 'map_url' ) ?: self::legacy( self::original( $post ), 'map_url' );
		return '' !== $old ? $old : Location_Data::maps_url( self::meta( $post, self::QUERY ) );
	}

	public static function embed_url( \WP_Post $post ): string {
		$old = self::legacy( $post, 'map_embed' ) ?: self::legacy( self::original( $post ), 'map_embed' );
		return '' !== $old ? $old : Location_Data::embed_url( self::meta( $post, self::QUERY ) );
	}

	/** The static map: the media library image; before migrating, the old path or URL if there is no image. */
	public static function static_map_url( \WP_Post $post ): string {
		$id = (int) self::meta( $post, self::STATIC_ID );
		if ( $id > 0 ) {
			$url = (string) wp_get_attachment_image_url( $id, 'full' );
			if ( '' !== $url ) {
				return $url;
			}
		}
		$path = self::legacy( $post, 'static_map' ) ?: self::legacy( self::original( $post ), 'static_map' );
		return '' !== $path ? self::local_image_url( $path ) : '';
	}

	/** schema.org streetAddress. */
	public static function street_line( \WP_Post $post ): string {
		return Location_Data::street_line( self::meta( $post, self::STREET ) );
	}

	/** A local_image value (theme path, site path or same-host URL) as a URL. */
	public static function local_image_url( string $path ): string {
		if ( preg_match( '#^https?://#i', $path ) ) {
			return $path;
		}
		if ( str_starts_with( $path, '/' ) ) {
			// From the domain's root (Sanitize::local_image); not home_url(), which WPML gives an /en/.
			$home = wp_parse_url( (string) get_option( 'home' ) );
			return ( $home['scheme'] ?? 'https' ) . '://' . ( $home['host'] ?? '' ) . ( isset( $home['port'] ) ? ':' . $home['port'] : '' ) . $path;
		}
		return (string) get_theme_file_uri( $path );
	}

	/** The company's hours text (modules/site, «hours» of Company details), in the request's language. */
	public static function company_hours_text( $hours ): string {
		return Location_Data::hours_text( $hours, self::formats( self::lang() ) );
	}

	/**
	 * The location meta box's «Shown on the site» under Address, Opening
	 * hours and Map (Fields_Panel::render_meta_box()). What the page shows
	 * as of the last save.
	 */
	public static function preview( string $html, string $group, \WP_Post $post ): string {
		if ( $post->post_type !== self::type() ) {
			return $html;
		}
		$show = static fn( string $text ): string => '' === $text ? '<em>' . esc_html__( 'Nothing yet.', 'estrategia-web' ) . '</em>' : nl2br( esc_html( $text ) );
		switch ( $group ) {
			case 'address':
				$value = $show( self::address_text( $post ) );
				break;
			case 'hours':
				$value = $show( self::hours_text( $post ) );
				break;
			case 'map':
				$url   = self::maps_url( $post );
				$value = '' === $url ? $show( '' ) : '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html__( 'Open in Google Maps', 'estrategia-web' ) . '</a>';
				break;
			default:
				return $html;
		}
		return $html . '<div class="estrategia-web-ct-preview"><strong>' . esc_html__( 'Shown on the site (as of the last save):', 'estrategia-web' ) . '</strong><div>' . $value . '</div></div>';
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
