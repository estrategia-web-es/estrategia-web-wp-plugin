# Changelog

One entry per version, newest first. It is written in the same commit that
bumps the number (README, «Versions»). If a version requires changing
something on the sites, the entry says so first, along with what to change.

Since 1.1.0 this is the CHANGELOG of the single **Estrategia Web** plugin.
The history of the forms up to their 1.0.0 is in
[modules/forms/CHANGELOG.md](modules/forms/CHANGELOG.md), and that of the
four blocks, in the theme README (armazon-wp-padre, «Versions»).

## 1.9.0 — 27-10-2026

**On each site, after updating (with a backup): `wp estrategia-web migrate-forms --dry-run`, check what it says (the forms it will create, the posts it will change, any «THE COPIES DIFFER» warning), then `wp estrategia-web migrate-forms`.** Until it runs nothing breaks: the forms inside pages keep rendering and submitting as in 1.8.0, and «Forms → Forms» shows them with a notice. Lavandería: Contacto (inline in the Contacto page, es/en) and Presupuesto (in the synced pattern «Formulario de presupuesto», es/en) become four form posts, two per language, en linked to es; four posts change (the two Contacto pages and the two «Formulario de presupuesto» patterns), the service pages that use the pattern are not touched (dry run on a copy of the loaded test snapshot, 28-10-2026: «4 form post(s) to create, 0 reused; 4 post(s) to change», no copies, and the expected warning that WPML does not translate eweb_form yet, which the real run fixes by re-reading the config).

- **forms: a form is its own entity** (David, 27-10-2026, after Formello). New post type **`eweb_form`** («Formulario»; not public, REST base `eweb-forms`, page capabilities), edited with the block editor using the SAME blocks: its content is one `estrategia-web-forms/form` block (put by the template, locked) with the fields, the button and the notifications inside. The form-level settings stay in that block's side panel (name in the log, identifier, messages): same attributes, same snapshot, same hash. The post title is only the admin name. `modules/forms/includes/class-form-post-type.php`.
- **New block «Formulario» (`estrategia-web-forms/form-ref`)**, the way pages show a form: it picks a form post from a list (with a preview and an «Edit this form» link) and renders it server side EXACTLY as the inline tree — same markup, token, honeypot, rate limit, REST submission and no-JS POST — because the form post's blocks are rendered as its inner blocks (as core's synced pattern does), so a synced pattern's per-page preselection (`{"servicio":{"defaultOption":2}}`) still reaches the dropdown. With WPML it renders the current language's form post (the original if there is none), whatever language the stored id is. A NEW block and not the old `form` block evolved: the old one is the form post's root, and inline trees on sites that have not migrated keep rendering with it. The old `form` block leaves the inserter (it still edits where it is).
- **Forms → Forms is the list of form posts**: «Add form», Edit link, notifications and recipients as before, and **«Used in»**: the posts with a reference to it (or, with WPML, to any of its translations) and, for a synced pattern, the pages that use it. Inline forms still listed, after, with a notice to migrate. Submissions unchanged (grouped by identifier).
- **Migration `wp estrategia-web migrate-forms [--dry-run] [--ids=…]`** (`modules/forms/includes/class-form-migration.php`): every inline form tree (any post type but revisions; publish, draft, pending, future, private) becomes a form post per identifier AND language — never unified across identifiers; the same identifier copied in two posts of a language is ONE form post, reported, with whether the copies were identical —, linked as WPML translations by identifier (it asks WPML to re-read its config first, so `eweb_form` is translatable), and the tree is replaced byte for byte by the reference block. The original content is kept in the post meta `_eweb_forms_migration_backup`; straight to the table, filter `estrategia_web_forms_migration_post` for sites that keep fingerprints of their content (same contract as the prefix migration). Idempotent: the second run says «ya». Submissions stay associated by identifier.
- **WPML**: `wpml-config.xml` declares `<custom-type translate="1">eweb_form</custom-type>`; `modules/site/tests/wpml-config.test.mjs` knows it is the forms module's, not a content-types definition.
- Tests: `forms/form-migration-plan` (level 1), `forms/form-ref`, `forms/form-migration` and `forms/form-migration-wpml` (level 2, the last one with real WPML).

## 1.7.0 — 24-10-2026

**Before updating a site with WPML: check `existing_content_language_verified=1`** (`wp eval 'echo get_option("icl_sitepress_settings")["existing_content_language_verified"];'`); **if it is 0, set it (or /en/ will redirect to /en/home/).** Lavandería: done 24-10-2026.

**Nothing else to change on the sites.** No option, key or table changes besides that: the rest is a reorganisation of the admin. The old admin URLs keep working (`estrategia-web-cookies` and `estrategia-web-google` redirect to their new tab; Company details, Modules and Submissions keep their URLs). Whoever uses the dashboard will find things in new places (below); Lavandería's notes that say «Ajustes › Datos de la empresa» or «Estrategia Web › Módulos» still point to the right screens.

