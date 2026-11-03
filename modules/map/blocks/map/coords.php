<?php
/**
 * The map from COORDINATES (2.9.0, latitude/longitude attributes): for sites
 * whose locations are in the database as numbers, not as a Google embed
 * pasted by hand. Gastronòmicament has 736 restaurants with the old WP Geo
 * coordinates; copying 736 embeds from Google is not a job, it is a wall.
 *
 * The URL: https://www.google.com/maps?q=LAT,LNG&hl=<lang>&z=17&output=embed.
 * It needs no API key and shows the street map with the marker, in the page's
 * language. Measured in a browser on 03-11-2026 with the same coordinates:
 * «/maps/embed?q=LAT,LNG», the only form the embed validation accepts,
 * shows the WORLD map (it ignores q); «?output=embed» on www or maps.google.com
 * shows the street with the marker.
 *
 * Same mechanics as a pasted embed: two clicks, nothing requested from Google
 * until the visitor asks for it or accepted the maps cookies (render.php).
 * A pasted embedUrl wins over the coordinates: it is a choice someone made.
 *
 * How to maintain: whoever touches the map module. When: if Google stops
 * serving «output=embed» without a key (the browser tests would show a blank
 * or error frame), this is the one place to change. Edge case, with a name:
 * «0,0» is a real place in the Gulf of Guinea, but in a database it means
 * «never filled in», so it gives no map (WP Geo saved empty ones that way).
 * The trap: coordinates come as TEXT (Block Bindings only bring text, and the
 * meta is text): a comma as decimal separator («39,57») is accepted and
 * normalised; anything else that is not a number gives '' and no map.
 * Test: modules/map/tests/coords.test.mjs (level 1, machine PHP).
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'estrategia_web_map_coords' ) ) {
	/**
	 * «LAT,LNG» with at most 6 decimals, or '' if they are not valid coordinates.
	 *
	 * @param string $lat Latitude.
	 * @param string $lng Longitude.
	 */
	function estrategia_web_map_coords( string $lat, string $lng ): string {
		$num = static function ( string $v, float $max ): ?float {
			$v = str_replace( ',', '.', trim( $v ) );
			if ( ! preg_match( '/^-?\d{1,3}(\.\d+)?$/', $v ) || abs( (float) $v ) > $max ) {
				return null;
			}
			return (float) $v;
		};
		$a = $num( $lat, 90 );
		$b = $num( $lng, 180 );
		if ( null === $a || null === $b || ( 0.0 === $a && 0.0 === $b ) ) {
			return '';
		}
		return rtrim( rtrim( sprintf( '%.6F', $a ), '0' ), '.' ) . ',' . rtrim( rtrim( sprintf( '%.6F', $b ), '0' ), '.' );
	}
}

if ( ! function_exists( 'estrategia_web_map_coords_embed' ) ) {
	/**
	 * Keyless Google Maps embed URL for the coordinates, or '' if they are not valid.
	 *
	 * @param string $lat  Latitude.
	 * @param string $lng  Longitude.
	 * @param string $lang Two-letter language of the page (hl).
	 */
	function estrategia_web_map_coords_embed( string $lat, string $lng, string $lang ): string {
		$q = estrategia_web_map_coords( $lat, $lng );
		if ( '' === $q ) {
			return '';
		}
		$lang = preg_match( '/^[a-z]{2}$/', $lang ) ? $lang : 'en';
		return 'https://www.google.com/maps?q=' . $q . '&hl=' . $lang . '&z=17&output=embed';
	}
}
