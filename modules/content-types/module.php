<?php
/**
 * Module Name: Content types from JSON
 * Description: Each site declares its own content types, taxonomies and simple fields in JSON files of its repository, with the editor panel, Block Bindings and WPML generated.
 * Default: off
 * Requires:
 *
 * «ACF style» without a database: the definitions are JSON files in the
 * site's repo (like ACF's «local JSON»), versioned and deployed as code. The
 * client cannot break a type from the admin; the admin screen
 * (Estrategia Web → Content types) only LISTS what is declared and the
 * validation errors. First site: Gastronòmicament (restaurant, recipe,
 * product, winery). Decided by David on 15-10-2026.
 *
 * WHERE THE FILES GO: <child theme>/content-types/*.json by default, and any
 * folder added with the filter estrategia_web_content_types_dirs (a site
 * plugin). Why, and the catch of keeping them in the theme: class-loader.php.
 *
 * THE SCHEMA, in short (the full list: content-types.schema.json, which is
 * also what the code editor uses for autocomplete — put
 * "$schema": "<path to it>" at the top of each file):
 *
 *   one file = one post type
 *   prefix         the site's own, «xxx_», checked with
 *                  `node tools/buscar-colisiones.mjs '[\x27\x22]xxx_'`.
 *                  eweb_, estrategia_web_, ew_ and wp_ are refused.
 *   name           the post type, starts with prefix, ≤ 20 characters
 *   slug, labels {singular, plural}, description, icon, supports, public,
 *   has_archive, show_in_rest, hierarchical, menu_position,
 *   wpml           translate | display-as-translated | ignore
 *   taxonomies[]   an object (declared here; name ≤ 32) or the name of one
 *                  declared in another file (or category / post_tag)
 *   fields[]       name (the meta key, with prefix), type, label, help,
 *                  required, default, wpml (copy | translate | ignore),
 *                  options (select), post_type (relation), min/max/integer (number)
 *   Field types: text, textarea, number, url, email, tel, image (attachment
 *   ID), date (YYYY-MM-DD), select, relation (ID of a post of a given type),
 *   boolean. Labels, help and option labels are a string or an object
 *   locale → string ({"ca": "…", "es": "…", "en": "…"}).
 *   NO repeaters and NO groups, on purpose: a recipe's ingredients go as
 *   blocks in the content. Example: examples/gmct_restaurant.json.
 *
 * WHAT A DEFINITION GENERATES (class-registrar.php): register_post_type and
 * register_taxonomy; one register_post_meta per field (show_in_rest, typed,
 * sanitized, labelled — so it is listed in the Block Bindings UI and works
 * with core/post-meta in templates); the «<Singular> details» panel in the
 * block editor (class-fields-panel.php, a meta box on the classic screen);
 * the WPML config, through WPML's filter, plus the copy on save
 * (class-wpml.php); a 400 on publish when a required field is empty; and a
 * rewrite flush only when the definitions change (hash).
 *
 * Tests: modules/content-types/tests/ — level 1: schema.test.mjs (validator,
 * name limits, PHP ↔ JSON Schema drift), wpml.test.mjs (the WPML config and
 * the copy on save, with a WPML double); level 2: register.test.mjs
 * (registration, REST, sanitizers, required, URLs, binding, admin screen);
 * level 3: editor-panel.test.mjs (the panel saves). The test site reads the
 * definitions in tests/fixtures/ (its mu-plugin, tests/wp/).
 *
 * =========================================================================
 * HOW TO MAINTAIN
 * =========================================================================
 *
 * - Who and when: whoever needs a field type or a key the schema does not
 *   have. A site that needs a TYPE does not touch this module: it adds a
 *   JSON file to its own repo.
 *
 * - A NEW FIELD TYPE is six places, in the same commit, and the level 1 test
 *   fails if the first two disagree:
 *     1. Schema::FIELD_TYPES (and its rules in check_type_rules(), and its
 *        meta type and WPML default in normalize_field());
 *     2. content-types.schema.json (the enum and, if it has its own keys,
 *        an if/then like select's);
 *     3. Registrar::sanitizer();
 *     4. assets/fields-panel.js, a case in Field();
 *     5. Fields_Panel::render_meta_box() (classic screen);
 *     6. Wpml::config() if it stores IDs (post-ids, like image and relation).
 *   A new KEY is 1, 2 and wherever it is used.
 *
 * - BOUNDARY CASE, with a name: a relation to a type of ANOTHER file, or to
 *   one registered by other code (eweb_client). It is allowed — the target
 *   only has to exist when the sanitizer runs — and if it does not exist at
 *   init 98 the screen says so: the field would store only 0.
 *
 * - THE TRAPS:
 *   · WordPress cuts a post type at 20 characters WITHOUT an error (and a
 *     taxonomy at 32): «gmct_restaurant_menu» (20) fits, one more letter
 *     registers a different name from the one in the file. The validator
 *     refuses it instead.
 *   · A file with ONE error is not registered at all. Never half a type:
 *     a type without its required field, or with a field typed differently,
 *     would write data that the fixed file then reads wrong.
 *   · The example prefix: «gmt_» was the obvious one for Gastronòmicament
 *     and buscar-colisiones found it in 2,804 plugins (WordPress's own
 *     'gmt_offset' option and friends). «gmct_»: 0. Check before choosing.
 *   · WPML does not read the config until an admin screen of its list opens
 *     (class-wpml.php): on a fresh test site, open /wp-admin/plugins.php
 *     before measuring a «copy». And with WPML's setup not completed, WPML
 *     does not even load wpml_sync_custom_field: nothing is copied, and it
 *     looks like this module failing (measured, class-wpml.php).
 *   · Moving the existing eweb_ types (client, location…) to this system is
 *     NOT done here: TODO.md says how.
 */

namespace EstrategiaWeb\ContentTypes;

defined( 'ABSPATH' ) || exit;

define( 'ESTRATEGIA_WEB_CONTENT_TYPES_DIR', __DIR__ . '/' );
define( 'ESTRATEGIA_WEB_CONTENT_TYPES_URL', plugin_dir_url( __FILE__ ) );

require_once __DIR__ . '/includes/class-schema.php';
require_once __DIR__ . '/includes/class-loader.php';
require_once __DIR__ . '/includes/class-registrar.php';
require_once __DIR__ . '/includes/class-wpml.php';
require_once __DIR__ . '/includes/class-fields-panel.php';

Registrar::boot();
Wpml::boot();
Fields_Panel::boot();

// Another theme, other folders: read again and let the hash decide the flush.
add_action( 'after_switch_theme', array( Loader::class, 'forget' ) );

if ( is_admin() ) {
	require_once __DIR__ . '/includes/admin/class-page.php';
	Page::boot();
}
