// NIVEL: 2 — Playground WordPress with REAL WPML. NOT LEVEL 1 BECAUSE: what is tested is that WPML itself takes the per-language slugs of a JSON-declared type (its «URL slug» strings, its switches) and then serves /restaurantes/… and /en/restaurants/… linked to each other; only a WordPress running WPML routes and links like that. The WPML config built from the definitions is wpml.test.mjs (level 1, with a double). NOT LEVEL 3: everything checked is status codes, the HTML the server sends and the stored rows.
// WPML: REAL — runs against the WordPress with WPML (tests/run.mjs, «WPML»).
//
// PER-LANGUAGE SLUG OF THE content-types TYPES (17-10-2026). With the
// Gastronòmicament example of this module switched on (examples/
// gment_restaurant.json: slug {"es": "restaurantes", "en": "restaurants", …},
// taxonomy gment_cuisine {"es": "cocina", "en": "cuisine", …}; the
// estrategia-web-ct-test/v1/setup route) and WPML with es by default and en:
//  1. what the module leaves in WPML: the strings «URL slug: gment_restaurant»
//     and «URL gment_cuisine tax slug» (original es, translation en,
//     complete) and the slug translation switches on;
//  2. a restaurant in Spanish and its translation: /restaurantes/<slug>/ and
//     /en/restaurants/<slug>/ answer 200 and each points to the other in its
//     hreflang alternate (what WPML's switchers use), and the Spanish slug
//     does not serve in /en/;
//  3. the archive (/restaurantes/, /en/restaurants/) and the taxonomy
//     (/cocina/…, /en/cuisine/…).
// The example is switched off at the end, whatever happens (that deletes the
// gment_ posts and terms).
//
// Moved from Lavandería (tests/content-types-slugs-wpml.test.mjs) on
// 25-10-2026, where it switched on this same example with a route of its own;
// the hreflang read there was its header switcher's, here WPML's <link
// rel="alternate"> (the theme here has no switcher; both come from the same
// WPML permalink). NEEDS the local WPML copy (with String Translation):
// without it, it FAILS (tests/run.mjs, «WPML»).
//
// How to maintain: if the example changes its slugs, the constants below.
// The trap (17-10-2026): WPML does not take the type as translatable until it
// re-reads its config (one of its screens, or Plugins): before that,
// post_type_link translates nothing and /en/restaurants/… redirects to
// /en/restaurantes/…, which looks as if the module had not written the slugs
// (it had: step 1 says so). The test opens Plugins, as a deploy would.
//
// Seen red (25-10-2026): expecting the English slug «restaurant», step 1
// fails («translation: restaurants»); restored, green.
import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { reset, wp, BASE, wpml, requireWpml, adminSession } from "../../../tests/lib.mjs";

const ct = (on) => fetch(BASE + "/wp-json/estrategia-web-ct-test/v1/setup", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ on }) });
const get = (p) => fetch(BASE + p, { redirect: "manual" });
const page = async (p) => {
  const res = await get(p);
  assert.equal(res.status, 200, `${p} -> ${res.status} ${res.headers.get("location") || ""}`);
  return res.text();
};
// The alternate WPML prints for a language: <link rel="alternate" hreflang="en" href="…">.
const alternate = (text, lang) => text.match(new RegExp(`<link[^>]+hreflang="${lang}"[^>]*>`))?.[0]?.match(/href="([^"]+)"/)?.[1];
let state;
let es;
let en;

before(async () => {
  await requireWpml();
  await reset();
  await ct(false);
  await ct(true);
  await get("/"); // This request loads the module, registers the types, flushes the rules and writes the slugs into WPML.
  await (await adminSession()).get("/wp-admin/plugins.php"); // What a deploy does: WPML re-reads its config and the types become translatable.
  state = await wpml("/slugs", { type: "gment_restaurant", like: "gment" });
});

after(() => ct(false));

