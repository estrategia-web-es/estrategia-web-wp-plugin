// NIVEL: 2 — Playground WordPress with REAL WPML. NOT LEVEL 1 BECAUSE: what is tested is what WPML does with the migrated forms — the language and translation group (trid) of each form post, WPML re-reading wpml-config.xml so that eweb_form is translatable, and the language WPML gives a request to /en/ — and that only exists with WPML. NOT LEVEL 3: what is checked is the database and the rendered HTML.
// WPML: REAL — runs against the WordPress with WPML (tests/run.mjs, «WPML»).
//
// THE MIGRATED FORM, PER LANGUAGE (2.0.0, class-form-migration.php, step 3,
// and Form_Post_Type::resolve()). A Spanish contact page with its form
// inline and its English translation (made with WPML) with the English
// labels, as Lavandería's Contacto/Contact; then:
//  1. dry run: two form posts, the English one a translation of the Spanish;
//  2. real run: WPML says eweb_form is translatable, the two form posts are
//     es and en in ONE translation group;
//  3. each page shows its own language's form;
//  4. a page whose reference points to the SPANISH form post (what WPML
//     leaves in a translated page when it copies the block from the
//     original) shows the ENGLISH form under /en/: the reference resolves
//     the language at render time.
// At the end its pages and form posts are removed.
//
// Seen red (27-10-2026): with Form_Post_Type::resolve() not translating the
// id (the ref used as it is), step 4 fails (the /en/ page shows «Nombre
// completo»); restored, green.
import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { reset as coreReset, wp, wpml, requireWpml } from "../../../tests/lib.mjs";
import { testApi } from "./lib.mjs";

const form = (t) => `<!-- wp:estrategia-web-forms/form {"formId":"contacto-wmig","title":"${t.title}"} -->
<!-- wp:estrategia-web-forms/text {"label":"${t.name}","name":"nombre","required":true} /-->
<!-- wp:estrategia-web-forms/email {"label":"${t.email}","name":"email","required":true} /-->
<!-- wp:estrategia-web-forms/notification {"label":"Equipo","to":"equipo@ejemplo.test"} /-->
<!-- wp:estrategia-web-forms/submit {"text":"${t.send}"} /-->
<!-- /wp:estrategia-web-forms/form -->`;
const ES = { title: "Contacto", name: "Nombre completo", email: "Correo", send: "Enviar" };
const EN = { title: "Contact", name: "Full name", email: "Email", send: "Send" };
const SLUGS = ["contacto-wmig", "contact-wmig", "ref-wmig", "ref-wmig-en"];
const html = async (url) => (await fetch(url)).text();

let es, en, ids;

before(async () => {
  await requireWpml();
  await coreReset();
  await wpml("/purge", { slugs: SLUGS });
  await testApi("/eweb-forms", { method: "DELETE" });
  const r = await wp("/pages", { method: "POST", body: { title: "Contacto", slug: "contacto-wmig", status: "publish", content: form(ES) } });
  assert.equal(r.status, 201, r.text);
  const tr = await wpml("/translate", { kind: "post", type: "page", original: r.json.id, title: "Contact", slug: "contact-wmig", content: form(EN) });
  es = { id: r.json.id, url: r.json.link };
  en = { id: tr.id, url: tr.url };
  ids = [es.id, en.id];
  assert.match(new URL(en.url).pathname, /^\/en\//);
});

after(async () => {
  await wpml("/purge", { slugs: SLUGS }).catch(() => {});
  await testApi("/eweb-forms", { method: "DELETE" }).catch(() => {});
});

test("1. dry run: one form post per language, the English one a translation of the Spanish", async () => {
  const r = await testApi("/migrate-forms", { method: "POST", body: { dry: true, ids } });
  assert.deepEqual(r.formularios.map((f) => [f.form_id, f.idioma, f.accion, f.traduccion_de]), [
    ["contacto-wmig", "es", "create", null],
    ["contacto-wmig", "en", "create", "contacto-wmig|es"],
  ]);
  assert.deepEqual(r.contenido.map((c) => [c.id, c.idioma]), [[es.id, "es"], [en.id, "en"]]);
  assert.equal(r.wpml.idioma_por_defecto, "es");
});

test("2. real run: eweb_form translatable, and the two form posts in one translation group", async () => {
  const r = await testApi("/migrate-forms", { method: "POST", body: { dry: false, ids } });
  assert.equal(r.wpml.traducible, true, "WPML did not take eweb_form as translatable: " + r.avisos.join(" / "));
  const forms = await testApi("/eweb-forms");
  assert.deepEqual(forms.map((f) => f.lang).sort(), ["en", "es"]);
  assert.equal(forms[0].trid, forms[1].trid, "one translation group");
  assert.ok(forms[0].trid > 0);
  assert.match(forms.find((f) => f.lang === "en").content, /Full name/);
});

test("3. each page shows its own language's form", async () => {
  const a = await html(es.url);
  const b = await html(en.url);
  assert.match(a, /Nombre completo/, `${es.url}: no Spanish form`);
  assert.match(b, /Full name/, `${en.url}: no English form`);
  assert.doesNotMatch(b, /Nombre completo/);
});

test("4. a reference to the SPANISH form post shows the English form under /en/", async () => {
  const esForm = (await testApi("/eweb-forms")).find((f) => f.lang === "es");
  const ref = `<!-- wp:estrategia-web-forms/form-ref {"ref":${esForm.id}} /-->`;
  const r = await wp("/pages", { method: "POST", body: { title: "Ref", slug: "ref-wmig", status: "publish", content: ref } });
  const tr = await wpml("/translate", { kind: "post", type: "page", original: r.json.id, title: "Ref", slug: "ref-wmig-en", content: ref });
  assert.match(await html(r.json.link), /Nombre completo/);
  const b = await html(tr.url);
  assert.match(b, /Full name/, `${tr.url} shows the Spanish form`);
  assert.doesNotMatch(b, /Nombre completo/);
});
