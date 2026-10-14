<?php
/**
 * Module Name: Carousel
 * Description: Carousel block: a horizontal scrolling row of any blocks (or the posts of a Query) with arrows, optional dots, infinite loop and autoplay only if asked for.
 * Default: on
 * Requires:
 *
 * Bloque estrategia-web/carousel. Hasta la 1.0.0 lo registraba el tema
 * padre; WordPress.org no deja que un tema registre bloques y se trajo aquí
 * tal cual (14-10-2026). Todo lo que hace, en la cabecera de
 * blocks/carousel/render.php; la prioridad, la carga diferida y el sizes de
 * sus fotos, en includes/carousel-images.php.
 *
 * Sin el plugin (o con este módulo apagado) las plantillas y patrones del
 * tema que lo usan no dan error: WordPress no pinta un bloque que no está
 * registrado.
 *
 * Pruebas: siguen en el repo del tema (armazon-wp-padre/tests/carousel*.test.mjs),
 * porque miden el carrusel con el CSS del tema y su sitio de pruebas; aquel
 * servidor monta este plugin. Moverlas aquí es un pendiente (PENDIENTES.md).
 */

namespace EstrategiaWeb\Carousel;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/includes/carousel-images.php';

add_action( 'init', static fn() => register_block_type( __DIR__ . '/blocks/carousel' ) );
