# Module `forms` — the forms of the WordPress sites

> **Since 14-10-2026 (Estrategia Web plugin 1.1.0) this is a MODULE of the
> single plugin**, not a separate plugin (the armazon-wp-formularios repo was
> archived). What changes with respect to what this README says, written
> when it was a plugin:
> - It is installed with the `estrategia-web` plugin and switched on or off
>   in «Estrategia Web → Modules». Its bootstrap is [module.php](module.php).
> - **Translation domain: `estrategia-web`** (not `estrategia-web-forms`), in
>   `languages/` at the plugin root; there is no longer an editor JSON.
> - `wpml-config.xml` is at the plugin root (WPML only reads that one).
> - Tests: [tests/](tests/), run by the `tests/run.mjs` at the plugin root
>   (`node tests/run.mjs forms/`), on port **9404** (formerly 9402).
> - Everything else —blocks, REST routes, tables, options, «Forms» menu— is
>   the same, with the same names.

Forms **built from blocks**, with **several email notifications** made from
templates, a **log of everything submitted**, and **WPML-compatible**. Every
site built with the Armazón parent theme uses it; it is shared and versioned.
The reason it is our own (Contact Form 7 is disliked; Formello is abandoned,
no WPML) is in
[armazon/decisiones/2026-10-01-wordpress-tema-padre-compartido.md](E:/Repositories/armazon/decisiones/2026-10-01-wordpress-tema-padre-compartido.md).

- Slug and text domain: `estrategia-web-forms` · WordPress **7.1+** · PHP **8.1+** · GPLv2+
- No dependencies, no `node_modules`, no build step.

> **Since 2.0.0 a form is a post of its own** (`eweb_form`, «Formulario»),
> like Formello's: it is created and edited in **Forms → Forms**, and a page
> shows it with the **«Form» reference block** (`estrategia-web-forms/form-ref`).
> What the form post holds is the same block tree as before, so everything
> below about fields, notifications, markers and preselection still holds;
> only WHERE the tree lives changed. Sites with forms written inside pages
> move them with `wp estrategia-web migrate-forms` (below, «Form posts and
> the migration»).

## What it does

1. **Blocks**: «Form» (container) and inside it «Text field», «Email
   field», «Phone field», «Message field», «Dropdown field», «Checkbox»
   (also the GDPR consent), «Send button» and «Email notification». Each
   field has a label, name (key), required, placeholder
   text and width (full / half). They can be put inside groups and columns: the
   form is laid out like any other block.
2. **Notifications**: each «Email notification» block is an email sent with
   every submission — to the team, an acknowledgement to the sender, a copy
   somewhere else… — with recipients, reply-to, subject and body, all with
   markers.
3. **Log**: each submission is stored with form, page, date, language, data,
   result of each notification (sent / failed and reason), truncated IP and
   browser. **Forms → Forms** (1.7.0, the first item: the menu opens on
   it): every form on the site — pages, posts, synced patterns and the
   pages that use them, drafts —, with its name and identifier in the log,
   its notifications and recipients, and an Edit link; with WPML, one row per
   form in the default language and the languages it is also in
   ([includes/admin/class-forms-list.php](includes/admin/class-forms-list.php)).
   **Forms → Submissions** screen (same URL as before): list, filter by form, detail
   view, CSV export and delete with confirmation. Configurable retention with
   a daily purge.
4. **Security**: submission through its own REST endpoint with a signed
   token, server-side validation by field type, honeypot, minimum fill-in time
   and a per-IP submission limit.
5. **Works without JavaScript** (plain POST and redirect) and **with
   JavaScript** (Interactivity API, no reload, messages in an `aria-live`
   region).

## Configuring a form

1. **Forms → Forms → Add form** (2.0.0). The new form post comes with the
   Form block and its template: name, email, message, consent, button and
   two notifications (to the site and an acknowledgement to the sender).
   Change, remove or add whatever is needed; the post title is only its name
   in the admin. To show it on a page, add the **«Form»** block there and
   choose it from the list. (Before 2.0.0 the Form block went straight into
   the page; it no longer appears in the inserter.)
2. In the form's side panel: the **Name in the submissions log**, the **Form
   identifier** and, if needed, custom success and error messages.
