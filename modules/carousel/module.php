<?php
/**
 * Module Name: Carousel
 * Description: Carousel block: a horizontal scrolling row of any blocks (or the posts of a Query) with arrows, optional dots, infinite loop and autoplay only if asked for.
 * Default: on
 * Requires:
 *
 * The estrategia-web/carousel block. Up to 1.0.0 the parent theme
 * registered it; WordPress.org does not allow a theme to register blocks, so
 * it was moved here as is (14-10-2026). Everything it does is in the header
 * of blocks/carousel/render.php; the priority, lazy loading and sizes of its
 * images, in includes/carousel-images.php; the mobile crop (mobileRatio,
 * 1.1.x), in includes/carousel-mobile-crop.php; the desktop preload of the
 * slides visible on load (preloadSlides, 1.4.2), in includes/carousel-images.php
 * (preload_config()). WordPress serves
 * blocks/carousel/view.min.js: after editing view.js, run `node tools/minify.mjs`.
 *
 * Without the plugin (or with this module off) the theme templates and
 * patterns that use it do not error: WordPress does not render a block that
 * is not registered.
 *
 * Tests: they are still in the theme repo (armazon-wp-padre/tests/carousel*.test.mjs),
 * because they measure the carousel with the theme CSS and its test site;
 * that server mounts this plugin. Moving them here is pending (TODO.md).
 */

namespace EstrategiaWeb\Carousel;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/includes/carousel-images.php';
require_once __DIR__ . '/includes/carousel-mobile-crop.php';

add_action( 'init', static fn() => register_block_type( __DIR__ . '/blocks/carousel' ) );
