<?php
/**
 * Module Name: Map
 * Description: Two-click map block: a static image with the address; the Google Maps embed only loads after the visitor asks for it (or accepted the maps cookies).
 * Default: on
 * Requires:
 *
 * The estrategia-web/map block. Up to 1.0.0 the parent theme registered it
 * (see modules/carousel/module.php: the same move). Everything it does is in
 * the header of blocks/map/render.php.
 *
 * Block Bindings: core only allows binding attributes of its own blocks; the
 * filter below (WordPress 6.9+) allows binding these, so that a location's
 * address (core/post-meta, inside a Query) reaches the map without copying
 * it. A new TEXT or URL attribute that makes sense to fill from outside goes
 * into BINDABLE. Edge case: numbers and booleans (imageId, showDetails) do
 * not, because sources return text. The trap: bindings are resolved BEFORE
 * render.php, so render.php does not know where a value came from; do not
 * write code that depends on that.
 *
 * Tests: in tests/ of this module (since 17-10-2026; before, in the theme
 * repo), level 3, in Playground's default theme, with their pages seeded by
 * tests/wp/estrategia-web-map-test.php and the shared harness in the
 * plugin's tests/browser.mjs. With the cookie notice they are in
 * modules/consent and in Lavandería (tests/consent-maps.test.mjs).
 */

namespace EstrategiaWeb\Map;

defined( 'ABSPATH' ) || exit;

const BINDABLE = array( 'placeName', 'address', 'phone', 'hours', 'embedUrl', 'mapsUrl', 'imageUrl', 'imageAlt', 'staticMapUrl' );

add_action( 'init', static fn() => register_block_type( __DIR__ . '/blocks/map' ) );

/*
 * Cookies: the Google embed sets its cookies on google.com, where the
 * detector cannot see them. Once a map WITH an embed has been rendered on the
 * site (render.php records it in GOOGLE_SEEN_OPTION, once), this module
 * declares the «google-maps» service to the cookie registry and its cookies
 * show as proposals on «Estrategia Web → Cookies»
 * (modules/consent/includes/class-cookie-services.php). On a render, not on
 * the module being on: the module is on by default on every site, with or
 * without a map. Edge case: the maps are removed later. The flag stays (it
 * is not worth scanning the content); the administrator marks the cookies
 * Ignored, or deletes the option.
 */
const GOOGLE_SEEN_OPTION = 'estrategia_web_map_google_seen';

/** Called by render.php when it prints a map with a Google embed. */
function google_rendered(): void {
	if ( ! get_option( GOOGLE_SEEN_OPTION ) ) {
		update_option( GOOGLE_SEEN_OPTION, time(), false );
	}
}

add_filter(
	'estrategia_web_cookie_services',
	static function ( $services ) {
		$services = (array) $services;
		if ( get_option( GOOGLE_SEEN_OPTION ) && ! in_array( 'google-maps', $services, true ) ) {
			$services[] = 'google-maps';
		}
		return $services;
	},
	99
);

add_filter(
	'block_bindings_supported_attributes_estrategia-web/map',
	static fn( $supported ) => array_values( array_unique( array_merge( (array) $supported, BINDABLE ) ) )
);
