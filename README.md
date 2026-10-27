# Estrategia Web — the WordPress sites plugin, in modules

**Everything that is not design** on an Estrategia Web site, in a single
plugin with **modules you switch on and off** in **Estrategia Web → Modules**.
The design (templates, parts, patterns, `theme.json`) belongs to the
[Estrategia Web](E:/Repositories/armazon-wp-padre/README.md) theme, which
since its 1.1.0 does not register any block (a WordPress.org requirement).

David's decision of 14-10-2026: **one theme + one plugin**. Up to 1.0.0
(which was never published) there were three pieces: the «Estrategia Web
Site» plugin (this repo), the «Estrategia Web Forms» plugin
([armazon-wp-formularios](E:/Repositories/armazon-wp-formularios/README.md),
archived) and four blocks registered by the theme. **The public names of
1.0.0 do not change** (blocks, options, meta keys, tables, REST routes,
filters): what changed is where the code lives. The general framework is in
[armazon/decisiones/2026-10-01-wordpress-tema-padre-compartido.md](E:/Repositories/armazon/decisiones/2026-10-01-wordpress-tema-padre-compartido.md).

- Slug, folder and text domain: `estrategia-web` (`estrategia-web/estrategia-web.php`) · WordPress **7.1+** · PHP **8.1+** · GPLv2+ · version **1.8.0**
- The repo is still called `armazon-wp-sitio`; the plugin folder on a site is `estrategia-web/`.
- No dependencies, no `node_modules`, no build step.

Why a plugin and not the parent theme: what is stored and what works **cannot
disappear when the theme changes**. What is seen belongs to the theme; what is
stored and what it does, to this plugin.

## Modules

| Module (folder) | What it does | Default |
|---|---|---|
| `site` | company details (Block Bindings, `[estrategia_web_company]`), the details of one location (Block Bindings) and sanitized SVG: sections 1 to 4 below; the content types (clients, testimonials, locations, projects, portfolio) are `content-types` templates since 17-10-2026 | on |
| `content-types` | the constructor: each site's own content types, fields and taxonomies, made in the panel or from templates (section «content-types module») | on (since 17-10-2026) |
| `matomo` | Matomo code, cookieless or hybrid (unreleased: previously inside `site`; requires `site`, stores in its option): section 5 | on |
| `consent` | the cookie notice and its API; (unreleased) cookie register with detector and table for the policy: sections 6 and 6 bis | on |
| `google-tags` | (unreleased) GA4, Google Ads and Tag Manager with Consent Mode v2; requires `consent`: section 6 ter | **off** |
| `webp` | image sizes in WebP: section 7 | on |
| `external-links` | links to another domain in a new tab: section 8 | on |
| `forms` | block-based forms, email notifications and submission log: [modules/forms/README.md](modules/forms/README.md) | on |
| `carousel` | `estrategia-web/carousel` block | on |
| `map` | `estrategia-web/map` block (two-click map) | on |
| `whatsapp` | `estrategia-web/whatsapp` block and its two options | on |
| `language-switcher` | `estrategia-web/language-switcher` block (WPML) | on |
| `social` | free list of networks (**Estrategia Web → Social networks**, option `estrategia_web_social_links`) and `estrategia-web/social-links` block; requires `site`. All of it in the header of [modules/social/module.php](modules/social/module.php) | on (unreleased) |
| `local-business` | (unreleased) schema.org structured data: the Organization on the front page and a LocalBusiness on each location's page and on the pages tied to one (address, hours, coordinates), inside Rank Math's or Yoast's graph when they are there; requires `site` and `content-types`: section «local-business module» | on |
| `redirects` | 301/302/410 redirects editable in **Estrategia Web → Redirects**, CSV import/export, optional 404 log, `wp estrategia-web redirects import\|list\|export`: [modules/redirects/module.php](modules/redirects/module.php) | **off** |

**On by default: the ones Lavandería already used** (also `matomo`, which
came out of `site`): a site that updates does not change. Anything new that
changes what the visitor sees or what the site requires (`redirects`,
`google-tags`) starts off; in Lavandería the redirects are handled by Rank
Math. A module that is off **loads nothing** (not its PHP, nor its blocks,
nor its assets) and **deletes nothing** of what it stored.

**`site` is core since 1.7.0**: always loaded (header `Core: yes`), not on
the Modules screen, even if a saved `estrategia_web_modules` lacks it.

**`wpml-front-page` was removed in 1.7.0**: it papered over a WPML set up
without its step 1 (`existing_content_language_verified`), with which WPML
does not translate the front page. What to check on a WPML site:
[docs/web-nueva.md](docs/web-nueva.md), «5. WPML: yes or no». A saved list
that still names it is harmless: unknown slugs are ignored.

### The admin (1.7.0)

One top-level item, **Estrategia Web**, at position 3 (right after the
Dashboard), that opens on Company details:

| Submenu | URL (`admin.php?page=…`) | What |
|---|---|---|
| Company details | `estrategia-web-site` | Company and Contact (and local-business's type of business) |
| Content types | `estrategia-web-content-types` | content-types module |
| Social networks | `estrategia-web-social` | social module |
| Settings | `estrategia-web-settings&tab=…` | tabs **Cookies** (consent), **Analytics** (google-tags + matomo), **Media** (SVG + uploads); a tab only if its module is on |
| Redirects | `estrategia-web-redirects` | redirects module |
| Modules | `estrategia-web` | always last, grouped by the modules' `Category` |

**Forms** keeps its own top-level item: **Forms** (the list of the site's
forms, where each one is, its notifications), **Submissions**, **Settings**.

Old URLs: `estrategia-web-cookies` and `estrategia-web-google` redirect to
their tab; `estrategia-web-site`, `estrategia-web` and
`estrategia-web-forms` did not change. A module adds a Settings tab or
section through the registry in
[includes/admin/class-settings-page.php](includes/admin/class-settings-page.php)
(its header: how, the edge case of two screens saving one option, and the
trap); the menu order and the old URLs, in
[includes/admin/class-menu.php](includes/admin/class-menu.php).

**How to add a module:** a `modules/<slug>/` folder with its `module.php`,
which carries a header (name, description, whether it is on by default, what
it depends on, its category). It is discovered on its own: there is no central list.
Everything you need to know —fields, the edge case of a new module on a site
that already existed, the traps— is in the header of
[includes/class-modules.php](includes/class-modules.php). The minimal example
is [modules/webp/module.php](modules/webp/module.php); the complete one,
[modules/forms/module.php](modules/forms/module.php).

    estrategia-web.php          entry point: constants and core bootstrap
    includes/                   the core, always loaded:
      class-modules.php           the loader (HOW TO ADD A MODULE)
      class-plugin.php            translations and the estrategia_web_activate / _deactivate hooks
      class-blocks.php            autoRegister labels, editor translations, armazon/… aliases
      class-armazon-migration.php the migrator from «Armazón» (for all the pieces)
      class-prefix-migration.php  the migrator of the post type prefix (1.2.0)
      admin/class-menu.php          the «Estrategia Web» menu, its order and the old URLs (1.7.0)
      admin/class-settings-page.php «Estrategia Web › Settings» and its tab registry (1.7.0)
      admin/class-modules-page.php  the «Estrategia Web → Modules» screen
    modules/<slug>/             module.php (header + bootstrap), includes/, blocks/, assets/, tests/, uninstall.php
    languages/                  estrategia-web.pot · estrategia-web-es_ES.po · .l10n.php (one for everything)
    tests/                      runner, lib.mjs, blueprint and the core tests
    tools/i18n.mjs              extracts (module headers too), merges and compiles
    tools/open-cookie-database.mjs  regenerates the Open Cookie Database extract (consent module)
    wpml-config.xml             WPML only reads the one at the root: all modules' entries go here
    uninstall.php               calls each module's uninstall.php

---

## The contract

This is what the parent theme, the child themes and the content loading read.
**These names do not change in a minor version** (see «Versions»).

### 0. Public names, with their prefix (15-10-2026)

Everything this plugin leaves in WordPress's shared space (the database, the
block and post type registries, the REST API, the browser) carries a prefix,
so that it does not clash with another plugin, and for WordPress.org. **This
table is the contract**: a new name goes in here in the same commit as the
code, with the prefix of its row.

