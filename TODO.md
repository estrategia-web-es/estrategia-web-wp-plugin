# TODO — armazon-wp-sitio

A single place (Armazón, row 25). Whatever is finished **leaves** this file:
it goes to the git history, with the commit that closes it.

---

## 2.0.0: remove the Spanish marker aliases of forms
**Date:** 15-10-2026 · **Due:** 2.0.0

Remove `Markers::ALIASES` and `with_aliases()` in
`modules/forms/includes/class-markers.php`. Before that, change
`{{todos_los_campos}}`, `{{fecha}}` and `{{url_pagina}}` to the English names in
Lavandería's saved forms (and in any other site that uses them).

## The plugin's translations must not depend on the site language (WPLANG)
**Date:** 15-10-2026 · **Due:** before the next site (Gastronòmicament, Catalan by default)

Measured in Lavandería production after publishing 1.1.0/1.2.0: WPLANG was empty (en_US) and WPML sets the Spanish locale LATER than `Plugin::load_textdomain()` (init, priority 1). The plugin loaded `estrategia-web-en_US` (does not exist), so every plugin string on the Spanish site came out in English (cookie notice «Accept all», maps «Show map») and the location CPT slug was registered as `locations` instead of `sedes`. Local tests did not catch it because the local site has WPLANG = es_ES. Fixed on the server by setting WPLANG = es_ES (Settings → General). Pending in code: with WPML active, take the locale of WPML's current language (or reload on `wpml_language_has_switched`) instead of trusting `determine_locale()` at init; and a test that boots with WPLANG empty and a WPML default language that is not English. The deployer's check should also look for a translated plugin string, not only `lang`.

---

## Estrategia Web roadmap — what EVERY site must have (12-10-2026)

David's list, so that new sites and existing ones carry the same things. It
lives here because almost all of it ends up in this plugin. **How to
maintain**: when something is finished AND published on the sites, it leaves
this list with the commit that closes it; if it is finished but not
published, it moves to «Done and not deployed».

**Decided structure** (David, 14-10-2026): a theme that is only design + an
«Estrategia Web» plugin with modules that can be switched on. The «To add»
items are built as a **new module** (a folder in `modules/`, see the header
of `includes/class-modules.php`) or inside the module they belong to (the
cookies, in `consent`).

### Done and not deployed (local, committed)

- **One theme + one plugin with modules** (plugin 1.1.0, theme 1.1.0,
  14-10-2026): this repo is now the `estrategia-web` plugin, with Site, Forms
  (repo archived) and the theme's four blocks as modules (the blocks no
  longer live in the theme); «Estrategia Web → Modules» screen; one
  translation domain. Lavandería already uses it locally.
- **Migrator to the single plugin** (14-10-2026): `class-armazon-migration.php`
  leaves only `estrategia-web/estrategia-web.php` active (out go armazon-site,
  armazon-forms and the two from 1.0.0). Lavandería's deployer uploads one
  plugin (without `tests/`, `modules/*/tests/` or `tools/`) and the theme
  without `blocks/`, sets aside the uploads MU-plugin, switches
  `comments-off` on once, and has the optional steps `medios` and
  `limpieza`. Rehearsed locally with the production versions; see its
  header.
- **Image uploads** (`uploads` module, Default: on): 20 MB, 2560 px threshold
  and deleting the giant original, configurable in Settings → Media.
  Replaces Lavandería's MU-plugin, which the deployer sets aside on publish.
- **Cookies with detector and analytics** (merged on 14-10-2026): AEPD
  categories, name detector, Open Cookie Database, Cookies screen, policy
  table; `google-tags` (Consent Mode v2, Default: off) and `matomo`
  (Default: on, the option stays in `estrategia_web_site`) modules. What
  was left out:
  - the Google Ads conversion of a form sent WITHOUT JavaScript (the
    redirect with `af_result`) is not counted;
  - the policy table does not include third-party cookies nobody adds by
    hand (the google.com ones from the map iframe): the detector does not
    see them.
- **Custom social networks** (`social` module, Default: on): free list
  (name, link, icon from the collection or SVG) and the
  `estrategia-web/social-links` block. David will review how he wants it.
- **Client «Business type»** (`estrategia_web_client_business_type`,
  translatable). In Lavandería, what today goes in brackets in the title
  still has to be moved to that field (by hand in the admin, after
  publishing).
- **«Location details» box** (and the client and testimonial ones): the
  `site` module's `Meta_Panel`, with `wpml-config.xml` to copy or translate
  each field.
- **Other new modules**: `comments-off` (Default: off; Lavandería switches it
  on when deploying), `featured-images` (size `estrategia-web-small`,
  200 px: `wp media regenerate --only-missing` on sites that already have
  photos), vertical crop of the carousel on mobile (the theme asks for it
  with `estrategia_web_carousel_mobile_crop`).
- **Phase 1: the Estrategia Web name** in the three repos (theme 1.0.0, this
  plugin 1.0.0, forms 1.0.0): folders, prefixes, blocks, options, tables,
  cookie; aliases of the old names until 2.0.0; «Powered by» off by default
  (WordPress.org, guideline 10). Migrator: Lavandería
  `despliegue/migrar-a-estrategia-web.mjs` (--seco against the server,
  clean).
