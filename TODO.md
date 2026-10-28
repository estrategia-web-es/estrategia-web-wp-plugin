# TODO — armazon-wp-sitio

A single place (Armazón, row 25). Whatever is finished **leaves** this file:
it goes to the git history, with the commit that closes it.

---

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


## After 2.0.1: what Lavandería's repo still calls (its repo, not this one)
**Date:** 28-10-2026 · **Due:** before Lavandería's next deploy with this repo's main

The migrators removed in 2.0.1 are still read by Lavandería's deployer FROM THIS REPO: `despliegue/prefijo-tipos.mjs` (`script()`) reads `includes/class-prefix-migration.php` on every dry run of `desplegar.mjs`, so its `--seco` now stops; `despliegue/migrar-a-estrategia-web.mjs` reads `includes/class-armazon-migration.php` (only when the site still has the old plugins); `despliegue/tipo-negocio.php` asks `class_exists( Unprefixed_Meta )` to pick the meta key and now falls back to `client_url`; `tests/fixtures/lm-test.php` serves the old prefix routes. In Lavandería: remove `prefijo-tipos.*`, `prefijo-metas.php`, `migrar-a-estrategia-web.mjs` and their steps in `desplegar.mjs`, and make `tipo-negocio.php` use `estrategia_web_client_url` always (or pin `LM_SITE` to a 2.0.0 checkout until then).
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