3. Each field: its **name (key)** is the one used in markers (`{{email}}`)
   and in the log. It is generated automatically; change it before
   publishing, not after (the log groups by it).
4. Each **notification**, in its side panel:
   - **To**: addresses separated by commas or a marker (`{{email}}`).
     Empty = the site administrator's email.
   - **Reply to**: an address or a marker. Optional.
   - **Subject** and **Message**: text with markers. Empty message =
     `{{all_fields}}`.
5. The **consent checkbox** is always required. Whatever goes between braces
   in its label is the link: «I have read and accept the {privacy policy}.»
   (on a Spanish site, «He leído y acepto la {política de privacidad}»).
   Without its own URL it links to the privacy page from *Settings → Privacy*
   (with WPML, the translated one).

**Forms → Settings** only has the common things: retention,
antispam and the default texts.

### Preselecting a dropdown option depending on the page

The same form on several pages, and on each one the dropdown arrives with its
own option already selected (quote on the hospitality page → «Lavandería para
hoteles…»). The option is given by its **position**: 1 is the first, 0 none.

- **Normal form** (or a NON-synced pattern, which is copied into each page):
  field panel → **Preselected option**. It stays in the block:
  `<!-- wp:estrategia-web-forms/select {"name":"servicio","options":"…","defaultOption":2} /-->`.
- **Synced block** (the same form on every page): its inner blocks are the
  same on all of them, so the option is set on the pattern block of EACH
  page, in its `content` attribute, keyed by the **field name**. In the
  page's code editor:
  `<!-- wp:block {"ref":123,"content":{"servicio":{"defaultOption":1}}} /-->`.
  There is no panel control for this: WordPress only allows per-page
  overrides on core blocks.
- **From a link**: `?af_servicio=2` or
  `?af_servicio=lavanderia-para-barcos-y-yates` (the option text as a slug).
  It wins over the page setting. The `af_` prefix is on purpose: a field
  called `name`, `p` or `s` would clash with WordPress. A value that is not
  an option is ignored and never reaches the HTML.

If the visitor already chose (coming back from a no-JS submission with
errors), what they chose wins. With WPML, the position holds in every
language because options are translated without changing their order; the
slug link carries the text in its own language. The order of the sources,
the reason for using the position and the pitfalls, in
[includes/class-preselect.php](includes/class-preselect.php).

### Markers

| Marker | What it inserts |
|---|---|
| `{{field_name}}` | what the visitor typed in that field (a checkbox: Sí / No) |
| `{{all_fields}}` | all fields with their label; a table in HTML, lines in plain text |
| `{{submission_date}}` | date and time of the submission, in the site's format |
| `{{page_url}}` | the page the form was submitted from |
| `{{site_name}}` | the site name |

The old Spanish names (`{{todos_los_campos}}`, `{{fecha}}`, `{{url_pagina}}`,
`{{nombre_sitio}}`) worked as aliases until 2.0.1, which removed them:
`wp estrategia-web migrate-form-markers [--dry-run]` renames them in every
saved form, inline form and stored snapshot
([includes/class-marker-migration.php](includes/class-marker-migration.php)).
`{{submission_date}}` and not `{{date}}`: a fixed marker shadows a field with
the same name, and «date» is a likely field name. The details, in
[includes/class-markers.php](includes/class-markers.php).

An empty optional field is replaced with nothing. A marker **that does not
exist** (`{{emial}}`) is left as is, on purpose: the typo shows in the email.

### Email template

Each notification is sent as HTML (simple: tables and inline styles) with the
plain-text version as the alternative. The template is
[templates/email.php](templates/email.php); a site replaces it **without
touching the plugin** by copying it to `<theme>/estrategia-web-forms/email.php`
(child or parent).

## How a child theme uses it

Nothing to code: install the plugin and use the blocks. To style it:

- **The button** is `.wp-element-button`: it inherits `styles.elements.button`
  from `theme.json`.
- **The fields** inherit typography and colour; border, radius, padding, gap
  and error colour are variables: `--estrategia-web-forms-border`,
  `--estrategia-web-forms-radius`, `--estrategia-web-forms-padding`, `--estrategia-web-forms-gap`,
  `--estrategia-web-forms-error`, in the child's CSS on `.estrategia-web-forms`.
