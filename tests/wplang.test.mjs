// NIVEL: 2 — Playground WordPress, because what is checked is which translation file WordPress has loaded at init and what a URL slug is registered with, and that only happens in a real request.
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
//
// How to maintain: the double knows es, en and ca (&wpml-default=<code>
// changes the default language). The Gastronòmicament case, Catalan by
// default, is tests/catalan.test.mjs. A fourth language goes into the
// double's get_locale() and needs that language's .po.
// The check with REAL WPML is not here: it is Lavandería's deployer («comprobar»
// step) and its local tests/server.mjs.
import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { reset, testApi, saveCompany } from "./lib.mjs";

before(async () => {
  await reset();
  await saveCompany({ enabled_types: ["location"] });
});
after(reset);

test("without WPML (the usual test site, locale es_ES): Spanish texts and slug", async () => {
  const r = await testApi("/i18n");
  assert.equal(r.now.text, "Aceptar todas");
  assert.equal(r.registered_slug, "sedes");
});

test("WPLANG empty, WPML in Spanish (/): the plugin speaks Spanish and the slug is «sedes»", async () => {
  const r = await testApi("/i18n?wpml-double=es");
  assert.equal(r.now.text, "Aceptar todas", `determine_locale() was ${r.determine_locale}`);
  assert.equal(r.now.slug, "sedes");
  assert.equal(r.registered_slug, "sedes");
});

test("a WPML switch in the middle of the page changes the texts and back, never the slug", async () => {
  const r = await testApi("/i18n?wpml-double=es");
  assert.equal(r.switched_en.text, "Accept all", "after switching to English");
  assert.equal(r.switched_en.slug, "sedes", "the slug is the site's, also while in English");
  assert.equal(r.switched_back.text, "Aceptar todas", "after switching back");
});

test("WPLANG empty, WPML in English (/en/): English texts, and the slug is still the site's «sedes»", async () => {
  const r = await testApi("/i18n?wpml-double=en");
  assert.equal(r.now.text, "Accept all");
  assert.equal(r.now.slug, "sedes");
  assert.equal(r.registered_slug, "sedes", "registered in English, the stored rules (Spanish) would not know the links");
});