| What | Prefix | Today's names |
|---|---|---|
| Post types | `eweb_` (≤ 20 characters in total) | `eweb_client`, `eweb_testimonial`, `eweb_location`, `eweb_project`, `eweb_portfolio` (unreleased, 1.2.0; unprefixed up to 1.1.0); `eweb_content_type` (private: the content-types module's definitions, one per type) |
| Taxonomies | `eweb_` | `eweb_client_sector`, `eweb_project_category`, `eweb_portfolio_category` (same) |
| Blocks | `estrategia-web/`, `estrategia-web-site/`, `estrategia-web-forms/` | `estrategia-web/carousel`, `/map`, `/whatsapp`, `/language-switcher`, `/social-links`, `/cookie-table`; `estrategia-web-site/consent`; `estrategia-web-forms/form`, `/text`, `/email`, `/tel`, `/textarea`, `/select`, `/checkbox`, `/submit`, `/notification` |
| Options | `estrategia_web_` | `estrategia_web_site`, `estrategia_web_modules`, `estrategia_web_modules_known`, `estrategia_web_forms_settings`, `estrategia_web_forms_db_version`, `estrategia_web_forms_last_purge`, `estrategia_web_forms_migrations_log`, `estrategia_web_cookies`, `estrategia_web_cookies_settings`, `estrategia_web_google_tags`, `estrategia_web_map_google_seen`, `estrategia_web_redirects_settings`, `estrategia_web_redirects_db_version`, `estrategia_web_social_links`, `estrategia_web_uploads`, `estrategia_web_whatsapp_phone`, `estrategia_web_whatsapp_message`, `estrategia_web_content_types_hash`, `estrategia_web_content_types_wpml_pending`, `estrategia_web_content_types_wpml_slugs` |
| Post meta | `estrategia_web_` | those of section 3, all of them (unreleased: `client_url`, `testimonial_role` and `testimonial_client`, from before 1.0.0, became `estrategia_web_…`; the old keys are read aliases until 2.0.0, see «Names») |
| Tables | `{prefix}estrategia_web_` | `estrategia_web_forms_submissions`, `estrategia_web_forms_definitions`, `estrategia_web_redirects` |
| Transients and cron | `estrategia_web_` | `estrategia_web_redirects_report_*`, `estrategia_web_cs_*` (detector limit); events `estrategia_web_forms_purge`, `estrategia_web_redirects_purge` |
| Cookies | `estrategia_web_` | `estrategia_web_consent` (the old `armazon_consent` is read and carried over) |
| Hooks (filters and actions) | `estrategia_web_` | those of each section; the live list: `grep -rhoE "(apply_filters\|do_action)\( *'estrategia_web[a-z_]*'" modules includes` |
| REST routes | `estrategia-web/v1`, `estrategia-web-forms/v1` | `estrategia-web/v1/cookies`, `/cookies/seen`, `/content-types`, `/content-types/<name>`, `/content-types/templates/<key>`; `estrategia-web-forms/v1/submit`, `/token`; the post types' routes are core's, `/wp/v2/eweb_client`… (aliases of `/wp/v2/client`… until 2.0.0) |
| Block Bindings sources | `estrategia-web-site/`, `estrategia-web/` | `estrategia-web-site/company`, `estrategia-web-site/location`, `estrategia-web/content-field` (content-types module) |
| Shortcodes | `estrategia_web_` | `[estrategia_web_company]`, `[estrategia_web_consent_settings]`, `[estrategia_web_cookie_table]` |
| Global functions (for themes) | `estrategia_web_` | `estrategia_web_type_name()` (1.2.0) |
| PHP | namespace `EstrategiaWeb\` | `EstrategiaWeb\Site\…`, `EstrategiaWeb\Forms\…`… |
| JS and events | `estrategiaWeb…`, `estrategia-web:` | `window.estrategiaWebConsent`, `estrategia-web:consent`, `estrategia-web:consent-ready`, `estrategia-web:form-sent` |
| WP-CLI | `estrategia-web` | `wp estrategia-web redirects …`, `migrate-armazon`, `migrate-prefix` |
| Text domain, folder | `estrategia-web` | — |

**Two prefixes, on purpose**: `estrategia_web_` / `estrategia-web` for
everything else, and **`eweb_` only for post types and taxonomies**, because
WordPress cuts a post type name at **20 characters** and
`estrategia_web_testimonial` is 26. **Why `eweb_`** (David, 15-10-2026):
measured across the code of every plugin on WordPress.org (veloria.dev), no
plugin uses `eweb_`; `ew_` is used by 13 (Easy Watermark, Essential
Widgets…) and `ewp_` by 9 (ElasticPress), and they were discarded; `estweb_`
was not liked. The post type prefix lives in a single place
([class-type-names.php](modules/site/includes/class-type-names.php)) and does
not change once published on a site (it would be a whole new migration).

### 1. Company details — option `estrategia_web_site`

**Estrategia Web › Company details** screen (capability `manage_options`;
since 1.7.0 only the company and contact keys: `allow_svg` is saved from
Settings › Media and `matomo_*` from Settings › Analytics, into this same
option — each screen sends only its keys and the merge keeps the rest). A
single option (array), registered with the Settings API and exposed in REST
with its schema: `GET/POST /wp/v2/settings` → key `estrategia_web_site`.

| Key | Type | Sanitizing |
|---|---|---|
| `company_name` | text | no HTML |
| `legal_name` | text | no HTML |
| `tax_id` | text | no HTML |
| `registry` | text (0.4.0): the company registry entry **exactly as the legal notice states it**, without «Inscrita en» («Registro Mercantil de Mallorca, tomo 1234, folio 56, hoja PM-7890, inscripción 1.ª»). A single key on purpose: the notice writes it as a sentence, and not every company is in the Mercantile Registry | no HTML |
| `email` | text | valid email; otherwise **the previous one stays** |
| `phone` | text | as written («971 12 34 56») |
| `phone_secondary` | text | same |
| `whatsapp` | text | stored **as digits only, with the country code** («+34 600…» → `34600…`); invalid → the previous one stays |
| `whatsapp_message` | text | no HTML |
| `address` | multi-line text | no HTML, keeps line breaks |
| `schedule` | multi-line text | same |
| `map_url` | URL | http(s); invalid → the previous one stays |
| `social` | object: `facebook`, `instagram`, `linkedin`, `x`, `youtube`, `tiktok` | http(s) URL each. **Deprecated (unreleased), goes away in 2.0.0**: it is no longer on the screen; the `social` module copies it once into its list. Networks are written in `estrategia_web_social_links` |
| `enabled_types` | array ⊂ `client`, `testimonial`, `location`, `project`, `portfolio` | any other value → **400** for the whole request |
| `allow_svg` | boolean | |
| `rewrite_slugs` | object `name → slug` with the names `client`, `client_sector`, `testimonial`, `location`, `project`, `project_category`, `portfolio`, `portfolio_category` (0.2.0) | `sanitize_title` («Mi Cliente!» → `mi-cliente`); `''` = the usual one; any other name → **400** for the whole request. Merged **per key**. When it changes, the URL rules are rebuilt |

**It is saved by merging**: a POST with `{"estrategia_web_site": {"phone": "…"}}`
changes the phone and leaves the rest. That way the content loading fills it
in by parts.

**With WPML** (1.4.3), `registry`, `whatsapp_message`, `address` and
`schedule` are offered in **WPML › String Translation** (domain
`admin_texts_estrategia_web_site`); untranslated, every language shows the
original. The translation reaches the site through `Company::value()`
(shortcode, Block Bindings, WhatsApp button) on its own. The «Company
details» screen always shows and saves the original, whatever the admin
language; `/wp/v2/settings?lang=en` returns the translation, so a content
load reads and writes **without** `?lang`. The rest of the details are the
same in every language. Criterion and edge case, in
[wpml-config.xml](wpml-config.xml).

```bash
# REST (as an administrator; Playground or a site with an application password)
curl -u admin:APP_PASS -X POST https://web.test/wp-json/wp/v2/settings \
  -H 'Content-Type: application/json' \
  -d '{"estrategia_web_site":{"phone":"971 12 34 56","enabled_types":["client","location"]}}'

# WP-CLI: goes through the same sanitizing (sanitize_option), so it merges too.
# (Deduced from the code, not tested: the tests go through REST.)
wp option update estrategia_web_site '{"phone":"971 12 34 56"}' --format=json
```

In PHP, a theme reads a value with `\EstrategiaWeb\Site\Company::value( 'phone' )`
(not with `get_option`, which skips the filter and the derived keys).

### 2. Block Bindings source `estrategia-web-site/company`

`args.key` = any key above (except `enabled_types`, `allow_svg` and
`rewrite_slugs`), `social.<network>` with a dot (**alias until 2.0.0**: with
the `social` module on, the link of the first network in its list with that
icon; see [class-company.php](modules/site/includes/class-company.php)), and
four **derived** keys:

| Key | Example |
|---|---|
| `phone_href` | `tel:+34971123456` (country code 34 if it has none; filter `estrategia_web_site_default_country_code`) |
| `phone_secondary_href` | same, from `phone_secondary` (0.2.0) |
| `email_href` | `mailto:hola@empresa.com` |
| `whatsapp_href` | `https://wa.me/34600111222?text=Hola%2C%20quer%C3%ADa…` |

It works for the **content** of paragraphs and headings (escaped text;
multiple lines come out with `<br>`) and the **url** of buttons and links. In
the editor the block **shows the value** (or `[Name of the detail]` if empty)
and the source appears in the «Attributes» panel with the list of details; it
cannot be edited from the block.

A paragraph with the phone:

```html
<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"estrategia-web-site/company","args":{"key":"phone"}}}}} -->
<p></p>
<!-- /wp:paragraph -->
```

A WhatsApp button (the button text is fixed; the url, bound):

```html
<!-- wp:buttons -->
<div class="wp-block-buttons"><!-- wp:button {"metadata":{"bindings":{"url":{"source":"estrategia-web-site/company","args":{"key":"whatsapp_href"}}}}} -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button">Escríbenos por WhatsApp</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons -->
```

A «Call» button with text and url bound at the same time (the button's `text`
has no test of its own; the `url` does):

```html
<!-- wp:button {"metadata":{"bindings":{"text":{"source":"estrategia-web-site/company","args":{"key":"phone"}},"url":{"source":"estrategia-web-site/company","args":{"key":"phone_href"}}}}} -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button"></a></div>
<!-- /wp:button -->
```

### 2 bis. A detail inside a sentence: `[estrategia_web_company key="…"]` (0.4.0)

A binding replaces the **whole** paragraph (WP 7.1 has no inline bindings),
so for the **legal notice** (LSSI, art. 10) there is a shortcode. Same keys
as the source (`Company::value()`: also `social.<network>` and the derived
ones), escaped text and **on one line**: address and opening hours come out
joined with «, ». A key that does not exist or is empty prints nothing.

```html
<!-- wp:paragraph -->
<p>Titular: [estrategia_web_company key="legal_name"], con NIF [estrategia_web_company key="tax_id"] y domicilio en [estrategia_web_company key="address"]. Teléfono: [estrategia_web_company key="phone"]. Correo: [estrategia_web_company key="email"]. Inscrita en el [estrategia_web_company key="registry"].</p>
<!-- /wp:paragraph -->
```

- In the **editor you see the shortcode** as is; the value shows on the site.
  A detail alone in its paragraph is better as a binding (which is visible in
  the editor).
- It works in the content and in block templates; **not** in a block
  attribute nor in an `href` (for links, the button binding).
- `[[estrategia_web_company …]]` with double brackets is WordPress's
  **escape**: it comes out literally. Why a shortcode and not a block, in the
  header of
  [modules/site/includes/class-shortcode.php](modules/site/includes/class-shortcode.php).

### 2 ter. Block Bindings source `estrategia-web-site/location` (0.2.0)

The details of **one location** (post type `location`, which must be
enabled). Two ways to say which, through the `args`:

| args | Which location |
|---|---|
| `{"slug": "maioris", "key": "…"}` | that one, from **any** page. The identifier is the location's **slug** (its «permalink»), not the title |
| `{"key": "…"}` | the one in **context**: inside a Query Loop of locations, the one of each row (`postId`). Outside a location, empty |

Keys:

| Key | What |
|---|---|
| `title` | the location's name |
| `estrategia_web_location_address` | **derived (1.8.0)**: the address text, from street (1-2 lines), postal code, locality and region, in the format of the location's language. Multi-line (`<br>`) |
| `estrategia_web_location_schedule` | **derived (1.8.0)**: the hours text, from the `hours` field («Abierto todos los días» / «De 8:00 a 23:00»). Multi-line |
| `estrategia_web_location_map_url`, `estrategia_web_location_map_embed` | **derived (1.8.0)**: «Open in Google Maps» and the embed URL, from «What to search on Google Maps» (`estrategia_web_location_map_query`) |
| `estrategia_web_location_static_map` | **derived (1.8.0)**: the URL of the media library image `estrategia_web_location_static_map_id` |
| `estrategia_web_location_phone`, `estrategia_web_location_email`, every other field of the definition | as stored |

**The derived keys only exist in THIS source** (1.8.0). They were stored
meta up to 1.7.0, and a theme that bound them with `core/post-meta` must
switch those bindings to `estrategia-web-site/location` (same `key`, no
`slug` inside a Query Loop) BEFORE running the data migration — after it the
meta is gone and `core/post-meta` shows nothing. Until the migration runs, the
old stored text is what these keys show, so updating the plugin changes nothing.

**The wording of the derived texts** is a per-language format
(`Location_Data::FORMATS` in
[class-location-data.php](modules/content-types/includes/class-location-data.php):
es, ca, en; any other language uses en). A site changes it with a filter, in
its theme or a mu-plugin:

```php
add_filter( 'estrategia_web_location_formats', function ( array $format, string $lang ) {
	if ( 'es' === $lang ) {
		$format['open']      = 'Abrimos {days}';          // first line when every open day has the same hours
		$format['interval']  = 'de {open} a {close} h';   // each range
		$format['address']   = "{street}\n{postal_code} {locality} ({region})";
	}
	return $format;
}, 10, 2 );
```

Keys: `address` (placeholders `{street}` `{postal_code}` `{locality}`
`{region}` `{country}`; an empty part takes its separator with it), `open`,
`line` (`{days}: {times}`, one per set of hours when there are several),
`every_day`, `day_range` (`{from}`/`{to}`, three days or more), `list_and`,
`days` (seven names, Monday first), `interval` (`{open}`/`{close}`),
`times_and`, `time` (a PHP date format: `G:i`, `g:ia`). The company's hours
(Company details) use the same format.
| `estrategia_web_location_phone_href` | `tel:+34971112233`, from `estrategia_web_location_phone` (same country code as the company) |
| `estrategia_web_location_email_href` | `mailto:…`, from `estrategia_web_location_email` |

Same treatment as the company source: escaped text with `<br>`, and in `url`
only `http`, `https`, `tel` and `mailto`. **A location that does not exist,
is a draft or is private comes out empty** (in the editor, `[Name of the
detail]`). In the editor it shows the value of the locations published when
it was opened; a location created later shows in brackets until reloading.

Button with the phone of the «maioris» location (text and url):

```html
<!-- wp:button {"metadata":{"bindings":{"text":{"source":"estrategia-web-site/location","args":{"slug":"maioris","key":"estrategia_web_location_phone"}},"url":{"source":"estrategia-web-site/location","args":{"slug":"maioris","key":"estrategia_web_location_phone_href"}}}}} -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button"></a></div>
<!-- /wp:button -->
```

The address of each location inside a Query Loop (`"postType":"location"`):

```html
<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"estrategia-web-site/location","args":{"key":"estrategia_web_location_address"}}}}} -->
<p></p>
<!-- /wp:paragraph -->
```

### 3. Content types

**Since 17-10-2026 they are templates of the content-types module** (see
«content-types module: the constructor»): same type, taxonomy and meta names
as in the table below, stored as definitions in each site's database and
edited in **Estrategia Web → Content types**. A site that had them gets them
stored once, with the URLs it serves today; `enabled_types` and
`rewrite_slugs` still switch them on and set the site language's slug
(`modules/content-types/includes/class-site-types.php`). The editor panel and
the WPML config come from the definition. What follows is the contract as it
was, and still is, for those names.

Each one is registered **only if it is in `enabled_types`**. Disabling it
**deletes nothing**: its posts stay in the database and come back as they
were when it is re-enabled. All of them `public`, `show_in_rest` (REST route
= registered name: `/wp/v2/eweb_client`…), with archive, icon and labels in
Spanish on es_ES.

**Key and registered name (unreleased, 1.2.0).** In the table, the type's
**key** (`client`): it is what is ticked in `enabled_types`, what
`rewrite_slugs` carries and what the `estrategia_web_site_rewrite_slug`
filter receives. WordPress registers it with the **prefix** in front:
`eweb_client`, `eweb_client_sector`… (the prefix lives only in
[modules/site/includes/class-type-names.php](modules/site/includes/class-type-names.php),
`Type_Names::PREFIX`; decided by David on 15-10-2026, the reason in section
0). That name is the one in `wp_posts.post_type`, a Query Loop's
`"postType"`, the REST route and WPML. A theme asks for it with
`estrategia_web_type_name( 'client' )`. The public URLs (`/clientes/`) do not
change. Whatever was stored before with the unprefixed names is carried over
with the prefix migrator (section «Names»).

| Key (registered: `eweb_` + key) | Menu | Slug (es_ES) | Uses | Fields (`register_post_meta`, `single`, `show_in_rest`) | Taxonomy |
|---|---|---|---|---|---|
| `client` | Clients | `/clientes/` | title = name, featured image = logo/photo, excerpt, manual order | `estrategia_web_client_url` (URL; `client_url` up to 1.4.3), `estrategia_web_client_image_fit` (`cover` by default or `contain`; any other value → `cover`; 0.2.0), `estrategia_web_client_business_type` (text: «agencia de alquiler vacacional», what used to go in brackets in the title; translated with WPML; unreleased) | `client_sector` (hierarchical, slug `/sector/`) |
| `testimonial` | Testimonials | `/opiniones/` | title (internal), content = the testimonial, image = author's photo, order | `estrategia_web_testimonial_author`, `estrategia_web_testimonial_role` (text), `estrategia_web_testimonial_rating` (integer 0-5; **0 = no rating**), `estrategia_web_testimonial_client` (id of a `client`, or 0) (the role and the client were `testimonial_role` / `testimonial_client` up to 1.4.3) | — |
| `location` | Locations | `/sedes/` | title = location, content, image = static map, excerpt, order | **1.8.0, each datum once** («A location's data stored once», at the end): `estrategia_web_location_phone`, `_email`, `_street` (1-2 lines), `_postal_code`, `_locality`, `_region`, `_country`, `_hours`, `_map_query`, `_static_map_id` (an **image** attachment; otherwise 0), `_latitude`, `_longitude`, `_business_type`. The address and hours texts, the Maps link, the embed and the static map URL are derived (section 2 ter). Up to 1.7.0 also `_address`, `_schedule`, `_map_url`, `_map_embed`, `_static_map`: removed by the migration | — |
| `project` | Projects | `/proyectos/` | title, content, image, excerpt, order, revisions | — | `project_category` (`/categoria-proyecto/`) |
| `portfolio` | Portfolio | `/portfolio/` | same | — | `portfolio_category` (`/categoria-portfolio/`) |

**Where they are edited** (unreleased): on the location and testimonial
screens, the **«Location details» / «Testimonial details»** panel in the
editor sidebar; on the client one (classic screen: it has no `editor`), the
**«Client details»** box. With WPML, phone, email, links, map, image fit,
author and rating are **copied** from the original to the translation;
address, opening hours, business type and role are **translated**. The
criterion, in the header of
[modules/site/includes/class-meta-panel.php](modules/site/includes/class-meta-panel.php).

**With WPML, the types and taxonomies** (1.4.3) are declared by the plugin's
`wpml-config.xml`, all as *display-as-translated* (translatable, showing the
original while there is no translation): a new language never empties a
list. WPML locks that setting on its screen; a site that needs another mode
for a type changes it with the `wpml_config_array` filter. The criterion,
in [wpml-config.xml](wpml-config.xml).

All the types have `custom-fields`, so the fields are rendered with the
**`core/post-meta`** source inside a Query Loop (the business type on a
client's page: a paragraph with
`{"source":"core/post-meta","args":{"key":"estrategia_web_client_business_type"}}`).
A Query Loop of the clients of one sector (id 3) with logo and name:

```html
<!-- wp:query {"queryId":1,"query":{"perPage":12,"pages":0,"offset":0,"postType":"eweb_client","order":"asc","orderBy":"title","inherit":false,"taxQuery":{"eweb_client_sector":[3]}}} -->
<div class="wp-block-query"><!-- wp:post-template -->
<!-- wp:post-featured-image /-->
<!-- wp:post-title /-->
<!-- /wp:post-template --></div>
<!-- /wp:query -->
```

(Manual order —`page-attributes`, `"orderBy":"menu_order"`— is in the types
but has no test in a Query Loop yet: see [TODO.md](TODO.md).)

A field inside the Query Loop (the author of a testimonial):

```html
<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"core/post-meta","args":{"key":"estrategia_web_testimonial_author"}}}}} -->
<p></p>
<!-- /wp:paragraph -->
```

The map of each location with `estrategia-web/map`, inside a Query Loop of
`location` — since 1.8.0 through THIS source (the four values are derived;
`core/post-meta` cannot show them):

```html
<!-- wp:estrategia-web/map {"metadata":{"bindings":{"address":{"source":"estrategia-web-site/location","args":{"key":"estrategia_web_location_address"}},"embedUrl":{"source":"estrategia-web-site/location","args":{"key":"estrategia_web_location_map_embed"}},"mapsUrl":{"source":"estrategia-web-site/location","args":{"key":"estrategia_web_location_map_url"}},"staticMapUrl":{"source":"estrategia-web-site/location","args":{"key":"estrategia_web_location_static_map"}}}}} /-->
```

`staticMapUrl` gets the URL of the media library image
(`estrategia_web_location_static_map_id`); the block's own `staticMapId`
(a number) cannot be bound. The map block checks the URL again when
rendering (`estrategia_web_map_static_url`).

**Public slugs per site: `rewrite_slugs`** in the option (0.2.0). The routes
belong to the plugin, not the theme: a site with a **page** `/clientes/`
(which the type's archive would overwrite) moves the archive like this, via
REST or in its content loading:

```bash
curl -u admin:APP_PASS -X POST https://web.test/wp-json/wp/v2/settings \
  -H 'Content-Type: application/json' \
  -d '{"estrategia_web_site":{"rewrite_slugs":{"client":"cliente"}}}'
```

Order: the translated slug ← the one in `rewrite_slugs` if not empty ← the
filter `estrategia_web_site_rewrite_slug( $slug, $type_or_taxonomy )`, which
**still rules** (it already receives the option's one). A change through the
filter does not rebuild the rules on its own; one through the option does.

**Classes the plugin puts on a post** (`post_class` filter: they come out on
a Query Loop's `<li>` and on the single page). They describe the data; **the
plugin adds no CSS**, the look belongs to the theme:

| Class | When | What for (in the theme) |
|---|---|---|
| `has-image-fit-contain` | a `client` with `estrategia_web_client_image_fit` = `contain` (0.2.0) | the image is a **logo**: it must not be cropped, e.g. `.has-image-fit-contain .wp-block-post-featured-image img { object-fit: contain; }` |

Only on the site: the Query Loop preview in the editor is rendered by JS and
does not go through `post_class` (deduced from core, not tested).

### 4. SVG

With `allow_svg` enabled, **only** whoever has `unfiltered_html` or
`manage_options` can upload `.svg` (not `.svgz`). It is always **sanitized on
the server** with an allowlist of elements and attributes
([modules/site/includes/class-svg-sanitizer.php](modules/site/includes/class-svg-sanitizer.php)):
out go `<script>`, `on*` attributes, `<foreignObject>`, `javascript:` links,
external URLs, `@import`, entities (XXE) and comments; gradients, masks,
filters, text and `<style>` with `url(#id)` stay. The dimensions
(width/height or viewBox) show in the media library and in `media_details`,
and the featured image works with an SVG. **No dependencies**: the reason,
in the header of that class. A theme must not add `svg` to `upload_mimes` on
its own (Lavandería's old child theme did, without sanitizing).

### 5. Matomo (0.3.0)

Three more keys in the `estrategia_web_site` option (same merge, same REST),
and a **Statistics (Matomo)** section on the screen:

| Key | Type | Sanitizing |
|---|---|---|
| `matomo_url` | URL | http(s); stored **ending in `/`** and without the `matomo.php`/`matomo.js` that sometimes gets pasted; invalid → the previous one stays |
| `matomo_site_id` | integer ≥ 0 | `0` = not configured; negative → **400** |
| `matomo_mode` | `cookieless` (default) or `hybrid` | any other value → **400** |

```bash
curl -u admin:APP_PASS -X POST https://web.test/wp-json/wp/v2/settings \
  -H 'Content-Type: application/json' \
  -d '{"estrategia_web_site":{"matomo_url":"https://stats.ejemplo.com/","matomo_site_id":3}}'
```

- **Without URL or without id nothing is printed.** With both, the tracking
  code goes inline in the `<head>` (`<script id="estrategia-web-site-matomo">`).
- **Nothing is measured** in the dashboard, the editor, previews, nor for
  whoever is logged in and can edit (`edit_posts`). Filter
  `estrategia_web_site_matomo_track( $track )` to change it.
- **`cookieless`** (the one from row 16): `disableCookies`, nothing to ask.
- **`hybrid`**: measures without cookies (`requireCookieConsent`) and, when
  the visitor accepts **«statistics»** in the **cookie notice** (this
  plugin's since 0.5.0; see section 6), calls
  `rememberCookieConsentGiven`; if it is withdrawn, `forgetCookieConsentGiven`.
  It listens to the `estrategia-web:consent` event and checks
  `window.estrategiaWebConsent.get()` (the notice's contract), and **declares
  the category itself** with the `estrategia_web_consent_categories` filter
  (priority 99): the theme does not have to remember. Since 0.5.0 it works
  with any theme. **The legality of hybrid is not verified** (CNIL's
  criterion): that is why it is not the default.
- «No full IP» is configured on the Matomo server, not here.

The reason for each decision and its traps, in the header of
[modules/matomo/includes/class-matomo.php](modules/matomo/includes/class-matomo.php).
Since 1.1.0 Matomo is its own module (`matomo`, on by default, requires
`site`); the option and its sanitizing stay in `estrategia_web_site`, with no
migration.

### 6. Cookie notice (0.5.0)

It came from the parent theme (`armazon-wp-padre` ≤ 0.6.0, `inc/consent.php`)
**without changing the contract**: consent is functionality, not
presentation, and a site that changes theme cannot be left without a notice.
The whole documentation (decisions, how to maintain it, traps) is in the
header of
[modules/consent/includes/class-consent.php](modules/consent/includes/class-consent.php).

- **It only appears if the site declares categories** (`statistics`, `maps`,
  `marketing`) with the `estrategia_web_consent_categories` filter; with none,
  there is no notice, which is the normal case. Hybrid Matomo declares
  `statistics` on its own.

      add_filter( 'estrategia_web_consent_categories', fn( $c ) => array_merge( (array) $c, array( 'maps' ) ) );

- **The cookie is the same** (`estrategia_web_consent`,
  `"<revision>.<cat~cat>.<unix>"`, the same revision): whoever already chose
  with the parent's notice does not see it again.
- **PHP:** `estrategia_web_consent_has( 'maps' )`,
  `estrategia_web_consent_categories()` and the other usual
  `estrategia_web_consent_*`
  ([modules/consent/includes/consent-functions.php](modules/consent/includes/consent-functions.php)),
  and `\EstrategiaWeb\Site\Consent`. Filters:
  `estrategia_web_consent_categories`, `estrategia_web_consent_revision`,
  `estrategia_web_consent_max_age`, `estrategia_web_consent_text`.
- **JS:** `window.estrategiaWebConsent` (`categories`, `has()`, `get()`,
  `open()`, `grant()`), the `estrategia-web:consent` event on `document` with
  `detail.accepted`, **`estrategia-web:consent-ready`** (new in 0.5.0: once at
  startup, also with no choice made, with `accepted: null`; Matomo uses it),
  the Interactivity store `estrategia-web/consent`, and
  `<script type="text/plain" data-estrategia-web-consent="statistics">` for
  whatever only runs with consent. WP Consent API if present, without
  depending on it.
- **«Cookie settings» link:** block **`estrategia-web-site/consent`**
  (attribute `label`), or `[estrategia_web_consent_settings label="…"]`. The
  old name **`estrategia-web/consent` is still registered as an alias** hidden
  from the inserter, with the same HTML (class
  `wp-block-estrategia-web-consent`): content and parts already saved with it
  keep working. Removing the alias is a major version.
- **Styles:** a neutral base stylesheet in the plugin
  ([modules/consent/blocks/consent/style.css](modules/consent/blocks/consent/style.css))
  that reads the `theme.json` tokens (`base`, `contrast`, `border`, spacing,
  sizes) with fallback values. The theme fine-tunes with its tokens or with
  CSS on `.estrategia-web-consent`.
- **Coexistence:** with an old parent theme that still ships its own notice,
  the plugin steps aside (there are no two notices nor redeclared functions).
  With parent 0.7.0 and this plugin ≤ 0.4.0 there is no notice: both are
  updated at the same time.

**«Powered by»**: at the foot of the notice, in small print and aligned to the
end, **«Powered by Estrategia Web»** linking to `https://estrategiaweb.es/`,
in a new tab, `rel="noopener"` and **without `nofollow`** (it counts as a
link). Translatable text (`Powered by %s`; on es_ES it is left in English).
It is changed or removed with the **filter**
`estrategia_web_site_consent_powered_by` (not with an option: it is the
signature of whoever builds the site, not a setting the client touches, and
an option would be one more key in the contract):

    // Another text or URL (whatever is not passed stays):
    add_filter( 'estrategia_web_site_consent_powered_by', fn( $p ) => array_merge( $p, array( 'text' => 'Hecha por X', 'url' => 'https://x.es/' ) ) );
    // Remove it:
    add_filter( 'estrategia_web_site_consent_powered_by', '__return_false' );

An empty `url` (or one that is not http/https) prints the text without a
link; an empty `text` does not print the line. Its colour is the notice's
text colour, with no opacity: AA contrast measured in the tests (11.05:1 in
Lavandería). A theme that dims it has to measure it again.

**Focus order (0.5.1):** the notice is printed at the **start of the
`body`** (`wp_body_open`; in `wp_footer` if the theme does not call it), so
while it is open the first Tab lands on it. It does not steal focus on load,
does not trap it (Tab leaves, Shift+Tab comes back; `aria-modal="false"`)
and **Escape does not close it the first time**: a choice has to be made.
Once reopened, Escape does close it. The gap between the text and the
buttons is 24 px and survives a `theme.json`'s `:root :where(p) { margin: 0 }`.
During the parent theme's page transitions (0.7.5) it stays still
(`view-transition-name: estrategia-web-consent`).

**Categories (unreleased, branch `modulo/cookies`)**: necessary, preferences
(`preferences`, new), analytics (`statistics`) and advertising
(`marketing`), plus maps (`maps`). The slugs do not change (the notice's
revision is their hash); the aliases `analytics`, `advertising` and `ads` are
accepted. The code declares them with the filter and, in addition, the
administrator in **Estrategia Web › Settings › Cookies** (option
`estrategia_web_cookies_settings`, key `ask`). The panel can also be
reopened from any link to `#estrategia-web-consent` or an element with the
class `estrategia-web-consent-open` (useful in the footer menu).

### 6 bis. Cookie register, detector and policy table (unreleased)

All in the `consent` module; each piece documents its own part in its
header:
[class-cookie-registry.php](modules/consent/includes/class-cookie-registry.php)
(what is stored and where the proposals come from),
[class-cookie-rest.php](modules/consent/includes/class-cookie-rest.php)
(the detector and its limits),
[class-cookie-table.php](modules/consent/includes/class-cookie-table.php)
(the table and its translation) and
[admin/class-cookies-page.php](modules/consent/includes/admin/class-cookies-page.php).

- **Detector**: when ACCEPTING any category, the browser sends the NAMES of
  its cookies (never values) to `POST /wp-json/estrategia-web/v1/cookies/seen`,
  only if its list changed or every 7 days. No nonce (the page cache would
  expire it) and with limits: 1 report / 10 min per IP (hash salted with the
  day, not stored), 100 / hour for the whole site, 60 names, 300 rows.
- **Proposals**: first our own list (the notice's cookie, WordPress, WPML
  —`wp-wpml_current_language` necessary, 1 day—, Polylang, Matomo) and then
  the extract of the [Open Cookie Database](https://github.com/jkwakman/Open-Cookie-Database)
  (Apache 2.0) in [modules/consent/data/](modules/consent/data/NOTICE),
  regenerable with `node tools/open-cookie-database.mjs` (`--seco`, `--csv`).
  Wildcards: `_ga_*` catches `_ga_ABC123`.
- **Third-party services our modules load**: the map (Google embed, once a
  map with an embed has been rendered), Google tags (GA4, Ads) and Matomo in
  hybrid mode declare their service with the
  **`estrategia_web_cookie_services`** filter (an array of ids), and its
  known cookies —from the vendor's own documentation, in
  [class-cookie-services.php](modules/consent/includes/class-cookie-services.php)—
  appear on the screen as pending «Declared by the plugin», without anyone
  typing them. That covers the ones the detector cannot see (google.com,
  doubleclick.net). How a new module adds its service: that file's header.
- **Estrategia Web › Settings › Cookies** (1.7.0; before, its own screen): pending / confirmed / ignored;
  editable category, provider, purpose and duration; adding third-party ones
  by hand; and a warning if there are confirmed cookies in a category the
  notice does not ask about. Administration also via REST
  (`GET/POST/DELETE /wp-json/estrategia-web/v1/cookies`, `manage_options`).
- **Table for the policy**: block **`estrategia-web/cookie-table`**
  (attributes `category` and `headingLevel`) or
  `[estrategia_web_cookie_table category="" heading_level="3"]`. Only what is
  confirmed. Durations like «2 years» are translated on their own; provider,
  purpose and free-text duration, via WPML String Translation (context
  `estrategia-web-cookies`).

### 6 ter. Google tags — `google-tags` module (unreleased)

Off by default. GA4 (`G-…`), Google Ads (`AW-…`, with an optional form
submission conversion) and Tag Manager (`GTM-…`) in **Estrategia Web ›
Settings › Analytics** (1.7.0; before, «Estrategia Web → Google») (option
`estrategia_web_google_tags`, also via `/wp/v2/settings`).
Empty field = nothing is loaded. Consent Mode v2: `default` all denied before
any tag and `update` on choosing (Analytics → `analytics_storage`;
Advertising → `ad_storage`, `ad_user_data`, `ad_personalization`). **Basic
mode** (default): nothing from Google until Analytics or Advertising is
accepted; **advanced mode**: Google loads right away, denied (cookieless
pings before consent: the client's decision). The form conversion also
counts a submission WITHOUT JavaScript: the page that comes back
(`?af_result=…`) fires `estrategia-web:form-sent` with `detail.returned`,
once per submission, and the conversion waits until Advertising is
accepted. All of it, in the header of
[class-google-tags.php](modules/google-tags/includes/class-google-tags.php).

### 7. WebP (0.5.1)

Uploaded JPEGs and PNGs are stored **as WebP**: WordPress's
`image_editor_output_format` filter, only if the server's image editor can
write it (`wp_image_editor_supports`). Measured: WordPress converts **the main
file too** (`foto.jpg` → the media library serves `foto.webp`; the `.jpg`
stays as `original_image`). To switch it off, in the theme:

    add_filter( 'estrategia_web_site_webp', '__return_false' );

**What was already uploaded does not change on its own**, and **regenerating
without touching the content makes it worse**: `wp media regenerate` moves
the meta to `.webp`, but the URLs saved in the pages do not change. The main
file's one (`foto.jpg`, the one Lavandería's content loading uses
everywhere) keeps being served, **but loses its `srcset`**: the whole JPEG
at any width. An intermediate size's one (`foto-1024x683.jpg`) points to a
deleted file. After regenerating, the media library's current URL has to be
written into the content again. The details, in the header of
[modules/webp/includes/class-webp.php](modules/webp/includes/class-webp.php).

**Quality (0.5.4): 75**, not the 82 WordPress applies to everything
(`wp_editor_set_quality`, only when what is saved is `image/webp`: a JPEG
that stays a JPEG does not change). It is `cwebp`'s default; on photos it
weighs ~20 % less and cannot be told apart. To change it, in the theme:

    add_filter( 'estrategia_web_site_webp_quality', fn() => 82 );   // 0: WordPress's

**Like the format, it only applies to what is generated from then on.** What
was already uploaded stays at 82 until regenerated (in Lavandería, the
«webp» step of its deployer, which redoes it without touching the content
and empties the cache).

### 8. External links in a new tab (0.5.2)

Every `<a href>` on the **front end** whose host is not `home_url()`'s comes
out with `target="_blank"`, `noopener` added to `rel` (without removing
`nofollow`, `sponsored`…) and an `aria-label` with its text and **«(opens in
a new tab)»** (the same translatable text mechanism as the «Powered by»; if
it already had an `aria-label`, the notice goes at the end). Real case:
«Hablar con un asesor» (wa.me) on Lavandería's /hosteleria/; also Google
Maps, social networks and any link in the content to another domain.

- **One filter on the whole page**, core's
  `wp_template_enhancement_output_buffer` (6.9+), with
  `WP_HTML_Tag_Processor`: it catches blocks, templates, shortcodes and the
  cookie notice. The dashboard, the editor, REST and feeds do not go through
  it.
- **Internal:** the same host, with or without `www.`, and its subdomains
  (`tienda.x.es` from `x.es`).
- **Left untouched:** relative links, anchors, `mailto:`, `tel:`, `sms:`,
  `javascript:` (anything that is not http/https) and **those that already
  carry a `target`**, `_self` included: that is the way to keep one in the
  same tab. That is why the «Powered by» is not duplicated.
- **Switch off** (on by default), in the theme:

      add_filter( 'estrategia_web_site_external_links', '__return_false' );

- **Edge case:** the link to the **client's old site on another domain counts
  as external**: what decides is `home_url()`'s host, not who owns the site.
  On the staging server (`*.sg-host.com`) the links to the final domain too.
  If a site really has two domains of its own, `target="_self"` on those
  links.

Decisions, the trap (it runs inside the output buffer: a failure does not
break the page but leaves the links untouched, silently) and how to maintain
it, in the header of
[modules/external-links/includes/class-external-links.php](modules/external-links/includes/class-external-links.php).

### Glossary (code in English, domain in Spanish)

`client` cliente · `testimonial` opinión · `location` sede · `project`
proyecto · `portfolio` portfolio · `sector` the client's sector · `company`
empresa · `binding` link from a block to a stored value · `consent` cookie
notice / consent · `powered by` the signature at the foot of the notice ·
`external link` link to another domain.

---

## Tests (row 7)

    node tests/run.mjs             1 → 2 → 3, stops at the first red
    node tests/run.mjs --nivel 1   level 1 only (every commit, ~1 s)
    node tests/run.mjs webp        level 1 and, of the rest, only those starting with «webp»
    node tests/run.mjs forms/      level 1 and, of the rest, those of the forms module

While developing, **level 1 always and, of levels 2 and 3, only the new
test** (Armazón row 7, «Cuándo corre la batería», "when the suite runs").
The whole suite, when David asks for it or before taking the plugin to a
production site.

**Where they are (1.1.0):** the core ones in `tests/` (the module loader:
[tests/modules.test.mjs](tests/modules.test.mjs), level 1, and
[tests/modules-page.test.mjs](tests/modules-page.test.mjs), level 2) and each
module's in `modules/<slug>/tests/`. The runner discovers them in both
places. The ones in the table below moved from `tests/` to their module
without changing anything but the path to `lib.mjs`; the forms ones are in
[modules/forms/README.md](modules/forms/README.md). **The tests of the blocks that came from the theme** (carousel, map,
WhatsApp; 17-10-2026) are in their modules like the rest, at level 3, in
Playground's default theme: a plugin block must work in any block theme.
Their pages are seeded by each module's
`tests/wp/estrategia-web-<block>-test.php` and the shared harness is
[tests/browser.mjs](tests/browser.mjs) (`blockSite()`: reset, only the block
modules, the seed).

The runner starts **Playground** (WP 7.1, PHP 8.3) on **port 9404** if there
is none, with the plugin mounted as `plugins/estrategia-web` and
[tests/blueprint.json](tests/blueprint.json), and kills it at the end with
`taskkill /T /F`. All modules share that WordPress, with a test mu-plugin
per module that needs one. The tests talk to the **real REST API** as an
administrator and as an author (application passwords created by
[tests/wp/estrategia-web-site-test.php](tests/wp/estrategia-web-site-test.php)).
The trap of sharing it: that mu-plugin sets the site to `es_ES` and the forms
tests expect English; their `reset()` marks it and goes back to `en_US` (see
`modules/forms/tests/wp/estrategia-web-forms-test.php`).

| File (in `modules/<module>/tests/`) | Level | What it tests |
|---|---|---|
| [site/pure](modules/site/tests/pure.test.mjs) | 1 | tel:/mailto:/wa.me; hostile SVG sanitized, unacceptable SVGs, dimensions; (0.5.2) which link is external (another host, www, subdomain, `//`, relative, anchors, other schemes) and `rel` with `noopener` without losing what it had; (0.5.4) WebP quality: 75 only for `image/webp`, the filter, 0 and the cap |
| [external-links/external-links](modules/external-links/tests/external-links.test.mjs) | 2 | external links (0.5.2) on the served page: the wa.me button with `_blank`, `noopener` and `aria-label`; internal, www, relative, anchor, tel, mailto untouched; with `target` untouched; with `rel` and with `aria-label`, added to; image link with its alt; «Powered by» not duplicated; REST untouched; the filter switches it off. Seen in red without `boot()` |
| [site/settings](modules/site/tests/settings.test.mjs) | 2 | saving and reading via REST, merge, sanitizing (invalid email/URL/WhatsApp are not saved), schema, `rewrite_slugs` (sanitizing, merge per key, 400), author without permission |
| [site/bindings](modules/site/tests/bindings.test.mjs) | 2 | paragraph with the phone, button with wa.me, tel: (also `phone_secondary_href`), mailto:, `<br>`, escaping, `social.<network>` |
| [site/location](modules/site/tests/location.test.mjs) | 2 | locations source: by slug (button text+url, title, address, mailto, map), nonexistent/draft/out of context empty, by context in a Query Loop |
| [site/types](modules/site/tests/types.test.mjs) | 2 | nonexistent type when not enabled; enabling/disabling without losing content; slug `/clientes/`; sanitized meta; Query Loop by `client_sector` with title and image; `estrategia_web_client_image_fit` and the `has-image-fit-contain` class; `rewrite_slugs` moves the archive to `/cliente/` and frees the `/clientes/` page |
| [site/legal](modules/site/tests/legal.test.mjs) | 2 | legal details (0.4.0): `registry` sanitized and merged; legal sentence with `[estrategia_web_company]` (legal name, tax id, address on one line, phone, email, registry, escaping); nonexistent key empty; `registry` via binding |
| [site/static-map](modules/site/tests/static-map.test.mjs) | 2 | a location's static map (0.4.0): `estrategia_web_location_static_map` accepts a theme path, a site path and a same-domain URL, and rejects another domain, `//`, `javascript:`, `..`, non-images; `estrategia_web_location_static_map_id` only image attachments; in a Query Loop, core/post-meta gives each location its own (paragraph and image); also via the locations source |
| [site/svg](modules/site/tests/svg.test.mjs) | 2 | uploaded SVG with `<script>` sanitized (multipart and sideload), dimensions, an author cannot, `allow_svg` off |
| [matomo/matomo](modules/matomo/tests/matomo.test.mjs) | 2 | Matomo (0.3.0): without URL or id nothing is printed; `disableCookies` by default; hybrid with `requireCookieConsent` and the «statistics» category declared; sanitizing (URL, id, 400 for the mode); its keys are not bindable values |
| [matomo/matomo-browser](modules/matomo/tests/matomo-browser.test.mjs) | 3 | with the real `matomo.js` ([modules/matomo/tests/fixtures/matomo.js](modules/matomo/tests/fixtures/matomo.js), 5.4.0, BSD-3) served through a fake route and, since 0.5.0, with the **real cookie notice** (its buttons and its cookie, not a simulated event): without configuration it is not requested; cookieless measures, leaves no cookies and there is no notice; hybrid without cookies until «Accept all» (only «maps» does not count), `mtm_cookie_consent` and `_pk_id` on accepting, deleted on «Reject all»; notice already answered on load; an expired choice forgets; an administrator is not measured |
| [consent/consent](modules/consent/tests/consent.test.mjs) | 2 | cookie notice (0.5.0): without categories it does not appear; with them, in a stable order and with the three buttons; revision and cookie equal to the parent's; hybrid Matomo makes it appear; «Powered by» (text, `href`, `_blank`, `rel="noopener"` without `nofollow`, after the buttons); the filter changes text and URL, an empty or non-http(s) URL leaves the text without a link, `false` removes it; link via the new block, the old alias and the shortcode |
| [consent/consent-browser](modules/consent/tests/consent-browser.test.mjs) | 3 | cookie notice (0.5.0): it appears; accepting and rejecting weigh the same; rejecting stores the cookie and persists; the link (new and alias) reopens it with focus inside and Escape closes it; JS contract (event, `has`, `get`, `categories`, `grant`, `open`) and deferred scripts; a cookie from the parent's notice is still valid; «Powered by» visible, smaller than the buttons and with measured AA contrast; (0.5.1) when open it is the first thing when tabbing, no trap, and Escape does not close it without choosing; ≥ 16 px between the text and the buttons at 402 and 1512 with Lavandería's `:root :where(p)` |
| [consent/cookies-pure](modules/consent/tests/cookies-pure.test.mjs) | 1 | cookie register without WordPress: exact before pattern and longest prefix; `wp-wpml_current_language` necessary; GA with `_ga_*`; Matomo's `_pk_id.*` and not Piwik's; names without values or HTML; row cap; aliases and order of the categories; attribution of the extract; the third-party services catalogue (valid names, categories, durations) and its merge (pending, the administrator's rows untouched, removal of pending rows when a service goes) |
| [consent/cookie-services](modules/consent/tests/cookie-services.test.mjs) | 2 | each module declares only what its configuration loads (GA4 yes; GTM, cookieless Matomo, a map never shown, no); a rendered map, Ads and hybrid Matomo bring their cookies as pending; confirmed, into the policy table; Ads off removes only its pending rows |
| [admin-pure](tests/admin-pure.test.mjs) | 1 | (1.7.0) the Settings tab registry (`layout()`: order, sections, options, empty tabs out), the submenu order (Modules last, unknown pages before it) and where the old URLs go |
| [menu](tests/menu.test.mjs) | 2 | (1.7.0) the «Estrategia Web» item right after the Dashboard and its submenu order; old URLs (`estrategia-web-cookies`, `-google` redirect to their tab; `-site` and `estrategia-web` unchanged); `site` loads with a saved list that lacks it; the Modules screen grouped, without `site` |
| [settings-page](tests/settings-page.test.mjs) | 2 | (1.7.0) each tab iff its module is on; saving Media, Company details and Analytics does not wipe the other screens' keys of `estrategia_web_site`; saving WordPress's Settings → Media does not reset uploads |
| [forms/forms-list](modules/forms/tests/forms-list.test.mjs) | 2 | (1.7.0) «Forms → Forms»: a form inside a synced pattern and the pages using it, notifications with their recipients, drafts; a mention in text is not a form; the Forms menu opens on the list |
| [consent/cookies](modules/consent/tests/cookies.test.mjs) | 2 | detector: names only, 202, 429 per client and per hour, 400 with more than 60, 403 from another origin or when off; (1.7.0) a logged-in user's report records nothing and their page carries no detector; admin route by permissions; policy table (only confirmed, by category, in Spanish, block and shortcode, empty prints nothing); «Preferences» added by the administrator and aliases when declaring, without changing the usual revision |
| [consent/cookies-browser](modules/consent/tests/cookies-browser.test.mjs) | 3 | on accepting, the browser sends only names (it checks the request body) and does not repeat; rejecting sends nothing; reopening from a link and a class; the Cookies screen confirms and the table shows it |
| [google-tags/google-tags](modules/google-tags/tests/google-tags.test.mjs) | 2 | off by default; empty prints nothing; `default` denied first and no Google `<script src>`; categories per tag; conversion only with its account; invalid ids 400; without `consent` it does not load |
| [google-tags/google-tags-browser](modules/google-tags/tests/google-tags-browser.test.mjs) | 3 | basic: nothing to Google without accepting, when rejecting or with maps only; on accepting gtag.js and only `analytics_storage`; withdrawing deletes `_ga*`; Ads and form conversion; conversion of a form sent without JavaScript (once, waiting for Advertising); advanced loads earlier |
| [webp/webp](modules/webp/tests/webp.test.mjs) | 2 | WebP (0.5.1): the editor writes WebP; uploaded JPEG and PNG generate their sizes (and the main file) in WebP; the filter set to `false` switches it off; the trap of regenerating what was already uploaded (the content's `.jpg` without `srcset`, the intermediate one broken) and its fix |
| [site/editor](modules/site/tests/editor.test.mjs) | 3 | the editor shows the bound value and lists the details (company, and location by slug); the settings screen saves, warns and disables the last type |
| [site/meta-panel](modules/site/tests/meta-panel.test.mjs) | 1 | (unreleased) every location, client and testimonial meta has a WPML decision (copy/translate) and `wpml-config.xml` says the same as `Meta_Panel`. Seen in red with the phone switched to «translate» in the XML |
| [site/wpml-config](modules/site/tests/wpml-config.test.mjs) | 1 | (1.4.3) `wpml-config.xml` declares every type and taxonomy of `Type_Names` with the mode of `Content_Types::WPML_MODES`, and in `<admin-texts>` exactly `Settings::WPML_TRANSLATE`; every company detail is decided; every `translate` attribute is 0/1 (WPML's XSD). Seen in red with `translate="2"` and without `schedule`. The check with real WPML is in Lavandería (`tests/company-data-wpml.test.mjs`) |
| [wpml-blocks](tests/wpml-blocks.test.mjs) | 1 | (unreleased) every string attribute of every `block.json` is declared in `<gutenberg-blocks>` of `wpml-config.xml` or excluded in the test with its reason; nothing stale; only attributes WPML's XSD admits. Seen in red with the 1.4.3 XML (11 attributes undecided). The check with real WPML is in Lavandería (`tests/wpml-blocks.test.mjs`, `tests/forms-wpml.test.mjs`) |
| [site/meta-fields](modules/site/tests/meta-fields.test.mjs) | 2 | (unreleased) `estrategia_web_client_business_type` sanitized and rendered by core/post-meta in a Query Loop of clients; the location embed stores the `src` of the pasted `<iframe>` and rejects `javascript:` |
| [site/meta-panel-editor](modules/site/tests/meta-panel-editor.test.mjs) | 3 | (unreleased) the «Location details» panel starts open, shows the values and saves the phone changed there; the «Client details» meta box on the classic screen; the testimonial panel saves rating and client as numbers; a page does not have it. Seen in red on its first run: the panel started closed and the client had none |
| [social/social](modules/social/tests/social.test.mjs) | 2 | (unreleased) migration: with no saved list, the fixed fields of «Company details» go into the list once and stay saved; alias `social.<network>` via shortcode (first network with that icon); accessible name «Instagram de …», `_blank` + `noopener`, `currentColor`, no `<title>`; the list rules in its order, guessed icon, email to `mailto:` without a new tab, `javascript:` out; own SVG sanitized and with its colour, a PNG is not valid; an empty list prints nothing and is not migrated again; an author cannot |
| [social/social-admin](modules/social/tests/social-admin.test.mjs) | 3 | (unreleased) «Company details» no longer has network fields and links here; the «Social networks» screen: add, move up, remove and save in the order shown; emptying it saves `[]`; the block shows in the editor without errors |
| [redirects/pure](modules/redirects/tests/pure.test.mjs) | 1 | normalizing and matching (%3F, `?p=`/`?page_id=`, trailing slash, uppercase, extra query); Lavandería's real CSV (83 rules) whole and error-free; loops, duplicates, targets and types; CSV with `;`, BOM and «revisar» |
| [redirects/server](modules/redirects/tests/server.test.mjs) | 2 | off by default; importing the real CSV (dry-run, idempotent); its sources redirect with variants; 410; counter; 0 queries on a page that exists and 1 on a 404; 404 log without query string and its purge; cost of a lookup with 84 and 5000 rules |
| [content-types/location-data](modules/content-types/tests/location-data.test.mjs) | 1 | (1.8.0) a location's derived texts with Lavandería's real strings (address, hours in es/en/ca, the Maps link and embed byte for byte), several sets of hours, lists of days, and the migration's parsers (what they read and what they refuse). Seen red with «cada día» and with `rawurlencode()` |
| [content-types/location-migration](modules/content-types/tests/location-migration.test.mjs) | 2 | (1.8.0) a 1.7.0 site through `migrate-location-data`: the page the same before and after, the dry run's before → after and DIFFERENCES (none for Lavandería's two), nothing written by it, the real run (new fields, old meta out, backup, 1.8.0 definitions, client with `editor`, the company's hours), a second run that changes nothing, and the way out of «not parsed». Seen red with the street sanitized as one line |
| [content-types/location-box](modules/content-types/tests/location-box.test.mjs) | 2 | (1.8.0) the fields meta box in the block editor's main area for locations, testimonials and clients (groups, «For Google» closed, no sidebar panel) and its save as the block editor posts it. Seen red with the `\r\n` of a `<textarea>` |
| [redirects/admin](modules/redirects/tests/admin.test.mjs) | 3 | the screen: list and search, adding, loop error without losing what was typed, Rank Math notice, importing by uploading the CSV, exporting, «Create redirect» from the 404 log |

**All of them have been seen failing** (02-10-2026): three batches of
mutants, ten in total, each caught by its test. The 0.2.0 ones (marked
«(0.2.0)» in their name) were seen failing BEFORE writing the code, and the
`rewrite_slugs` one in types, in addition, with a mutant of `maybe_flush`.
The Matomo ones (0.3.0, 03-10-2026) with five mutants: printing without id,
removing `disableCookies`, swapping `remember` for `forget`, not forgetting
with an expired choice and measuring administrators; each one caught. The
0.4.0 ones (03-10-2026) were seen in red before the code (shortcode and
fields not existing yet), and with two mutants: `registry` out of DEFAULTS
and a static map sanitizing that lets everything through. The 0.5.0 ones
(05-10-2026), with seven mutants, each one caught: `rel` with `nofollow`
(also by Lavandería's test), another revision, the «Powered by» filter not
applied, the alias not registered, without the `estrategia-web:consent`
event, without `estrategia-web:consent-ready` and the «Powered by» in
`#aaa`. And when Matomo was moved onto the real notice, a real bug showed up
that the simulated test was hiding (see CHANGELOG, 0.5.0).

## Strings and translations (row 11)

**A single domain for the whole plugin: `estrategia-web`** (since 1.1.0;
before, `estrategia-web-site` and `estrategia-web-forms`; the theme uses the
same one and WordPress merges both files). `languages/`:
[estrategia-web.pot](languages/estrategia-web.pot),
[estrategia-web-es_ES.po](languages/estrategia-web-es_ES.po) and its
`.l10n.php`. They are generated with `node tools/i18n.mjs` (see its header),
which also extracts the name and description of each `module.php`. The
`msgid` is the **English text** (WordPress convention). There is no editor
JSON: core passes the translations to `wp.i18n` inline
(`includes/class-blocks.php`).

## Installing on a site

**A whole new site** (parent theme, child, plugin, modules, admin, WPML,
seed and pre-publishing checklist): [docs/web-nueva.md](docs/web-nueva.md).
Here, only the plugin:

Copy the folder to `wp-content/plugins/estrategia-web/` **without `tests/`,
`modules/*/tests/` or `tools/`**, activate it, and in **Estrategia Web →
Modules** switch off whatever that site does not use (by default, everything
is on). Then, in **Estrategia Web › Company details**, fill in the details,
and the types in **Estrategia Web › Content types**.
The `tests/wp/` files open REST routes without permissions: they are only
loaded with the blueprint's mu-plugin and its `ESTRATEGIA_WEB_*_TESTING`
constants (double key), but there is no reason for them to be on the server.

**It does not coexist with the 1.0.0 plugins** (`estrategia-web-site`,
`estrategia-web-forms`) **nor with a parent theme that still registers the
blocks** (1.0.0): they would register the same things twice. A site coming
from them deactivates them when activating this one (the migrator does it).

**Secrets (row 21): none.** When the plugin is **deleted**, the root
[uninstall.php](uninstall.php) calls each module's: no client data is
deleted unless the site defines `ESTRATEGIA_WEB_SITE_REMOVE_DATA` or
`ESTRATEGIA_WEB_FORMS_REMOVE_DATA`; the content of the post types is never
deleted.

## Names: from «Armazón Site» to «Estrategia Web Site» (1.0.0)

Up to 0.5.4 the plugin was called **Armazón Site** (folder `armazon-site`).
In 1.0.0 (07-10-2026) it became **Estrategia Web Site** so it could be
published on WordPress.org (unique prefixes of 4 or more characters). The
0.x entries in the CHANGELOG use the old name; the repo is still at
`E:\Repositories\armazon-wp-sitio`.

| What | Before | Now |
|---|---|---|
| Folder, file, text domain | `armazon-site/armazon-site.php` | `estrategia-web-site/estrategia-web-site.php` |
| Namespace | `Armazon\Site` | `EstrategiaWeb\Site` |
| Functions, constants, filters | `armazon_…`, `ARMAZON_SITE_…` | `estrategia_web_…`, `ESTRATEGIA_WEB_SITE_…` |
| Option | `armazon_site` | `estrategia_web_site` |
| Post type meta | `location_static_map`, `client_image_fit`… | `estrategia_web_location_static_map`, `estrategia_web_client_image_fit`… |
| Bindings sources | `armazon-site/company`, `/location` | `estrategia-web-site/company`, `/location` |
| Block | `armazon-site/consent` (and `armazon/consent`) | `estrategia-web-site/consent` |
| Cookie | `armazon_consent` | `estrategia_web_consent` (same format) |
| JS API and event | `window.armazonConsent`, `armazon:consent` | `window.estrategiaWebConsent`, `estrategia-web:consent` |
| Shortcodes | `[armazon_company]`, `[armazon_consent_settings]` | `[estrategia_web_company]`, `[estrategia_web_consent_settings]` |
| CSS classes | `.armazon-consent__…` | `.estrategia-web-consent__…` |

The **post types** (`client`, `testimonial`, `location`, `project`,
`portfolio`) and their **taxonomies** did not change in 1.0.0. **They get
the prefix in 1.2.0** (unreleased, David's decision of 15-10-2026:
`eweb_client`…; next section).

**The old names keep working** during 1.x
([modules/site/includes/class-legacy-names.php](modules/site/includes/class-legacy-names.php)):
blocks, bindings sources, shortcodes and the option (if the new one is
missing); the old cookie is read and carried over to the new one without
asking again
([modules/consent/includes/class-consent.php](modules/consent/includes/class-consent.php)).
They are removed in **2.0.0**.

**THE MIGRATOR** ([includes/class-armazon-migration.php](includes/class-armazon-migration.php))
moves a site from the old names to the new ones IN PLACE: content (blocks
and WPML translations), meta, options, the forms plugin's tables, WPML
strings, active theme and plugins. Idempotent, with a dry run, and it does
not run on its own: `wp estrategia-web-site migrate-armazon --dry-run`, or
included from `wp eval-file` as Lavandería does
(`despliegue/migrar-a-estrategia-web.mjs`). Before it, a database backup.

## Prefixed types (unreleased, 1.2.0)

| Before (≤ 1.1.0) | Now |
|---|---|
| post types `client`, `testimonial`, `location`, `project`, `portfolio` | `eweb_client`, `eweb_testimonial`, `eweb_location`, `eweb_project`, `eweb_portfolio` |
| taxonomies `client_sector`, `project_category`, `portfolio_category` | `eweb_client_sector`, `eweb_project_category`, `eweb_portfolio_category` |
| REST routes `/wp/v2/client`… | `/wp/v2/eweb_client`… |
| WPML `post_client`, `tax_client_sector` | `post_eweb_client`, `tax_eweb_client_sector` |
| Rank Math `pt_client_…`, `tax_client_sector_…` | `pt_eweb_client_…`, `tax_eweb_client_sector_…` |

**Unchanged**: the public URLs (they come from the slug), the
`estrategia_web_site` option (`enabled_types` and `rewrite_slugs` stay in
keys: `client`), the `estrategia_web_site_rewrite_slug` filter (it receives
the key), the meta keys, the blocks and the tables.

**The old names keep working until 2.0.0**
([modules/site/includes/class-unprefixed-names.php](modules/site/includes/class-unprefixed-names.php)):
a Query Loop or a `post-terms` saved with `"postType":"client"` render the
new ones on the site, and `/wp/v2/client…` answers like
`/wp/v2/eweb_client…` if no other plugin owns that route. **Not** in the
editor (the old Query Loop shows empty) nor for a theme's
`get_posts( 'post_type' => 'client' )`.

**THE PREFIX MIGRATOR**
([includes/class-prefix-migration.php](includes/class-prefix-migration.php)):
`wp_posts`, terms, block content (Query Loops, `post-terms`, navigation
links), `single-client`… templates, classic menus, WPML (translations,
translatable types, slugs), Rank Math and screen preferences; it lists as a
warning whatever names an old type and it does not know how to migrate.
Idempotent, with a dry run, and it does not run on its own:
`wp estrategia-web migrate-prefix --dry-run`, or from `wp eval-file` as
Lavandería does (`despliegue/prefijo-tipos.mjs`, which also renews the
fingerprints of its content loading). It is run **right after uploading the
1.2.0 plugin**: in between, the site cannot find its clients or locations.
Before it, a database backup.

**THE THREE METAS (unreleased).** `client_url`, `testimonial_role` and
`testimonial_client` are registered as `estrategia_web_client_url`,
`estrategia_web_testimonial_role` and `estrategia_web_testimonial_client`
(the map lives only in
[modules/site/includes/class-unprefixed-meta.php](modules/site/includes/class-unprefixed-meta.php)).
Until 2.0.0 the old keys are **read aliases** on clients and testimonials:
`get_post_meta( $id, 'client_url', true )` answers with the new value, a
`core/post-meta` binding saved with `"key":"client_url"` renders the new
one, and until the site is migrated the new key reads the old row. **Not**
for writing (an old key in REST `meta` is ignored) nor in the editor. The
migrator is
[includes/class-meta-prefix-migration.php](includes/class-meta-prefix-migration.php)
(`wp estrategia-web migrate-meta-prefix --dry-run`): `wp_postmeta` of
clients and testimonials and the `core/post-meta` bindings of the content;
idempotent, with a dry run; a post with both keys keeps the new one and is
warned about. A class of its own so that its dry run works against a server
still on 1.4.3 (Lavandería: `despliegue/prefijo-tipos.mjs` carries both).

## If you change provider

- **License:** GPL-2.0-or-later. It is yours to use, change and take with
  you: it does not depend on any service of ours.
- **If you deactivate the plugin**, nothing is deleted (not even when
  deleting it, unless `ESTRATEGIA_WEB_SITE_REMOVE_DATA`), but these stop
  showing:
  - the **company details** that came through Block Bindings: each block
    shows whatever text it has saved (in patterns it is usually empty or
    sample text); it is advisable to write them by hand first;
  - the **shortcodes** `[estrategia_web_company …]`: they come out as is, as
    text;
  - the **post types** (clients, testimonials, locations…): they stay in the
    database, but without the plugin they are not shown in the dashboard or
    in Query Loops;
  - the **cookie notice** and its link: they do not appear. The site needs
    another notice if it loads something that requires it (hybrid Matomo,
    maps);
  - the **SVGs** already uploaded stay in the media library; uploading more,
    no.
- **Export, BEFORE deactivating it:** Tools › Export with the plugin active
  also takes clients, testimonials, locations, projects and portfolio (they
  are exportable types) with their meta. The company details are the
  `estrategia_web_site` option (`wp option get estrategia_web_site
  --format=json`).

## Versions

`MAJOR.MINOR.PATCH`, with its note in [CHANGELOG.md](CHANGELOG.md).

- **A minor or patch version never forces touching a site**: not its bound
  blocks, not its Query Loops, not its theme.
- **Disabling a type does not delete its content**, and neither does a new
  version.
- It is a **major version**, with a note on what to change on each site:
  renaming or removing a key of `estrategia_web_site`, a source key, a type,
  a taxonomy, a meta field, or **changing a slug's translation** (it changes
  the published URLs).
- Adding (a key, a type, a field) is minor.

## How to maintain

- **Who and when:** whoever touches the plugin, on every version: the number
  in `estrategia-web.php` and `ESTRATEGIA_WEB_VERSION` (modules have no
  version of their own), a note in the CHANGELOG, `node tools/i18n.mjs` if a
  string changed, and the whole `node tests/run.mjs` before taking it to any
  site.
- **What goes in:** what **any** corporate site would have (a contact
  detail, a kind of repeated record, a block). Whatever belongs to a single
  site goes to its child theme. Whatever someone may want to switch off
  separately is a **new module**, not one more option inside another (see
  [includes/class-modules.php](includes/class-modules.php)).
- **The edge case:** «services». It is not a type, on purpose: on these
  sites a service is a page with its own design. If several sites end up
  asking for service records, it goes in as a new type (minor version).
- **The trap:** the public slug comes from gettext, so **changing a site's
  language changes its URLs** (`clientes` ↔ `clients`). On a published site
  that is going to change language, pin the slug in `rewrite_slugs` first.
  And the other one: enabling or disabling a type, or changing
  `rewrite_slugs`, regenerates the URL rules on the next load; a page cache
  may serve an old 404 for a while.
- **Each file says how it is maintained in its header.** If you change one,
  check that its header is still true.

Pending: [TODO.md](TODO.md).

## content-types module: the constructor

Content types are **data of each site**: one definition per type, stored in the database (private post type `eweb_content_type`, the JSON as its content, with revisions), made and edited in **Estrategia Web → Content types**: names and address per language, supports, taxonomies and fields (text, textarea, number, url, email, tel, image, date, select, relation, boolean, rating, embed, local_image, hours, with required, default, help, options and the WPML mode). Export and import a definition as JSON from the same screen.

- **Templates**: clients, testimonials, locations, projects and portfolio, with the same type, taxonomy and meta names as the `site` module's (`modules/content-types/templates/`). «Add …» copies one into the database; from then on it is the site's.
- **New fields of a template** (unreleased): when a template gets fields a site's copy does not have (the location template's address by parts, structured hours, coordinates and type of business, 22-10-2026), the constructor **offers** them on that type («The template this type comes from has new fields… Add the new fields of the template»), each after its template neighbour; nothing is added by itself. A deploy script does the same with `Templates::add_missing()`.
- **The `hours` field type** (unreleased): opening hours per day of the week, several ranges a day, stored as JSON text `{"mo":["08:00-14:00","16:00-20:00"],"sa":["09:00-13:00"]}` (closed days left out; `""` = empty). Bad ranges are dropped, overlapping ones merged. In the editor: a group per day with time inputs, «Add hours» and «Same as the day before»; on the classic screen, one line per day.
- **What is protected**: the internal name cannot change once there are entries; removing a field keeps its data (adding it back with the same name shows it again); changing an address warns and creates the 301s in the `redirects` module (or warns that the old addresses will give 404 if it is off); a type with entries cannot be deleted — switch it off instead (its menu and addresses go, its entries stay).
- **REST**, administrators only: `GET|POST /wp-json/estrategia-web/v1/content-types`, `GET|POST|DELETE …/content-types/<name>`, `POST …/content-types/templates/<key>`. A write is `{"definition": {…}, "active": true, "confirm": true}`; without `confirm`, a change that moves published things answers 409 with the list of warnings.
- **In the site's repo**: the definitions go in its content as JSON, loaded into a new or local site through that route and brought back from the server (Lavandería: `content/semilla/tipos-contenido/`, phase `tipos` of `cargar.mjs`, and `despliegue/bajar-contenido.mjs`). In production the database rules: a load never overwrites a definition edited in the panel.
- **JSON files in the theme** (`<child theme>/content-types/*.json`, filter `estrategia_web_content_types_dirs`) still work, read-only; a definition with the same name in the database wins.

The schema: `modules/content-types/content-types.schema.json`. How to maintain it: the header of `modules/content-types/module.php`.

## content-types module: showing a field with `estrategia-web/content-field`

`core/post-meta` shows what is stored: `€€` instead of the option's label, an image or a related post as its ID, a boolean as `1`. The source `estrategia-web/content-field` shows the field formatted by its type, for the post in context (each row of a Query, or the post of a single template):

```html
<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"estrategia-web/content-field","args":{"key":"gment_price_range"}}}}} -->
<p></p>
<!-- /wp:paragraph -->
```

| Type | In text (content, alt…) | In url / href | In id |
|---|---|---|---|
| select | the option's label, in the request's language | — | — |
| image | the alt text (`title`: the image's title) | the image URL (`"size": "large"`…) | the attachment ID |
| relation | the related post's title (`"link": true`: a link) | its permalink | its ID |
| boolean | `true_label` / `false_label` of the definition, or Yes / No translated | — | — |
| date | the site's date format (`"format": "d/m/Y"` to change it) | — | — |
| number | localized, with the decimals that were stored (`"decimals": 2`) | — | — |
| url, email, tel | as they are (`"link": true`: a link) | the URL, `mailto:…`, `tel:…` | — |
| embed | the URL | the URL | — |
| rating | the number, 1 to 5 (nothing for 0) | — | — |
| local_image | the stored path | the image URL (a theme path resolved in the child theme, then the parent) | — |
| hours | one line per run of days with the same hours («lun–sáb: 8:00–16:00»), in the request's language and the site's time format | — | — |
| text, textarea | escaped (textarea: line breaks as `<br>`) | — | — |

The related post and the image are those of the request's language (WPML). Empty, invalid or unpublished gives nothing. A button binds `url` and `text` to the same field, without `link`. How to maintain it, in the header of `modules/content-types/module.php`.

## local-business module: schema.org for the company and its locations (unreleased)

JSON-LD so search engines can show the business (address, phone, hours, map) from the site itself.

| Page | What it gets |
|---|---|
| front page | `Organization`: name (Company details, or the site title), url, logo (the site logo), telephone, email, `sameAs` (the social module's web links), and each location as a `department` |
| a location's own page, and a page **tied** to one (its content binds a block to it by slug with `estrategia-web-site/location`, also inside a group or a synced pattern) | that location as a `LocalBusiness` (its type, see below): name, `address` (`PostalAddress` by parts), telephone (the company's if it has none), email, `openingHoursSpecification`, `geo`, `url` = the page being read, `image` (featured image, static map, logo), `@id` = `<its permalink>#localbusiness`, `parentOrganization` → the Organization |

- **The data**: fields of the location template — `estrategia_web_location_street` (1-2 lines, joined with «, » for `streetAddress`; «translate» in WPML since 1.8.0), `_postal_code`, `_locality`, `_region`, `_country` (ISO 3166, default `ES`), `_hours` (type `hours`), `_latitude`, `_longitude` (0 = not given), `_business_type` (`company` = the company's) — all but the street «copy» in WPML. Since 1.8.0 the SAME fields make what the page shows (the address and hours texts are derived: section 2 ter); there are no free texts any more.
- **A site without locations** (1.8.0, a restaurant: the company is the business): the Company details hours (`hours` of `estrategia_web_site`, day by day) go into the front page's Organization as `openingHoursSpecification`, and the node's `@type` becomes `["Organization", <type of business>]` (only a LocalBusiness may have hours; in Rank Math's or Yoast's Organization the type is added to theirs). With locations, the company's hours field is hidden: each location has its own.
- **Type of business**: **Company details › Search engines (schema.org)**, option `estrategia_web_local_business_type` (also over `/wp/v2/settings`), one of a closed list of schema.org LocalBusiness subtypes (`LocalBusiness` by default; `DryCleaningOrLaundry`, `Restaurant`, `Store`…); a location may set its own.
- **A location is left out** (no node, not half a one) without a name, a street and a town or postal code.
- **With Rank Math or Yoast**: our nodes go into **their** graph (`rank_math/json_ld`, `wpseo_schema_graph`): their Organization is completed with what it lacks, never duplicated, and our locations point to its `@id`. If their local SEO already publishes a LocalBusiness on a page, ours steps aside on that page. Their settings are never touched. **Without them**: our own `<script type="application/ld+json" class="estrategia-web-local-business">`.
- **Out, on purpose**: ratings and reviews (Google ignores a business's ratings of itself), events, offers.
- **Filter** `estrategia_web_local_business_graph`: `[Organization|null, LocalBusiness[]]` before printing or merging.

Tests: `modules/local-business/tests/graph.test.mjs` (level 1: the nodes against Google's rules — `tests/google-rules.mjs`, which the sites' tests import too —, the merge, the type list against the template) and `output.test.mjs` (level 2: alone, with Rank Math doubles with and without its local SEO, with a Yoast double). The hours field: `modules/content-types/tests/hours.test.mjs` (level 1) and `hours-editor.test.mjs` (level 3, with the constructor's offer of new template fields). How to maintain it: the header of `modules/local-business/module.php`.

## A location's data stored once (1.8.0)

Up to 1.7.0 a location kept the same data twice — the address as a text and by parts, the hours as a text and day by day, the map as a link and as an embed URL, the static map as a URL and as a media ID — and the copies drifted apart. Since 1.8.0:

| Stored (one field each) | Derived (shown) |
|---|---|
| street (1-2 lines), postal code, locality, region, country | the address text; `streetAddress` for Google |
| `hours` (day by day) | the hours text |
| «What to search on Google Maps» (`estrategia_web_location_map_query`: a Google Business profile name or an address) | «Open in Google Maps» (`https://www.google.com/maps/search/?api=1&query=…`) and the embed (`https://www.google.com/maps/embed?origin=mfe&pb=!1m3!2m1!1s…!6i15`) |
| static map image (`estrategia_web_location_static_map_id`, media library) | its URL |

Where they are shown: section 2 ter (the location source, with the format filter); local-business. Where they are edited: a **meta box in the main area** of the block editor, grouped (Contact, Address, Opening hours, Map, «For Google» closed), with «Shown on the site» under each group — the definition keys `fields_box: "main"`, `groups` and a field's `group` ([content-types.schema.json](modules/content-types/content-types.schema.json)). Locations, testimonials and clients (which now have `editor`) use it; a type without `editor` still gets it on the classic screen.

**The migration**, once per site, after reading its dry run:

    wp estrategia-web migrate-location-data --dry-run     JSON: per location, before → after, DIFFERENCES, what it could not read
    wp estrategia-web migrate-location-data               does it (backup in _estrategia_web_location_backup_1_8_0 and the option estrategia_web_location_migration_backup)

What it does, what a DIFFERENCE means and how to get out of «not parsed»: the header of [class-location-migration.php](modules/content-types/includes/class-location-migration.php). Until it runs nothing changes on the site (the old texts are shown while they exist); the admin shows a notice on the locations screens.
