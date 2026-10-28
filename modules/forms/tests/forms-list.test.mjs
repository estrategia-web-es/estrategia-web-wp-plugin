// NIVEL: 2 — Playground WordPress. NOT LEVEL 1 BECAUSE: what is tested is what the «Forms → Forms» screen finds in a real database (pages, a synced pattern and the pages using it, drafts), the Forms menu WordPress builds, and the editor settings WordPress writes for a new form post. Read with a cookie session (tests/lib.mjs adminSession): the screen is plain HTML.
//
// What and why: includes/admin/class-forms-list.php. With WPML (one row per
// form in the default language) it is not tested here: the test WordPress
// has no WPML; collapse() is pure and documented in the class.
//
// ROOT CAUSE OF ITS RED DEPENDING ON THE ORDER (28-10-2026, «expected
// «Página, Borrador», got English»). The «(Página, Borrador)» and «(Patrón»
// of a row are NOT our texts: place() joins the post type's singular label
// and the post status' label, and both are WordPress CORE translations. The
// core es_ES language pack is not ours either: the test WordPress has it only
// when WordPress downloads it by itself (translations auto-update through
// cron, on some request, at a moment nobody controls: wp-content/languages/
// es_ES.l10n.php appears). And the locale itself depended on the order: this
// file used the CORE reset, which removes the forms en_US marker but not the
// locale i18n.test.mjs forces. So the labels were Spanish or English
// depending on which files ran before and on WordPress's own download; on a
// fresh WordPress the plugin's texts came out in Spanish (its own .l10n.php)
// and core's in English. The fix, in the test (core's translations are not
// ours to test): this module's seed() (en_US, deterministic), and what OUR
// code does is asserted — the type label and the status label joined as
// «(<type>, <status>)», no status for a published post — accepting either
// language of core's labels and of ours.
//
// Seen red (28-10-2026), every test: place() without the status (the draft row
// came out «(Page)»); place() adding the status to published posts too (the
// pattern's row); the list's submenu without its position 0 (the menu); and
// the allowed_block_types_all filter unhooked («Add form»).
// Seen red again after round 2 (28-10-2026), every test, with the mutants above (one by one where a test reads what the one before left).
import { test, before } from "node:test";
import assert from "node:assert/strict";
import { adminSession } from "../../../tests/lib.mjs";
import { seed } from "./lib.mjs";

const PATTERN_FORM = `<!-- wp:group --><div class="wp-block-group"><!-- wp:estrategia-web-forms/form {"formId":"presupuesto","title":"Presupuesto"} -->
<!-- wp:estrategia-web-forms/email {"label":"Correo","name":"email","required":true} /-->
<!-- wp:estrategia-web-forms/notification {"label":"Aviso al equipo","to":"equipo@ejemplo.test, {{email}}"} /-->
<!-- wp:estrategia-web-forms/notification {"to":"{{email}}"} /-->
<!-- wp:estrategia-web-forms/submit /-->
<!-- /wp:estrategia-web-forms/form --></div><!-- /wp:group -->`;

// Core's labels in either language (they are WordPress's, see the header).
const PAGE = "(?:Page|Página)";
const DRAFT = "(?:Draft|Borrador)";
const PATTERN = "(?:Pattern|Patrón)";

let s, pages, list, dash, submissionsScreen, editor;

before(async () => {
  [{ pages }, s] = await Promise.all([
    seed({
      pages: [
        { key: "pattern", type: "wp_block", slug: "formulario-de-presupuesto-lista", title: "Formulario de presupuesto", content: PATTERN_FORM },
        { key: "user", slug: "pide-presupuesto", title: "Pide presupuesto", content: `<!-- wp:paragraph --><p>Pide presupuesto</p><!-- /wp:paragraph --><!-- wp:block {"ref":{{id:pattern}}} /-->` },
        { key: "draft", slug: "contacto-borrador-lista", title: "Contacto (borrador)", status: "draft", content: '<!-- wp:estrategia-web-forms/form {"formId":"contacto","title":"Contacto"} --><!-- wp:estrategia-web-forms/submit /--><!-- /wp:estrategia-web-forms/form -->' },
        // Mentions the block name in TEXT: the LIKE finds it, parse_blocks() must not.
        { key: "mention", slug: "ayuda-lista", title: "Ayuda", content: "<!-- wp:paragraph --><p>Usa el bloque &lt;!-- wp:estrategia-web-forms/form --&gt;</p><!-- /wp:paragraph -->" },
      ],
    }),
    adminSession(),
  ]);
  // Every screen of the file at once (round 2, 28-10-2026); each test asserts its own.
  [list, dash, submissionsScreen, editor] = await Promise.all([
    s.get("/wp-admin/admin.php?page=estrategia-web-forms-list").then((r) => r.text),
    s.get("/wp-admin/index.php"),
    s.get("/wp-admin/admin.php?page=estrategia-web-forms"),
    s.get("/wp-admin/post-new.php?post_type=eweb_form").then((r) => r.text),
  ]);
});

