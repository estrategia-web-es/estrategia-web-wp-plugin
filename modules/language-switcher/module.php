<?php
/**
 * Module Name: Language switcher
 * Description: The «ES ▾» of the header: switches language to the same page, keyboard-accessible and with hreflang. Needs WPML; without it, nothing is shown.
 * Default: on
 * Requires:
 * Category: Languages
 *
 * Block estrategia-web/language-switcher. Up to 1.0.0 the parent theme
 * registered it (see modules/carousel/module.php: the same move). Everything
 * it does, in the header of blocks/language-switcher/render.php.
 *
 * Tests: tests/language-switcher.test.mjs (the served markup) and
 * tests/language-switcher-browser.test.mjs (opening and closing), against
 * the plugin's WordPress with real WPML (tests/run.mjs, «WPML»). They came
 * from Lavandería on 25-10-2026.
 */

namespace EstrategiaWeb\LanguageSwitcher;

defined( 'ABSPATH' ) || exit;

add_action( 'init', static fn() => register_block_type( __DIR__ . '/blocks/language-switcher' ) );
