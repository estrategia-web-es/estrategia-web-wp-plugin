// NIVEL: 2 — Playground WordPress. NOT LEVEL 1 BECAUSE: the migrator READS and WRITES the database (form posts, a page with an inline form, the backup meta, the definitions table) and what is checked is the email WordPress then sends from a page cached BEFORE the migration. Its pure part (which text changes, before → after per notification) is level 1 (pure.test.mjs).
//
// wp estrategia-web migrate-form-markers (2.0.1, class-marker-migration.php),
// run here through the test route /migrate-form-markers (the same
// Marker_Migration::run() the WP-CLI command calls). The site: a form post
// with the old Spanish markers in its team notice, a page showing it through
// the reference block, and a page with a form still written INSIDE it (a
// site that has not run migrate-forms). The page is rendered BEFORE
// migrating, so its snapshot (the definitions table) has the old markers,
// and it is submitted AFTER, as a cached page would. Then:
//  1. dry run: every notification before → after, the inline form listed
//     with its reminder, the snapshot counted, nothing written;
//  2. real run: the contents renamed byte for byte around the
//     notifications, the originals in the backup meta, the snapshot's
//     original in the option;
//  3. the cached page's submission: the email says the page URL and the
//     site name, no «{{» left (without the snapshot step it would say
//     {{url_pagina}} verbatim: Markers::ALIASES is gone);
//  4. second run: «ya».
// Seen red (28-10-2026), twice: the first run found a real bug — the cached
// page's token was rejected («token_invalid») because the submit endpoint
// recomputed the hash from the rewritten snapshot instead of using the key
// it was loaded under (fixed in Rest::handle()) —; then with the snapshot
// step skipped (steps 1-3: no snapshot counted, and the email carried
// «{{url_pagina}}» verbatim).
import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { seed, loadForm, submit, GOOD, FORM, waitMinTime, testApi } from "./lib.mjs";

const OLD = (tree) =>
  tree.replace("{{page_url}}", "{{url_pagina}}").replace("{{submission_date}}", "{{ Fecha }}").replace("{{site_name}}", "{{nombre_sitio}}").replace("{{all_fields}}", "{{todos_los_campos}}");
const FORM_OLD = OLD(FORM);
const INLINE_OLD = `<!-- wp:paragraph -->\n<p>Antes</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:estrategia-web-forms/form {"formId":"mk-inline","title":"En línea"} -->
<!-- wp:estrategia-web-forms/email {"label":"Correo","name":"email","required":true} /-->
<!-- wp:estrategia-web-forms/notification {"label":"Copia","to":"equipo@ejemplo.test","body":"{{todos_los_campos}}"} /-->
<!-- wp:estrategia-web-forms/submit /-->
<!-- /wp:estrategia-web-forms/form -->`;

let form, inline, ref, ids, cached;
const migrate = (dry, extra = {}) => testApi("/migrate-form-markers", { method: "POST", body: { dry, ids, ...extra } });

before(async () => {
  const { forms, pages } = await seed({
    forms: [{ key: "form", title: "Contacto", content: FORM_OLD }],
    pages: [
      { key: "inline", slug: "mk-inline", title: "En línea", content: INLINE_OLD },
      { key: "ref", slug: "mk-contacto", title: "Contacto", content: `<!-- wp:estrategia-web-forms/form-ref {"ref":{{id:form}}} /-->` },
    ],
  });
  form = forms.form;
  ({ inline, ref } = pages);
  ids = [form, inline.id];
  // The page as a cache would keep it: rendered (and its snapshot stored) before migrating.
  ({ hidden: cached } = await loadForm(ref.url));
});
// The form posts are not undone by the core reset that the next file (of another module) runs.
after(() => testApi("/eweb-forms", { method: "DELETE" }));

let dry;
test("dry run: every notification before → after, the inline form with its reminder, the snapshot counted, nothing written", async () => {
  dry = await migrate(true, { fresh: true });
  assert.equal(dry.seco, true);
  assert.equal(dry.ya, false);
  const mine = dry.formularios.find((f) => f.id === form);
  assert.ok(mine, JSON.stringify(dry.formularios));
  assert.deepEqual(mine.avisos.map((a) => [a.form_id, a.aviso, Object.keys(a.cambios)]), [["contacto", "Aviso al equipo", ["body"]]], "only the team notice had old markers");
  assert.deepEqual(mine.avisos[0].cambios.body, {
    antes: "Desde {{url_pagina}} el {{ Fecha }} en {{nombre_sitio}}:\n\n{{todos_los_campos}}",
    despues: "Desde {{page_url}} el {{submission_date}} en {{site_name}}:\n\n{{all_fields}}",
  });
  const line = dry.en_linea.find((f) => f.id === inline.id);
  assert.ok(line && /migrate-forms not run yet/.test(line.nota), JSON.stringify(dry.en_linea));
  assert.ok(dry.avisos.some((a) => a.includes("migrate-forms") && a.includes("still pending")), dry.avisos.join("\n"));
  assert.ok(dry.instantaneas >= 1, "the snapshot the page stored when rendered");
  assert.match(dry.resumen, /^DRY RUN: \d+ notification\(s\) in \d+ form post\(s\) and \d+ post\(s\) with an inline form to change; \d+ snapshot\(s\) to change/);
  assert.equal(dry.after[form].content, FORM_OLD, "nothing written");
  assert.equal(dry.after[inline.id].content, INLINE_OLD);
  assert.deepEqual([dry.after[form].backups, dry.after[inline.id].backups, dry.snapshot_backup], [[], [], []]);
});

test("real run: renamed byte for byte around the notifications; originals in the meta and the option", async () => {
  const r = await migrate(false);
  assert.equal(r.seco, false);
  assert.equal(r.after[form].content, FORM, "exactly the tree with the English markers");
  assert.equal(r.after[inline.id].content, INLINE_OLD.replace("{{todos_los_campos}}", "{{all_fields}}"));
  assert.deepEqual(r.after[form].backups, [FORM_OLD]);
  assert.deepEqual(r.after[inline.id].backups, [INLINE_OLD]);
  assert.equal(r.snapshot_backup.length, r.instantaneas);
  assert.ok(r.instantaneas >= 1);
});

test("a page cached before the migration: its email has the values, no marker left", async () => {
  await waitMinTime();
  const res = await submit(cached, GOOD, { state: true });
  assert.equal(res.status, 200, JSON.stringify(res.json));
  const team = res.state.mails.find((m) => m.to.includes("equipo@ejemplo.test"));
  assert.match(team.text, new RegExp(`^Desde ${ref.url.replace(/[.?]/g, "\\$&")} el \\S.* en Sitio de pruebas:\\n\\nNombre: Ana`), team.text);
  assert.ok(!team.text.includes("{{") && !team.html.includes("{{"), team.text);
});

test("second run: «ya», nothing changed", async () => {
  const r = await migrate(false);
  assert.equal(r.ya, true, r.resumen);
  assert.match(r.resumen, /^ya:/);
  assert.equal(r.after[form].backups.length, 1, "no second backup");
});
