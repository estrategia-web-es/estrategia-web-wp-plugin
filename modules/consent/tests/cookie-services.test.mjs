// NIVEL: 2 — Playground WordPress. NOT LEVEL 1 BECAUSE: what is tested is that the REAL modules (map, google-tags, matomo), loaded by WordPress, declare their services through the filter from their saved configuration or from a map really rendered, and that the confirmed rows reach the policy table the site prints; the merge rules themselves are level 1 (cookies-pure.test.mjs).
//
// Third-party cookies the detector cannot see (17-10-2026). What it is and
// why: header of modules/consent/includes/class-cookie-services.php. The
// merge rules (existing rows untouched, patterns, removal) are level 1, in
// cookies-pure.test.mjs; here, only the wiring.
//
// Seen failing on 17-10-2026 with three mutants at once (the first test's
// diff showed the three extra services): the map module declaring
// google-maps without the «rendered» flag (proposals on a site with no map),
// google-tags declaring google-ads from the GA4 id, and Matomo declaring in
// cookieless mode.
// 28-10-2026: one reset for the file and ONE seeded page (the policy table
// and a map) that is first left unvisited (no map rendered: case 1), then
// visited (case 2) and read again once rows are confirmed (case 3). The rows
// are confirmed in one request (/rows of tests/wp/estrategia-web-cookies-test.php).
// Round 2 (28-10-2026): the google-tags and Matomo configuration travels on
// the list request itself (?ew-options, same file), the request that merges
// what the modules declare: no saves. How those options are SAVED is
// google-tags.test.mjs and matomo.test.mjs.
// Seen red (28-10-2026), one break per case: google-maps expected in case 1
// (a map never shown); the Ads rows expected without «IDE» (case 2); the
// pending «_gcl_au» expected to stay when Ads is switched off (case 3).
//
// Round 2, before(): ONE /setup request (reset, every module on, empty
// registry, the page). Seen red again with the same three breaks.
//
// How to maintain: a module that declares a new service gets its step here
// (off → nothing; on → its rows pending with its service).
import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { BASE, credentials, html, testApi } from "../../../tests/lib.mjs";

const MAP =
  '<!-- wp:estrategia-web/map {"placeName":"Sede de prueba","address":"Plaça de Cort 1, 07001 Palma","embedUrl":"https://www.google.com/maps/embed?pb=!1m18!1m12"} /-->';

const cookiesTest = (path, body) => fetch(BASE + "/wp-json/estrategia-web-cookies-test/v1" + path, { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify(body) });

/** The admin list (it merges what the modules declare), with the modules' options for THIS request (?ew-options). */
async function list(options = {}) {
  const who = (await credentials()).administrator;
  const res = await fetch(BASE + "/wp-json/estrategia-web/v1/cookies?ew-options=" + encodeURIComponent(JSON.stringify(options)), {
    headers: { Authorization: "Basic " + Buffer.from(`${who.user}:${who.password}`).toString("base64") },
  });
  assert.equal(res.status, 200);
  return (await res.json()).cookies;
}
const byService = (cookies) => {
  const out = {};
  for (const [key, e] of Object.entries(cookies)) if (e.source === "module") (out[e.service] ??= []).push(key);
  for (const k in out) out[k].sort();
  return out;
};
/** What a site with these google-tags ids and this Matomo mode has stored. */
const config = (tags, mode) => ({
  estrategia_web_google_tags: { ga4: "", ads: "", ads_form_label: "", gtm: "", mode: "basic", ...tags },
  estrategia_web_site: { matomo_url: "https://matomo.example.test/", matomo_site_id: 3, matomo_mode: mode },
});

let url;
before(async () => {
  // ONE request (/setup, tests/wp/estrategia-web-site-test.php): the reset,
  // every module on (google-tags is off by default), an empty registry (the
  // options the cookies-test reset deletes) and the page.
  const { pages } = await testApi("/setup", {
    method: "POST",
    body: { modules: "all", options: { estrategia_web_cookies: null, estrategia_web_cookies_settings: null, estrategia_web_cookies_test: null, estrategia_web_google_tags: null, estrategia_web_map_google_seen: null }, pages: [{ slug: "prueba-tabla-servicios", content: "<!-- wp:estrategia-web/cookie-table /-->" + MAP }] },
  });
  url = pages[0].url;
  await credentials();
});
// The site reset() leaves the registry and the google-tags option: the other files share this WordPress.
after(() => cookiesTest("/reset", {}));

test("1. each module declares ONLY what its configuration loads (GA4 yes; GTM, cookieless Matomo and a map never shown, no)", async () => {
  assert.deepEqual(
    byService(await list(config({ ga4: "G-TEST12345", gtm: "GTM-ABCD123" }, "cookieless"))),
    { "google-analytics": ["_ga", "_ga_*"] },
    "Tag Manager alone and cookieless Matomo declare nothing; the map module is on but no map was shown; GA4 is not Ads"
  );
});

test("2. a rendered Google map, Ads and hybrid Matomo bring their cookies as pending proposals", async () => {
  const rendered = await html(url + "?nocache=" + Date.now()); // Renders the map: from now on google-maps is declared.
  assert.match(rendered.text, /estrategia-web-map/);
  const cookies = await list(config({ ads: "AW-123456789" }, "hybrid"));
  assert.deepEqual(byService(cookies), {
    "google-maps": ["AEC", "NID", "SOCS", "__Secure-ENID"],
    "google-ads": ["IDE", "_gcl_au", "_gcl_aw", "test_cookie"],
    matomo: ["_pk_id.*", "_pk_ref.*", "_pk_ses.*", "mtm_cookie_consent"],
  });
  assert.equal(cookies.NID.status, "pending");
  assert.equal(cookies.NID.category, "maps");
  assert.equal(cookies.IDE.category, "marketing");
});

test("3. confirmed, a Google Maps cookie goes into the policy table; switching Ads off removes only its PENDING rows", async () => {
  const confirmed = await cookiesTest("/rows", { rows: [{ name: "IDE", status: "confirmed" }, { name: "NID", status: "confirmed" }] });
  assert.ok(confirmed.ok, await confirmed.text());
  // The table and the list with Ads switched off do not depend on each other.
  const [served, cookies] = await Promise.all([html(url + "?nocache=" + Date.now()), list(config({}, "hybrid"))]);
  const page = served.text;
  assert.match(page, /<code>NID<\/code><\/td><td>Google \(google\.com\)/, "NID with its third-party provider in the table");
  assert.match(page, /Mapas y contenido de terceros/);
  assert.ok(!page.includes("<code>AEC</code>"), "pending ones do not show");

  assert.equal(cookies._gcl_au, undefined, "pending Ads rows go away with the service");
  assert.equal(cookies.IDE?.status, "confirmed", "the confirmed one stays: the administrator decides");
});
