# Setting up a new site with Estrategia Web

The guide for building a client site with the two shared pieces —the
**parent theme** `estrategia-web` and the **plugin** `estrategia-web`— plus
its own **child theme**. Written on 15-10-2026 from what exists in
Lavandería Mallorca, the only site built this way so far: **the proof that
this scales is that the next site comes out of following this guide without
asking anyone**. Wherever today you have to ask someone, it says **GAP**.

**The real case it is tested against: rebuilding https://gastronomicament.cat**
(Grup Gastronòmicament, since 2006). What is known as of 15-10-2026, from
reading the home page and from David: a gastronomy magazine for the Balearic
Islands, **in Catalan** with Spanish, English and German (probably WPML); it
is **WordPress** (confirmed) on **Raiola Networks** with cPanel, **no SSH**,
several GB (mostly photos); menu Restaurants, Receptes, Alimentació (with a
search box), Vinícola, Actualitat (Fires i rutes, Concursos i premis, Cursos
i tallers, Reportatges i novetats…; about 133 pages of articles) and
Contacte; newsletter («Subscriu-m'hi»), search, comments on articles and
social networks (Facebook, X, Instagram, email); **no branches or
addresses**. Each section says what applies to this site with
«**Gastronòmicament:**». What it needs and we do not have yet, with a
proposal for each item, is in section 8.

The three pieces and what each one carries:

| Piece | Repo | What it carries | What it does NOT carry |
|---|---|---|---|
| Parent theme `estrategia-web` | `armazon-wp-padre` | templates, parts, generic patterns, base `theme.json` | no blocks (since its 1.1.0), no data |
| Plugin `estrategia-web` | `armazon-wp-sitio` (this one) | everything that is stored or does something: company data, types, blocks, forms, cookies, analytics, redirects, uploads… in **modules** | design |
| Child theme `<client>` | the site's repo | the brand: tokens, section styles, patterns with their look, parts | content (not a single phone number or client name: checked by `theme-has-no-content.test.mjs` in Lavandería) |

---

## 1. What is installed and in what order

1. **WordPress 7.1+**, PHP 8.1+, in the site's MAIN language (Settings ›
   General): Spanish in Lavandería; **Catalan** in Gastronòmicament (see
   8.4: today the plugin only ships `es_ES`).
2. **The `estrategia-web` plugin** (folder `wp-content/plugins/estrategia-web/`,
   without `tests/`, `modules/*/tests/` or `tools/`), and activate it. It
   goes before the theme: the parent renders blocks that the plugin
   registers (`estrategia-web/map`, `/carousel`…).
3. **The `estrategia-web` parent theme** (folder `wp-content/themes/estrategia-web/`,
   without `blocks/` if it still has it, without `tests/`). It is not
   activated on its own.
4. **The child theme** (folder `wp-content/themes/<client>/`), and THIS is
   the one activated. If there was already a theme with the same folder,
   see the `switch_theme` trap in the header of
   `lavanderia-mallorca/despliegue/desplegar.mjs`.
5. **WPML** (if the site is bilingual; section 5) and **Rank Math** (SEO;
   its per-type settings use the registered names, `pt_eweb_client_…`).

On a live site the deployer does this (section 6); by hand only on the first
install or on a server without SSH.

## 2. The child theme: how it is created