- **Once sent** (2.3.0) the form gets `.is-sent`, which hides its fields,
  and the message `.estrategia-web-forms__status.is-success`: a box with a
  check, in `--estrategia-web-forms-success` and
  `--estrategia-web-forms-success-background`; the text colour is inherited.
  With JS the focus goes to it and it is scrolled to the middle of the
  screen; without JS the page comes back at its anchor showing the same.
  To send another one, reload.
- The `.estrategia-web-forms__*` classes are stable across minor versions.
- **The dropdown arrow** is the browser's native one: the plugin does not
  remove it. A theme that wants its own chevron sets `appearance: none` and
  its arrow together, and paints the background with `background-color`, not
  with the `background` shorthand, which wipes out the arrow image (that was
  the «caja lisa» —"plain box"— of the Lavandería review, 03-10-2026). Why
  the plugin doesn't do it, in the header of
  [assets/style.css](assets/style.css).
- A child-theme pattern can carry an entire form (blocks with their
  attributes). Copies of the same pattern share an identifier and are grouped
  together in the log; if they should be kept apart, change it in the editor.

Hooks for the unusual: `estrategia_web_forms_email` (tweak a notification),
`estrategia_web_forms_submitted` and `estrategia_web_forms_rejected` (actions),
`estrategia_web_forms_capability` (who sees the submissions, `manage_options`
by default), `estrategia_web_forms_client_ip` (IP behind a trusted proxy),
`estrategia_web_forms_csv_separator` (`;` by default) and `estrategia_web_forms_settings`.

## Form posts and the migration (2.0.0)

