<?php
/**
 * Module Name: Content types
 * Description: Build your own content types, taxonomies and fields from the panel (or start from a template: clients, testimonials, locations, projects, portfolio), with the editor panel, Block Bindings and WPML generated.
 * Default: on
 * Requires:
 * Category: Content
 *
 * THE CONSTRUCTOR (17-10-2026, David: «la idea del constructor es poder
 * crear tus propios tipos… para mí es dato: un JSON en content»). Each
 * definition is DATA of its site: a JSON stored in the database
 * (includes/class-store.php), made and edited in «Estrategia Web → Content
 * types» (includes/admin/class-page.php + assets/constructor.js) through the
 * REST route estrategia-web/v1/content-types (includes/class-rest.php, which
 * validates and protects what is published: includes/class-changes.php).
 * A site's repo keeps them in content/ as JSON, like its pages and settings:
 * its content load puts them into a new or local site through that route,
 * and its «download the content» script brings them back. In production the
 * database rules: a load never overwrites a definition edited in the panel.
 *
 * TEMPLATES (includes/class-templates.php, templates/*.json): the five types
 * modules/site used to register in PHP, as definitions with the same names.
 * «Add testimonials» copies one into the database. A site that had them
 * registered by modules/site gets them stored ONCE, with the URLs it serves
 * today, and «enabled_types» still switches them: includes/class-site-types.php.
 *
 * DEFAULT: ON since that move (17-10-2026, decided by David): the types of
 * every corporate site live here now. With no definitions it registers
 * nothing; what a site sees is the «Content types» screen.
 *
 * JSON FILES, the first way (15-10-2026), stay as a READ-ONLY second
 * source: <child theme>/content-types/*.json and any folder added with the
 * filter estrategia_web_content_types_dirs. Why they stay, and what wins when
 * a name is in both: class-loader.php. First site with files:
 * Gastronòmicament (restaurant, recipe, product, winery).
 *
 * THE SCHEMA, in short (the full list: content-types.schema.json, which is
 * also what the code editor uses for autocomplete — put
 * "$schema": "<path to it>" at the top of each file):
 *
 *   one definition = one post type
 *   template       set when copied from a template (the names it ships keep
 *                  the plugin's prefixes; Schema::validate())
 *   prefix         the site's own, «xxx_», checked with
 *                  `node tools/buscar-colisiones.mjs '[\x27\x22]xxx_'`.
 *                  eweb_, estrategia_web_, ew_ and wp_ are refused.
 *   name           the post type, starts with prefix, ≤ 20 characters
 *   slug           a string or locale → string ({"ca": "restaurants",
 *                  "es": "restaurantes"}); ASCII lowercase, digits, - and /;
 *                  never the same as another type's or taxonomy's in the
 *                  same language. Registered: the SITE language's. With
 *                  WPML + String Translation, the rest become WPML slug
 *                  translations (class-wpml.php, sync_slugs()).
 *   labels         {singular, plural} required; details (panel title),
 *                  featured_image, set_featured_image optional
 *   description, icon, supports, public,
 *   has_archive, show_in_rest, hierarchical, menu_position,
 *   wpml           translate | display-as-translated | ignore
 *   taxonomies[]   an object (declared here; name ≤ 32) or the name of one
 *                  declared in another file (or category / post_tag)
 *   fields[]       name (the meta key, with prefix), type, label, help,
 *                  required, default, wpml (copy | translate | ignore),
 *                  options (select), post_type (relation), min/max/integer (number),
 *                  true_label/false_label (boolean: what the binding shows; "" = nothing)
 *   Field types: text, textarea, number, url, email, tel, image (attachment
 *   ID), date (YYYY-MM-DD), select, relation (ID of a post of a given type),
 *   boolean, rating (0–5, stars), embed (a map embed; the whole <iframe>
 *   may be pasted), local_image (an image of this site by theme path, site
 *   path or same-host URL), hours (opening hours per day of the week, JSON
 *   text: includes/class-hours.php). Labels, help and option labels are a string or an object
 *   locale → string ({"ca": "…", "es": "…", "en": "…"}).
 *   NO repeaters and NO groups, on purpose: a recipe's ingredients go as
 *   blocks in the content. Example: examples/gment_restaurant.json.
 *
 * WHAT A DEFINITION GENERATES (class-registrar.php): register_post_type and
 * register_taxonomy; one register_post_meta per field (show_in_rest, typed,
 * sanitized, labelled — so it is listed in the Block Bindings UI and works
 * with core/post-meta in templates); the «<Singular> details» panel in the
 * block editor (class-fields-panel.php, a meta box on the classic screen);
 * the WPML config, through WPML's filter, plus the copy on save
 * (class-wpml.php) and the slug of each language as a WPML slug translation;
 * «required»: a 400 on publish in the block editor, and on the classic
 * screen a draft with a notice (or, if already published, the previous
 * value kept: Registrar::check_required_classic()); and a rewrite flush only
 * when the definitions or the site language change (hash).
 *
 * SHOWING A FIELD (16-10-2026): core/post-meta shows what is STORED — a
 * select's value («€€»), an image or a relation as an ID, a boolean as 1.
 * The Block Bindings source `estrategia-web/content-field` (class-bindings.php)
 * shows it formatted by its type: the option's label in the request's
 * language, the image's URL / alt, the related post's title or link (its WPML
 * translation), Yes/No or the definition's texts, the date and the number in
 * the site's format, mailto:/tel: links. The rules, type by type:
 * class-field-format.php. Use it instead of core/post-meta for anything but
 * plain text.
 *
 * Tests: modules/content-types/tests/ — level 1: schema.test.mjs (validator,
 * name limits, PHP ↔ JSON Schema drift), wpml.test.mjs (the WPML config and
 * the copy on save and the slug translations, with a WPML double), format.test.mjs (the content-field
 * rules per type); level 2: register.test.mjs (registration, REST,
 * sanitizers, required, URLs, binding, admin screen), bindings.test.mjs
 * (content-field rendered in Queries); level 3: editor-panel.test.mjs (the
 * panel saves), bindings-editor.test.mjs (content-field in the editor),
 * classic-required.test.mjs («required» on the classic screen). The test site reads the
 * definitions in tests/fixtures/ (its mu-plugin, tests/wp/).
 * The constructor (17-10-2026): level 1 constructor-pure.test.mjs (the
 * templates validate, the template rules, Changes); level 2
 * constructor.test.mjs (the REST route, registration from the database,
 * templates, protections, redirects, export/import); level 3
 * constructor-editor.test.mjs (create «Restaurantes» with three fields in
 * the panel, and its type and panel appear).
 *
 * =========================================================================
 * HOW TO MAINTAIN
 * =========================================================================
 *
 * - Who and when: whoever needs a field type or a key the schema does not
 *   have. A site that needs a TYPE does not touch this module: it makes it
 *   in the panel (and its repo gets the JSON in content/ when its content is
 *   brought down).
 *
 * - A key a person should set from the panel also needs its control in
 *   assets/constructor.js and its text in Page::strings() (a key without a
 *   control is kept, and editable in the panel's JSON box).
 *
 * - A NEW FIELD TYPE is seven places, in the same commit, and the level 1 test
 *   fails if the first two disagree:
 *     1. Schema::FIELD_TYPES (and its rules in check_type_rules(), and its
 *        meta type and WPML default in normalize_field());
 *     2. content-types.schema.json (the enum and, if it has its own keys,
 *        an if/then like select's);
 *     3. Registrar::sanitizer();
 *     4. assets/fields-panel.js, a case in Field();
 *     5. Fields_Panel::render_meta_box() (classic screen);
 *     6. Wpml::config() if it stores IDs (post-ids, like image and relation);
 *     7. how it is SHOWN: Field_Format::format() and its editor copy in
 *        assets/bindings.js, with its line in tests/format.test.mjs. Without
 *        it, the content-field binding shows "" for that type.
 *   A new KEY is 1, 2 and wherever it is used (true_label/false_label:
 *   Schema, the JSON Schema, Field_Format and Bindings::editor_field()).
 *
 * - THE CONTENT-FIELD BINDING, who and when: whoever changes how a type is
 *   shown changes Field_Format and assets/bindings.js together (the level 3
 *   test compares the editor with the site). Boundary case, with a name: a
 *   number never filled in shows «0» and a select never saved shows its
 *   default's label — they are values, not «empty». The trap: core escapes a
 *   value that goes into an HTML ATTRIBUTE (core/image's alt) and only
 *   kses-filters one that goes into the HTML (a paragraph): escaping both
 *   gave «&amp;quot;» in an alt. Bindings::is_html() decides per attribute.
 *
 * - BOUNDARY CASE, with a name: a relation to a type of ANOTHER file, or to
 *   one registered by other code (eweb_client). It is allowed — the target
 *   only has to exist when the sanitizer runs — and if it does not exist at
 *   init 98 the screen says so: the field would store only 0.
 *
 * - THE TRAPS:
 *   · WordPress cuts a post type at 20 characters WITHOUT an error (and a
 *     taxonomy at 32): «gment_restaurant_bar» (20) fits; «gment_restaurant_menu»
 *     (21) would register a different name from the one in the file. The
 *     validator refuses it instead. The prefix counts: every letter it has
 *     is one less for the name.
 *   · A file with ONE error is not registered at all. Never half a type:
 *     a type without its required field, or with a field typed differently,
 *     would write data that the fixed file then reads wrong.
 *   · The example prefix: «gmt_» was the obvious one for Gastronòmicament
 *     and buscar-colisiones found it in 2,804 plugins (WordPress's own
 *     'gmt_offset' option and friends). «gment_» (David, 15-10-2026): 0.
 *     «gmct_», the first example, also had 0 but did not read as the brand.
 *     Check before choosing.
 *   · WPML does not read the config until an admin screen of its list opens
 *     (class-wpml.php): on a fresh test site, open /wp-admin/plugins.php
 *     before measuring a «copy». And with WPML's setup not completed, WPML
 *     does not even load wpml_sync_custom_field: nothing is copied, and it
 *     looks like this module failing (measured, class-wpml.php).
 *   · SLUGS PER LANGUAGE: the registered slug is ALWAYS the site language's,
 *     never the request's (Plugin::url_slug() says why: the rewrite rules
 *     are stored once). The other languages live in WPML, not in the rules:
 *     WPML rewrites permalinks and rules on the fly from its «URL slug: …»
 *     strings. A file that changes the default-language slug changes the
 *     original of that string, and WPML then marks every translation
 *     «needs update» — and stops using them — until sync_slugs() writes
 *     them again (it does, in the same request: the hash changed). A
 *     language the file does not name is left to WPML's screen. Without
 *     String Translation every language uses the registered slug.
 *     The real-WPML check: lavanderia-mallorca/tests/content-types-slugs-wpml.test.mjs.
 *   · A definition saved from the panel is registered from the NEXT request
 *     (types register at init 5, REST runs later): «Saved. The changes are
 *     visible from the next page you open» says so on purpose.
 */

