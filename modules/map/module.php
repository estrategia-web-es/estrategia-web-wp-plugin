<?php
/**
 * Module Name: Map
 * Description: Two-click map block: a static image with the address; the Google Maps embed only loads after the visitor asks for it (or accepted the maps cookies).
 * Default: on
 * Requires:
 *
 * Bloque estrategia-web/map. Hasta la 1.0.0 lo registraba el tema padre (ver
 * modules/carousel/module.php: el mismo traslado). Todo lo que hace, en la
 * cabecera de blocks/map/render.php.
 *
 * Block Bindings: el núcleo solo deja enlazar atributos de sus propios
 * bloques; el filtro de abajo (WordPress 6.9+) deja enlazar estos, para que
 * la dirección de una sede (core/post-meta, dentro de una Consulta) llegue al
 * mapa sin copiarla. Un atributo de TEXTO o URL nuevo que tenga sentido
 * rellenar desde fuera entra en BINDABLE. Caso frontera: números y
 * booleanos (imageId, showDetails) no, porque las fuentes devuelven texto. La
 * trampa: los enlazados se resuelven ANTES de render.php, así que render.php
 * no sabe de dónde vino un valor; no se escribe código que dependa de eso.
 *
 * Pruebas: en el repo del tema (armazon-wp-padre/tests/map*.test.mjs), como
 * las del carrusel.
 */

namespace EstrategiaWeb\Map;

defined( 'ABSPATH' ) || exit;

const BINDABLE = array( 'placeName', 'address', 'phone', 'hours', 'embedUrl', 'mapsUrl', 'imageUrl', 'imageAlt', 'staticMapUrl' );

add_action( 'init', static fn() => register_block_type( __DIR__ . '/blocks/map' ) );

add_filter(
	'block_bindings_supported_attributes_estrategia-web/map',
	static fn( $supported ) => array_values( array_unique( array_merge( (array) $supported, BINDABLE ) ) )
);
