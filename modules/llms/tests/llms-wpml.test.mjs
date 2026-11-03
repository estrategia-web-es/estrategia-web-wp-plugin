// NIVEL: 2 — Playground WordPress with REAL WPML. NOT LEVEL 1 BECAUSE: what is tested is what OUR code does with WPML's own behaviour: the languages and the directory mode WPML reports, the URL wpml_permalink gives the English file, the pages WPML filters by language for each draft, and a Company detail translated in String Translation reaching the English draft. That only exists in a WordPress running WPML (es by default, en in /en/). The pure cases of the paths are text.test.mjs (level 1). NOT LEVEL 3: everything checked is what the server sends.
// WPML: REAL — runs against the WordPress with WPML (tests/run.mjs, «WPML»).
//
// What and why: modules/llms, «ONE PER LANGUAGE WITH WPML» in module.php.
//  1. /llms.txt is the Spanish text and ends with «Otros idiomas» linking
//     /en/llms.txt (the heading in Spanish: the file's language, not the
//     visitor's).
//  2. /en/llms.txt is the English text, without that section.
//  3. The tab has one text per language, and each draft lists the pages of
//     ITS language with the address in its language.
//
// How to maintain: this changes only if HOW a language is resolved or linked
// changes (class-languages.php). The trap, from company-data-wpml.test.mjs:
// WPML registers the strings of an option only if it exists when it re-reads
// the files, so every Company key is saved before translating the address.
//
// Seen red (31-10-2026), step 1, the bug it was written to catch: the English
// link pointed to /llms.txt, because wpml_permalink was called with its third
// argument (header of Languages::url()).
import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { BASE, reset, testApi, wp, saveCompany, adminSession, wpml, requireWpml, credentials } from "../../../tests/lib.mjs";

const OPTION = "estrategia_web_llms";
const ES = "# Lavandería de pruebas\n\n> Lavandería autoservicio en Palma.";
const EN = "# Test laundry\n\n> Self-service laundry in Palma.";
const ADDRESS = "Calle Camp Franc, 11, Nave 14";
const ADDRESS_EN = "Calle Camp Franc, 11, Unit 14";
let original, translation;

before(async () => {
  await Promise.all([requireWpml(), reset(), credentials()]);
  const { loaded } = await testApi("/modules");
  await testApi("/modules", { method: "POST", body: { active: [...new Set([...loaded, "llms"])] } });
  await saveCompany({ company_name: "Lavandería de pruebas", legal_name: "Pruebas S.L.", tax_id: "B00000000", phone: "971 00 00 00", whatsapp: "", address: ADDRESS, registry: "", schedule: "", whatsapp_message: "" });
  const r = await wp("/pages", { method: "POST", body: { title: "Autoservicio llms", slug: "autoservicio-llms", status: "publish", excerpt: "Lavadoras de 9 y 18 kg." } });
  assert.equal(r.status, 201, r.text);
  original = r.json.id;
  const [tr] = await Promise.all([
    wpml("/translate", { kind: "post", type: "page", original, title: "Self-service llms", slug: "self-service-llms", content: "" }),
    wpml("/string", { context: "admin_texts_estrategia_web_site", name: "[estrategia_web_site]address", value: ADDRESS_EN }),
  ]);
  translation = tr.id;
  const s = await wp("/settings", { method: "POST", body: { [OPTION]: { es: ES, en: EN } } });
  assert.equal(s.status, 200, s.text);
});

after(async () => {
  await Promise.all([original, translation].filter(Boolean).map((id) => wp(`/pages/${id}?force=true`, { method: "DELETE" })));
  await testApi("/options", { method: "POST", body: { names: [OPTION], delete: [OPTION] } });
  await reset();
});

test("/llms.txt is Spanish and ends linking /en/llms.txt; /en/llms.txt is English, without that section", async () => {
  const [es, en] = await Promise.all([fetch(BASE + "/llms.txt"), fetch(BASE + "/en/llms.txt")]);
  assert.equal(es.status, 200);
  assert.equal(en.status, 200);
  assert.equal(en.headers.get("content-type"), "text/plain; charset=utf-8");
  assert.equal(await es.text(), `${ES}\n\n## Otros idiomas\n\n- [English](${BASE}/en/llms.txt)\n`);
  assert.equal(await en.text(), EN + "\n");
});

test("the tab: one text per language, and each draft with the pages and the address of its language", async () => {
  const s = await adminSession();
  const tab = await s.get("/wp-admin/admin.php?page=estrategia-web-settings&tab=llms");
  assert.equal(tab.status, 200, tab.location);
  const names = [...tab.text.matchAll(/name="estrategia_web_llms\[([a-z-]+)\]"/g)].map((m) => m[1]);
  assert.deepEqual(names, ["es", "en"]);
  const draft = (code) => (tab.text.match(new RegExp(`id="estrategia-web-llms-${code}-draft">([\\s\\S]*?)</textarea>`)) || [])[1] || "";
  const es = draft("es");
  const en = draft("en");
  assert.match(es, /\[Autoservicio llms\]/);
  assert.doesNotMatch(es, /Self-service llms/);
  assert.match(es, new RegExp(ADDRESS));
  assert.match(es, /## Páginas/);
  assert.match(en, /\[Self-service llms\]\([^)]*\/en\/self-service-llms\/\)/);
  assert.doesNotMatch(en, /Autoservicio llms/);
  assert.match(en, new RegExp(ADDRESS_EN));
  assert.match(en, new RegExp(` · ${BASE}/en/\n`), "the English home, not the Spanish one");
  assert.match(es, new RegExp(` · ${BASE}/\n`));
  assert.match(en, /## Pages/);
});
