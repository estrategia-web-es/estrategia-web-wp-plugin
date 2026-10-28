// NIVEL: 2 — Playground WordPress. NOT LEVEL 1 BECAUSE: what is tested is what WordPress RENDERS for a «Formulario» reference block pointing to a form post (eweb_form) — WP_Block, block context through a synced pattern, the Interactivity API markup —, what the submission endpoint does with it (it WRITES: the log, the intercepted emails) and who the REST API lets read form posts. Not level 3: no browser behaviour is involved (browser.test.mjs covers the form's JS, which is the same markup).
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
// Everything is seeded in ONE request (seed() in lib.mjs) and each page is
// loaded once (28-10-2026: from ~30 requests to ~13). Round 2: the four
// pages are read at once in the first test, the JS and no-JS submissions go
// together, and the log with the no-JS return page (7 rounds instead of 11).
//
// Seen red (27-10-2026): rendering the form post with do_blocks() instead of
// as the block's inner blocks, step 4 fails (the dropdown arrives with no
// option selected: the pattern's context does not reach it); restored, green.
// Seen red (28-10-2026), every test, with these mutants at once: a «{{email}}»
// comment in the rendered form (1), the definition's post_id as 0 (2), the
// 303 without its «-status» anchor (3), the form post rendered with
// do_blocks() (4), resolve() accepting drafts (5) and the REST guard asking
// for «read» instead of «edit_pages» (6).
// Seen red again after round 2 (28-10-2026), every test, with the mutants above (one by one where a test reads what the one before left).
import { test, before } from "node:test";
import assert from "node:assert/strict";
import { seed, loadForm, submit, state, GOOD, TEAM, FAIL, FORM, BASE } from "./lib.mjs";
import { wp, credentials } from "../../../tests/lib.mjs";

const SITE = BASE.replace(/[.*+?^${}()|[\]\\/]/g, "\\$&");
const ref = (key) => `<!-- wp:estrategia-web-forms/form-ref {"ref":{{id:${key}}}} /-->`;
const formOf = (html) => (html.match(/<form [\s\S]*?<\/form>/) || [""])[0];
const withoutPerPage = (html) => formOf(html).replace(/(name="_af_(?:def|token|page)" value=")[^"]*"/g, '$1"');

let form, refPage, inline, patternUser, empty, viaRef;

before(async () => {
  const [s] = await Promise.all([seed({
    forms: [
      { key: "form", title: "Contacto", content: FORM },
      { key: "draft", title: "Borrador", content: FORM, status: "draft" },
    ],
    pages: [
      { key: "ref", slug: "contacto-ref", title: "Contacto", content: `<!-- wp:paragraph --><p>Escríbenos</p><!-- /wp:paragraph -->\n${ref("form")}` },
      { key: "inline", slug: "contacto-inline", title: "Contacto", content: FORM },
      { key: "pattern", type: "wp_block", slug: "formulario-ref", title: "Formulario ref", content: ref("form") },
      { key: "user", slug: "planchado-ref", title: "Planchado", content: `<!-- wp:block {"ref":{{id:pattern}},"content":{"servicio":{"defaultOption":2}}} /-->` },
      { key: "empty", slug: "ref-vacias", title: "Vacías", content: `<p>antes</p>${ref("draft")}<!-- wp:estrategia-web-forms/form-ref {"ref":0} /--><!-- wp:estrategia-web-forms/form-ref {"ref":999999} /-->${ref("ref")}<p>después</p>` },
    ],
  }), credentials()]); // The users for the last test, at once.
  form = s.forms.form;
  ({ ref: refPage, inline, user: patternUser, empty } = s.pages);
});

let viaPattern, emptyHtml, noJs, noJsBack, logged;
test("the reference renders the same form as the inline tree (but for the per-page hidden values)", async () => {
  // Every page of the file is read here, at once (round 2, 28-10-2026); each test asserts its own.
  const [a, b, c, d] = await Promise.all([loadForm(refPage.url), loadForm(inline.url), loadForm(patternUser.url), fetch(empty.url).then((r) => r.text())]);
  [viaRef, viaPattern, emptyHtml] = [a, c, d];
  assert.match(a.html, /name="fields\[nombre\]"/, "the form is rendered through the reference");
  assert.equal(withoutPerPage(a.html), withoutPerPage(b.html));
  for (const secret of [TEAM, FAIL, "Aviso al equipo", "{{"]) assert.ok(!a.html.includes(secret), `the public HTML contains «${secret}»`);
  assert.doesNotMatch(a.html, / selected>/, "no preselection outside the pattern (step 4's control)");
});

test("a submission through the reference: logged with the form's id and the referencing page, notifications sent", async () => {
  // At once, the next test's no-JS submission (an error: it neither logs nor sends), and then its return page.
  const [r, back] = await Promise.all([submit(viaRef.hidden, GOOD, { state: true }), submit(viaRef.hidden, { ...GOOD, mensaje: "" }, { js: false })]);
  noJs = back;
  logged = r.state; // The emails and the log, in the submission's own answer.
  noJsBack = noJs.status === 303 ? await (await fetch(noJs.location)).text() : "";
  assert.equal(r.status, 200);
  assert.equal(r.json.ok, true);
  const { submissions: [row], mails: [team] } = logged;
  assert.equal(row.form_id, "contacto", "the same identifier: the log does not split");
  assert.equal(row.form_title, "Contacto");
  assert.equal(row.page_url, refPage.url);
  assert.equal(Number(row.post_id), refPage.id, "the page being viewed, not the form post");
  assert.equal(row.status, "partial");
  assert.deepEqual(row.notifications.map((n) => [n.to, n.status]), [[TEAM, "sent"], ["ana@ejemplo.test", "sent"], [FAIL, "failed"]]);
  assert.equal(team.subject, "Nuevo mensaje de Ana");
  assert.match(team.html, new RegExp(`Desde ${SITE}/contacto-ref/`));
});

test("without JS: 303 back to the referencing page, with its errors and what was typed", () => {
  assert.equal(noJs.status, 303);
  assert.match(noJs.location, new RegExp(`^${SITE}/contacto-ref/\\?af_result=[a-z0-9]{20}#af-contacto-status$`));
  assert.match(noJsBack, /aria-invalid="true"/);
  assert.match(noJsBack, /value="Ana"/);
});

test("inside a synced pattern, the page's preselection reaches the dropdown through the reference", () => {
  const { html } = viaPattern;
  assert.match(html, /name="fields\[nombre\]"/, "pattern → reference → form");
  assert.match(html, /<option value="Planchado" selected>/, "the second option, from the page's «content»");
});

test("a draft form, a reference to nothing and a reference to a page render nothing", () => {
  const html = emptyHtml;
  assert.match(html, /<p>antes<\/p><p>después<\/p>/, "nothing between the two paragraphs");
  assert.doesNotMatch(html, /<form [^>]*estrategia-web-forms/);
});

test("/wp/v2/eweb-forms: only for someone who can edit pages", async () => {
  const [anonymous, one, author, admin] = await Promise.all([wp("/eweb-forms", { as: null }), wp(`/eweb-forms/${form}`, { as: null }), wp("/eweb-forms", { as: "author" }), wp("/eweb-forms?context=edit")]);
  assert.equal(anonymous.status, 401, "anonymous");
  assert.equal(one.status, 401, "anonymous, one form");
  assert.equal(author.status, 403, "an author cannot edit pages");
  assert.equal(admin.status, 200);
  assert.ok(admin.json.some((f) => f.id === form && /formId/.test(f.content.raw)), "the administrator reads the tree");
});
