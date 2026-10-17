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

add_filter(
	'block_bindings_supported_attributes_estrategia-web/map',
	static fn( $supported ) => array_values( array_unique( array_merge( (array) $supported, BINDABLE ) ) )
);
