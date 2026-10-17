<?php
/**
 * Module Name: Company data
 * Description: Company details with Block Bindings and the [estrategia_web_company] shortcode, the details of one location as a Block Bindings source, and sanitised SVG uploads.
 * Default: on
 * Requires:
 *
 * What the «Estrategia Web Site» plugin was up to 1.0.0, minus whatever could
 * be split off without rewriting it (cookie notice, WebP, external links,
 * WPML front page, Matomo: each one is its own module). Own screen: «Company
 * data» (includes/admin/class-admin.php).
 *
 * THE CONTENT TYPES LEFT THIS MODULE on 17-10-2026: clients, testimonials,
 * locations, projects and portfolio are definitions of the content-types
 * module now (its templates, same names), with their editor panel and their
 * WPML config. What is still here and why: includes/class-content-types.php.
 *
 * Why SVG is still here and not in its own module: its setting lives in the
 * same option (estrategia_web_site, Settings class) and on the same screen as
 * the company data. Matomo left in 1.1.0 (modules/matomo) WITHOUT splitting the
 * option: its matomo_* keys and their sanitising stay in Settings, and it
 * renders its own fields on the estrategia_web_site_settings_sections hook of
 * the screen. That is the path for the next one that leaves this module.
 *
 * Constants: ESTRATEGIA_WEB_SITE_DIR and _URL point to THIS folder (before, to
 * the plugin root); ESTRATEGIA_WEB_SITE_VERSION is the plugin's version.
 *
 * Tests: modules/site/tests/ (level 1: pure.test.mjs; levels 2 and 3, the
 * rest, with tests/lib.mjs).
 *
 * How to maintain: a new company-data concern goes in here; one that can be
 * switched off separately is a new module (see includes/class-modules.php at
 * the root).
 */

namespace EstrategiaWeb\Site;

defined( 'ABSPATH' ) || exit;

define( 'ESTRATEGIA_WEB_SITE_VERSION', ESTRATEGIA_WEB_VERSION ); // Globals with define(): a const here would be EstrategiaWeb\Site\….
define( 'ESTRATEGIA_WEB_SITE_DIR', __DIR__ . '/' );
define( 'ESTRATEGIA_WEB_SITE_URL', plugin_dir_url( __FILE__ ) );

require_once __DIR__ . '/includes/class-type-names.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/class-links.php';
require_once __DIR__ . '/includes/class-svg-sanitizer.php';
require_once __DIR__ . '/includes/class-settings.php';
require_once __DIR__ . '/includes/class-company.php';
require_once __DIR__ . '/includes/class-bindings.php';
require_once __DIR__ . '/includes/class-location.php';
require_once __DIR__ . '/includes/class-shortcode.php';
require_once __DIR__ . '/includes/class-content-types.php';
require_once __DIR__ . '/includes/class-svg.php';
require_once __DIR__ . '/includes/class-legacy-names.php';
require_once __DIR__ . '/includes/class-unprefixed-names.php';
require_once __DIR__ . '/includes/class-unprefixed-meta.php';
require_once __DIR__ . '/includes/admin/class-admin.php';

add_action( 'init', array( Settings::class, 'register' ) );
add_action( 'init', array( Bindings::class, 'register' ) );
add_action( 'init', array( Location::class, 'register' ) );
add_action( 'init', array( Shortcode::class, 'register' ) );
Legacy_Names::boot(); // The pre-1.0.0 names, as aliases (until 2.0.0).
Unprefixed_Names::boot(); // The unprefixed types and taxonomies from before 1.2.0, as aliases (until 2.0.0).
Unprefixed_Meta::boot(); // client_url, testimonial_role, testimonial_client: read aliases of their prefixed names (until 2.0.0).
add_action( 'enqueue_block_editor_assets', array( Bindings::class, 'enqueue_editor' ) );
add_filter( 'post_class', array( Content_Types::class, 'post_class' ), 10, 3 );
Svg::boot();

// On plugin activation: the URLs up to date (the content-types module has
// registered its types by now). On deactivation, their URLs stop existing
// (the content stays).
add_action( 'estrategia_web_activate', static fn() => flush_rewrite_rules( false ) );
add_action( 'estrategia_web_deactivate', static fn() => delete_option( 'rewrite_rules' ) );

if ( is_admin() ) {
	Admin::boot();
}
