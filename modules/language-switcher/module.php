<?php
/**
 * Module Name: Language switcher
 * Description: Language switcher block for WPML: the current language and links to the same page in the others, as a drop-down or inline. Without WPML it shows nothing.
 * Default: on
 * Requires:
 * Category: Languages
 *
 * Block estrategia-web/language-switcher. Up to 1.0.0 the parent theme
 * registered it (see modules/carousel/module.php: the same move). Everything
 * it does, in the header of blocks/language-switcher/render.php.
 *
 * Tests: there are none here (WPML is paid and not in the plugin's
 * Playground); the real one is Lavandería's,
 * lavanderia-mallorca/tests/language-switcher.test.mjs, with its local copy
 * of WPML.
 */

namespace EstrategiaWeb\LanguageSwitcher;

defined( 'ABSPATH' ) || exit;

add_action( 'init', static fn() => register_block_type( __DIR__ . '/blocks/language-switcher' ) );