- **Module `wpml-front-page` removed** (David, 24-10-2026). It papered over a WPML setup without its step 1: WPML only hooks its public filters (`pre_option_page_on_front` among them) when BOTH `setup_complete` and `existing_content_language_verified` are set, and Lavandería's content seed called `finish_installation()` (only the first), so `/en/` redirected to `/en/home/`. Fixed at the root (Lavandería's seed sets the flag, commit dc57f2f; production set and checked 24-10-2026: 26 pages ES/EN 200, `/en/` serves the English front page without the module). A saved `estrategia_web_modules` still naming it is harmless (unknown slugs are ignored; level 1 test). Its filter is gone with it: a theme or test that still filters it does nothing.
- **One «Estrategia Web» menu** (David, 24-10-2026), at position 3 (where «Company details» was), opening on Company details. Submenu: Company details, Content types, Social networks, **Settings**, Redirects, Modules (last). The old top-level «Estrategia Web» item at position 81 is gone. The top-level item's slug is Company details' (`estrategia-web-site`), so that URL and the Modules one (`estrategia-web`) did not change; `Modules_Page::MENU` now equals that parent, so a child theme hanging a page on it still lands in the menu. `includes/admin/class-menu.php`.
- **Company details: only Company and Contact** (plus local-business's «Type of business», which is company data). Out: the «Social networks» and «Content types» sections (they were links; their pages are in the menu) and «Media».
- **New page «Estrategia Web › Settings»** with tabs: **Cookies** (the consent module's screen, as it was), **Analytics** (Google tags, formerly «Estrategia Web → Google», and Matomo, formerly on Company details; one form, one Save), **Media** (SVG uploads, formerly on Company details, and the uploads module's three settings, **formerly in WordPress's Settings → Media**). A tab shows only if its module is on; with no tab, no page. Modules add their tabs and sections through a registry (`Settings_Page::add_tab()` / `add_section()` on `estrategia_web_settings_tabs`, `includes/admin/class-settings-page.php`): the page knows no module.
- **Same storage, three screens**: `allow_svg` and `matomo_*` stay keys of `estrategia_web_site`; each screen sends only its keys and `Settings::sanitize()` merges, so saving one does not wipe the other's (tested both ways). The uploads option `estrategia_web_uploads` leaves WordPress's «media» settings group for its own: in that group, saving WordPress's Media screen without its fields would have reset it to the defaults (tested).
- **Modules screen grouped by category**, with a new module header `Category:` (Content, Contact, Privacy & analytics, SEO, Languages, Media & performance; none → «Other»), categories in a fixed order and modules alphabetical inside, in the reader's language. Translated with the context «module category» (`tools/i18n.mjs` extracts it).
- **`site` (Company data) is core**: new header `Core: yes`, always loaded, not on the Modules screen. A saved `estrategia_web_modules` without `site` (every site after its next save of the Modules screen) still loads it, and what requires it (social, matomo, local-business).
- **forms: new first item «Forms → Forms»**: every form on the site (any post type, synced patterns included, publish/draft/pending/future/private, not revisions) with its name and identifier in the log, where it is (and, for a synced pattern, the pages that use it), its notifications with their recipients as written, and an Edit link; with WPML, one row per form in the default language with the languages it is also in. Read with `Definitions::build()`, the same parser as the submissions. The «Forms» sidebar item now opens this list (WordPress opens a top-level item on its first submenu); Submissions keeps its URL.
- **Cookie detector: logged-in users are never recorded** (Lavandería production, 24-10-2026: an administrator's `wp-settings-*` and `wp-wpml_current_admin_language_*` landed as pending). The page of a logged-in user carries no detector configuration (and `view.js` also checks the `logged-in` body class), and `/cookies/seen` answers 202 without recording when the request carries a valid logged-in cookie. `view.js` now sends with `credentials: 'same-origin'` (was `omit`) so the server can see it; the server validates the cookie itself because `is_user_logged_in()` is always false in a REST request without a nonce. Safe with page caches: they never serve a page rendered for a logged-in user to anyone. Pending rows already recorded that way are deleted by hand on the Cookies tab.
- Tests: `admin-pure` (level 1), `modules` (level 1: core modules, categories, grouping), `menu`, `settings-page`, `forms/forms-list` and a new case in `consent/cookies` (level 2). `tests/lib.mjs` has `adminSession()`: a wp-admin cookie session without a browser, for level 2 tests of plain HTML screens.

## 1.6.1 — 24-10-2026

- **forms: the notifications are signed with the site name** («Lavandería Mallorca <wordpress@lavanderiamallorca.com>») instead of WordPress's «WordPress». The address does not change (WordPress's default, already covered by the host's SPF), and an SMTP plugin's `wp_mail_from` / `wp_mail_from_name` still win. Nothing to change on the sites.
- **Prefix migration: one false warning less.** Its dry run listed the option `estrategia_web_content_types_site_types` («names client, testimonial, location»): that is content-types' already-migrated mark and it holds template keys, not post types.

## 1.6.0 — 22-10-2026

- **New module `local-business`** (Default: on; David, 22-10-2026): schema.org JSON-LD — the Organization on the front page (with the locations as departments) and a LocalBusiness on each location's page and on pages tied to one by an `estrategia-web-site/location` binding (address by parts, hours, geo, page url, image, parentOrganization), per WPML language. Merged into Rank Math's (`rank_math/json_ld`) or Yoast's (`wpseo_schema_graph`) graph without duplicating their Organization; steps aside where their local SEO already publishes a LocalBusiness; its own script otherwise. Type of business: Company details (option `estrategia_web_local_business_type`, 68 LocalBusiness subtypes) and per location. Filter `estrategia_web_local_business_graph`. No ratings: Google does not accept self-serving reviews.
- **content-types: new field type `hours`** (opening hours per weekday, several ranges, stored as JSON text; editor panel with time inputs, one line per day on the classic screen; the `content-field` binding shows «Mon–Sat: 8:00–16:00»).
- **content-types: the location template has new fields** (street, postal code, locality, region, country, hours, latitude, longitude, business type). Sites that copied it earlier are OFFERED them in Content types («Add the new fields of the template»); nothing is added by itself.

## 1.5.0 — 17-10-2026

- **content-types: the constructor** (David, 17-10-2026: «una definición de tipo es dato»). Definitions are data of each site (private post type `eweb_content_type`, one per type, with revisions), made in «Estrategia Web → Content types» (names and URL per language, supports, taxonomies, fields) with JSON export/import; REST `estrategia-web/v1/content-types` (administrators). Templates: clients, testimonials, locations, projects, portfolio, added with one click and then editable. Field types `rating`, `embed`, `local_image`. Protections: the internal name is fixed once there are entries, removed fields keep their data, URL changes create 301s per language (redirects module, or a warning), a type with entries can only be switched off.
- **The site module no longer registers clients, testimonials, locations, projects and portfolio**: they are content-types templates with the same names. On update, each site's types are stored once with the URLs it serves today (`Site_Types`); `enabled_types` and `rewrite_slugs` still switch them. `Meta_Panel` and their `wpml-config.xml` entries are removed (content-types declares them). content-types is now **Default: on**. JSON files in the theme are a read-only second source; a definition in the database with the same name wins.
- **content-types: «required» is enforced on the classic screen** (types without «editor»): publishing with a required field empty saves a draft and says which fields are missing; on an already published item the emptied field keeps its previous value.
- **content-types: «slug» may be an object locale → slug**, like the labels. The site language's slug is registered; with WPML + String Translation the others become WPML slug translations («URL slug: <type>», «URL <taxonomy> tax slug»), written only when they change. Slugs are validated (ASCII lowercase, digits, -, /) and two types or taxonomies cannot share a slug in the same language. Checked with real WPML in Lavandería.
- **WPML: every block text a visitor reads is translated with the page** (`wpml-config.xml`, `<gutenberg-blocks>`): the WhatsApp button's message and label; the map's place name, address, hours, image alt text and Google Maps URLs (as links, so a translation can switch the map's language); the carousel's name; the cookie settings link text; and the form's title (the default subject of its emails). Level 1 test `tests/wpml-blocks.test.mjs`: every string attribute of every block.json is declared or excluded with its reason.
- **Fixed: forms settings texts with WPML** (default success/error message, email footer) were registered in String Translation without a language and WPML took them as English, so /en/ always showed the original. They are now registered in the site's default language. A site that saved them before must delete those strings in String Translation (domain estrategia-web-forms) and save the settings again (Lavandería: none, checked 17-10-2026).
- Forms tested with real WPML for the first time: validation, success message, acknowledgement (subject, body, labels, footer), log language and the dropdown's preselected option on a translated page.
- **The three unprefixed metas get the prefix** (David, 17-10-2026: «los prefijos son obligatorios para WordPress»): `client_url` → `estrategia_web_client_url`, `testimonial_role` → `estrategia_web_testimonial_role`, `testimonial_client` → `estrategia_web_testimonial_client` (registration, the editor panel, `Meta_Panel::WPML_*` and `wpml-config.xml`). **On a site published before, run the migrator after uploading the plugin**: `wp estrategia-web migrate-meta-prefix --dry-run` (then without `--dry-run`), or Lavandería's `despliegue/prefijo-tipos.mjs`, which runs it in the same pass as the types one. It renames `wp_postmeta` of clients and testimonials and the `core/post-meta` bindings of the content; idempotent; a post with both keys keeps the new one and is warned about. Until 2.0.0 the old keys are read aliases (`Unprefixed_Meta`). Tests: `site/meta-prefix` (level 1) and `site/meta-migration` (level 2).
- **Tests: the browser tests of the carousel, map and WhatsApp blocks moved from the parent theme repo to their modules** (`modules/<block>/tests/`), with their seed in `modules/<block>/tests/wp/` and a shared harness in `tests/browser.mjs` (`blockSite`: reset, only the block modules, the module's seed). They run in Playground's default theme. The theme's editor test was split into carousel-editor, map-editor and whatsapp-editor.
- **Map: the details box over a static map is now at most 42 % INCLUDING its padding** (`box-sizing: border-box`). With content-box it could reach the marker in a theme with larger text (seen in Twenty Twenty-Five when the tests moved).
- **Cookies: third-party services the plugin itself loads now declare their known cookies.** The map (once a map with a Google embed has been rendered), Google tags (GA4 / Ads) and Matomo in hybrid mode declare their service with the new `estrategia_web_cookie_services` filter; the cookies of each service (catalogue in `modules/consent/includes/class-cookie-services.php`, taken from Google's and Matomo's own documentation, source and date in comments) appear on Estrategia Web → Cookies as pending «Declared by the plugin» proposals and, once confirmed, in the policy table. Existing rows are never overwritten; pending rows of a service no longer declared are removed. New option `estrategia_web_map_google_seen`.
- **Google tags: the form conversion also counts a submission sent WITHOUT JavaScript.** The page that comes back (`?af_result=…`) fires `estrategia-web:form-sent` with `detail.returned`, once per submission; the conversion waits until Advertising is granted on that page and is never sent without it.

## 1.4.3 — 16-10-2026

- **Carousel: the infinite loop works with any number of cards in a multi-row grid.** A short last row (e.g. 23 clients in rows of 12) used to stop the rotation and the loop rewound from the last column to the first; now each card is placed in its grid cell and the gap travels with its column (option (a), David, 16-10-2026). No clones, nothing new in the accessibility tree. Test: parent theme `tests/carousel-any-count.test.mjs`.
- **content-types: types and taxonomies default to display-as-translated** (David, 16-10-2026), like the site module's types: a list of restaurants or recipes does not go empty in a language until every item is translated. Written as `translate="1" display-as-translated="1"`, the form WPML's XSD accepts.
- **Catalan translation (ca)**: languages/estrategia-web-ca.po and .l10n.php, all 727 strings. Buttons in the imperative (Softcatalà), the site speaks to the visitor as «tu». URL slugs in Catalan: clients, sector, opinions, seus, projectes, categoria-projecte, portafolis, categoria-portafolis. A Catalan-by-default site (Gastronòmicament) registers these from the start; changing one later changes its URLs. Doubtful wording, pending David's review: docs/translations/ca-review.md.
- `tools/i18n.mjs new <locale>` starts a language (header and Plural-Forms from the PLURALS table).
- **Fixed: the default email templates were served untranslated** on a site deployed from a Windows checkout. The compiled translations keep multi-line strings with literal line breaks; checked out as CRLF they stopped matching their msgid. `.gitattributes` now forces LF, like the parent theme's.
- Tests: translations (level 1: every .po complete, no fuzzy, placeholders kept, ASCII slugs, compiled) and catalan (level 2: what the visitor reads on a Catalan site, and the Catalan slugs whatever the request's language). The WPML double knows «ca» and takes `&wpml-default=<code>`.
- **WPML: `wpml-config.xml` declares the site module's types** (eweb_client, eweb_testimonial, eweb_location, eweb_project, eweb_portfolio) **and taxonomies as display-as-translated** (`translate="1" display-as-translated="1"`; `"2"` fails WPML's XSD and drops the whole file's admin texts). WPML locks the setting on its screen. Same values Lavandería had set by hand.
- **WPML String Translation offers the company details** `registry`, `whatsapp_message`, `address` and `schedule` (`estrategia_web_site`) and the WhatsApp module's `estrategia_web_whatsapp_message`. `Settings::WPML_TRANSLATE` / `WPML_SAME`, `Content_Types::WPML_MODES`.
- Test `site/wpml-config` (level 1): the XML against the code.
- **content-types: Block Bindings source `estrategia-web/content-field`.** It shows a declared field formatted by its type, where `core/post-meta` shows the stored value: select → the option's label in the request's language; image → URL (`size`), alt or ID; relation → the related post's title, permalink or ID, in its WPML translation (`link` for an `<a>`); boolean → translated Yes/No or the definition's new `true_label` / `false_label` (`""` shows nothing); date → the site's date format (`format`); number → `number_format_i18n` with the stored decimals (`decimals`); url / email / tel → as they are, as a link with `link`, and as `mailto:` / `tel:` in `url` / `href`. The editor shows the formatted value and lists the fields of the type in context.

## 1.4.2 — 16-10-2026

- **carousel: desktop preload of the LCP slide (`preloadSlides` attribute, `estrategia_web_carousel_preload` filter).** PageSpeed on Lavandería's desktop (16-10-2026): «LCP request discovery — fetchpriority=high should be applied». On desktop the hero's LCP is slide 2, and only slide 1 has `fetchpriority="high"` (0.7.9, for mobile, where only one slide is visible). Slides 2…n now get a `<link rel="preload" as="image" fetchpriority="high" media="(min-width: 782px)">` in the `<head>` with exactly their `<img>`'s srcset and sizes (the landscape one, not the mobile crop), so the `<img>` reuses it. Only for a hero carousel (WordPress gave its first image priority) and only for slides that load eagerly. Printed in `wp_head`: block themes render the template before it; in a classic theme nothing is preloaded. **Does nothing until asked for** (default 0): Lavandería asks for 2 from its theme. Measured with Lighthouse 13.5 on local Lavandería: desktop insight from failed to passed, slide 2 downloaded once (51 KB, High, first request); mobile, the same three crops as before and none of the preload's files. Test: the theme's `tests/carousel-preload.test.mjs` (level 3).
- **Every front-end view.js is served minified** (PageSpeed: «Minify JavaScript», 2 KiB on consent's view.js): consent 13.1 → 4.5 KB, form 4.3 → 1.7, language-switcher 0.8 → 0.6, map 2.5 → 0.9 (the carousel already was). `tools/minify.mjs` now takes each `view.min.asset.php`'s dependencies from the imports of its view.js (no hand list) and refuses relative imports; `tests/minified.test.mjs` (level 1) also fails if a block.json serves an unminified script. The hand-written `view.asset.php` files are gone (the form had none: it now declares `@wordpress/interactivity`). Nothing to change on the sites.

## 1.4.1 — 15-10-2026

- **wpml-front-page: fixed, REST requests no longer get `page_on_front` translated.** `boot()` checked `REST_REQUEST`, which does not exist yet at `plugins_loaded`, so the filter was added on REST requests too and the option came back as an int (`109`) instead of the stored string (`"109"`). Measured on Lavandería (15-10-2026): its content load fingerprints the front-page options over REST, the fingerprint changed between two loads and the second one took its own front page for an edit made in the dashboard. The REST check now runs when the option is read (`wp_is_serving_rest_request()`), and a value that is not translated goes back untouched, type included. Nothing to change on the sites. Test: `modules/wpml-front-page/tests/front-page-pure.test.mjs` (level 1).

## 1.4.0 — 15-10-2026

- **Fixed: the plugin's texts now follow the language of the request with WPML, even when WordPress' locale (WPLANG) disagrees.** Measured in Lavandería production (15-10-2026): WPLANG empty and WPML in Spanish → every plugin text in English on the Spanish site (cookie notice «Accept all», maps «Show map»). The file is chosen by WPML's current language, loaded under both the locale WordPress looks up now and that language's, and reloaded on `wpml_language_has_switched` (WPML String Translation moves the locale later without `change_locale`).
- **Fixed: URL slugs (`_x( …, 'URL slug' )`) are always in the SITE language** (WPML default language, or `get_locale()`), never the request's. Before, /en/ registered `locations` while the stored rules said `sedes`, and every English location permalink was a 404. Per-language slugs are WPML's slug translation.
- content-types: labels use `Plugin::locale()`.
- Test: `tests/wplang.test.mjs` (level 2) with a WPML double in the test mu-plugin (`?wpml-double=<code>`).

- **forms: fixed email markers in English** (David, 15-10-2026): `{{all_fields}}`, `{{submission_date}}`, `{{page_url}}`, `{{site_name}}`. The old Spanish names (`{{todos_los_campos}}`, `{{fecha}}`, `{{url_pagina}}`, `{{nombre_sitio}}`) keep working as deprecated aliases until 2.0.0, so saved forms (Lavandería) need no change; the editor only offers the new ones. `{{submission_date}}` and not `{{date}}`: a fixed marker shadows a field with the same name, and "date" is a likely field name. Field markers (`{{name}}`, `{{email}}`…) do not change.
- **content-types: example prefix `gment_`** (was `gmct_`): `examples/gment_restaurant.json`. 0 plugins on WordPress.org use it.

## 1.3.0 — 15-10-2026

- **Code in English** (Armazón row 8, 15-10-2026): comments, tests, tool output and the plugin header. Checked token by token that no logic changed.
- **New module `content-types`** (off by default): each site declares its own content types, taxonomies and simple fields in JSON files of its repository (`<child theme>/content-types/*.json` or a folder added with `estrategia_web_content_types_dirs`). Generates the post types, taxonomies and typed, sanitized post meta (show_in_rest, labelled for Block Bindings / `core/post-meta`), the «<Singular> details» panel in the block editor (meta box on the classic screen), a 400 on publish when a required field is empty, the WPML configuration through `wpml_config_array` plus the copy of «copy» fields on REST saves, and a rewrite flush only when the definitions change. Field types: text, textarea, number, url, email, tel, image, date, select, relation, boolean (no repeaters or groups). Validated by `content-types.schema.json` and the PHP validator; invalid files are not registered and are listed in «Estrategia Web → Content types» (read-only).

## 1.2.0 — 15-10-2026

### Types and taxonomies with the `eweb_` prefix (15-10-2026) — proposed version 1.2.0

**Requires running a migrator on every site that already has clients,
testimonials or locations**, right after uploading the plugin (Lavandería:
`desplegar.mjs` does it, once). Without it, the site cannot find its types.

- **`site`: types and taxonomies are registered with a prefix**:
  `client` → `eweb_client`, `client_sector` → `eweb_client_sector`, and so on
  for all eight (table in the README, «Prefixed types»). WordPress.org
  requires it and `estrategia_web_` does not fit in the 20 characters of a
  type. `eweb_` was David's decision: no WordPress.org plugin uses it
  (`ew_`, 13; `ewp_`, 9). The prefix lives only in `Type_Names::PREFIX`
  (modules/site/includes/class-type-names.php).
- **Rebuilds the URL rules when migrating**: the dry run showed that if they
  were deleted and a request to /en/ rebuilt them, /sedes/ returned 404.
  **Unchanged**: the public URLs, the `estrategia_web_site` option (still
  keyed by the unprefixed keys), the `estrategia_web_site_rewrite_slug` filter
  (it receives the key), the meta, the blocks and the tables. The REST routes
  become `/wp/v2/eweb_client`….
- **Aliases until 2.0.0** (`Unprefixed_Names`): Query Loops and `post-terms`
  saved with the old name render the new one; `/wp/v2/client…` answers like
  `/wp/v2/eweb_client…` if nobody else owns that route. In the editor, no.
- **Migrator** `Prefix_Migration` (`wp estrategia-web migrate-prefix
  [--dry-run]`): wp_posts, terms, block content, templates, menus, WPML,
  Rank Math and screen preferences; idempotent; filter
  `estrategia_web_prefix_migration_post` for anyone who stores fingerprints.
- **`estrategia_web_type_name( 'client' )`**: the registered name, for the
  themes' patterns.
- **Table of public names** in the README («The contract», section 0) and
  a guide to setting up a new site: [docs/web-nueva.md](docs/web-nueva.md).
- **Version**: the «Versions» rule says renaming a type is MAJOR. 1.2.0 is
  proposed because the alias and the migrator keep the existing sites
  working (today only Lavandería); what breaks without warning is a theme's
  code asking for `get_posts( 'client' )`, and there is none. David
  decides.

### Social networks: the fixed fields leave «Company details» (15-10-2026)

Does not require changes to the sites or the deployer: the migration runs on
its own on the first request and deletes nothing.

- **`site`: the six fixed social network fields** (`social.facebook`,
  `social.instagram`, …) **no longer appear in «Company details»**; in their
  place, a link to **Estrategia Web → Social networks**. The `social` object
  of `estrategia_web_site` is still accepted over REST and stays stored.
  **Deprecated: removed in 2.0.0.**
- **`social`: idempotent migration** (`Links::migrate`, on `init`): if the
  `estrategia_web_social_links` list has never been saved and some fixed
  field has a value, they are copied to the list once, in their order and
  with their icon. With no values, it writes nothing. With the list already
  saved (even empty), it does not run again.
- **`social.<network>` alias until 2.0.0**: `Company::value()`, the Block
  Bindings source and `[estrategia_web_company]` return the link of the first
  network in the list with that icon (chosen or guessed from the link). With
  the `social` module off, the old stored value.
- **The `estrategia-web/social-links` block no longer uses the fixed fields
  as a fallback**: it renders only the list. The «Social networks» screen
  loses the «Not saved yet» notice.

### Migrator to the single plugin (14-10-2026)

- `includes/class-armazon-migration.php`: step 7 leaves ONLY
  `estrategia-web/estrategia-web.php` active (armazon-site, armazon-forms and
  the two from 1.0.0 go away); plan B for the tables uses the migration in
  `modules/forms/`; a second pass after plan B no longer warns about
  «duplicate» tables if the new one has the same rows. WP-CLI command:
  `wp estrategia-web migrate-armazon`. Rehearsed locally with Lavandería's
  production versions (0.7.9 / 0.5.4 / 0.2.2) and WPML.

### Branch modulo/contenido (14-10-2026)

Does not require changes to the sites: everything is additive; nothing is
renamed or deleted.

- **New module `social`** (Default: on, needs `site`): free, ordered list of
  social networks in **Estrategia Web → Social networks** (option
  `estrategia_web_social_links`: name, link, icon from a collection of
  17 —Simple Icons, CC0; Bootstrap Icons, MIT— or an SVG from the media
  library, sanitized when rendered) and block `estrategia-web/social-links`
  (icons only or icon and name, three sizes, text colour). The networks in
  «Company details» are migrated to the list (see above, 15-10-2026). With
  WPML, name and link as admin texts (untested with WPML).
- **`site`: the client's «Business type»**
  (`estrategia_web_client_business_type`), translatable, bindable with
  core/post-meta.
- **`site`: where the location, client and testimonial fields are edited**:
  panel in the editor sidebar (location, testimonial) and meta box on the
  classic screen (client). Open on entry.
- **`site`: WPML** (`wpml-config.xml`, `<custom-fields>`): the location's
  phone, email, links and map, the client's website and image fit, and the
  testimonial's author, rating and client are COPIED to translations;
  address, opening hours, business type and job title are TRANSLATED. The
  copy is requested from WPML (`wpml_sync_custom_field`) every time one of
  those fields changes in the ORIGINAL: WPML only copies on `save_post` what
  changed earlier in the same request, and the block editor saves the meta
  over REST afterwards, so without this the translation kept the old phone
  number (measured with WPML 5.1 on local Lavandería).
- **`site`: the location's map embed** accepts pasting the whole `<iframe>`
  (its `src` is stored), as the map block already did.
- Tests: `tests/run.mjs` accepts `AS_PORT` (a port other than 9404).
- **Module `redirects`** (Default: off): editable 301/302/410 redirects in
  **Estrategia Web → Redirects**, with hit counter and last visit, CSV
  import/export (Lavandería's, 83 rules, goes in whole), loop and duplicate
  validation, optional 404 log (path only, no IP) with «Create redirect», a
  warning if Rank Math or another redirect system is active, and
  `wp estrategia-web redirects import|list|export`. It only looks at its
  table on 404s (0 queries on a page that exists). New tables
  `{prefix}estrategia_web_redirects` and `…_404`; options
  `estrategia_web_redirects_settings` and `…_db_version`. **Changes nothing
  on the sites**: it ships off. How it is maintained, in
  `modules/redirects/module.php`.
- Tests: `EW_PORT` chooses the Playground port of `tests/run.mjs`
  (9404 by default).
### Cookies with a detector, Google and Matomo separate (branch modulo/cookies)

**Requires changes to the sites: no.** Whoever already answered the notice
does not see it again (same slugs, same revision) and Matomo keeps measuring
the same way.

- **Cookie notice, AEPD categories**: necessary, **preferences**
  (new, `preferences`), analytics and advertising, plus maps. The usual slugs
  (`statistics`, `marketing`) stay and are LABELLED «Analytics» and
  «Advertising» (previously «Statistics» and «Marketing»); the aliases
  `analytics`, `advertising` and `ads` are accepted in PHP and JS. When
  shown, preferences goes first and advertising before maps (does not touch
  the revision).
- **Reopen the panel from any link**: `#estrategia-web-consent`, the class
  `estrategia-web-consent-open` or `data-estrategia-web-consent-open`
  (for the footer menu), in addition to the block, the shortcode and
  `.open()`.
- **Cookie register with a detector** (module `consent`): on ACCEPT, the
  browser sends the NAMES of its cookies (never values) to
  `POST /wp-json/estrategia-web/v1/cookies/seen`, at most when its list
  changes or every 7 days; no nonce (page cache) and with rate limits per IP
  (without storing it) and per hour. Screen **Estrategia Web → Cookies**:
  pending ones with a proposal (own database + an extract of the **Open
  Cookie Database**, Apache 2.0, `modules/consent/data/`, regenerable with
  `tools/open-cookie-database.mjs`), confirm/change/ignore, add third-party
  ones by hand, wildcards (`_ga_*`) and extra categories for the notice.
  `wp-wpml_current_language` is classed as necessary (WPML, 1 day).
- **Cookie table for the policy**: block `estrategia-web/cookie-table`
  and shortcode `[estrategia_web_cookie_table]`, only what is confirmed, by
  category; fixed texts via `.po`, the administrator's via WPML String
  Translation (context `estrategia-web-cookies`).
- **New module `google-tags`** (Default: off, Requires: consent): GA4, Google
  Ads (+ form submission conversion) and Tag Manager with **Consent Mode
  v2** (`default` denied before anything else, `update` on choosing). Basic
  mode by default: nothing from Google until Analytics or Advertising is
  accepted; optional advanced mode. Screen **Estrategia Web → Google**.
- **Matomo is its own module** (`matomo`, Default: on, Requires: site),
  without migrating the option (`estrategia_web_site`, keys `matomo_*`). New
  hook `estrategia_web_site_settings_sections` in «Company details».
- **forms** fires `estrategia-web:form-sent` on a successful JS submission
  (used by the Google Ads conversion).
- Tests: `EW_PORT` chooses the port of the test WordPress.
- **Module `uploads`** (Default: on): replaces Lavandería's uploads
  MU-plugin. Upload limit 20 MB, downscaling threshold 2560 px and deleting
  the original after downscaling, configurable in Settings → Media (option
  `estrategia_web_uploads`). **When publishing it on Lavandería, the deployer
  deletes `wp-content/mu-plugins/estrategia-web-subidas.php`** (both at once
  do not break anything, but they do the same thing twice).
- **Module `comments-off`** (Default: off): removes comments entirely
  (closed, menu, widget, admin bar, front-end blocks, feeds), without writing
  anything to the database. On Lavandería it is switched on by hand.
- **Module `featured-images`** (Default: on): a featured image with a fixed
  width in px gets `sizes` of that width (also inside the carousel, which
  now respects a fixed sizes); new size `estrategia-web-small` (200 px,
  uncropped); and `alt=""` when the alt repeats the title that the Query
  card renders next to it (never if the image is a link). **On the server,
  once: `wp media regenerate --only-missing --yes`**, so that what is
  already uploaded gets the small size.
- **Map: «Open in Google Maps» with an accessible name per location**
  (`aria-label` «Open in Google Maps: <location> (opens in a new tab)»):
  two maps on one page no longer have two identical links to different
  destinations (Lighthouse).
- **Carousel: crop for mobile** (attributes `mobileRatio` and
  `mobileImageSizes`, or the filter `estrategia_web_carousel_mobile_crop`):
  below 782 px each photo goes in a `<picture>` with a crop in the shape of
  the box, centred on its object-position, generated the first time and
  stored as an attachment size. Local Lavandería, home page on mobile: the
  LCP photo goes from 92.5 KB (1536×1024) to 38.7 KB (720×895). **Does
  nothing until asked for**: Lavandería asks for it from its theme (see
  TODO).
- **Carousel: view.min.js** (24.8 → 7.7 KB) generated with
  `node tools/minify.mjs` (pinned esbuild via npx); `tests/minified.test.mjs`
  fails if view.js changes and is not regenerated. And the ResizeObserver no
  longer measures twice on startup (one forced reflow less).

## 1.1.0 — 14-10-2026

**One theme + one plugin** (David's decision, 14-10-2026). **Requires
changes to the sites**: the plugin changes folder and replaces two. What to
do on a site coming from 1.0.0 or from Armazón: deactivate
`estrategia-web-site` and `estrategia-web-forms` (or `armazon-site` and
`armazon-forms`), upload this one to `plugins/estrategia-web/` and activate
it, and the parent theme to 1.1.0 at the same time (Lavandería's migrator
will do it; it is the only site).

- **A single plugin, «Estrategia Web»** (`estrategia-web/estrategia-web.php`),
  with what used to be the Estrategia Web Site plugin, the Estrategia Web
  Forms plugin (archived repo) and the carousel, map, WhatsApp and language
  switcher blocks, which the theme used to register (WordPress.org does not
  allow a theme to register blocks).
- **Switchable modules** in **Estrategia Web → Modules**: `site`, `consent`,
  `webp`, `external-links`, `wpml-front-page`, `forms`, `carousel`, `map`,
  `whatsapp`, `language-switcher`. **All on by default**: a site that
  updates does not change. A module that is off loads nothing and deletes
  nothing. New options: `estrategia_web_modules` (the ones on) and
  `estrategia_web_modules_known` (the ones that existed when saving; see the
  edge case in `includes/class-modules.php`).
- **A new module is a folder** `modules/<slug>/` with a header `module.php`:
  it is discovered on its own, with no central list. How, in the header of
  `includes/class-modules.php`.
- **Public names unchanged**: blocks `estrategia-web/…`,
  `estrategia-web-site/…` and `estrategia-web-forms/…`, options and meta
  `estrategia_web_…`, tables, REST routes, filters, shortcodes and
  `armazon…` aliases. The `armazon/carousel`… aliases that the theme
  provided are now provided by the core, only for the blocks whose module is
  on.
- **A single text domain: `estrategia-web`** (previously
  `estrategia-web-site` and `estrategia-web-forms`). The editor translations
  no longer go in JSON with the md5 of the path: the core passes them inline
  to `wp.i18n`, for all the plugin's blocks.
- **Carousel functions in a namespace**:
  the theme's `estrategia_web_carousel_images_*()` become
  `EstrategiaWeb\Carousel\images_*()`. The filters
  (`estrategia_web_carousel_eager_slides`, `…_image_sizes`) do not change.
  No child theme called them.
- **Hooks for the modules**: `estrategia_web_activate`,
  `estrategia_web_deactivate` and `estrategia_web_modules_loaded`.
- **Tests per module** in `modules/<slug>/tests/`, with a single runner and
  a single test WordPress (port 9404). New:
  `tests/modules.test.mjs` (level 1) and `tests/modules-page.test.mjs`
  (level 2).
- **The migrator** (`includes/class-armazon-migration.php`) does not change
  in this version: its step 7 still activates the two plugins from 1.0.0.
  Adapting it is the next part (see TODO.md).

## 1.0.0 — 07-10-2026

**MAJOR: name change. Requires changes to the sites:** run the migrator
(below) and update the parent theme to Estrategia Web 1.0.0 at the same
time.

- **«Armazón Site» becomes «Estrategia Web Site»**: folder and file
  `estrategia-web-site/estrategia-web-site.php`, text domain, namespace
  `EstrategiaWeb\Site`, functions, constants, filters, option, meta, bindings
  sources, block, cookie, JS API, event, shortcodes, handles and classes
  (table in the README, «Names: from «Armazón Site» to «Estrategia Web Site»
  (1.0.0)»). Author, Plugin URI and Author URI: Estrategia Web,
  https://estrategiaweb.es/.
- **Prefixed meta**: those of the types (`location_…`, `client_image_fit`,
  `testimonial_…`) become `estrategia_web_…`, also as keys of the
  `core/post-meta` bindings and of the `…/location` source.
- **«Powered by Estrategia Web» OFF by default** (WordPress.org guideline
  10); it is switched on with `add_filter(
  'estrategia_web_site_consent_powered_by', '__return_true' )`.
- **Consent cookie**: `estrategia_web_consent`, same format and revision.
  The old one is read if the new one is missing and the browser moves it to
  the new one: nobody sees the notice again because of the change.
- **Aliases** for the old names until 2.0.0
  (`includes/class-legacy-names.php`); it replaces the `armazon/consent`
  alias that existed since 0.5.0.
- **The migrator** (`includes/class-armazon-migration.php`, WP-CLI command
  `estrategia-web-site migrate-armazon [--dry-run]`): content, meta,
  options, form tables, WPML strings, active theme and plugins. Tested
  locally on a site loaded with the old names.
- README: «If you change provider».

## 0.5.4 — 06-10-2026

Patch: does not require changes to any site. Changes the weight of the WebP
files generated from the moment it is active.

- **WebP at quality 75** (`includes/class-webp.php`, `Webp::quality`):
  WordPress's `wp_editor_set_quality` filter, only when the format being
  saved is `image/webp` (JPEG and PNG that remain so, untouched). WordPress
  uses 82 for everything. Why: Lavandería's PageSpeed (mobile, 06-10-2026)
  reported ~371 KB under «improve image delivery», partly due to
  compression. Setting: `armazon_site_webp_quality` (0 or less, WordPress's
  value; capped at 100).
- **The trap:** what is already uploaded stays at 82 until regenerated, and
  regenerating plainly has the 0.5.1 trap. On Lavandería it is done by the
  «webp» step of `despliegue/desplegar.mjs` (marks `_lm_webp_calidad` per
  attachment).
- Test: `tests/pure.test.mjs` (level 1), with a fake `apply_filters` in
  `tests/php/pure.php`. There is no level 2 test that checks the real
  weight: the quality that reaches the editor is what WordPress's filter
  says, and that is what is tested.

## 0.5.3 — 06-10-2026

Patch: does not require changes to any site. Only acts with WPML.

- **The translated front page** (`includes/class-wpml-front-page.php`): on
  the public side, `page_on_front` and `page_for_posts` are translated into
  the request's language with `wpml_object_id`. On Lavandería, with WPML
  5.0.2 on the server, /en/ redirected to /en/home/ because WordPress still
  saw the Spanish front page; locally WPML did do it. It is switched off
  with `armazon_site_wpml_front_page`. The trap: it cannot be checked via
  WP-CLI (WPML does not load its public side); you have to request /en/.

## 0.5.2 — 06-10-2026

Patch: does not require changes to any site. Changes how links to another
domain open on every site that updates.

- **External links in a new tab** (`includes/class-external-links.php`):
  every front-end `<a href>` to another host gets `target="_blank"`,
  `noopener` added to `rel` (without removing what it had) and an
  `aria-label` ending in «(opens in a new tab)». A single filter over the
  whole page (`wp_template_enhancement_output_buffer`, WP 6.9+) with
  `WP_HTML_Tag_Processor`. Internal: the same host, with or without `www.`,
  and its subdomains. Relative links, anchors, `mailto:`, `tel:`, `sms:`,
  `javascript:` and those that already have a `target` are left alone (the
  «Powered by» is not duplicated). It is switched off with `add_filter(
  'armazon_site_external_links', '__return_false' )`. David asked for it
  because of «Hablar con un asesor» (talk to an advisor, wa.me) on
  Lavandería's /hosteleria/.
  **Edge case:** the client's old website on another domain counts as
  external.
- **Tests:** `node tests/run.mjs <name>` runs level 1 and, of the rest,
  only that test (Armazón row 7, «Cuándo corre la batería», "when the suite runs").

## 0.5.1 — 06-10-2026

Patch: does not require changes to any site. **But WebP needs a separate
step for what is already uploaded** (below, the trap).

- **WebP** (`includes/class-webp.php`): uploaded JPEG and PNG files are
  saved as WebP, including the main file (the `.jpg` stays as
  `original_image`). Only if the server can write WebP. It is switched off
  with `add_filter( 'armazon_site_webp', '__return_false' )`.
  **The trap:** what is already uploaded does not change on its own, and
  when regenerating (`wp media regenerate`) the URLs stored in the content
  do not change: the main `.jpg` keeps working but **without `srcset`**
  (worse than before) and that of an intermediate size breaks. After
  regenerating, write the media library's current URL back into the
  content.
- **Cookie notice, focus order:** it is rendered at the start of the `body`
  (`wp_body_open`), so when open it is the first thing reached by tabbing
  (WCAG 2.4.3; previously, about 30-35 tab presses). No focus trap; Escape
  still does not close it the first time.
- **Cookie notice, the text no longer sticks to the buttons:** the margins
  of the title, the text and the «Powered by» use two classes. With one they
  tied with `:root :where(p) { margin: 0 }` from the theme's `theme.json`,
  which loads later and won (0.6 px on Lavandería). Gap from text to
  buttons: 24 px.
- Notice: the link in the text, underlined (WCAG 1.4.1); the «Powered by»
  with a 24 px target (2.5.8); and `view-transition-name: armazon-consent`
  without animation, so it does not jump during the parent theme 0.7.5
  transitions.
- Tests: `tests/webp.test.mjs` (level 2, new) and two more in
  `tests/consent-browser.test.mjs` (level 3). Seen failing before the fix:
  the sizes came out as `.jpg`, the first Tab went to «Skip to content» and
  the gap was 0.6 px.

## 0.5.0 — 05-10-2026

Minor for the plugin (additive only), but **it goes together with parent
theme 0.7.0**, which stops shipping the cookie notice: on each site both are
uploaded at the same time. With this plugin and the old parent, the plugin
steps aside and the parent's notice shows as before.

- **Cookie notice**, brought over from the parent theme with the same
  contract: the same `armazon_consent` cookie and the same revision
  (whoever already chose does not see the notice again), the
  `armazon_consent_categories` filter, the `armazon_consent_*` functions,
  `window.armazonConsent` (`categories`, `has`, `get`, `open`, `grant`), the
  `armazon:consent` event, the `armazon/consent` store, the
  `<script type="text/plain" data-armazon-consent>` tags, the
  `[armazon_consent_settings]` shortcode and the WP Consent API. Why: it is
  functionality, not presentation; a site that changes theme cannot be left
  without a notice, and Matomo already depended on it.
- Link block **`armazon-site/consent`**; the old name `armazon/consent` is
  registered as a hidden alias, so what is already saved still renders the
  link.
- Neutral base styles in the plugin, which read the theme's tokens.
- **«Powered by Estrategia Web»** at the foot of the notice: a link to
  https://estrategiaweb.es/ in a new tab, `rel="noopener"`, without
  `nofollow`. It is changed or removed with the
  `armazon_site_consent_powered_by` filter.
- Hybrid Matomo: its header no longer talks about the «parent theme's
  notice». With any theme there is a notice and, therefore, consent.
- **Fix** (found while testing Matomo with the real notice): if the notice
  hydrated after `window.load`, Matomo did not learn about an expired choice
  and kept its cookies (1 in 3 loads). The notice now fires
  `armazon:consent-ready` once on startup (with `accepted: null` if there is
  no choice) and Matomo listens for it.
- `tools/i18n.mjs` also extracts `blocks/` (PHP and `block.json`).
- Tests: `tests/consent.test.mjs` (level 2) and
  `tests/consent-browser.test.mjs` (level 3); `tests/matomo-browser.test.mjs`
  uses the REAL notice instead of simulating the event. The
  `consent-setup` test route acts as the theme (categories, «Powered by»,
  deferred script).

## 0.4.0 — 03-10-2026

Minor: additive only. No site has to change anything.

- Legal details: `registry` key in the `armazon_site` option (the company
  registry entry as the legal notice writes it, free text), on the screen,
  in REST (merged and sanitized), in the `armazon-site/company` source and
  in `Company::value()`. Company name, tax ID and registered address were
  already there (`legal_name`, `tax_id`, `address`).
- Shortcode `[armazon_company key="…"]`: a company detail inside a sentence
  (the legal notice), escaped and on one line (address and opening hours
  joined with «, »). A binding replaces the whole paragraph.
- Locations: fields `location_static_map` (URL of the static map image,
  only from the site itself: theme path, site path or same domain) and
  `location_static_map_id` (image attachment), for `staticMapUrl` of the
  parent theme's `armazon/map` with core/post-meta in a Query Loop. Also in
  the `armazon-site/location` source.
- Tests: `tests/legal.test.mjs` and `tests/static-map.test.mjs` (level 2).

## 0.3.0 — 03-10-2026

Minor: additive only. No site has to change anything; without Matomo
configured, nothing is printed.

- Matomo: keys `matomo_url`, `matomo_site_id` and `matomo_mode`
  (`cookieless` by default, or `hybrid`) in the `armazon_site` option, and
  its «Statistics (Matomo)» section on the screen. The tracking code goes in
  the `<head>`; nothing is tracked in the admin, previews or for anyone who
  can edit (filter `armazon_site_matomo_track`).
- Hybrid mode: no cookies until the visitor accepts «statistics» in the
  parent theme's notice (`rememberCookieConsentGiven`; on withdrawal,
  `forgetCookieConsentGiven`). It declares the category with
  `armazon_consent_categories`. Its legality is not verified (CNIL).
- Tests: `tests/matomo.test.mjs` (level 2) and
  `tests/matomo-browser.test.mjs` (level 3, with the real matomo.js in
  `tests/fixtures/`).

## 0.2.0 — 02-10-2026

Minor: additive only. No site has to change anything.

- Source `armazon-site/company`: derived key `phone_secondary_href`
  (`tel:` of the secondary phone).
- New source `armazon-site/location`: the details of ONE location, by slug
  (`{"slug":"maioris","key":"location_phone"}`) from any page or from the
  context of a Query Loop (`{"key":…}`). Keys `title`, the `location_*`
  fields and the derived `location_phone_href` and `location_email_href`.
  Also in the editor.
- Clients that are a logo: field `client_image_fit` (`cover` by default or
  `contain`) and class `has-image-fit-contain` on the post (post_class) when
  it is `contain`. No CSS: the theme renders it.
- Public slugs per site: key `rewrite_slugs` in the `armazon_site` option
  (object type-or-taxonomy → slug, `sanitize_title`, empty = the usual one,
  merged by key). It is applied before the `armazon_site_rewrite_slug`
  filter, which still has the last word, and on change the URL rules are
  rebuilt. A site that moved `/clientes/` with the filter from its theme can
  move it to the option (not mandatory: the filter keeps working the same).

## 0.1.0 — 02-10-2026

First version.

- Company details: `armazon_site` option with its own screen, Settings API
  and REST schema; it is saved by merging and invalid data does not
  overwrite what was there.
- Block Bindings source `armazon-site/company` (front end and editor), with
  the derived `phone_href`, `email_href` and `whatsapp_href`.
- Switchable types `client`, `testimonial`, `location`, `project`,
  `portfolio`, with taxonomies `client_sector`, `project_category`,
  `portfolio_category` and their meta fields. Deactivating does not delete
  content.
- SVG upload for administrators, sanitized by allowlist, with dimensions.
- es_ES translation (`.po` and `.l10n.php`).
