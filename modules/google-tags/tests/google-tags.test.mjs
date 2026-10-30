// NIVEL: 2 — Playground WordPress. NOT LEVEL 1 BECAUSE: what is tested is what the site PRINTS (the <head> and the notice categories) from the module's option, that option's register_setting validation over REST, and the modules WordPress loads, including «requires consent».
//
// Google tags (1.1.0). What it does and why: header of
// modules/google-tags/includes/class-google-tags.php. The browser side (what
// is requested from Google before and after accepting, Consent Mode) is in
// the browser scenarios 22-27 (modules/google-tags/tests/browser/).
//
// 28-10-2026: the module is switched on ONCE in before() (it was switched
// on again in every test: three requests each). Round 2 (28-10-2026): the
// READING cases pass the option on the request itself (?ew-options,
// modules/consent/tests/wp/estrategia-web-cookies-test.php) and go at once;
// HOW it is saved (the 400s, the value stored) is case 3, over REST, and
// case 4 reads what case 3 stored.
// Seen red (28-10-2026), one break per case: «statistics» expected with
// empty fields (1); GA4 expected to require «marketing» (2); a mistyped id
// expected to be accepted (3); the block expected from «site» instead of
// «consent» (4).
//
// How to maintain: a new field gets its «empty = nothing» and its «what it
// requires» reading in case 2 and its validation in case 3. The traps: the
// modules list is saved in one request and read in the NEXT one, so «switch
// on» and «read what loaded» are never the same request; and the override is
// only for reading — a save carries none (sanitize() merges with the stored
// value).
import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { BASE, credentials, html, reset, testApi, wp } from "../../../tests/lib.mjs";

/** The front page with the google-tags option `tags` for this request only (none: what is stored). */
const front = async (tags) =>
  (await html(`${BASE}/?${tags ? "ew-options=" + encodeURIComponent(JSON.stringify({ estrategia_web_google_tags: tags })) + "&" : ""}nocache=${Date.now()}`)).text;
const TAG = 'id="estrategia-web-google-tags"';
const offered = (page) => [...page.matchAll(/name="estrategia-web-consent-category"\s+value="([^"]+)"/g)].map((m) => m[1]);
const cookiesReset = () => fetch(BASE + "/wp-json/estrategia-web-cookies-test/v1/reset", { method: "POST" });
const save = (value) => wp("/settings", { method: "POST", body: { estrategia_web_google_tags: value } });

let byDefault;
before(async () => {
  await reset();
  // cookies-test reset also deletes estrategia_web_google_tags (another file may have left it).
  const [, { loaded }] = await Promise.all([cookiesReset(), testApi("/modules"), credentials()]);
  byDefault = loaded;
  await testApi("/modules", { method: "POST", body: { active: [...loaded, "google-tags"] } });
});
// The site reset() does not delete the google-tags option: the other files share this WordPress.
after(cookiesReset);

test("1. OFF by default; switched on with empty fields it prints nothing", async () => {
  assert.ok(!byDefault.includes("google-tags"), "Default: off");
  const [mods, page] = await Promise.all([testApi("/modules"), front()]);
  assert.ok(mods.loaded.includes("google-tags"));
  assert.ok(!page.includes(TAG) && !page.includes("googletagmanager"), "without ids there is nothing from Google");
  assert.deepEqual(offered(page), [], "and no categories in the notice");
});

test("2. what each id prints: GA4 (consent «default» denied FIRST, no Google <script src>), Ads + its conversion label, Tag Manager, the advanced mode", async () => {
  const [ga4, ads, gtm] = await Promise.all([
    front({ ga4: "G-TEST12345" }),
    front({ ads: "AW-123456789", ads_form_label: "AW-123456789/AbCd_ef-12" }),
    front({ ga4: "G-ABCDE12345", gtm: "GTM-ABCD123", mode: "advanced" }),
  ]);

  // GA4: our script is the FIRST in the <head>, everything starts denied, Google is not in the HTML.
  assert.ok(ga4.includes(TAG));
  const head = ga4.slice(0, ga4.indexOf("</head>"));
  const ours = head.indexOf(TAG);
  const firstScript = head.search(/<script\b/);
  assert.ok(ours >= 0 && head.lastIndexOf("<script", ours) === firstScript, "it is the FIRST script in the <head>");
  const defaults = head.match(/gtag\('consent', 'default', \{([\s\S]*?)\}\);/)?.[1] || "";
  for (const key of ["ad_storage", "ad_user_data", "ad_personalization", "analytics_storage"]) {
    assert.match(defaults, new RegExp(`${key}: 'denied'`), `${key} does not start denied`);
  }
  assert.ok(!/<script[^>]+src="[^"]*googletagmanager/.test(ga4), "the Google script is not in the HTML: the browser requests it after accepting");
  assert.match(ga4, /"ga4":"G-TEST12345"/);
  assert.deepEqual(offered(ga4), ["statistics"], "GA4 requires Analytics");

  // Ads requires Advertising; the conversion label goes with its Ads account.
  assert.deepEqual(offered(ads), ["marketing"]);
  assert.match(ads, /"form":"AW-123456789\\?\/AbCd_ef-12"/);

  // Tag Manager requires both; without an Ads account no conversion; the advanced mode reaches the page.
  assert.deepEqual(offered(gtm), ["statistics", "marketing"]);
  assert.match(gtm, /"form":""/, "without an Ads account, the conversion is not sent");
  assert.match(gtm, /"advanced":true/);
});

test("3. saving over REST: mistyped ids and modes → 400; a good save is stored clean", async () => {
  // The refused saves change nothing, so they go with the good one.
  const [ok, ...bad] = await Promise.all([save({ ga4: "G-TEST12345", mode: "advanced" }), save({ ga4: "UA-1234-1" }), save({ gtm: "GTM-<script>" }), save({ mode: "loco" })]);
  assert.equal(ok.status, 200, ok.text);
  assert.equal(ok.json.estrategia_web_google_tags.ga4, "G-TEST12345");
  assert.equal(ok.json.estrategia_web_google_tags.mode, "advanced");
  assert.deepEqual(bad.map((b) => b.status), [400, 400, 400]);
});

test("4. Requires consent — with the notice off it does not load (and the modules screen says why)", async () => {
  // GA4 is stored since case 3: switched on, it would print its tag.
  await testApi("/modules", { method: "POST", body: { active: [...byDefault.filter((m) => m !== "consent"), "google-tags"] } });
  const [mods, page] = await Promise.all([testApi("/modules"), front()]);
  assert.ok(!mods.loaded.includes("google-tags"));
  assert.deepEqual(mods.blocked["google-tags"], ["consent"]);
  assert.ok(!page.includes(TAG));
});