namespace EstrategiaWeb\ContentTypes;

defined( 'ABSPATH' ) || exit;

define( 'ESTRATEGIA_WEB_CONTENT_TYPES_DIR', __DIR__ . '/' );
define( 'ESTRATEGIA_WEB_CONTENT_TYPES_URL', plugin_dir_url( __FILE__ ) );

require_once __DIR__ . '/includes/class-hours.php';
require_once __DIR__ . '/includes/class-schema.php';
require_once __DIR__ . '/includes/class-sanitize.php';
require_once __DIR__ . '/includes/class-store.php';
require_once __DIR__ . '/includes/class-templates.php';
require_once __DIR__ . '/includes/class-loader.php';
require_once __DIR__ . '/includes/class-changes.php';
require_once __DIR__ . '/includes/class-registrar.php';
require_once __DIR__ . '/includes/class-wpml.php';
require_once __DIR__ . '/includes/class-fields-panel.php';
require_once __DIR__ . '/includes/class-field-format.php';
require_once __DIR__ . '/includes/class-bindings.php';
require_once __DIR__ . '/includes/class-rest.php';
require_once __DIR__ . '/includes/class-site-types.php';

Store::boot();
Registrar::boot();
Wpml::boot();
Fields_Panel::boot();
Bindings::boot();
Rest::boot();
Site_Types::boot(); // modules/site's old types → definitions, once; «enabled_types» still switches them.

// Another theme, other folders: read again and let the hash decide the flush.
add_action( 'after_switch_theme', array( Loader::class, 'forget' ) );

if ( is_admin() ) {
	require_once __DIR__ . '/includes/admin/class-page.php';
	Page::boot();
}