- **Deployer that does not overwrite content** (Lavandería): publishing =
  code; content only with `--contenido`, with a fingerprint per post and
  without touching what was edited in the admin or WPML's English pages.
- **bajar-contenido.mjs** (Lavandería): brings what was edited on the server
  into the repo.
- **Redirects editable in the admin** (`redirects` module, Default: off,
  14-10-2026): Lavandería's whole CSV, `%3F` and `?p=`/`?page_id=`, only on
  404s, 404 log, Rank Math notice, WP-CLI. In Lavandería it stays OFF (Rank
  Math handles them).
- **Post types and taxonomies with the `eweb_` prefix** (15-10-2026, branch
  prefijo-tipos, proposed version 1.2.0): `Type_Names`, the
  `Unprefixed_Names` alias until 2.0.0, the `Prefix_Migration` migrator (and
  in Lavandería `despliegue/prefijo-tipos.mjs`, which the deployer runs
  once). Table of public names in the README and the guide
  [docs/web-nueva.md](docs/web-nueva.md).

### To add (not done yet)

(Nothing from David's list of 12-10-2026: everything is in «Done and not
deployed». Whatever new he asks for goes here.)

## Block tests: from the theme to the plugin
**Date:** 14-10-2026 · **Due:** —

The carousel, map and WhatsApp ones are still in `armazon-wp-padre/tests/`,
which mounts this plugin with only the block modules switched on. They work,
but whoever touches `modules/carousel/` has to know that its test lives in
another repo (its `module.php` says so). Moving them requires bringing their
seeding along (the theme's `tests/fixtures/setup/setup.php`) and measuring
them without the theme's CSS, or with a test theme of their own. It gets done
the next time one of them is touched.

## Forms: test it with real WPML
**Date:** 01-10-2026 · **Due:** before the first site with WPML

(Came from armazon-wp-formularios, archived.) `class-language.php` and
`wpml-config.xml` have never run with WPML in the plugin's tests (Playground
does not have it). Lavandería does have WPML locally
(`tests/fixtures/wpml/`): translate a page with a form, submit in the second
language, check that the acknowledgement comes out translated, that the log
records that language and that the default strings show up in String
Translation; and that on the translated page the dropdown with
`defaultOption` arrives with the same option selected.

## Forms: level 4
**Date:** 01-10-2026 · **Due:** —

(Came from armazon-wp-formularios.) Nobody has looked with human eyes at the
form editor (the half-width grid, the notifications card, the sidebar). And
since 1.1.0, not at the «Estrategia Web → Modules» screen either.

---


## Three unprefixed meta keys, before WordPress.org
**Date:** 15-10-2026 · **Due:** before submitting it to WordPress.org

The post types got a prefix in 1.2.0 (`eweb_client`…; branch prefijo-tipos).
Three META keys from before 1.0.0 remain unprefixed: `client_url`,
`testimonial_role` and `testimonial_client` (the rest are
`estrategia_web_…`). Same problem the post types had: another plugin may use
`client_url`. Fix: one more step in `Prefix_Migration` (meta of the `eweb_*`
types), `wpml-config.xml`, `Meta_Panel::WPML_*`, the content's
`core/post-meta` bindings and the tests. It was not done together with the
types because the brief of 15-10-2026 said «metas no cambian» (meta keys do
not change). David decides.

## Repo remote (row 24)
**Date:** 02-10-2026 · **Due:** before installing it on the first site

It was born without a remote: it was created in a session without permission
to create repos on GitHub. It is not a deliberate «none» like `atlas`'s: the
preferred option is private GitHub, and a plugin several sites will use needs
a place to download it from. David decides (same case as
armazon-wp-formularios).

## WPML: what gets translated from here
**Date:** 02-10-2026 · **Due:** before the first site with WPML

Partly done (checked 15-10-2026): `wpml-config.xml` exists since 1.1.0 and
declares the meta of locations, clients and testimonials (copy/translate) and
the social links list; Lavandería runs on WPML. Still open:
- The company details in the `estrategia_web_site` option (`schedule`,
  `address`, `whatsapp_message`) are not declared in `<admin-texts>`, so WPML
  does not offer them for translation.
- The post types and taxonomies do not declare whether they are translatable
  (`<custom-types>`/`<taxonomies>`); on Lavandería that was set by hand in
  WPML's settings. A new site (Gastronòmicament, four languages) would repeat
  it by hand.
- URL slugs: with WPML, WPML translates them, not gettext.

## Manual order in a Query Loop
**Date:** 02-10-2026 · **Due:** —

The post types have `page-attributes` (manual order) but no test looks at a
Query Loop with `"orderBy":"menu_order"`. That is why the README uses `title`
in the example. A level 2 test in types.test.mjs closes it.

## Level 4: look at it
**Date:** 02-10-2026 · **Due:** —

The tests check that it works, not that it looks right. Nobody has yet
looked with human eyes at the «Company details» screen, a block's
«Attributes» panel with the source, or an SVG in the media library.
