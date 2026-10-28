// NIVEL: 2 — Playground WordPress. NOT LEVEL 1 BECAUSE: the migrator READS and WRITES the database (posts, the new form posts, the backup meta) and what is checked is what WordPress then renders, accepts and lists. Its plan, without WordPress, is level 1 (form-migration-plan.test.mjs); with WPML, form-migration-wpml.test.mjs.
//
// wp estrategia-web migrate-forms (2.0.0, class-form-migration.php), run
// here through the test route /migrate-forms (the same Form_Migration::run()
// the WP-CLI command calls), limited to this test's posts (ids) so that the
// inline forms other tests leave in the shared WordPress are not touched.
// The site: a contact page and a COPY of it in another page (same formId),
// a quote form inside a synced pattern and a page using that pattern with
// its dropdown preselected through the pattern's «content», and one
// submission sent BEFORE migrating. Then:
//  1. dry run: what it would create and change, the copy reported as
//     identical, and nothing written;
//  2. real run: two form posts (not three: the copy is the same form), each
//     tree replaced by the reference byte for byte around it, the original
//     content in the backup meta, the page using the pattern untouched;
//  3. after: the pages render the same form, the preselection still works,
//     a new submission lands with the old one under the same identifier, and
//     «Forms → Forms» lists the form posts with where they are used;
//  4. second run: «ya», nothing created or changed.
// The site is seeded in ONE request (seed(): it also drops the pages' old
// backups, so «one backup» is exact), and each migration answers, in the same
// request, the four posts' contents and backups and the form posts it left
// (/migrate-forms with «read») (28-10-2026: from ~50 requests to ~12). Round 2:
// the submission from before is sent at once with the dry run, and the list
// screen read at once with the new submission, which answers the log itself
// (8 rounds instead of 11).
//
// Seen red (27-10-2026): without the add_post_meta() of the backup, step 2
// fails («the original content is kept»); restored, green. Seen red
// (28-10-2026), every test: the dry run inserting the form posts (step 1, «no
// form post created»); and at once, without the backup (step 2), the form
// post rendered with do_blocks() (step 3, the preselection), «Forms → Forms»
// without «Add form» (the list) and run() never answering «ya» (step 4).
// Seen red again after round 2 (28-10-2026), every test, with the mutants above (one by one where a test reads what the one before left).
import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { seed, loadForm, submit, GOOD, FORM, waitMinTime, testApi } from "./lib.mjs";
import { adminSession } from "../../../tests/lib.mjs";

const QUOTE = `<!-- wp:estrategia-web-forms/form {"formId":"presupuesto-mig","title":"Presupuesto"} -->
<!-- wp:estrategia-web-forms/email {"label":"Correo","name":"email","required":true} /-->
<!-- wp:estrategia-web-forms/select {"label":"Servicio","name":"servicio","options":"Lavado\\nPlanchado"} /-->
<!-- wp:estrategia-web-forms/notification {"label":"Aviso al equipo","to":"equipo@ejemplo.test"} /-->
<!-- wp:estrategia-web-forms/submit /-->
<!-- /wp:estrategia-web-forms/form -->`;
const BEFORE = "<!-- wp:paragraph -->\n<p>Antes del formulario</p>\n<!-- /wp:paragraph -->\n\n";
const AFTER = "\n\n<!-- wp:paragraph -->\n<p>Después</p>\n<!-- /wp:paragraph -->";

let page, copy, pattern, user, ids, originals, forms, migrated;
/** The migration, and in the same request what it left: [report, { id: {content, backups} } of the four posts, every form post]. */
const migrate = async (dry) => {
  const r = await testApi("/migrate-forms", { method: "POST", body: { dry, ids, read: true } });
  return [r, r.after.posts, r.after.forms];
};
const contentsOf = (posts) => ids.map((id) => posts[id].content);

let session;
before(async () => {
  let pages;
  [{ pages }, session] = await Promise.all([seed({
    pages: [
      { key: "page", slug: "mig-contacto", title: "Contacto", content: BEFORE + FORM + AFTER },
      { key: "copy", slug: "mig-contacto-copia", title: "Contacto", content: FORM },
      { key: "pattern", type: "wp_block", slug: "mig-presupuesto", title: "Formulario de presupuesto (mig)", content: `<!-- wp:group --><div class="wp-block-group">${QUOTE}</div><!-- /wp:group -->` },
      { key: "user", slug: "mig-planchado", title: "Planchado", content: `<!-- wp:block {"ref":{{id:pattern}},"content":{"servicio":{"defaultOption":2}}} /-->` },
    ],
  }), adminSession()]);
  ({ page, copy, pattern, user } = pages);
  ids = [page.id, copy.id, pattern.id, user.id];
  originals = Object.fromEntries([page, copy, pattern, user].map((p) => [p.id, p.content]));
  // The form a submission from before the migration is sent with (at once with the dry run, below).
  ({ hidden: before0 } = await loadForm(page.url));
  await waitMinTime();
});
let before0, list;
// The form posts are not undone by the core reset that the next file (of another module) runs.
after(() => testApi("/eweb-forms", { method: "DELETE" }));

