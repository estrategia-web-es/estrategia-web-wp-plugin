// NIVEL: 2 — Playground WordPress, because what is tested is that the REAL modules (map, google-tags, matomo) declare their services through the filter, depending on their configuration or on what was rendered, and that the confirmed rows reach the policy table.
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
//
// How to maintain: a module that declares a new service gets its case here
// (off → nothing; on → its rows pending with its service).
import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { BASE, credentials, html, reset, saveCompany, seedPage, testApi, wp } from "../../../tests/lib.mjs";

const MAP =
  '<!-- wp:estrategia-web/map {"placeName":"Sede de prueba","address":"Plaça de Cort 1, 07001 Palma","embedUrl":"https://www.google.com/maps/embed?pb=!1m18!1m12"} /-->';

const cookiesReset = () => fetch(BASE + "/wp-json/estrategia-web-cookies-test/v1/reset", { method: "POST" });
const clean = async () => {
  await reset();
  await cookiesReset();
  await saveCompany({ matomo_url: "", matomo_site_id: 0, matomo_mode: "cookieless" });
};

async function list() {
  const who = (await credentials()).administrator;
  const res = await fetch(BASE + "/wp-json/estrategia-web/v1/cookies", {
    headers: { Authorization: "Basic " + Buffer.from(`${who.user}:${who.password}`).toString("base64") },
  });
  assert.equal(res.status, 200);
  return (await res.json()).cookies;
}
async function save(body) {
  const who = (await credentials()).administrator;
  const res = await fetch(BASE + "/wp-json/estrategia-web/v1/cookies", {
    method: "POST",
    headers: { Authorization: "Basic " + Buffer.from(`${who.user}:${who.password}`).toString("base64"), "Content-Type": "application/json" },
    body: JSON.stringify(body),
  });
  assert.equal(res.status, 200, await res.text());
}
const byService = (cookies) => {
  const out = {};
  for (const [key, e] of Object.entries(cookies)) if (e.source === "module") (out[e.service] ??= []).push(key);
  for (const k in out) out[k].sort();
  return out;
};
const tags = (value) => wp("/settings", { method: "POST", body: { estrategia_web_google_tags: { ga4: "", ads: "", ads_form_label: "", gtm: "", mode: "basic", ...value } } });

before(async () => {
  await clean();
  await testApi("/modules", { method: "POST", body: { active: null } });
  const { loaded } = await testApi("/modules");
  await testApi("/modules", { method: "POST", body: { active: [...loaded, "google-tags"] } });
});
after(async () => {
  await clean();
  await testApi("/modules", { method: "POST", body: { active: null } });
});

test("cookie services: each module declares ONLY what its configuration loads (GA4 yes; GTM, cookieless Matomo and a map never shown, no)", async () => {
  await tags({ ga4: "G-TEST12345", gtm: "GTM-ABCD123" });
  await saveCompany({ matomo_url: "https://matomo.example.test/", matomo_site_id: 3, matomo_mode: "cookieless" });
  assert.deepEqual(
    byService(await list()),
    { "google-analytics": ["_ga", "_ga_*"] },
    "Tag Manager alone and cookieless Matomo declare nothing; the map module is on but no map was shown; GA4 is not Ads"
  );
});

test("cookie services: a rendered Google map, Ads and hybrid Matomo bring their cookies as pending proposals", async () => {
  const { url } = await seedPage(MAP, "prueba-mapa-cookies");
  assert.match((await html(url + "?nocache=" + Date.now())).text, /estrategia-web-map/);
  await tags({ ads: "AW-123456789" });
  await saveCompany({ matomo_url: "https://matomo.example.test/", matomo_site_id: 3, matomo_mode: "hybrid" });
  const cookies = await list();
  assert.deepEqual(byService(cookies), {
    "google-maps": ["AEC", "NID", "SOCS", "__Secure-ENID"],
    "google-ads": ["IDE", "_gcl_au", "_gcl_aw", "test_cookie"],
    matomo: ["_pk_id.*", "_pk_ref.*", "_pk_ses.*", "mtm_cookie_consent"],
  });
  assert.equal(cookies.NID.status, "pending");
  assert.equal(cookies.NID.category, "maps");
  assert.equal(cookies.IDE.category, "marketing");
});

test("cookie services: confirmed, a Google Maps cookie goes into the policy table; switching Ads off removes only its PENDING rows", async () => {
  await tags({ ads: "AW-123456789" });
  await list();
  await save({ name: "IDE", status: "confirmed" });
  const { url } = await seedPage("<!-- wp:estrategia-web/cookie-table /-->" + MAP, "prueba-tabla-servicios");
  await html(url + "?nocache=" + Date.now()); // Renders the map: from now on google-maps is declared.
  await list();
  await save({ name: "NID", status: "confirmed" });
  const page = (await html(url + "?nocache=" + Date.now())).text;
  assert.match(page, /<code>NID<\/code><\/td><td>Google \(google\.com\)/, "NID with its third-party provider in the table");
  assert.match(page, /Mapas y contenido de terceros/);
  assert.ok(!page.includes("<code>AEC</code>"), "pending ones do not show");

  await tags({ ads: "" });
  const cookies = await list();
  assert.equal(cookies._gcl_au, undefined, "pending Ads rows go away with the service");
  assert.equal(cookies.IDE?.status, "confirmed", "the confirmed one stays: the administrator decides");
});
