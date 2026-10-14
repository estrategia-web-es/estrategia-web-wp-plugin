<?php
/**
 * Module Name: Language switcher
 * Description: Language switcher block for WPML: the current language and links to the same page in the others, as a drop-down or inline. Without WPML it shows nothing.
 * Default: on
 * Requires:
 *
 * Bloque estrategia-web/language-switcher. Hasta la 1.0.0 lo registraba el
 * tema padre (ver modules/carousel/module.php: el mismo traslado). Todo lo
 * que hace, en la cabecera de blocks/language-switcher/render.php.
 *
 * Pruebas: en el repo del tema no hay (WPML es de pago y no está en su
 * Playground); la de verdad es la de Lavandería,
 * lavanderia-mallorca/tests/language-switcher.test.mjs, con su copia local de
 * WPML.
 */

namespace EstrategiaWeb\LanguageSwitcher;

defined( 'ABSPATH' ) || exit;

add_action( 'init', static fn() => register_block_type( __DIR__ . '/blocks/language-switcher' ) );
