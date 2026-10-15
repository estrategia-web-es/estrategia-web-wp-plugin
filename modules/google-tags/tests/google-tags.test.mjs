// NIVEL: 2 — Playground WordPress, because what is tested is what the site PRINTS (the <head> and the notice categories) depending on the option saved over REST and the active modules.
//
// Google tags (1.1.0). What it does and why: header of
// modules/google-tags/includes/class-google-tags.php. The browser side (what
// is requested from Google before and after accepting, Consent Mode) is in
// google-tags-browser.test.mjs (level 3).
//
// How to maintain: a new field gets its «empty = nothing» case and its
// validation case here.
import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { BASE, html, reset, testApi, wp } from "../../../tests/lib.mjs";

const front = async () => (await html(BASE + "/?nocache=" + Date.now())).text;
const TAG = 'id="estrategia-web-google-tags"';
const offered = (page) => [...page.matchAll(/name="estrategia-web-consent-category"\s+value="([^"]+)"/g)].map((m) => m[1]);
const cleanAll = async () => {
  await reset();
  await fetch(BASE + "/wp-json/estrategia-web-cookies-test/v1/reset", { method: "POST" });
};

/** Switches google-tags on in addition to what the site loads by default. */
async function enable(extra = ["google-tags"], without = []) {
  await testApi("/modules", { method: "POST", body: { active: null } });
  const { loaded } = await testApi("/modules");
  await testApi("/modules", { method: "POST", body: { active: [...loaded.filter((m) => !without.includes(m)), ...extra] } });
}
const save = (value) => wp("/settings", { method: "POST", body: { estrategia_web_google_tags: value } });

before(cleanAll);
after(cleanAll);

test("google-tags: OFF by default; switched on with empty fields it prints nothing", async () => {
  const { loaded } = await testApi("/modules");
  assert.ok(!loaded.includes("google-tags"), "Default: off");
  await enable();
  assert.ok((await testApi("/modules")).loaded.includes("google-tags"));
  const page = await front();
  assert.ok(!page.includes(TAG) && !page.includes("googletagmanager"), "without ids there is nothing from Google");
  assert.deepEqual(offered(page), [], "and no categories in the notice");
});

test("google-tags: with GA4, consent «default» denied BEFORE anything else and no Google <script src> in the HTML", async () => {
  await enable();
  const r = await save({ ga4: "G-TEST12345" });
  assert.equal(r.status, 200, r.text);
  const page = await front();
  assert.ok(page.includes(TAG));
  const head = page.slice(0, page.indexOf("</head>"));
  const ours = head.indexOf(TAG);
  const firstScript = head.search(/<script\b/);
  assert.ok(ours >= 0 && head.lastIndexOf("<script", ours) === firstScript, "it is the FIRST script in the <head>");
  const defaults = head.match(/gtag\('consent', 'default', \{([\s\S]*?)\}\);/)?.[1] || "";
  for (const key of ["ad_storage", "ad_user_data", "ad_personalization", "analytics_storage"]) {
    assert.match(defaults, new RegExp(`${key}: 'denied'`), `${key} does not start denied`);
  }
  assert.ok(!/<script[^>]+src="[^"]*googletagmanager/.test(page), "the Google script is not in the HTML: the browser requests it after accepting");
  assert.match(page, /"ga4":"G-TEST12345"/);
  assert.deepEqual(offered(page), ["statistics"], "GA4 requires Analytics");
});

test("google-tags: Ads requires Advertising; Tag Manager, both; the conversion label only with its Ads account", async () => {
  await enable();
  await save({ ga4: "", ads: "AW-123456789", ads_form_label: "AW-123456789/AbCd_ef-12", gtm: "" });
  let page = await front();
  assert.deepEqual(offered(page), ["marketing"]);
  assert.match(page, /"form":"AW-123456789\\?\/AbCd_ef-12"/);

  await save({ ads: "", gtm: "GTM-ABCD123" });
  page = await front();
  assert.deepEqual(offered(page), ["statistics", "marketing"]);
  assert.match(page, /"form":""/, "without an Ads account, the conversion is not sent");
});

test("google-tags: mistyped ids → 400 over REST; lowercase from the screen is fixed automatically (clean)", async () => {
  await enable();
  assert.equal((await save({ ga4: "UA-1234-1" })).status, 400);
  assert.equal((await save({ gtm: "GTM-<script>" })).status, 400);
  assert.equal((await save({ mode: "loco" })).status, 400);
  const ok = await save({ ga4: "G-ABCDE12345", mode: "advanced" });
  assert.equal(ok.status, 200);
  assert.equal(ok.json.estrategia_web_google_tags.mode, "advanced");
  assert.match(await front(), /"advanced":true/);
});

test("google-tags: Requires consent — with the notice off it does not load (and the modules screen says why)", async () => {
  await enable(["google-tags"], ["consent"]);
  await save({ ga4: "G-TEST12345" });
  const mods = await testApi("/modules");
  assert.ok(!mods.loaded.includes("google-tags"));
  assert.deepEqual(mods.blocked["google-tags"], ["consent"]);
  assert.ok(!(await front()).includes(TAG));
});
