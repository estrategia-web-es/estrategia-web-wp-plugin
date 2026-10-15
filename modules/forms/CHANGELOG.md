# Changelog

One entry per version, newest on top. It is written in the same commit that
bumps the number (README, «Versions»). If a version requires changing
something on the sites, the entry says so first, with what to change.

## 1.0.0 — 07-10-2026

**MAJOR: rename. Requires changes on the sites:** run the migrator
(Estrategia Web Site plugin 1.0.0) and change, in the child theme, the
`armazon-forms…` classes and properties to `estrategia-web-forms…`.

- **«Armazón Forms» becomes «Estrategia Web Forms»**: folder and file
  `estrategia-web-forms/estrategia-web-forms.php`, text domain, namespace
  `EstrategiaWeb\Forms`, functions, constants, filters, options, tables,
  blocks, REST route, WPML context, handles and classes (table in the README,
  «Names: from «Armazón Forms» to «Estrategia Web Forms» (1.0.0)»). Author,
  Plugin URI and Author URI: Estrategia Web, https://estrategiaweb.es/.
- **Aliases** for the nine `armazon-forms/…` blocks until 2.0.0
  (`includes/class-legacy-names.php`). The old REST route has no alias
  (only the block's own JS called it).
- Tables, options and strings are moved by the Estrategia Web Site migrator:
  submissions are preserved (RENAME TABLE).
- README: «If you change provider».

## 0.2.2 — 06-10-2026

Does not require changing any site: email and phone still carry `email` and
`tel`. For a text field to carry it, you have to set it.

- **`autocomplete` attribute** on the text, email, phone, textarea and
  dropdown fields (WCAG 1.3.5). It is output as is; when empty, email gets
  `email` and phone gets `tel`, as before. A text field without it doesn't
  carry it: it is not guessed from the field name. Values: those of the HTML
  specification (`name`, `organization`, `given-name`, `work tel`,
  `off`…); anything that is not lowercase words, digits and hyphens is
  discarded. New control in the editor («Autocompletar»
  at the time; now «Autocomplete»).
- From the Lavandería audit (06-10-2026): on Contacto, name and company
  didn't have it. There: `"autocomplete":"name"` and
  `"autocomplete":"organization"` on their blocks.
- Test: `tests/autocomplete.test.mjs` (level 2), seen failing first.

## 0.2.1 — 06-10-2026

Does not require changing any site: `{text}` links to privacy as before.

- Consent: **several links in the same sentence** with `{text|url}`
  («la {protección de datos|/proteccion/} y {Sus datos seguros|/sus-datos/}»).
  Previously only the first pair of braces was linked, and to the site's
  privacy page. A url that esc_url() empties (javascript:…) stays as text.
  Test: tests/consent-links.test.mjs (level 2).

## 0.2.0 — 03-10-2026

Does not require changing any site: without `defaultOption` the dropdown
comes out as before.

- Dropdown: **preselected option** depending on the page. `defaultOption`
  attribute (position, 1 = the first, 0 = none) with its control in the
  panel; in a synced block, per page with
  `"content":{"<field>":{"defaultOption":N}}` on the `core/block`; and from a
  link with `?af_<field>=` (position or slug of the option). Only an option
  that exists is selected. When reordering the options in the editor, the
  preselection follows its text.
- WPML: `defaultOption` is not translated; it holds in every language
  because it is a position.
- The dropdown arrow is still the native one: decided and documented that a
  custom chevron belongs to the theme (README, «How a child theme uses it»).
- es_ES translation of the three new editor texts.

## 0.1.0 — 01-10-2026

First version.

- Blocks: form, text, email, phone, message, dropdown, checkbox (with GDPR
  consent), button and email notification.
- Several notifications per form with HTML + text template and markers
  `{{campo}}`, `{{todos_los_campos}}`, `{{fecha}}`, `{{url_pagina}}`,
  `{{nombre_sitio}}`.
- Submission log (own table, migration 0001), submissions screen with
  filter, detail view, CSV and delete with confirmation; retention with a
  daily purge.
- REST endpoint with signed token, honeypot, minimum time and per-IP limit.
  With and without JavaScript (Interactivity API).
- WPML: `wpml-config.xml` and settings strings; submission language logged.
- es_ES translation (`.po`, `.l10n.php` and editor JSON).