const row = (postId) => {
  const m = list.match(new RegExp(`<tr data-form-id="[^"]*" data-post-id="${postId}">[\\s\\S]*?</tr>`));
  assert.ok(m, `no row for post ${postId}`);
  return m[0];
};

test("the Forms menu opens on the list; Submissions keeps its URL", () => {
  const submissions = submissionsScreen;
  const li = dash.text.match(/<li[^>]*id="toplevel_page_estrategia-web-forms"[\s\S]*?<\/ul>/)[0];
  const links = [...li.matchAll(/<a href=['"]admin\.php\?page=([^'"&]+)/g)].map((x) => x[1]);
  assert.deepEqual([...new Set(links)], ["estrategia-web-forms-list", "estrategia-web-forms", "estrategia-web-forms-settings"]);
  assert.equal(submissions.status, 200, "Submissions");
});

test("a form inside a synced pattern: its name, identifier, notifications, and the pages that use it", () => {
  const r = row(pages.pattern.id);
  assert.match(r, /data-form-id="presupuesto"/);
  assert.match(r, /estrategia-web-forms-list__title">Presupuesto</);
  assert.match(r, new RegExp(`Formulario de presupuesto</strong> <span class="description">\\(${PATTERN}\\)</span>`), "where: the pattern, with its type and no status (it is published)");
  assert.match(r, new RegExp(`post\\.php\\?post=${pages.pattern.id}&(amp;|#038;)?action=edit`), "an Edit link to the pattern");
  assert.match(r, new RegExp(`<li data-post-id="${pages.user.id}">`), "the page that uses it");
  assert.match(r, /2 (avisos|notifications)/, "how many notifications");
  assert.match(r, /Aviso al equipo[\s\S]*?<code>equipo@ejemplo\.test, \{\{email\}\}<\/code>/, "label and recipients as written, markers included");
  assert.match(r, /(Aviso|Notification) 2[\s\S]*?<code>\{\{email\}\}<\/code>/, "a notification without a label is numbered");
});

// 2.0.0 (class-form-post-type.php). Moved down from admin.test.mjs (level 3) on 28-10-2026: the
// template and the allowed blocks are settings the server writes into the editor page; what the
// editor does with a template is WordPress's (the Form block's own inner template is tested in
// admin.test.mjs). Seen red (28-10-2026, and on the first day at level 3) with the
// allowed_block_types_all filter unhooked: the reference block was allowed inside a form post.
test("«Add form»: the form post's editor gets the locked Form block as its template, and no reference block (a form inside a form)", () => {
  const add = list.match(/<a class="page-title-action" href="([^"]+)"/)[1].replace(/&#038;|&amp;/g, "&");
  assert.match(add, /post-new\.php\?post_type=eweb_form$/, "«Add form» opens the editor read in before()");
  assert.match(editor, /"template":\[\["estrategia-web-forms\\?\/form",\{"lock":\{"move":true,"remove":true\}\}\]\],"templateLock":false/, "the template: one Form block that cannot be moved or removed");
  const setting = editor.match(/"allowedBlockTypes":(\[[^\]]*\])/);
  assert.ok(setting, "allowedBlockTypes is not a list: every block is allowed, the reference block included");
  const allowed = JSON.parse(setting[1]);
  assert.ok(allowed.includes("estrategia-web-forms/form"));
  assert.ok(!allowed.includes("estrategia-web-forms/form-ref"), "a form inside a form");
});

test("drafts are listed (with their status); a page that only MENTIONS the block is not; a page using the pattern is not a form row itself", () => {
  assert.match(row(pages.draft.id), new RegExp(`Contacto \\(borrador\\)</strong> <span class="description">\\(${PAGE}, ${DRAFT}\\)</span>`), "the type label and the status label, joined");
  assert.ok(!list.includes(`data-post-id="${pages.mention.id}">`), "the mention is not a form");
  assert.ok(!new RegExp(`<tr data-form-id="[^"]*" data-post-id="${pages.user.id}">`).test(list), "the user page is under the pattern, not a row");
});
