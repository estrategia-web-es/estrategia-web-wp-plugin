// NIVEL: 2 — Playground WordPress with REAL WPML. NOT LEVEL 1 BECAUSE: what is tested is what WPML does with the migrated forms — the language and translation group (trid) of each form post, WPML re-reading wpml-config.xml so that eweb_form is translatable, and the language WPML gives a request to /en/ — and that only exists with WPML. NOT LEVEL 3: what is checked is the database and the rendered HTML.
// WPML: REAL — runs against the WordPress with WPML (tests/run.mjs, «WPML»).
//
// THE MIGRATED FORM, PER LANGUAGE (2.0.0, class-form-migration.php, step 3,
// and Form_Post_Type::resolve()). A Spanish contact page with its form
// inline and its English translation (made with WPML) with the English
// labels, as Lavandería's Contacto/Contact; then:
//  1. the migration: WPML says eweb_form is translatable, one form post per
//     language, the English one planned as a translation of the Spanish one,
//     the two in ONE translation group, and each page pointing to its own
//     language's form post;
//  2. the Spanish page shows the Spanish form;
//  3. a page whose reference points to the SPANISH form post (what WPML
//     leaves in a translated page when it copies the block from the
//     original) shows the ENGLISH form under /en/: the reference resolves
//     the language at render time.
// At the end its pages and form posts are removed.
//
// A request to the WPML WordPress costs 3-6 s, so (28-10-2026, from ~21
// requests to ~7): the reset, the purge, the page and its translation are
// ONE request (seed() in lib.mjs), at once with requireWpml(); the migration
// answers the form posts it left in the same request; the dry run was deleted — what it planned
// is asserted on the real run's report (the same «formularios»), and «a dry
// run writes nothing» is form-migration.test.mjs's; step 3 does not create
// a new page and its translation: the same /migrate-forms request then
// points the English page itself to the Spanish form post («point»,
// 28-10-2026 round 2: 4 rounds instead of 5); and the English page is not rendered before that: what
// it pointed to after the migration is read from the migration's answer
// (rendered, it would show English either way: the reference resolves the
// language).
//
// Seen red (27-10-2026): with Form_Post_Type::resolve() not translating the
// id (the ref used as it is), step 4 (now 3) fails (the /en/ page shows
// «Nombre completo»); restored, green. Seen red again (28-10-2026), both
// tests: that same mutant (step 3), and at once the migration giving the
// English form post no trid (step 1, «one translation group»).
// Seen red again after round 2 (28-10-2026), every test, with the mutants above (one by one where a test reads what the one before left).
import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { wpml, requireWpml } from "../../../tests/lib.mjs";
import { seed, testApi } from "./lib.mjs";

const form = (t) => `<!-- wp:estrategia-web-forms/form {"formId":"contacto-wmig","title":"${t.title}"} -->
<!-- wp:estrategia-web-forms/text {"label":"${t.name}","name":"nombre","required":true} /-->
<!-- wp:estrategia-web-forms/email {"label":"${t.email}","name":"email","required":true} /-->
<!-- wp:estrategia-web-forms/notification {"label":"Equipo","to":"equipo@ejemplo.test"} /-->
<!-- wp:estrategia-web-forms/submit {"text":"${t.send}"} /-->
<!-- /wp:estrategia-web-forms/form -->`;
const ES = { title: "Contacto", name: "Nombre completo", email: "Correo", send: "Enviar" };
const EN = { title: "Contact", name: "Full name", email: "Email", send: "Send" };
const SLUGS = ["contacto-wmig", "contact-wmig"];
const html = async (url) => (await fetch(url)).text();

let es, en, forms;

before(async () => {
  // At once, but requireWpml()'s verdict first: without WPML, its message is the one that matters.
  const [wpmlState, seeded] = await Promise.allSettled([
    requireWpml(),
    seed({
      marker: false,
      purge: SLUGS,
      pages: [{ key: "es", slug: "contacto-wmig", title: "Contacto", content: form(ES), translation: { title: "Contact", slug: "contact-wmig", content: form(EN) } }],
    }),
  ]);
  if (wpmlState.status === "rejected") throw wpmlState.reason;
  if (seeded.status === "rejected") throw seeded.reason;
  const { pages } = seeded.value;
  es = pages.es;
  en = pages.es.translation;
  assert.match(new URL(en.url).pathname, /^\/en\//);
});

// The pages (in both languages) and the form posts: no reset of the next file removes them.
after(() => Promise.all([wpml("/purge", { slugs: SLUGS }), testApi("/eweb-forms", { method: "DELETE" })]).catch(() => {}));

test("1. the migration: eweb_form translatable, one form post per language, the English one a translation of the Spanish, in one translation group", async () => {
  // «read»: what it left comes in the same answer; «point»: and THEN the English page is pointed to the
  // Spanish form post, for step 3 (one request instead of three: 5-8 s each here).
  const r = await testApi("/migrate-forms", { method: "POST", body: { dry: false, ids: [es.id, en.id], read: true, point: { post: en.id, lang: "es" } } });
  assert.equal(r.wpml.traducible, true, "WPML did not take eweb_form as translatable: " + r.avisos.join(" / "));
  assert.equal(r.wpml.idioma_por_defecto, "es");
  assert.deepEqual(r.formularios.map((f) => [f.form_id, f.idioma, f.accion, f.traduccion_de]), [
    ["contacto-wmig", "es", "create", null],
    ["contacto-wmig", "en", "create", "contacto-wmig|es"],
  ]);
  assert.deepEqual(r.contenido.map((c) => [c.id, c.idioma]), [[es.id, "es"], [en.id, "en"]]);
  forms = r.after.forms;
  assert.deepEqual(forms.map((f) => f.lang).sort(), ["en", "es"]);
  assert.equal(forms[0].trid, forms[1].trid, "one translation group");
  assert.ok(forms[0].trid > 0);
  const [esForm, enForm] = ["es", "en"].map((lang) => forms.find((f) => f.lang === lang));
  assert.match(enForm.content, /Full name/);
  // Each page points to ITS language's form post (on the English page the render could not tell: it resolves the language anyway, step 3).
  const ref = (id) => `<!-- wp:estrategia-web-forms/form-ref {"ref":${id}} /-->`;
  assert.equal(r.after.posts[es.id].content, ref(esForm.id));
  assert.equal(r.after.posts[en.id].content, ref(enForm.id));
  assert.equal(r.pointed, esForm.id, "step 3's setup: the English page now points to the Spanish form post");
});

test("2. the Spanish page shows the Spanish form; 3. a reference to the SPANISH form post shows the English form under /en/", async () => {
  const [a, b] = await Promise.all([html(es.url), html(en.url)]);
  assert.match(a, /Nombre completo/, `${es.url}: no Spanish form`);
  assert.match(b, /Full name/, `${en.url} shows the Spanish form`);
  assert.doesNotMatch(b, /Nombre completo/);
});
