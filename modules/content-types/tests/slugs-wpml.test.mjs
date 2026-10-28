// NIVEL: 2 — Playground WordPress with REAL WPML. NOT LEVEL 1 BECAUSE: what is tested is that WPML itself takes the per-language slugs of a JSON-declared type (its «URL slug» strings, its switches) and then serves /restaurantes/… and /en/restaurants/… linked to each other; only a WordPress running WPML routes and links like that. The WPML config built from the definitions is wpml.test.mjs (level 1, with a double). NOT LEVEL 3: everything checked is status codes, the HTML the server sends and the stored rows.
// WPML: REAL — runs against the WordPress with WPML (tests/run.mjs, «WPML»).
// TIEMPO: 90 s — measured 28-10-2026: 45-47 s alone (same on 2.0.0 and 2.0.1), 56-82 s in the full battery while the machine was busy (the WPML WordPress is the slowest and its files run one after another); not made faster yet, discuss if it keeps growing.
//
// PER-LANGUAGE SLUG OF THE content-types TYPES (17-10-2026). With the
// Gastronòmicament example of this module switched on (examples/
// gment_restaurant.json: slug {"es": "restaurantes", "en": "restaurants", …},
// taxonomy gment_cuisine {"es": "cocina", "en": "cuisine", …}; the
// estrategia_web_ct_test option) and WPML with es by default and en:
//  1. what the module leaves in WPML: the strings «URL slug: gment_restaurant»
//     and «URL gment_cuisine tax slug» (original es, translation en,
//     complete) and the slug translation switches on;
//  2. a restaurant in Spanish and its translation: /restaurantes/<slug>/ and
//     /en/restaurants/<slug>/ answer 200 and each points to the other in its
//     hreflang alternate (what WPML's switchers use), and the Spanish slug
//     does not serve in /en/;
//  3. the taxonomy: /cocina/… points to /en/cuisine/….
// The example is switched off at the end (that deletes the gment_ posts and
// terms); a restaurant left by a run that died, which would take the slug
// «ca-nandreu», is deleted by the seed route.
//
// Moved from Lavandería (tests/content-types-slugs-wpml.test.mjs) on
// 25-10-2026, where it switched on this same example with a route of its own;
// the hreflang read there was its header switcher's, here WPML's <link
// rel="alternate"> (the theme here has no switcher; both come from the same
// WPML permalink). NEEDS the local WPML copy (with String Translation):
// without it, it FAILS (tests/run.mjs, «WPML»).
//
// 28-10-2026, from ~108-144 s (a request to the WPML WordPress costs ~4-5 s):
// the reset and the example switched on in one request
// (estrategia-web-site-test/v1/setup) next to the login; requireWpml next
// to Plugins;
// ONE request loads the module and makes WPML re-read its config
// (plugins.php: our slugs are written on init, WPML reads its config after);
// the restaurant, the term and their translations in ONE request
// (estrategia-web-ct-test/v1/wpml-seed, which also deletes what a run that
// died left) next to the read of WPML's state; every page read at once.
// The archives went (/en/restaurants/ is the same «URL slug» string as the
// single, which step 2 proves; /restaurantes/ is register.test.mjs's without
// WPML), and so did serving /en/cuisine/… (that it routes the translated
// slug is WPML's; that the slug is there, the alternate of /cocina/… says)
// and assigning the cuisine to the restaurants (a term's URL and its
// alternate do not depend on its posts).
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
// Seen red (28-10-2026) after the batching: expecting «restaurant» again,
// step 1 failed the same way; expecting /en/cuisine/majorcan2/, «2 and 3»
// failed; restored, green. Round 2 (requireWpml next to Plugins): both
// breaks red again; restored, green.
import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { BASE, wpml, requireWpml, adminSession, testApi } from "../../../tests/lib.mjs";

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

before(async () => {
  // reset() and the example on (the option the ct-test setup route sets) in one request, next to the login.
  const [session] = await Promise.all([adminSession(), testApi("/setup", { method: "POST", body: { options: { estrategia_web_ct_test: 1 } } })]);
  // What a deploy does: this request loads the module (types registered, rules flushed, slugs written into WPML on init) and WPML re-reads its config.
  // requireWpml() goes alongside: without WPML this file fails with its message (the /slugs route below would only say 404).
  await Promise.all([session.get("/wp-admin/plugins.php"), requireWpml()]);
  // The restaurant and the cuisine with their English translations: one request (the ct-test /wpml-seed route, which first
  // deletes what a run that died left: its slugs would be taken).
  const seed = fetch(BASE + "/wp-json/estrategia-web-ct-test/v1/wpml-seed", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({
      es: { title: "Ca n’Andreu", slug: "ca-nandreu", status: "publish", meta: { gment_address: "Carrer Major, 1" } },
      term: { name: "Mallorquina", slug: "mallorquina" },
      en: { title: "Andreu’s", slug: "andreus", meta: { gment_address: "Carrer Major, 1" } },
      term_en: { name: "Majorcan", slug: "majorcan" },
    }),
  }).then(async (res) => assert.equal(res.status, 200, await res.text()));
  [state] = await Promise.all([wpml("/slugs", { type: "gment_restaurant", like: "gment" }), seed]);
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

test("2 and 3. a restaurant and its translation, and the taxonomy: each under its own slug and pointing to the other", async () => {
  const [pageEs, pageEn, wrong, taxEs] = await Promise.all([page("/restaurantes/ca-nandreu/"), page("/en/restaurants/andreus/"), get("/en/restaurantes/andreus/"), page("/cocina/mallorquina/")]);
  assert.equal(alternate(pageEs, "en"), `${BASE}/en/restaurants/andreus/`, "the Spanish page points to the English one with its slug");
  assert.equal(alternate(pageEn, "es"), `${BASE}/restaurantes/ca-nandreu/`, "and the English one to the Spanish one");
  assert.notEqual(wrong.status, 200, "the Spanish slug does not serve in /en/");
  assert.equal(alternate(taxEs, "en"), `${BASE}/en/cuisine/majorcan/`, "taxonomy: points to the English term with the translated slug");
});
