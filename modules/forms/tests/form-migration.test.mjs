// NIVEL: 2 — Playground WordPress, because the migrator READS and WRITES the database (posts, the new form posts, the backup meta) and what is checked is what WordPress then renders and accepts. Its plan, without WordPress, is level 1 (form-migration-plan.test.mjs); with WPML, form-migration-wpml.test.mjs.
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
//
// Seen red (27-10-2026): without the add_post_meta() of the backup, step 2
// fails («the original content is kept»); restored, green.
import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { reset, seedPage, loadForm, submit, submissions, GOOD, FORM, waitMinTime, testApi } from "./lib.mjs";
import { adminSession } from "../../../tests/lib.mjs";

const QUOTE = `<!-- wp:estrategia-web-forms/form {"formId":"presupuesto-mig","title":"Presupuesto"} -->
<!-- wp:estrategia-web-forms/email {"label":"Correo","name":"email","required":true} /-->
<!-- wp:estrategia-web-forms/select {"label":"Servicio","name":"servicio","options":"Lavado\\nPlanchado"} /-->
<!-- wp:estrategia-web-forms/notification {"label":"Aviso al equipo","to":"equipo@ejemplo.test"} /-->
<!-- wp:estrategia-web-forms/submit /-->
<!-- /wp:estrategia-web-forms/form -->`;
const BEFORE = "<!-- wp:paragraph -->\n<p>Antes del formulario</p>\n<!-- /wp:paragraph -->\n\n";
const AFTER = "\n\n<!-- wp:paragraph -->\n<p>Después</p>\n<!-- /wp:paragraph -->";

let page, copy, pattern, user, ids, originals, backups;
const migrate = (dry) => testApi("/migrate-forms", { method: "POST", body: { dry, ids } });
const content = async (id) => (await testApi(`/post/${id}`)).content;

before(async () => {
  await reset();
  await testApi("/eweb-forms", { method: "DELETE" });
  page = await seedPage(BEFORE + FORM + AFTER, "mig-contacto");
  copy = await seedPage(FORM, "mig-contacto-copia");
  pattern = await testApi("/page", { method: "POST", body: { type: "wp_block", slug: "mig-presupuesto", title: "Formulario de presupuesto (mig)", content: `<!-- wp:group --><div class="wp-block-group">${QUOTE}</div><!-- /wp:group -->` } });
  user = await seedPage(`<!-- wp:block {"ref":${pattern.id},"content":{"servicio":{"defaultOption":2}}} /-->`, "mig-planchado");
  ids = [page.id, copy.id, pattern.id, user.id];
  originals = Object.fromEntries(await Promise.all(ids.map(async (id) => [id, await content(id)])));
  backups = Object.fromEntries(await Promise.all(ids.map(async (id) => [id, (await testApi(`/backup/${id}`)).length])));
  // A submission from before the migration.
  const { hidden } = await loadForm(page.url);
  await waitMinTime();
  assert.equal((await submit(hidden, GOOD)).status, 200);
});
after(async () => {
  await testApi("/eweb-forms", { method: "DELETE" });
});

test("dry run: two form posts to create, three posts to change, the copy reported as identical, nothing written", async () => {
  const r = await migrate(true);
  assert.equal(r.seco, true);
  assert.equal(r.ya, false);
  assert.deepEqual(r.formularios.map((f) => [f.form_id, f.accion, f.origen, f.copias, f.iguales]), [
    ["contacto", "create", page.id, [copy.id], true],
    ["presupuesto-mig", "create", pattern.id, [], true],
  ]);
  assert.deepEqual(r.contenido.map((c) => c.id).sort((a, b) => a - b), [page.id, copy.id, pattern.id].sort((a, b) => a - b), "the page that only uses the pattern is not changed");
  assert.ok(r.avisos.some((a) => a.includes("«contacto» is in 2 posts") && a.includes("identical")), r.avisos.join("\n"));
  assert.match(r.resumen, /^DRY RUN: 2 form post\(s\) to create, 0 reused; 3 post\(s\) to change/);
  assert.deepEqual(await testApi("/eweb-forms"), [], "no form post created");
  for (const id of ids) assert.equal(await content(id), originals[id], `post ${id} untouched`);
});

test("real run: the form posts, each tree replaced byte for byte around it, the original in the backup meta", async () => {
  const r = await migrate(false);
  assert.equal(r.seco, false);
  const forms = await testApi("/eweb-forms");
  assert.equal(forms.length, 2, "contacto and presupuesto-mig: the copy is the same form");
  const contact = forms.find((f) => f.content.includes('"formId":"contacto"'));
  const quote = forms.find((f) => f.content.includes('"formId":"presupuesto-mig"'));
  assert.equal(contact.content, FORM, "the form post holds the tree as it was");
  assert.equal(contact.title, "Contacto", "titled after the block's name in the log");
  assert.equal(quote.content, QUOTE);
  assert.equal(contact.status, "publish");

  const tag = (id) => `<!-- wp:estrategia-web-forms/form-ref {"ref":${id}} /-->`;
  assert.equal(await content(page.id), BEFORE + tag(contact.id) + AFTER);
  assert.equal(await content(copy.id), tag(contact.id), "the copy points to the same form post");
  assert.equal(await content(pattern.id), `<!-- wp:group --><div class="wp-block-group">${tag(quote.id)}</div><!-- /wp:group -->`);
  assert.equal(await content(user.id), originals[user.id], "the page using the pattern is untouched");
  for (const id of [page.id, copy.id, pattern.id]) {
    // ONE MORE row, the last: a re-run of this test on the same WordPress re-seeds the same pages, and each migration adds one.
    const rows = await testApi(`/backup/${id}`);
    assert.equal(rows.length, backups[id] + 1, `one backup row more (post ${id})`);
    assert.equal(rows.at(-1), originals[id], `the original content is kept (post ${id})`);
  }
});

test("after: the same form renders, the preselection holds, and a new submission joins the old one", async () => {
  const { html, hidden } = await loadForm(page.url);
  assert.match(html, /<p[^>]*>Antes del formulario<\/p>[\s\S]*name="fields\[nombre\]"[\s\S]*<p[^>]*>Después<\/p>/);
  assert.match((await loadForm(user.url)).html, /<option value="Planchado" selected>/, "pattern → reference → form, with the page's «content»");
  await waitMinTime();
  assert.equal((await submit(hidden, GOOD)).status, 200);
  const rows = (await submissions()).filter((s) => s.form_id === "contacto");
  assert.equal(rows.length, 2, "the one from before and the new one, under the same identifier");
});

test("«Forms → Forms»: the form posts first, with where they are used (and the pattern's pages)", async () => {
  const s = await adminSession();
  const list = (await s.get("/wp-admin/admin.php?page=estrategia-web-forms-list")).text;
  const forms = await testApi("/eweb-forms");
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
  const before = await Promise.all(ids.map(content));
  const r = await migrate(false);
  assert.equal(r.ya, true);
  assert.match(r.resumen, /^ya/);
  assert.deepEqual(r.formularios, []);
  assert.equal((await testApi("/eweb-forms")).length, 2);
  assert.deepEqual(await Promise.all(ids.map(content)), before);
});