Copy the structure of Lavandería's (`lavanderia-mallorca/theme/`) and strip
out the brand. The **generic** part (copied as is, changing the prefix):

    style.css            header (Template: estrategia-web) and only base rules
    functions.php        only hooks; prefix <client>_ on every function
    inc/*.php            pattern helpers (one per concern; loaded with glob)
    templates/           front-page, page, page-full, page-hero (the parent's already work)
    parts/               header, footer, navigation-overlay
    languages/           its .po/.l10n.php
    (and at the root of the site's repo, outside the theme: tools/juntar-css.mjs,
    which joins assets/css/*.css into assets/css/dist/: the site loads one sheet)

The **brand** part (made anew for each client):

    theme.json           colours, fonts, radii, shadows, spacing
    styles/block/*.json  one style per section TYPE (not per page)
    assets/css/*.css     the CSS theme.json cannot express, named after the style
    assets/fonts, deco, iconos
    patterns/*.php       sections with their look and neutral sample text

Rules already written down and not repeated here:
`lavanderia-mallorca/theme/README.md` (what goes in the theme and what does
not; stylesheet names; patterns). The ones that matter for the plugin:

- **The plugin's types in a pattern**, always by their registered name,
  asked from the plugin, never hard-coded:
  `"postType":"<?php echo esc_attr( function_exists( 'estrategia_web_type_name' ) ? estrategia_web_type_name( 'client' ) : 'client' ); ?>"`.
- **Company data** goes through Block Bindings
  (`estrategia-web-site/company`, `estrategia-web-site/location`) or through
  `[estrategia_web_company key="…"]`; never written into the theme.
- **The cookie notice categories** the site needs (`maps` if there are
  maps) are declared by the child with the `estrategia_web_consent_categories`
  filter (see `lavanderia-mallorca/theme/functions.php`).

**GAP — there is no child theme template.** Today you copy Lavandería and
delete its own stuff by hand; there is no empty `armazon-wp-hijo` with the
structure. Proposal (not done): extract from Lavandería a template repo with
the generic part above and `tools/juntar-css.mjs`, like
`armazon-template-tool` for tools (Armazón, row 23: the generator lives in
the template repo).

## 3. Which modules to turn on

In **Estrategia Web › Modules**. By default the ones Lavandería uses come
switched on; turning a module off does not delete anything it stored.

| Case | Modules |
|---|---|
| Every site | `site` (company data and types), `consent` (cookie notice), `webp`, `uploads`, `external-links`, `featured-images` |
| With a contact or quote form | `forms` |
| With branches and a map | `site` (type `location`) + `map` |
| With a carousel home page | `carousel` |
| With WhatsApp | `whatsapp` |
| With social networks in the footer | `social` |
| Bilingual with WPML | `language-switcher`, `wpml-front-page` |
| Cookieless analytics (Matomo) | `matomo` |
| Google Analytics, Ads or Tag Manager | `google-tags` (off by default; needs `consent`) |
| Site replacing another one (old URLs) | `redirects` (off by default; if Rank Math already handles redirects, only one of the two) |
| No comments | `comments-off` (off by default) |

The full list and what each one does: [README](../README.md#modules),
«Modules».

**Gastronòmicament:** `site` (company data; **none** of our types: it has
no clients or branches; its types are different ones, 8.1), `consent`,
`webp`, `uploads` (and its 2560 px resize and WebP matter for the import,
8.2), `external-links`, `featured-images`, `forms` (Contacte), `social`,
`language-switcher` and `wpml-front-page` (four languages, 8.4), `redirects`
**on** (the old URLs, 8.2), `matomo` or `google-tags` depending on what it
measures with today. `comments-off` **off**: it has comments (8.5). No `map`
or `whatsapp` until they are asked for.

## 4. What is configured in the admin

In this order (later steps use earlier ones):

1. **Estrategia Web › Company details** (the menu opens on it): trade name
   and legal name, tax ID (CIF), company registry entry, phone numbers,
   email, address, opening hours, map, WhatsApp, type of business. **Which
   content types the site uses** (clients, testimonials, branches, projects,
   portfolio): **Estrategia Web › Content types**. SVG yes/no: **Settings ›
   Media**; Matomo (URL, site id, mode): **Settings › Analytics** (1.7.0). If the site has a `/clientes/` **page** and the clients
   type, the type's archive would override it: set a different slug in
   `rewrite_slugs` (README,
   [«3. Content types»](../README.md#3-content-types)).
2. **Branches** (if the site has them): one per branch, with phone, email,
   address, opening hours, Google Maps link and *embed*, and the static map
   image («Branch data» panel in the editor).
3. **Clients and testimonials**: title, photo or logo (and «Logo» in the
   image setting if it is one), sector; the testimonial with author and
   rating.
4. **Estrategia Web › Social networks**: the list (name, link, icon).
5. **Estrategia Web › Settings › Cookies**: review what the detector finds and add
   what it cannot see (third-party ones inside an iframe). The policy table
   comes from the `estrategia-web/cookie-table` block.
6. **Estrategia Web › Settings › Analytics** (Google part if `google-tags`): GA4, Ads, Tag Manager.
7. **Forms › Settings**: sender and recipients; **Forms › Forms** lists every form and where it is.
8. **Estrategia Web › Settings › Media › Image uploads** (1.7.0; before, WordPress's Settings › Media): maximum size, pixel threshold and
   whether the original is deleted.
9. **Estrategia Web › Redirects** (if `redirects`): import the CSV of the
   old URLs.
10. **Rank Math**: titles and sitemap per type (they show up as
    `eweb_client`…).

**GAP — the data comes from the client.** There is no onboarding form for
the client to fill in: today David asks for it by email. Minimum list to
ask for: the fields in step 1, branches, logo in vector format (or it gets
vectorised, telling them: it redraws the brand), photos, legal texts (legal
notice, privacy, cookies) and whether there will be English.

**GAP — the legal texts.** Lavandería has them in its seed; there is no
shared template with the blanks marked.

## 5. WPML: yes or no

- **No** (monolingual): it is not installed; the `language-switcher` and
  `wpml-front-page` modules do nothing without it.
- **Yes**: default language Spanish, second language by directory
  (`/en/`). The types `eweb_location`, `eweb_client`, `eweb_testimonial` and
  the taxonomy `eweb_client_sector` translatable «showing the original if
  missing» (mode 2); pages, translatable (mode 1). Which branch fields are
  copied and which are translated is defined in the plugin's
  `wpml-config.xml`. **The trap** (measured in Lavandería): WPML does not
  read the `wpml-config.xml` of a plugin activated without its hooks until
  one of its screens is opened; the deployer forces it
  (`WPML_Config::load_config_run`).
- **GAP — the WPML licence** is paid and per site: who buys it and in whose
  name is not written down.

## 6. The seed and content loading

The **seed** is the starting content in files (`content/semilla/` in the
site's repo): pages in block HTML, clients, branches, menus, company data,
photos. The **loader** (`cargar.mjs`) uploads it through the REST API to any
WordPress (local Playground or the server over SSH), idempotently, and since
07-10-2026 it **does not overwrite what was edited in the admin**
(fingerprints). In production the content belongs to the client: the seed
is for **bootstrapping** and for the local WordPress, not for syncing.

What from Lavandería is reusable, and what is tied to it:

| Script | Reusable | Tied to Lavandería |
|---|---|---|
| `content/semilla/cargar.mjs` | the whole engine: phases, `{{media:…}}` markers, fingerprints, SSH bridge, WPML | the phases read `clientes.json`, `sedes.json`, `paginas.json` in their shape; the `en` language hard-coded; the WPML modes |
| `content/semilla/idiomas.php`, `puente.php`, `tipos.mjs` | **yes, as is** | — |
| `despliegue/desplegar.mjs` | copy, packages from HEAD, upload, migrators, check, recording of the OK | constants at the top (`DEST`, `URL_BASE`, `WP`, `CHILD`, `DESACTIVAR`, `MODULOS_ENCENDER`, `MU_SUBIDAS`, the pages it checks) and that site's past (spectra-one child theme, uploads MU-plugin) |
| `despliegue/bajar-contenido.mjs` (+ `.php`) | the mechanism (download and «un-fill» markers) | the shape of Lavandería's seed |
| `despliegue/prefijo-tipos.mjs`, `migrar-a-estrategia-web.mjs` | yes (with `--destino`/`--wp-path`) | only the defaults |

**Proposal (not done, David decides): extract into a shared repo
`armazon-wp-carga`** —or as a named tool, Armazón row 27— the loading
engine (`cargar.mjs` without its Lavandería phases, `idiomas.php`,
`puente.php`, `tipos.mjs`) and the skeleton of the deployer with its
constants in a per-site `web.json`. Each site would keep only its
`content/semilla/` and that `web.json`. Until that is done, **a new site
copies Lavandería's scripts and changes the constants**, and a fix in one
has to be carried over to the other by hand (which is what the proposal
avoids).

**GAP — no seed template.** The pages of a new site are written or
designed from scratch (in Lavandería they came from the design kit); there
is no minimal seed (home, contact, legal pages) to start from.

## 7. Checklist before going live

- [ ] Plugin, parent and child at the `main` versions, packaged from HEAD
      (not from the working tree).
- [ ] Backup of the database and of `wp-content` (the deployer does it; by
      hand, before anything else).
- [ ] **Dry-run migrators read**: the Armazón one (old sites only) and the
      type prefix one (`--seco`), with no unexplained warnings.
- [ ] Modules: the ones from section 3, and none extra.
- [ ] Company data complete; types ticked; slugs that do not override
      pages.
- [ ] Cookie notice: categories declared (`maps` if there are maps), no
      third-party content before accepting (the deployer measures it with
      Playwright), policy table with every cookie.
- [ ] Legal pages linked in the footer and in the notice.
- [ ] Accessibility **measured**: `node E:/Repositories/atlas/bin/kit-accesibilidad.mjs <url> --contraste`
      (zero failures is not «compliant»: say it that way).
- [ ] Images in WebP and none broken (`comprobar-imagenes.mjs`).
- [ ] With WPML: `/en/` responds, the language switcher shows up, the
      «copy» fields of the branches reach the translation.
- [ ] Rank Math: sitemap with the visible types; titles per type.
- [ ] Forms: a test submission reaches the recipient.
- [ ] Full test suite of the plugin, the parent and the child green.
- [ ] David's OK, verbatim, for `desplegar.mjs --ok "<phrase>"`.

## 8. What Gastronòmicament needs and we do not have yet

Five gaps, each with a **proposal** (not done: it is decided and done when
the time comes). The criterion for deciding where each thing goes is the
Armazón one: if the next site would do it the same way without knowing what
it is about, it belongs in the plugin; if not, it belongs in the site.

### 8.1 Site-specific content types (restaurant, recipe, product, winery)

Ours (clients, testimonials, branches…) belong to any corporate site; a
«restaurant» with its cuisine and its price belongs to this site.
**Proposal: a site-specific plugin** (`gastronomicament-tipos`, in the
site's repo, next to the child theme), **not the child theme** (what is
stored cannot disappear when the theme changes: README, first section)
**nor the shared plugin** (the next site would not do it the same way). And
so it is not reinvented every time, the shared plugin gives it **the
mould**:

- Declare a type with the same shape as `Content_Types::definitions()`
  (labels, translatable slug, supports, taxonomy, METAS with their
  sanitising) through a filter, e.g. `estrategia_web_content_types`, and let
  the shared plugin do the rest: registering, `rewrite_slugs`, the fields
  panel (`Meta_Panel`), the WPML copy of the «copy» fields and its test.
- **The site's own prefix** in its names (`gm_restaurant`, metas
  `gastronomicament_…`): `eweb_` is only for the shared plugin's types.
- Integration, same as ours: Query loops by `"postType"`, fields through
  `core/post-meta`, its own `wpml-config.xml` at the root of THAT plugin
  (WPML reads it from every active plugin), and the rules in this guide
  (fingerprints, seed) do not change.
- **GAP**: the mould filter does not exist; today a site-specific type is
  registered by hand with `register_post_type` and has no fields panel or
  WPML copy.

### 8.2 IMPORTING what already exists (thousands of posts, media, old URLs)

The seed and `cargar.mjs` are for **creating** a site from files; here a
live site has to be **brought over**. **Default procedure for hosting
without SSH** (which is Raiola's case):

1. **Database**: backup through cPanel (Backup › database) or phpMyAdmin
   (Export). It is imported LOCALLY and it is the MAIN route: it keeps what
   the REST API does not provide, above all the **WPML translation
   relationships** (`icl_translations`), the settings and the metas that are
   not in REST.
2. **Photos** (`wp-content/uploads`, several GB): over **FTP with an
   incremental mirror** (`lftp -e "mirror --only-newer --parallel=4 …"`),
   with a cPanel FTP account **restricted to `wp-content/uploads`**. **Not a
   single zip**: with GB it breaks off, and it cannot be resumed.
3. The old site **keeps publishing** while it is rebuilt: on switchover
   day, a **final resync** of only what is new (another `mirror
   --only-newer` and the new database posts, by date).
4. On import, the `uploads` module (2560 px and WebP) **reduces the
   weight**: **measure before and after** (GB of `uploads`, average weight
   per photo) and write it down, since it is an argument for the client.
5. **Old URLs → `redirects`**: if they change (permalink structure,
   categories), export old and new ones and import the CSV in
   Estrategia Web › Redirects (`wp estrategia-web redirects import`).
6. The **REST API** (with an application password of a read-only user,
   kept outside the repo) is left for **live checking**: how many posts and
   media there are today, which custom types and taxonomies are registered,
   and whether it returns language and translations. **The trap**: core
   REST does NOT include the WPML relationships; depending on the WPML
   version and configuration they are exposed or not. That is why it is not
   the import route.

**First thing once you have access**: whether the plan has SSH (if it does,
`wp db export` and `rsync` replace steps 1 and 2), the WPML version, whether
REST returns `lang`/translations, the custom types and taxonomies registered
(by a plugin or by the theme: 8.1), and the number of posts and media
items. **GAP**: there is no importer; the proposal is a script in the site's
repo, idempotent and with `--seco`, that goes from the local copy to the new
structure (custom types, blocks) and writes the redirects CSV.

### 8.3 Newsletter («Subscriu-m'hi»)

**We have nothing.** Proposal: do **not** store subscribers in WordPress
(personal data, unsubscribes, sending: it is a whole service); a **form from
the `forms` module** that sends the sign-up to a newsletter service (Brevo,
Mailchimp, MailerLite…) through its API, with the checkbox consent and the
service's own double opt-in. It fits as one more `forms` action («on submit,
subscribe to list X»). **GAP**: which service it uses today, how many
subscribers it has and whether they are migrated (the data belongs to it
and its readers: that is the client's call under its GDPR).

### 8.4 Four languages and Catalan as the main one

- **WPML with four languages** (ca, es, en, de): only tested with two.
  What has to be checked: the switcher (`language-switcher`) with four, the
  translated home page (`wpml-front-page`), the loader (`cargar.mjs` has
  English hard-coded: `LANG = 'en'`) and translated slugs (the /en/ trap:
  URL rules are rebuilt with the request's language; README and
  `Prefix_Migration::run`).
- **Catalan as the main language**: the strings of the plugin, the parent
  and the child exist in `es_ES`; **`ca` is missing**. Proposal: `ca.po` in
  all three with `tools/i18n.mjs` (merges and compiles), in the **informal
  (tu) imperative** for buttons (David's rule for Catalan interfaces), and
  reviewed against David's vocabulary before going live.
- Type and page slugs are translated with gettext: on a live site, **pin
  them** (`rewrite_slugs`) before changing or adding languages.
- **GAP**: who translates into German and who reviews the Catalan.

### 8.5 Comments

Lavandería turns them off (`comments-off`). Here there are comments on
articles: the module is left **off** and the site uses WordPress comments.
Proposal: prior moderation (Settings › Discussion), no links, with the
privacy notice in the form. **GAP**: the parent theme's `single.html`
template **has no comments block** (measured on 15-10-2026): either it is
added in the parent (the next site with comments would do it the same way)
or in this site's child. **GAP**: how much spam it gets today and whether
it uses anything (Akismet or other) — checked with the database copy (8.2).

## How to maintain this guide

- **Who and when**: whoever sets up a site. Every step they had to ask
  about is written here in the same commit, and a **GAP** that gets closed
  is removed (with the commit that closes it).
- **Criterion**: what goes in is what would be done the same way on the
  next site without knowing what it is about (Armazón: «the structure
  travels, the content does not»). What belongs to a specific site goes in
  that site's repo.
- **Boundary case, by name**: the type slugs (`/clientes/`). They belong to
  the plugin and are pinned per site in `rewrite_slugs`; NOT in the child
  theme.
- **The trap**: this guide was written with ONE site built. Whatever it
  calls «generic» is a hypothesis until the second one; if something here
  did not hold, it is fixed here, not only in the new site.
