# TODO — armazon-wp-sitio

A single place (Armazón, row 25). Whatever is finished **leaves** this file:
it goes to the git history, with the commit that closes it.

---

## 2.0.1 — agreed with David on 28-10-2026, while reviewing 2.0.0 in Lavandería
**Date:** 28-10-2026 · **Due:** 2.0.1

1. **Remove the Spanish marker aliases of forms** (was due in 2.0.0 and slipped;
   David saw `{{todos_los_campos}}` in a notification body next to the help that
   lists `{{all_fields}}`). First a MIGRATION, like 2.0.0's (dry run with
   before → after per notification, backup, idempotent): every saved
   `eweb_form` (all languages) changes `{{todos_los_campos}}`, `{{fecha}}`,
   `{{url_pagina}}`, `{{nombre_sitio}}` to `{{all_fields}}`,
   `{{submission_date}}`, `{{page_url}}`, `{{site_name}}`. Then remove
   `Markers::ALIASES` and `with_aliases()` in
   `modules/forms/includes/class-markers.php`. Field markers (`{{nombre}}`…)
   are the field names and don't change.
2. **Remove the old migrators and their aliases** (prefix `eweb_`, meta prefix,
   Armazón names; their tests `meta-migration` and `prefix-migration`, ~45 s of
   battery). Lavandería already went through them; new sites are born with the
   new names.
3. **One look for every plugin screen**: a shared admin stylesheet. Every
   section in a white box with its title (like the location fields box); the
   SAME tabs everywhere (the content-types builder's, also in Settings, which
   still uses WordPress's grey `nav-tab`); the same screen header (title + one
   line saying what it's for) and the save button always in the same place.
   Applies to Company details, Settings, Social networks, Forms. David: «la
   página de ajustes parece de otro plugin».
4. **Forms › Forms list**: each notification in the summary column is a LINK
   that opens the form with that notification block selected and the sidebar
   open (fix a recipient in two clicks).
5. **Language switcher description** in Modules (the module stays: WPML's own
   block fails keyboard opening, hreflang/lang and aria-controls — measured
   27-10-2026): «The "ES ▾" of the header: switches language to the same
   page, keyboard-accessible and with hreflang. Needs WPML; without it,
   nothing is shown.»
6. **Redirects module: keep or remove?** Lavandería's redirects live in Rank
   Math (83 rules). Decide with the next site: if it also uses Rank Math, remove.

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
## local-business: after publishing
**Date:** 22-10-2026 · **Due:** —

- Rich Results test of the live site once deployed and Lavandería's `despliegue/negocio-local.php` has run (/, /autoservicio/, /lavanderia-industrial/ and /en/); only a double of Rank Math's filter was tested locally.
- A test for the `hours` field on the classic screen (no template uses it today).
- Lavandería: `despliegue/bajar-contenido.mjs` does not bring back the locations' new fields (street, hours, coordinates) edited in the panel.
- Maioris: «Calle Imperial, 2» (the website) or «Local 12» (its Google Business profile)? Carlos decides; then both say the same.

## content-types: open points after the constructor
**Date:** 17-10-2026 · **Due:** —

- Lavandería's `footer-social.test.mjs` failed once in a full sequence and passes alone: cause unknown.
- `estrategia_web_testimonial_client` empty reads `null` over REST (likely WPML `post-ids`); nothing visible changes.
- `bajar-contenido.mjs` has not yet brought the definitions back from production (it reads production over ssh).
## Forms: level 4
**Date:** 01-10-2026 · **Due:** —

(Came from armazon-wp-formularios.) Nobody has looked with human eyes at the
form editor (the half-width grid, the notifications card, the sidebar). And
since 1.1.0, not at the «Estrategia Web → Modules» screen either.

---


## 2.0.0: remove the unprefixed meta aliases
**Date:** 17-10-2026 · **Due:** 2.0.0

Remove `Unprefixed_Meta` (modules/site/includes/class-unprefixed-meta.php) and `Meta_Prefix_Migration` (includes/class-meta-prefix-migration.php), together with `Unprefixed_Names` and `Prefix_Migration`, once every site has run the meta migrator (17-10-2026: only Lavandería, with no rows to rename). In Lavandería, remove despliegue/prefijo-metas.php and its part in prefijo-tipos.mjs / desplegar.mjs.
## Repo remote (row 24)
**Date:** 02-10-2026 · **Due:** before installing it on the first site

It was born without a remote: it was created in a session without permission
to create repos on GitHub. It is not a deliberate «none» like `atlas`'s: the
preferred option is private GitHub, and a plugin several sites will use needs
a place to download it from. David decides (same case as
armazon-wp-formularios).

## WPML: what is still not translatable
**Date:** 17-10-2026 · **Due:** before Gastronòmicament goes live

- URL slugs of the `eweb_` types (modules/site): the content-types mechanism works for them too (values from each language's .po), but turning it on changes Lavandería's English URLs (/en/sedes/ → /en/locations/). Proposal: opt-in per site, off by default; Gastronòmicament turns it on. The constructor (content-types templates) takes these types over.
- «required» in Quick Edit is not enforced.
- Slug translation measured with real WPML for es/en only; four languages with Catalan as default only at level 1.
- English pages of Lavandería are created by the content load, not by a WPML job: sending one to WPML translation rebuilds it from the original and loses the hand-written English.
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