**The model.** A form is a post of type `eweb_form` (not public, no URL,
edited with the block editor, page capabilities, translatable with WPML).
Its content is ONE Form block (the template puts it, locked) with the
fields, button and notifications inside: exactly the tree a page used to
carry. A page shows it with the **«Form» reference block**
(`estrategia-web-forms/form-ref`, attribute `ref` = the form post's id),
which renders the form post's blocks as its own inner blocks, so the HTML,
the token, the antispam, the REST submission, the no-JS POST and the
preselection of a synced pattern (its `content`) are the same as inline.
With WPML the reference renders the form post of the current language (or
the original), whatever id it stores: when WPML copies the block from the
original page into a translation, the English page still shows the English
form. Rules and traps: [includes/class-form-post-type.php](includes/class-form-post-type.php)
and `Blocks::render_form_ref()` in [includes/class-blocks.php](includes/class-blocks.php).

- **Why a new block and not the old Form block pointing to a post**: the
  old block is the form post's root, and on sites that have not migrated it
  still renders inline. Two jobs, two blocks.
- **Per-page preselection without a synced pattern** is not a reference
  attribute (yet): wrap the reference in a synced pattern and use its
  `content`, or link with `?af_<field>=`.

**The migration**, on each site after updating, with a backup:

    wp estrategia-web migrate-forms --dry-run     what it would create and change
    wp estrategia-web migrate-forms               does it
    wp estrategia-web migrate-forms --ids=14,190  only those posts

Every form tree written inside a post becomes a form post per **identifier
and language** (two identifiers are never unified; the same identifier
copied into two posts of one language is one form post, and the report says
whether the copies were identical), the translations are linked by
identifier, and the tree is replaced by the reference block, byte for byte
around it. The original content stays in the post meta
`_eweb_forms_migration_backup`. Idempotent («ya» the second time). The
submissions keep their identifier, so the log does not split. Everything
else (order of steps, edge cases, the trap of re-serializing), in the
header of [includes/class-form-migration.php](includes/class-form-migration.php).

## Decisions, with their reasons

### Why the notifications live in the block and not on a separate screen

- **They are translated with the page.** With WPML, the translated page
  carries its translated notifications, so the acknowledgement goes out in
  the visitor's language without doing anything else. On an admin screen
  each text would have to be registered as a standalone string.
- **You can see how many notifications each form has** while editing it.
- **They never reach the visitor's browser**: notification blocks render
  nothing, and the configuration in `post_content` is only visible to those
  who can edit.

And what makes it safe: when rendering the form, the server stores a
**snapshot of its definition** (fields and notifications) in a table and only
puts its hash in the HTML. On receiving the submission it validates and
notifies using the snapshot. The visitor cannot add fields, remove a
required one or change a recipient: only point to a definition the server
rendered. It works the same whether the form is on a page, a synced pattern
or a template part. Details and pitfalls in the header of
[includes/class-definitions.php](includes/class-definitions.php).

Each notification is a **block** and not a list in an attribute so that WPML
translates it with flat keys (`subject`, `body`) in
[wpml-config.xml](wpml-config.xml), without depending on how it handles
nested attributes.

### Why its own table and not a private post type

Filtering, exporting and purging by date are one query; with a CPT the data
would go into `postmeta`, and **WPML would treat submissions as translatable
content**. Two tables with numbered migrations (Armazón, row 14):
[includes/class-migrations.php](includes/class-migrations.php).

### Why the editor is not compiled

[assets/editor.js](assets/editor.js) is modern JavaScript written with
`wp.element.createElement` instead of JSX, and reads the `wp.*` globals the
editor already ships. No `@wordpress/scripts`, no `node_modules`, no `build/`:

- the plugin is installed by copying the folder, and what is in git is what
  runs;
- there is no tooling version to go stale (`@wordpress/scripts` drags in
  hundreds of packages that would have to be kept up to date in a plugin with
  ~400 lines of editor code);
- the price is writing `h('div', …)` instead of `<div>`, which in these
  blocks (side-panel forms) is little.

**It is not an ES module** (`import`): editor blocks are registered with
classic scripts that run before the editor starts, and a module runs
deferred. The public side **is** a module:
[blocks/form/view.js](blocks/form/view.js), with the Interactivity API, which
WordPress serves as an uncompiled module.

### Why its own token and not `wp_create_nonce()`

A WordPress nonce is tied to the user, and the REST API treats as anonymous
any request with cookies but no `X-WP-Nonce` header. An editor with an open
session testing their form without JavaScript would always fail. The token
is the render time signed with the site salt, bound to the definition; it
expires after 7 days and is also the clock for the minimum fill-in time.
See [includes/class-token.php](includes/class-token.php).

### WPML

Everything it knows about WPML is in
[includes/class-language.php](includes/class-language.php), and it only acts
if WPML is active:

- The block texts (labels, options, messages, notification subject and
  body) are translated by WPML along with the page, according to
  [wpml-config.xml](wpml-config.xml). The recipient is deliberately not
  translated (so a translator cannot redirect the emails).
- The **Settings** texts are registered with
  `wpml_register_single_string` and translated with
  `wpml_translate_single_string` (domain `estrategia-web-forms`), only once
  they are filled in (empty = the default text of the `.po`). They are
  registered in the site's **default language**: without it WPML assumes
  English for this domain and never translates them into English (fixed
  after 1.4.3; a site that saved them before with WPML has them marked as
  English in String Translation and must re-register them, see the
  CHANGELOG).
- The submission language is that of the page the visitor saw
  (`wpml_current_language` at render time), it is stored in the log, and on
  submission the code switches to it (`wpml_switch_language` +
  `switch_to_locale`) so the fixed texts of the emails come out in that
  language.
- Without WPML, the language is the WordPress locale (`es_ES`) and
  everything works the same.

### Strings and translations (row 11) — and where it departs

`languages/`: [estrategia-web-forms.pot](languages/estrategia-web-forms.pot),
[estrategia-web-forms-es_ES.po](languages/estrategia-web-forms-es_ES.po), its
`.l10n.php` (WP 6.5+ PHP format) and the editor JSON. They are generated with
`node tools/i18n.mjs` (see its header; it needs GNU gettext, which ships with
Git for Windows).

**It departs from row 11 in one thing, and says so:** the `msgid` is the
**English text**, not a symbolic key. It is the platform convention —
`__( 'Send', … )`—: WordPress, Poedit, Loco Translate and WPML string
translation expect the source text, and with keys a site in a language with
no `.po` would show `form.submit` on the button. The price is the one the
row warns about: changing the English marks the translation as *fuzzy*, and
the script reports it.

## Tests (row 7)

    node tests/run.mjs             1 → 2 → 3, stops at the first red
    node tests/run.mjs --nivel 1   only level 1 (every commit, ~0.2 s)

The runner starts **Playground** (WP 7.1, PHP 8.3) if there isn't one on
port 9402, with the plugin mounted and [tests/blueprint.json](tests/blueprint.json),
and kills it at the end. Emails are not sent: a test mu-plugin
([tests/wp/estrategia-web-forms-test.php](tests/wp/estrategia-web-forms-test.php))
intercepts them with `pre_wp_mail` and stores them for the test to read.

| File | Level | What it tests |
|---|---|---|
| [tests/pure.test.mjs](tests/pure.test.mjs) | 1 | markers, truncated IP, token, preselection (with the machine's PHP); the base CSS does not remove the dropdown arrow |
| [tests/preselect.test.mjs](tests/preselect.test.mjs) | 2 | option preselected by attribute, by URL (slug and position, foreign value ignored), per page in a synced block, and the no-JS return |
| [tests/server.test.mjs](tests/server.test.mjs) | 2 | public HTML without recipients, empty required field on the server, invalid email and option, honeypot, minimum time, fake token, per-IP limit, full submission with N notifications and markers, no JS (303), purge |
| [tests/autocomplete.test.mjs](tests/autocomplete.test.mjs) | 2 | `autocomplete` (0.2.2): the block's value is output (name, organization, on text, dropdown and textarea); email and phone with email and tel by default and changeable; a text field without it doesn't carry it; a value with quotes doesn't sneak through. Seen failing before the code |
| browser scenarios [20-forms-js](tests/browser/20-forms-js.json) and [21-forms-no-js](tests/browser/21-forms-no-js.json) (was tests/browser.test.mjs) | browser | with JS without reloading and `aria-live`, native validation, server error bypassing the browser, real no-JS |
| browser scenario [19-forms-editor](tests/browser/19-forms-editor.json) (was tests/admin.test.mjs; the screens went to level 2 on 28-10-2026) | browser | list, filter, detail view, CSV (and CSV injection), nonce, delete with confirmation, blocks in the editor; the preselection follows its text when reordering options, and opening and saving a page does not wipe the `content` of its synced block |
| [tests/i18n.test.mjs](tests/i18n.test.mjs) | 3 | es_ES: default texts, errors, logged language, email and editor |

All of them have been seen failing: the code each one covers was broken on
purpose (three rounds of mutants, 01-10-2026; the preselection ones,
03-10-2026) and they were checked to go red. The «opening and saving does
not wipe the `content`» test cannot be seen red by breaking the plugin: it
watches WordPress, and it will go red the day the editor starts dropping it.

## Installing on a site

Copy the folder to `wp-content/plugins/estrategia-web-forms/` **without
`tests/` or `tools/`** and activate. `tests/wp/` opens REST routes without
permissions: it is not loaded unless the test `blueprint` loads it (double
key: a mu-plugin that doesn't exist on a site and the
`ESTRATEGIA_WEB_FORMS_TESTING` constant), but there is no reason for it to be
on the server.

**Secrets (row 21): none.** The token is signed with `wp_salt('nonce')`,
which lives in each site's `wp-config.php`. Sending email is the site's job
(`wp_mail` and its SMTP): the plugin stores no credentials.

**Deleting** the plugin does not delete the log, unless the site defines
`ESTRATEGIA_WEB_FORMS_REMOVE_DATA` as `true` ([uninstall.php](uninstall.php)).

## Names: from «Armazón Forms» to «Estrategia Web Forms» (1.0.0)

Up to 0.2.2 the plugin was called **Armazón Forms** (folder
`armazon-forms`). In 1.0.0 (07-10-2026) it became **Estrategia Web Forms**
so it could be published on WordPress.org (unique prefixes of 4 or more
characters). The 0.x CHANGELOG entries use the old name; the repo is still
at `E:\Repositories\armazon-wp-formularios`.

| What | Before | Now |
|---|---|---|
| Folder, file, text domain | `armazon-forms/armazon-forms.php` | `estrategia-web-forms/estrategia-web-forms.php` |
| Namespace | `Armazon\Forms` | `EstrategiaWeb\Forms` |
| Functions, constants, filters, options | `armazon_forms_…`, `ARMAZON_FORMS_…` | `estrategia_web_forms_…`, `ESTRATEGIA_WEB_FORMS_…` |
| Tables | `{prefix}armazon_forms_submissions`, `…_definitions` | `{prefix}estrategia_web_forms_…` |
| Blocks | `armazon-forms/form`, `/text`… | `estrategia-web-forms/…` |
| REST route | `armazon-forms/v1` | `estrategia-web-forms/v1` |
| CSS classes and properties | `.armazon-forms__…`, `--armazon-forms-…` | `.estrategia-web-forms__…`, `--estrategia-web-forms-…` |
| WPML context | `armazon-forms` | `estrategia-web-forms` |

The old blocks kept rendering as aliases until **2.0.1**, which removed them
together with **the migrator** that moved tables, options and WPML strings
(`includes/class-armazon-migration.php` up to 2.0.0; submissions were renamed
with `ALTER TABLE … RENAME TO`, not copied or lost). A site still on the
Armazón names takes a 2.0.0 package first.

## If you change provider

- **License:** GPL-2.0-or-later. It is yours to use, change and take with
  you: it does not depend on any service of ours (email goes out through the
  site's `wp_mail`).
- **If you deactivate the plugin**, the form stops showing: its blocks are
  dynamic and without the plugin they render nothing (what remains, if any,
  is the text around the form on the page). Nobody can submit anything.
- **The submission log is not deleted** when deactivating or deleting the
  plugin (unless `ESTRATEGIA_WEB_FORMS_REMOVE_DATA`). It is in two tables of
  its own in the database.
- **Export, BEFORE deactivating it:** Forms › Submissions › «Export CSV»
  per form. With the plugin already removed: `wp db export --tables=<prefix>estrategia_web_forms_submissions,<prefix>estrategia_web_forms_definitions`
  (each submission's fields are stored as JSON in the `data` column).

## Versions

`MAJOR.MINOR.PATCH`, with its note in [CHANGELOG.md](CHANGELOG.md).

- **A minor or patch version never requires changing a site**: neither its
  blocks, nor its child theme, nor its email template. It is the same rule as
  the parent theme (condition 2 of the decision).
- Anything that breaks something on a site —renaming an attribute, a
  `.estrategia-web-forms__*` class, a marker, the variable of an email
  template— is a **major version**, with a note on what to change on each
  site.
- A site can stay on an old version: nobody is forced to update the same
  day.
- The database shape is versioned separately (`Migrations::LATEST`); it goes
  up when there is a migration, and the migration runs by itself on load.

## How to maintain

- **Who and when:** whoever touches the plugin, on every version: number in
  `estrategia-web-forms.php` and `ESTRATEGIA_WEB_FORMS_VERSION`, note in the
  CHANGELOG, `node tools/i18n.mjs` if a text changed, and the whole
  `node tests/run.mjs` before taking it to any site.
- **What goes in here and what doesn't:** what goes in is what applies to
  **any** form on **any** site (a field type, a marker, an antispam rule).
  What only one site needs goes in its child theme, with the hooks above.
- **The borderline case:** «the client wants the notification with their
  logo». That is not a plugin option: it is the child's
  `estrategia-web-forms/email.php` template. If **every** site ends up
  wanting a logo, then it does go in here.
- **The trap:** WPML is not in this plugin's tests (it is paid). Every
  version that touches [includes/class-language.php](includes/class-language.php)
  or [wpml-config.xml](wpml-config.xml) runs, before going to the other
  sites, Lavandería's WPML tests: `tests/forms-wpml.test.mjs` (a submission
  on `/en/contact/`: validations, messages, acknowledgement, log, String
  Translation) and `tests/wpml-blocks.test.mjs` (a page translated through
  WPML's flow, and its form submitted).
- **Each file says how it is maintained in its header.** If you change one,
  check that its header is still true.

Pending: [TODO.md](../../TODO.md).
