// NIVEL: 2 — Playground WordPress. NOT LEVEL 1 BECAUSE: what is tested is what the site PRINTS (the <head>, for a visitor and for a logged-in editor) from the company option, that option's register_setting sanitization over REST, the categories the notice gets through the filter and what the company bindings source exposes; all of it needs WordPress running.
//
// Matomo (0.3.0). What each mode does: header of includes/class-matomo.php.
// What happens in the browser (cookies, consent) is in the browser
// scenarios 36-39 (modules/matomo/tests/browser/).
//
// 28-10-2026: one reset for the file (it was one per test); «a logged-in
// administrator is not measured» came down from matomo-browser.test.mjs: it
// is what the server prints for a session (adminSession), no browser needed.
// Round 2 (28-10-2026): the READING cases pass the company option on the
// request itself (?ew-options, modules/consent/tests/wp/estrategia-web-cookies-test.php):
// one request per configuration, all of them at once. HOW the option is
// saved (sanitization, 400s, the default mode) is case 2, over REST.
// Seen red (28-10-2026) with should_track() ignoring current_user_can in
// class-matomo.php (case 1: «a logged-in administrator is not measured»),
// with the «/sub» URL expected without its final «/» (case 2), and with the
// hybrid categories expected in the other order (case 3).
//
// How to maintain: a new reading case is one more request in its batch with
// its own ?ew-options; a new field's validation goes in case 2, over REST.
// The trap: the override is only for reading; a save carries no override
// (sanitize() merges with the stored value).
import { test, before } from "node:test";
import assert from "node:assert/strict";
import { BASE, adminSession, credentials, html, reset, saveCompany, seedPage, testApi, wp } from "../../../tests/lib.mjs";

const MATOMO = "https://stats.ejemplo.test/";
const TAG = 'id="estrategia-web-site-matomo"';
/** ?ew-options with the company option `company` (for this request only). */
const as = (company) => "ew-options=" + encodeURIComponent(JSON.stringify({ estrategia_web_site: company })) + "&nocache=" + Date.now();
const front = async (company) => (await html(`${BASE}/?${as(company)}`)).text;
const categories = (company) => testApi(`/consent-categories?${as(company)}`);

let bindingPath;
let session;
before(async () => {
  await reset();
  const p = '<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"estrategia-web-site/company","args":{"key":"matomo_url"}}}}} --><p>x</p><!-- /wp:paragraph -->';
  const [{ url }, s] = await Promise.all([seedPage(p, "matomo-binding"), adminSession(), credentials()]);
  bindingPath = new URL(url).pathname;
  session = s;
});

test("1. without URL or without id NOTHING is printed; with both it is, cookieless by default and asking nothing; not for a logged-in editor", async () => {
  const both = { matomo_url: MATOMO, matomo_site_id: 3 };
  const [noId, noUrl, page, asked, editor] = await Promise.all([
    front({ matomo_url: MATOMO, matomo_site_id: 0 }), // The id as freshly installed.
    front({ matomo_url: "", matomo_site_id: 3 }),
    front(both),
    categories(both),
    session.get(`/?${as(both)}`),
  ]);
  assert.ok(!noId.includes(TAG), "with URL but no id, nothing");
  assert.ok(!noId.includes("_paq"), "no trace of _paq");
  assert.ok(!noUrl.includes(TAG), "with id but no URL, nothing");
  assert.ok(page.includes(TAG), "with URL and id, the code is there");
  assert.match(page, /setSiteId', "3"/);
  assert.ok(page.includes('"https:\\/\\/stats.ejemplo.test\\/"') || page.includes('"https://stats.ejemplo.test/"'), "the URL from the option");
  assert.ok(page.includes("'disableCookies'"), "cookieless by default: disableCookies");
  assert.ok(!page.includes("requireCookieConsent"), "no consent at all in cookieless mode");
  assert.ok(!page.includes("rememberCookieConsentGiven"));
  assert.deepEqual(asked, ["maps"], "without cookies there is nothing to ask");
  assert.ok(!editor.text.includes(TAG), "a logged-in administrator is not measured: no code for whoever edits");
});

test("2. saving over REST: cookieless by default; URL pasted with matomo.php, invalid URL, integer id, mode outside the enum", async () => {
  let saved = await saveCompany({ matomo_url: "https://stats.ejemplo.test/matomo.php", matomo_site_id: "7" });
  assert.equal(saved.matomo_url, MATOMO, "matomo.php is stripped and it ends in /");
  assert.equal(saved.matomo_site_id, 7, "integer, not text");
  assert.equal(saved.matomo_mode, "cookieless", "the default mode");

  const [badMode, badId] = await Promise.all([
    wp("/settings", { method: "POST", body: { estrategia_web_site: { matomo_mode: "always" } } }),
    wp("/settings", { method: "POST", body: { estrategia_web_site: { matomo_site_id: -1 } } }),
  ]);
  assert.equal(badMode.status, 400, "mode outside the enum → 400");
  assert.equal(badId.status, 400, "negative id → 400");

  // The next save answers with the whole stored option: the refused values did not get in.
  saved = await saveCompany({ matomo_url: "https://stats.ejemplo.test/sub" });
  assert.equal(saved.matomo_url, "https://stats.ejemplo.test/sub/", "always ending in /");
  assert.equal(saved.matomo_mode, "cookieless", "the refused mode was stored");
  assert.equal(saved.matomo_site_id, 7, "the refused id was stored");

  saved = await saveCompany({ matomo_url: "javascript:alert(1)" });
  assert.equal(saved.matomo_url, "https://stats.ejemplo.test/sub/", "an invalid URL does not erase the good one");
});

test("3. hybrid = requireCookieConsent + listening to the notice, declares «statistics»; the Matomo keys are NOT bindable data", async () => {
  const hybrid = { matomo_url: MATOMO, matomo_site_id: 3, matomo_mode: "hybrid" };
  const [page, asked, bound] = await Promise.all([front(hybrid), categories(hybrid), html(`${BASE}${bindingPath}?${as(hybrid)}`)]);
  assert.ok(page.includes("'requireCookieConsent'"));
  assert.ok(!page.includes("'disableCookies'"));
  assert.ok(page.includes("estrategia-web:consent") && page.includes("rememberCookieConsentGiven") && page.includes("forgetCookieConsentGiven"));
  assert.deepEqual(asked, ["maps", "statistics"], "it is appended without overwriting the child's");
  assert.equal(bound.status, 200);
  assert.ok(!bound.text.includes(">https://stats.ejemplo.test/<"), "the Matomo URL does not come out through the company source");
});
