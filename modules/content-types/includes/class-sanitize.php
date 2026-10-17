<?php
/**
 * THE SANITIZERS OF THE THREE SPECIAL FIELD TYPES (17-10-2026): rating, embed
 * and local_image. They were sanitizers of modules/site's fixed types
 * (Content_Types::sanitizer(): 'rating', 'embed', 'local_image'); when those
 * types became templates of this module (templates/), they became field types
 * any definition can use. The rest of the field types are sanitized inline in
 * Registrar::sanitizer(): these three have rules worth a name and a test.
 *
 * Pure except for home_url() / wp_parse_url() / esc_url_raw() (WordPress),
 * so it is tested at level 2 (tests/constructor.test.mjs).
 *
 * How to maintain: a rule changes here and nowhere else; what it accepts is
 * also in the description of the field type in content-types.schema.json.
 * The trap: esc_url_raw() puts «http://» in front of a path without a leading
 * «/» (assets/maps/x.webp → http://assets/maps/x.webp): that is why
 * local_image() validates paths by hand.
 */

namespace EstrategiaWeb\ContentTypes;

defined( 'ABSPATH' ) || exit;

final class Sanitize {

	/** 0 = no rating; anything else is clamped to 1–5. Not a number: 0. */
	public static function rating( $value ): int {
		$n = is_numeric( $value ) ? (int) $value : 0;
		return $n <= 0 ? 0 : min( 5, $n );
	}

	/**
	 * The code Google Maps gives (Share › Embed a map) is an <iframe>: only
	 * its src is kept. A bare URL goes through as a url field would.
	 */
	public static function embed( $value ): string {
		$value = (string) $value;
		$src   = preg_match( '/src\s*=\s*["\']([^"\']+)["\']/i', $value, $m ) ? $m[1] : $value;
		return esc_url_raw( trim( html_entity_decode( $src ) ), array( 'http', 'https' ) );
	}

	/**
	 * URL of an image served by THIS site, or ''. The same three forms that
	 * staticMapUrl of the parent theme's estrategia-web/map block accepts
	 * (estrategia_web_map_static_url, which checks it again when it renders):
	 *
	 *   assets/maps/sede.webp            THEME path (child, then parent); stored without «./»
	 *   /wp-content/uploads/mapa.webp    site path
	 *   https://this-domain/…/mapa.webp  absolute URL on the SAME host as home_url()
	 *
	 * Why so strict: a map from another domain is a request to a third party
	 * without consent when the page loads (with the visitor's IP); the theme
	 * would drop it anyway, but here it is noticed on save, not on the site.
	 * Boundary case: «//other.test/a.png» has a host without a scheme; it
	 * counts as another domain.
	 */
	public static function local_image( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		$parts = wp_parse_url( $value );
		if ( false === $parts || str_contains( $value, '..' ) ) {
			return '';
		}
		$path = (string) ( $parts['path'] ?? '' );
		if ( ! preg_match( '/\.(webp|png|jpe?g|gif|avif|svg)$/i', $path ) ) {
			return '';
		}
		if ( isset( $parts['host'] ) || isset( $parts['scheme'] ) ) {
			$site = wp_parse_url( home_url() );
			$ok   = in_array( $parts['scheme'] ?? '', array( 'http', 'https' ), true )
				&& isset( $parts['host'], $site['host'] )
				&& strtolower( $parts['host'] ) === strtolower( $site['host'] );
			return $ok ? esc_url_raw( $value, array( 'http', 'https' ) ) : '';
		}
		// A path: only URL characters that need no escaping (no spaces, quotes or «<»).
		if ( ! preg_match( '#^[A-Za-z0-9._~/\-]+(\?[A-Za-z0-9._~=&%\-]*)?$#', $value ) ) {
			return '';
		}
		return (string) preg_replace( '#^\./#', '', $value );
	}
}