test("1. what the module leaves in WPML: the slug strings translated and the switches on", () => {
  assert.equal(state.translated, true, "WPML does not have gment_restaurant as translatable (did it not re-read its config?)");
  assert.equal(state.registered, "restaurantes", `registered slug: ${state.registered} (it must be the default language's, es)`);
  const row = (name, lang) => state.strings.find((c) => c.name === name && (c.lang ?? null) === lang);
  const type = row("URL slug: gment_restaurant", "en");
  assert.ok(type, `no en translation of «URL slug: gment_restaurant»: ${JSON.stringify(state.strings)}`);
  assert.equal(type.value, "restaurantes", "the original is the registered slug");
  assert.equal(type.language, "es", "and it is in the default language");
  assert.equal(type.translation, "restaurants", `translation: ${type.translation}`);
  assert.equal(Number(type.status), 10, "complete translation (ICL_TM_COMPLETE)");
  const tax = row("URL gment_cuisine tax slug", "en");
  assert.ok(tax && tax.value === "cocina" && tax.translation === "cuisine", `taxonomy: ${JSON.stringify(tax)}`);
  assert.equal(Number(state.global), 1, "slug translation on in WPML");
  assert.equal(Number(state.types?.gment_restaurant), 1, `gment_restaurant with slug translation: ${JSON.stringify(state.types)}`);
  assert.equal(Number(state.taxonomies?.types?.gment_cuisine), 1, `gment_cuisine with slug translation: ${JSON.stringify(state.taxonomies)}`);
});

test("2. a restaurant and its translation, each under its own slug and pointing to the other", async () => {
  const r = await wp("/gment_restaurant", { method: "POST", body: { title: "Ca n’Andreu", slug: "ca-nandreu", status: "publish", meta: { gment_address: "Carrer Major, 1" } } });
  assert.equal(r.status, 201, r.text);
  es = r.json;
  en = await wpml("/translate", { kind: "post", type: "gment_restaurant", original: es.id, title: "Andreu’s", slug: "andreus", meta: { gment_address: "Carrer Major, 1" } });
  const pageEs = await page("/restaurantes/ca-nandreu/");
  const pageEn = await page("/en/restaurants/andreus/");
  assert.equal(alternate(pageEs, "en"), `${BASE}/en/restaurants/andreus/`, "the Spanish page points to the English one with its slug");
  assert.equal(alternate(pageEn, "es"), `${BASE}/restaurantes/ca-nandreu/`, "and the English one to the Spanish one");
  assert.notEqual((await get("/en/restaurantes/andreus/")).status, 200, "the Spanish slug does not serve in /en/");
});

test("3. the archive and the taxonomy, in both languages", async () => {
  await page("/restaurantes/");
  const archiveEn = await page("/en/restaurants/");
  assert.equal(alternate(archiveEn, "es"), `${BASE}/restaurantes/`, "archive: points to the Spanish one");
  const term = await wp("/gment_cuisine", { method: "POST", body: { name: "Mallorquina", slug: "mallorquina" } });
  assert.equal(term.status, 201, term.text);
  assert.equal((await wp(`/gment_restaurant/${es.id}`, { method: "POST", body: { gment_cuisine: [term.json.id] } })).status, 200);
  const termEn = await wpml("/translate", { kind: "term", type: "gment_cuisine", original: term.json.id, name: "Majorcan", slug: "majorcan" });
  // The address is required and «translate»: the update carries it.
  const up = await wp(`/gment_restaurant/${en.id}?lang=en`, { method: "POST", body: { gment_cuisine: [termEn.term_id], meta: { gment_address: "Carrer Major, 1" } } });
  assert.equal(up.status, 200, up.text);
  const taxEs = await page("/cocina/mallorquina/");
  await page("/en/cuisine/majorcan/");
  assert.equal(alternate(taxEs, "en"), `${BASE}/en/cuisine/majorcan/`, "taxonomy: points to the English term with the translated slug");
});
