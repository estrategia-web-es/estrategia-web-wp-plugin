// NIVEL: 2 — Playground WordPress, because what is tested is what WordPress RENDERS for a «Formulario» reference block pointing to a form post (eweb_form), what the submission endpoint does with it (it WRITES: the log, the intercepted emails) and who the REST API lets read form posts. Not level 1: the render goes through WP_Block, block context and the Interactivity API. Not level 3: no browser behaviour is involved (browser.test.mjs covers the form's JS, which is the same markup).
//
// THE REFERENCE RENDERS AND SUBMITS EXACTLY LIKE THE INLINE FORM (2.0.0,
// modules/forms/includes/class-form-post-type.php and
// Blocks::render_form_ref()). The same tree (lib.mjs FORM) as a form post
// shown through a reference, and as an inline form in another page, and:
//  1. the <form> markup is the same once the three per-page hidden values
//     are taken out (definition hash, token, page url);
//  2. a submission through the reference is logged and notifies like
//     server.test.mjs's (same form id, the REFERENCING page as the page);
//  3. without JS, the 303 goes back to the referencing page with its errors;
//  4. inside a synced pattern, the page's preselection
//     ({"servicio":{"defaultOption":2}}) still reaches the dropdown: the
//     reference passes the block context on (Lavandería's quote form);
//  5. a draft form, a reference to nothing or to a page render nothing;
//  6. /wp/v2/eweb-forms answers only to someone who can edit pages.
//
// Seen red (27-10-2026): rendering the form post with do_blocks() instead of
// as the block's inner blocks, step 4 fails (the dropdown arrives with no
// option selected: the pattern's context does not reach it); restored, green.
import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { reset, seedPage, loadForm, submit, submissions, mails, GOOD, TEAM, FAIL, FORM, waitMinTime, testApi, BASE } from "./lib.mjs";
import { wp } from "../../../tests/lib.mjs";

const SITE = BASE.replace(/[.*+?^${}()|[\]\\/]/g, "\\$&");
const ref = (id) => `<!-- wp:estrategia-web-forms/form-ref {"ref":${id}} /-->`;
const formOf = (html) => (html.match(/<form [\s\S]*?<\/form>/) || [""])[0];
const withoutPerPage = (html) => formOf(html).replace(/(name="_af_(?:def|token|page)" value=")[^"]*"/g, '$1"');

let form, refPage, inline, pattern, patternUser;

before(async () => {
  await reset();
  await testApi("/eweb-forms", { method: "DELETE" });
  form = (await testApi("/eweb-form", { method: "POST", body: { title: "Contacto", content: FORM } })).id;
  refPage = await seedPage(`<!-- wp:paragraph --><p>Escríbenos</p><!-- /wp:paragraph -->\n${ref(form)}`, "contacto-ref");
  inline = await seedPage(FORM, "contacto-inline");
});
after(async () => {
  await testApi("/eweb-forms", { method: "DELETE" });
});

test("the reference renders the same form as the inline tree (but for the per-page hidden values)", async () => {
  const a = withoutPerPage((await loadForm(refPage.url)).html);
  const b = withoutPerPage((await loadForm(inline.url)).html);
  assert.match(a, /name="fields\[nombre\]"/, "the form is rendered through the reference");
  assert.equal(a, b);
  const { html } = await loadForm(refPage.url);
  for (const secret of [TEAM, FAIL, "Aviso al equipo", "{{"]) assert.ok(!html.includes(secret), `the public HTML contains «${secret}»`);
});

test("a submission through the reference: logged with the form's id and the referencing page, notifications sent", async () => {
  await reset();
  const { hidden } = await loadForm(refPage.url);
  await waitMinTime();
  const r = await submit(hidden, GOOD);
  assert.equal(r.status, 200);
  assert.equal(r.json.ok, true);
  const [row] = await submissions();
  assert.equal(row.form_id, "contacto", "the same identifier: the log does not split");
  assert.equal(row.form_title, "Contacto");
  assert.equal(row.page_url, refPage.url);
  assert.equal(Number(row.post_id), refPage.id, "the page being viewed, not the form post");
  assert.equal(row.status, "partial");
  assert.deepEqual(row.notifications.map((n) => [n.to, n.status]), [[TEAM, "sent"], ["ana@ejemplo.test", "sent"], [FAIL, "failed"]]);
  const [team] = await mails();
  assert.equal(team.subject, "Nuevo mensaje de Ana");
  assert.match(team.html, new RegExp(`Desde ${SITE}/contacto-ref/`));
});

test("without JS: 303 back to the referencing page, with its errors and what was typed", async () => {
  await reset();
  const { hidden } = await loadForm(refPage.url);
  await waitMinTime();
  const r = await submit(hidden, { ...GOOD, mensaje: "" }, { js: false });
  assert.equal(r.status, 303);
  assert.match(r.location, new RegExp(`^${SITE}/contacto-ref/\\?af_result=[a-z0-9]{20}#af-contacto-status$`));
  const html = await (await fetch(r.location)).text();
  assert.match(html, /aria-invalid="true"/);
  assert.match(html, /value="Ana"/);
});

test("inside a synced pattern, the page's preselection reaches the dropdown through the reference", async () => {
  pattern = await testApi("/page", { method: "POST", body: { type: "wp_block", slug: "formulario-ref", title: "Formulario ref", content: ref(form) } });
  patternUser = await seedPage(`<!-- wp:block {"ref":${pattern.id},"content":{"servicio":{"defaultOption":2}}} /-->`, "planchado-ref");
  const { html } = await loadForm(patternUser.url);
  assert.match(html, /name="fields\[nombre\]"/, "pattern → reference → form");
  assert.match(html, /<option value="Planchado" selected>/, "the second option, from the page's «content»");
  assert.doesNotMatch((await loadForm(refPage.url)).html, / selected>/, "and only there");
});

test("a draft form, a reference to nothing and a reference to a page render nothing", async () => {
  const draft = (await testApi("/eweb-form", { method: "POST", body: { title: "Borrador", content: FORM, status: "draft" } })).id;
  const page = await seedPage(`<p>antes</p>${ref(draft)}${ref(0)}${ref(999999)}${ref(refPage.id)}<p>después</p>`, "ref-vacias");
  const html = await (await fetch(page.url)).text();
  assert.match(html, /<p>antes<\/p><p>después<\/p>/, "nothing between the two paragraphs");
  assert.doesNotMatch(html, /<form [^>]*estrategia-web-forms/);
});

test("/wp/v2/eweb-forms: only for someone who can edit pages", async () => {
  assert.equal((await wp("/eweb-forms", { as: null })).status, 401, "anonymous");
  assert.equal((await wp(`/eweb-forms/${form}`, { as: null })).status, 401, "anonymous, one form");
  assert.equal((await wp("/eweb-forms", { as: "author" })).status, 403, "an author cannot edit pages");
  const admin = await wp("/eweb-forms?context=edit");
  assert.equal(admin.status, 200);
  assert.ok(admin.json.some((f) => f.id === form && /formId/.test(f.content.raw)), "the administrator reads the tree");
});
