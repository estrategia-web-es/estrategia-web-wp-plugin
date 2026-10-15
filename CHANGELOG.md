# Changelog

One entry per version, newest first. It is written in the same commit that
bumps the number (README, «Versions»). If a version requires changing
something on the sites, the entry says so first, along with what to change.

Since 1.1.0 this is the CHANGELOG of the single **Estrategia Web** plugin.
The history of the forms up to their 1.0.0 is in
[modules/forms/CHANGELOG.md](modules/forms/CHANGELOG.md), and that of the
four blocks, in the theme README (armazon-wp-padre, «Versions»).

## Unreleased

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
