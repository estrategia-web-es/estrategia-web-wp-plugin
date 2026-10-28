// NIVEL: 2 — Playground WordPress. NOT LEVEL 1 BECAUSE: what is checked is which translation file WordPress has loaded at init and what a URL slug is registered with, and that only happens in a real request.
//
// The plugin's texts follow the language of the REQUEST and its URL slugs the
// language of the SITE, even when WordPress' locale (WPLANG) disagrees with
// WPML (includes/class-plugin.php, load_textdomain() and url_slug()).
//
// It boots requests with ?wpml-double=<code>: WPLANG empty (determine_locale()
// says en_US) and a WPML double, Spanish by default, that fixes the locale
// late without change_locale (tests/wp/estrategia-web-site-test.php explains
// what it imitates and what it does not). Lavandería production, 15-10-2026.
//
// Seen in red (15-10-2026) with the 1.3.0 load_textdomain(): on the Spanish
// request «Accept all» and the slug «locations» (the plugin loaded
// estrategia-web-en_US, which does not exist), and on the English one the
// slug «locations» instead of «sedes».
// Seen red (28-10-2026) with the expected slug changed («sedesX», as a slug
// registered in another language would differ): the three tests fail.
//
// 28-10-2026: four requests' setup in one (/setup), the three requests in
// parallel (each is independent), and the switch to English and back read
// from the same /es/ request as the plain Spanish case (they were two tests
// asking for the same URL).
//
// How to maintain: the double knows es, en and ca (&wpml-default=<code>
// changes the default language). The Gastronòmicament case, Catalan by
// default, is tests/catalan.test.mjs. A fourth language goes into the
// double's get_locale() and needs that language's .po.
// The check with REAL WPML is not here: it is Lavandería's deployer («comprobar»
// step) and its local tests/server.mjs.
import { test, before } from "node:test";
import assert from "node:assert/strict";
import { testApi } from "./lib.mjs";

let plain, es, en;
before(async () => {
  await testApi("/setup", { method: "POST", body: { company: { enabled_types: ["location"] } } });
  [plain, es, en] = await Promise.all(["/i18n", "/i18n?wpml-double=es", "/i18n?wpml-double=en"].map((p) => testApi(p)));
});

test("without WPML (the usual test site, locale es_ES): Spanish texts and slug", () => {
  assert.equal(plain.now.text, "Aceptar todas");
  assert.equal(plain.registered_slug, "sedes");
});

test("WPLANG empty, WPML in Spanish (/): Spanish texts and «sedes»; a WPML switch to English and back changes the texts, never the slug", () => {
  assert.equal(es.now.text, "Aceptar todas", `determine_locale() was ${es.determine_locale}`);
  assert.equal(es.now.slug, "sedes");
  assert.equal(es.registered_slug, "sedes");
  assert.equal(es.switched_en.text, "Accept all", "after switching to English");
  assert.equal(es.switched_en.slug, "sedes", "the slug is the site's, also while in English");
  assert.equal(es.switched_back.text, "Aceptar todas", "after switching back");
});

test("WPLANG empty, WPML in English (/en/): English texts, and the slug is still the site's «sedes»", () => {
  assert.equal(en.now.text, "Accept all");
  assert.equal(en.now.slug, "sedes");
  assert.equal(en.registered_slug, "sedes", "registered in English, the stored rules (Spanish) would not know the links");
});