test("dry run: two form posts to create, three posts to change, the copy reported as identical, nothing written", async () => {
  // At once, the submission from before the migration (the dry run neither reads nor writes the log).
  const [[r, posts, formPosts], early] = await Promise.all([migrate(true), submit(before0, GOOD)]);
  assert.equal(early.status, 200, "the submission from before the migration");
  assert.equal(r.seco, true);
  assert.equal(r.ya, false);
  assert.deepEqual(r.formularios.map((f) => [f.form_id, f.accion, f.origen, f.copias, f.iguales]), [
    ["contacto", "create", page.id, [copy.id], true],
    ["presupuesto-mig", "create", pattern.id, [], true],
  ]);
  assert.deepEqual(r.contenido.map((c) => c.id).sort((a, b) => a - b), [page.id, copy.id, pattern.id].sort((a, b) => a - b), "the page that only uses the pattern is not changed");
  assert.ok(r.avisos.some((a) => a.includes("«contacto» is in 2 posts") && a.includes("identical")), r.avisos.join("\n"));
  assert.match(r.resumen, /^DRY RUN: 2 form post\(s\) to create, 0 reused; 3 post\(s\) to change/);
  assert.deepEqual(formPosts, [], "no form post created");
  for (const id of ids) {
    assert.equal(posts[id].content, originals[id], `post ${id} untouched`);
    assert.deepEqual(posts[id].backups, [], `post ${id}: no backup`);
  }
});

test("real run: the form posts, each tree replaced byte for byte around it, the original in the backup meta", async () => {
  const [r, posts, formPosts] = await migrate(false);
  forms = formPosts;
  assert.equal(r.seco, false);
  migrated = contentsOf(posts);
  assert.equal(forms.length, 2, "contacto and presupuesto-mig: the copy is the same form");
  const contact = forms.find((f) => f.content.includes('"formId":"contacto"'));
  const quote = forms.find((f) => f.content.includes('"formId":"presupuesto-mig"'));
  assert.equal(contact.content, FORM, "the form post holds the tree as it was");
  assert.equal(contact.title, "Contacto", "titled after the block's name in the log");
  assert.equal(quote.content, QUOTE);
  assert.equal(contact.status, "publish");

  const tag = (id) => `<!-- wp:estrategia-web-forms/form-ref {"ref":${id}} /-->`;
  assert.equal(posts[page.id].content, BEFORE + tag(contact.id) + AFTER);
  assert.equal(posts[copy.id].content, tag(contact.id), "the copy points to the same form post");
  assert.equal(posts[pattern.id].content, `<!-- wp:group --><div class="wp-block-group">${tag(quote.id)}</div><!-- /wp:group -->`);
  assert.equal(posts[user.id].content, originals[user.id], "the page using the pattern is untouched");
  for (const id of [page.id, copy.id, pattern.id]) {
    assert.deepEqual(posts[id].backups, [originals[id]], `the original content is kept, once (post ${id})`);
  }
});

test("after: the same form renders, the preselection holds, and a new submission joins the old one", async () => {
  const [{ html, hidden }, used] = await Promise.all([loadForm(page.url), loadForm(user.url)]);
  assert.match(html, /<p[^>]*>Antes del formulario<\/p>[\s\S]*name="fields\[nombre\]"[\s\S]*<p[^>]*>Después<\/p>/);
  assert.match(used.html, /<option value="Planchado" selected>/, "pattern → reference → form, with the page's «content»");
  await waitMinTime();
  // At once, «Forms → Forms» for the next test; the submission answers the log itself.
  let sent;
  [sent, { text: list }] = await Promise.all([submit(hidden, GOOD, { state: true }), session.get("/wp-admin/admin.php?page=estrategia-web-forms-list")]);
  assert.equal(sent.status, 200);
  const rows = sent.state.submissions.filter((s) => s.form_id === "contacto");
  assert.equal(rows.length, 2, "the one from before and the new one, under the same identifier");
});

test("«Forms → Forms»: the form posts first, with where they are used (and the pattern's pages)", () => {
  const contact = forms.find((f) => f.content.includes('"formId":"contacto"'));
  const quote = forms.find((f) => f.content.includes('"formId":"presupuesto-mig"'));
  const row = (id) => list.match(new RegExp(`<tr data-form-id="[^"]*" data-post-id="${id}">[\\s\\S]*?</tr>`))?.[0] ?? "";
  assert.match(list, /post-new\.php\?post_type=eweb_form/, "«Add form»");
  assert.match(row(contact.id), new RegExp(`<li data-post-id="${page.id}">`), "used in the contact page");
  assert.match(row(contact.id), new RegExp(`<li data-post-id="${copy.id}">`), "and in its copy");
  assert.match(row(quote.id), new RegExp(`<li data-post-id="${pattern.id}">[\\s\\S]*<li data-post-id="${user.id}">`), "the pattern, and under it the page that uses it");
  assert.match(row(quote.id), /1 (aviso|notification)\b/, "its notification");
  for (const id of [page.id, copy.id, pattern.id]) assert.equal(row(id), "", `post ${id} no longer has a form of its own`);
});

test("second run: «ya», nothing created or changed", async () => {
  const [r, posts, formPosts] = await migrate(false);
  assert.equal(r.ya, true);
  assert.match(r.resumen, /^ya/);
  assert.deepEqual(r.formularios, []);
  assert.equal(formPosts.length, 2);
  assert.deepEqual(contentsOf(posts), migrated);
});
