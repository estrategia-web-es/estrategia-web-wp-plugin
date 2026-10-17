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

## WPML and the site language: what is left after 1.4.0
**Date:** 15-10-2026 · **Due:** —

- Open: why WPML's `locale` filter did not apply early in Lavandería production (same WPML 5.0.2 as the local copy, where it does). Not needed for the fix (1.4.0); worth a look if another WPML site shows the language late. The server keeps WPLANG = es_ES.
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

### Published

Everything on David's list of 12-10-2026 is built and published on Lavandería
(1.1.0 to 1.4.3, 14 to 17-10-2026): see the CHANGELOG. What each part left
open is below, in its own section.

### To add (not done yet)

(Nothing pending from David's list. Whatever new he asks for goes here.)

## Cookies: third-party services, open points
**Date:** 17-10-2026 · **Due:** —

- The Google Maps catalogue entries (NID, __Secure-ENID, AEC, SOCS) come from Google's general cookie list; a measurement on 17-10-2026 (Chromium, from Spain, no Google session) saw the embed set NO cookies. Re-check if Google publishes embed-specific cookies.
- Lavandería still uses its hand-written policy tables; switching to the cookie-table block is a content decision for David.
- The map's «rendered» flag never goes back: a site that removes its maps keeps the google-maps proposals (mark them Ignored or delete the option).
## content-types: open points
**Date:** 16-10-2026 · **Due:** before Gastronòmicament goes live (the first two)

- «required» is only enforced through REST (block editor); the classic
  screen (types without «editor») publishes anyway.
- URL slugs per language (WPML slug translation) are not handled; the slug is
  one string.
- Move the `eweb_` types (client, testimonial, location, project,
  portfolio) to content-types: one JSON per type inside the plugin (a folder
  added with `estrategia_web_content_types_dirs`), keeping the same type,
  taxonomy and meta names so nothing is migrated in the database. The special
  sanitizers (rating, fit, embed, local_image, client) need field types or a
  «sanitize» key first; then remove their definitions from `modules/site`
  (`Content_Types`, `Meta_Panel`) and from `wpml-config.xml`.

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

## WPML: what is still not translatable
**Date:** 16-10-2026 · **Due:** before Gastronòmicament goes live

Types, taxonomies and company details are declared since the release after 1.4.2. Left:
- URL slugs: with WPML, WPML translates them, not gettext.
- Block attributes a visitor reads (the whatsapp block's message and label, map, carousel, social-links…) are not in `<gutenberg-blocks>`: audit them.
- On Playground (SQLite), String Translation needs the collation fix of Lavandería's `tests/fixtures/lm-test.php`: the forms strings have never been seen translated there.
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
