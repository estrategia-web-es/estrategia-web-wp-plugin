// NIVEL: 2 — Playground WordPress, because what is tested is what the site PRINTS (the front page's <head>) depending on the option saved over REST, and that option's sanitization.
//
// Matomo (0.3.0). What each mode does: header of includes/class-matomo.php.
// What happens in the browser (cookies, consent) is in
// tests/matomo-browser.test.mjs (level 3).
import { test, before } from "node:test";
import assert from "node:assert/strict";
import { BASE, html, reset, saveCompany, seedPage, testApi, wp } from "../../../tests/lib.mjs";

const MATOMO = "https://stats.ejemplo.test/";
const TAG = 'id="estrategia-web-site-matomo"';

before(reset);

const front = async () => (await html(BASE + "/?nocache=" + Date.now())).text;

test("matomo (0.3.0): without URL or without id NOTHING is printed; with both, it is", async () => {
  assert.ok(!(await front()).includes(TAG), "freshly installed there is no Matomo code");
  assert.ok(!(await front()).includes("_paq"), "no trace of _paq");

  await saveCompany({ matomo_url: MATOMO, matomo_site_id: 0 });
  assert.ok(!(await front()).includes(TAG), "with URL but no id, nothing");

  await saveCompany({ matomo_url: "", matomo_site_id: 3 });
  assert.ok(!(await front()).includes(TAG), "with id but no URL, nothing");

  await saveCompany({ matomo_url: MATOMO, matomo_site_id: 3 });
  const page = await front();
  assert.ok(page.includes(TAG), "with URL and id, the code is there");
  assert.match(page, /setSiteId', "3"/);
  assert.ok(page.includes('"https:\\/\\/stats.ejemplo.test\\/"') || page.includes('"https://stats.ejemplo.test/"'), "the URL from the option");
});

test("matomo (0.3.0): by default it is cookieless (disableCookies) and does not request the category from the notice", async () => {
  await reset();
  const saved = await saveCompany({ matomo_url: MATOMO, matomo_site_id: 3 });
  assert.equal(saved.matomo_mode, "cookieless");
  const page = await front();
  assert.ok(page.includes("'disableCookies'"), "disableCookies");
  assert.ok(!page.includes("requireCookieConsent"), "no consent at all in cookieless mode");
  assert.ok(!page.includes("rememberCookieConsentGiven"));
  assert.deepEqual(await testApi("/consent-categories"), ["maps"], "without cookies there is nothing to ask");
});

test("matomo (0.3.0): hybrid = requireCookieConsent + listening to the notice, and declares «statistics» in the parent's notice", async () => {
  await reset();
  await saveCompany({ matomo_url: MATOMO, matomo_site_id: 3, matomo_mode: "hybrid" });
  const page = await front();
  assert.ok(page.includes("'requireCookieConsent'"));
  assert.ok(!page.includes("'disableCookies'"));
  assert.ok(page.includes("estrategia-web:consent") && page.includes("rememberCookieConsentGiven") && page.includes("forgetCookieConsentGiven"));
  assert.deepEqual(await testApi("/consent-categories"), ["maps", "statistics"], "it is appended without overwriting the child's");
});

test("matomo (0.3.0): sanitization — URL pasted with matomo.php, invalid URL, integer id, mode outside the enum", async () => {
  await reset();
  let saved = await saveCompany({ matomo_url: "https://stats.ejemplo.test/matomo.php", matomo_site_id: "7" });
  assert.equal(saved.matomo_url, MATOMO, "matomo.php is stripped and it ends in /");
  assert.equal(saved.matomo_site_id, 7, "integer, not text");

  saved = await saveCompany({ matomo_url: "https://stats.ejemplo.test/sub" });
  assert.equal(saved.matomo_url, "https://stats.ejemplo.test/sub/", "always ending in /");

  saved = await saveCompany({ matomo_url: "javascript:alert(1)" });
  assert.equal(saved.matomo_url, "https://stats.ejemplo.test/sub/", "an invalid URL does not erase the good one");

  const badMode = await wp("/settings", { method: "POST", body: { estrategia_web_site: { matomo_mode: "always" } } });
  assert.equal(badMode.status, 400, "mode outside the enum → 400");
  const badId = await wp("/settings", { method: "POST", body: { estrategia_web_site: { matomo_site_id: -1 } } });
  assert.equal(badId.status, 400, "negative id → 400");

  const read = await wp("/settings");
  assert.equal(read.json.estrategia_web_site.matomo_mode, "cookieless");
  assert.equal(read.json.estrategia_web_site.matomo_site_id, 7);
});

test("matomo (0.3.0): the Matomo keys are NOT bindable data in the editor", async () => {
  await reset();
  await saveCompany({ matomo_url: MATOMO, matomo_site_id: 3 });
  const p = '<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"estrategia-web-site/company","args":{"key":"matomo_url"}}}}} --><p>x</p><!-- /wp:paragraph -->';
  const { url } = await seedPage(p, "matomo-binding");
  const page = await html(url);
  assert.equal(page.status, 200);
  assert.ok(!page.text.includes(">https://stats.ejemplo.test/<"), "the Matomo URL does not come out through the company source");
});
