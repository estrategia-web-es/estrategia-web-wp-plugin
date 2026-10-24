// NIVEL: 2 — Playground WordPress, because what is tested is the detector REST route (limits, origin, what it stores), the admin route and the HTML the site prints (notice and policy table) according to what is stored.
//
// Cookie registry, detector and policy table (1.1.0). What it is and why:
// headers of includes/class-cookie-registry.php, class-cookie-rest.php and
// class-cookie-table.php. The browser side (that accepting sends only the
// names, the screen) is in cookies-browser.test.mjs (level 3).
//
// Helper routes: /consent-setup (tests/wp/, plays a theme that declares
// categories) and those of modules/consent/tests/wp/estrategia-web-cookies-test.php
// (reset, detector limits, the raw option).
//
// How to maintain: a new detector limit gets its case here with the filter
// set through /setup; never lower the real limits to make it pass.
import { test, beforeEach, after } from "node:test";
import assert from "node:assert/strict";
import { BASE, adminSession, credentials, html, reset, seedPage, testApi, wp } from "../../../tests/lib.mjs";

const cookiesTest = async (path, body) => {
  const res = await fetch(BASE + "/wp-json/estrategia-web-cookies-test/v1" + path, {
    method: body === undefined ? "GET" : "POST",
    headers: { "Content-Type": "application/json" },
    body: body === undefined ? undefined : JSON.stringify(body),
  });
  if (!res.ok) throw new Error(`${path} -> ${res.status} ${await res.text()}`);
  return res.json();
};
const setupConsent = (body) => testApi("/consent-setup", { method: "POST", body });
const front = async (path = "/") => (await html(BASE + path + (path.includes("?") ? "&" : "?") + "nocache=" + Date.now())).text;

/** Detector POST as a browser would send it. */
const report = (names, headers = {}) =>
  fetch(BASE + "/wp-json/estrategia-web/v1/cookies/seen", {
    method: "POST",
    headers: { "Content-Type": "application/json", ...headers },
    body: JSON.stringify({ names }),
  });

/** The admin route, as administrator (or without credentials with as = null). */
async function admin(method, body, as = "administrator", query = "") {
  const who = as ? (await credentials())[as] : null;
  const res = await fetch(BASE + "/wp-json/estrategia-web/v1/cookies" + query, {
    method,
    headers: {
      ...(who ? { Authorization: "Basic " + Buffer.from(`${who.user}:${who.password}`).toString("base64") } : {}),
      ...(body ? { "Content-Type": "application/json" } : {}),
    },
    body: body ? JSON.stringify(body) : undefined,
  });
  return { status: res.status, json: await res.json().catch(() => null) };
}

const offered = (page) => [...page.matchAll(/name="estrategia-web-consent-category"\s+value="([^"]+)"/g)].map((m) => m[1]);

const clean = async () => {
  await reset();
  await cookiesTest("/reset", {});
};
beforeEach(clean);
after(clean); // The other tests share this WordPress: no «ask» or rows left behind.

test("detector: stores only NAMES, replies 202 and with the same IP within the interval gives 429", async () => {
  await setupConsent({ categories: ["maps"] });
  const r = await report(["_ga", "_ga_ABC123", "wp-wpml_current_language", "x=secret", "<b>"]);
  assert.equal(r.status, 202, await r.text());
  const raw = (await cookiesTest("/raw")).raw;
  assert.deepEqual(Object.keys(raw).sort(), ["_ga", "_ga_*", "wp-wpml_current_language"]);
  assert.ok(!JSON.stringify(raw).includes("secret") && !JSON.stringify(raw).includes("<b>"), "no values and no HTML");
  assert.equal(raw["wp-wpml_current_language"].category, "necessary");
  assert.equal(raw["_ga"].status, "pending");

  const again = await report(["another"]);
  assert.equal(again.status, 429, "the same client within the 10 minutes");
  assert.ok(!("another" in (await cookiesTest("/raw")).raw));
});

test("detector: hourly cap for the whole site, more than 60 names = 400, foreign origin = 403, off = 403", async () => {
  await setupConsent({ categories: ["maps"] });
  await cookiesTest("/setup", { interval: 0, cap: 2 });
  assert.equal((await report(["a1"])).status, 202);
  assert.equal((await report(["a2"])).status, 202);
  assert.equal((await report(["a3"])).status, 429, "third in the hour with cap 2");

  await cookiesTest("/reset", {});
  await setupConsent({ categories: ["maps"] });
  await cookiesTest("/setup", { interval: 0 });
  assert.equal((await report(Array.from({ length: 61 }, (_, i) => "n" + i))).status, 400);
  assert.equal((await report(["b1"], { Origin: "https://evil.example" })).status, 403);
  assert.equal((await report(["b2"], { Origin: BASE })).status, 202, "the site's own origin is allowed");

  const off = await wp("/settings", { method: "POST", body: { estrategia_web_cookies_settings: { detector: false, ask: [] } } });
  assert.equal(off.status, 200, off.text);
  assert.equal((await report(["b3"])).status, 403);
  assert.ok(!(await front()).includes('"detector":{'), "when off, the page does not carry the detector configuration");
});

// 1.7.0 (Lavandería production, 24-10-2026: an admin's wp-settings-* and
// wp-wpml_current_admin_language_* landed as pending). Both ends, see
// «Logged-in users» in includes/class-cookie-rest.php. Seen in red
// (24-10-2026) with from_logged_in_user() forced to false: «nothing
// recorded: wp-settings-*, wp-wpml_current_admin_language_d41d8».
test("detector: a logged-in user's report adds nothing and their page has no detector; an anonymous one still adds as pending", async () => {
  await setupConsent({ categories: ["maps"] });
  await cookiesTest("/setup", { interval: 0 });
  const session = await adminSession();
  const logged = await report(["wp-settings-1", "wp-wpml_current_admin_language_d41d8"], { Cookie: session.cookie(), Origin: BASE });
  assert.equal(logged.status, 202, "same answer as always: nothing revealed");
  const raw = (await cookiesTest("/raw")).raw ?? {};
  assert.ok(!("wp-settings-1" in raw) && !Object.keys(raw).some((k) => k.startsWith("wp-wpml_current_admin")), "nothing recorded: " + Object.keys(raw).join(", "));

  const page = (await session.get("/?nocache=" + Date.now())).text;
  assert.ok(page.includes("estrategia-web-consent"), "the logged-in page does have the notice");
  assert.ok(!page.includes('"detector":{'), "but no detector configuration");

  const anonymous = await report(["_ga"]);
  assert.equal(anonymous.status, 202);
  assert.equal((await cookiesTest("/raw")).raw["_ga"].status, "pending", "anonymous reports still come in");
});

test("detector: the page with the notice carries the detector URL, the period and the delay", async () => {
  await setupConsent({ categories: ["maps"] });
  const config = (await front()).match(/"detector":\{"url":"([^"]+)","days":(\d+),"delay":(\d+)\}/);
  assert.ok(config, "the detector configuration is missing from the notice state");
  assert.match(config[1].replace(/\\\//g, "/"), /\/wp-json\/estrategia-web\/v1\/cookies\/seen$/);
  assert.equal(config[2], "7");
});

test("admin: manage_options only; creates, confirms and deletes", async () => {
  assert.equal((await admin("GET", null, null)).status, 401);
  assert.equal((await admin("GET", null, "author")).status, 403);
  const made = await admin("POST", { name: "NID", category: "maps", purpose: "Google Maps sets it when the map loads." });
  assert.equal(made.status, 200, JSON.stringify(made.json));
  assert.equal(made.json.entry.provider, "Google", "provider from the suggestion");
  assert.equal(made.json.entry.source, "manual");
  assert.equal((await admin("POST", { name: "bad name" })).status, 400);
  const list = await admin("GET");
  assert.ok(list.json.cookies.NID);
  assert.equal(list.json.attribution.license, "Apache-2.0");
  assert.equal((await admin("DELETE", null, "administrator", "?name=NID")).json.deleted, true);
});

// The purpose and the free duration below stay in Spanish on purpose: they
// stand for what the client writes on a Spanish site, and they come out as is.
test("policy table: only what is CONFIRMED, by category, in Spanish; block and shortcode", async () => {
  await admin("POST", { name: "estrategia_web_consent", status: "confirmed" });
  await admin("POST", { name: "_ga", status: "confirmed" });
  await admin("POST", { name: "_ga_*", status: "confirmed", purpose: "Distingue visitantes para las estadísticas." });
  await admin("POST", { name: "_gcl_au", status: "pending" });
  await admin("POST", { name: "NID", category: "maps", duration: "Hasta cerrar el navegador", status: "confirmed" });
  const { url } = await seedPage(
    "<!-- wp:estrategia-web/cookie-table /-->" + '<!-- wp:shortcode -->[estrategia_web_cookie_table category="statistics" heading_level="4"]<!-- /wp:shortcode -->',
    "test-cookie-table"
  );
  const page = await front(new URL(url).pathname);
  const block = page.match(/<div [^>]*wp-block-estrategia-web-cookie-table[\s\S]*?<\/figure><\/div>/)?.[0] || "";
  assert.ok(block, "the block does not print the table");
  const titles = [...block.matchAll(/<h3 class="estrategia-web-cookie-table__title">([^<]+)<\/h3>/g)].map((m) => m[1]);
  assert.deepEqual(titles, ["Necesarias", "Analítica", "Mapas y contenido de terceros"], "categories in order and translated");
  assert.match(block, /<code>_ga_\*<\/code><\/td><td>Google Analytics<\/td><td>Distingue visitantes para las estadísticas\.<\/td><td>2 años<\/td>/);
  assert.match(block, /<code>estrategia_web_consent<\/code><\/td><td>Este sitio web<\/td>/);
  assert.match(block, /<td>Hasta cerrar el navegador<\/td>/, "free duration as is");
  assert.ok(!block.includes("_gcl_au"), "pending items do not show in the policy");
  assert.match(page, /<h4 class="estrategia-web-cookie-table__title">Analítica<\/h4>/, "the shortcode with its category and its level");
});

test("policy table: with nothing confirmed it prints NOTHING on the site", async () => {
  await report(["_ga"]);
  const { url } = await seedPage("<!-- wp:estrategia-web/cookie-table /-->", "test-empty-table");
  assert.ok(!(await front(new URL(url).pathname)).includes("estrategia-web-cookie-table"));
});

test("notice 1.1.0: the administrator adds «Preferences»; aliases work when declaring; the usual revision does not change", async () => {
  const base = await setupConsent({ categories: ["maps"] });
  assert.deepEqual(offered(await front()), ["maps"]);
  const r = await wp("/settings", { method: "POST", body: { estrategia_web_cookies_settings: { detector: true, ask: ["preferences"] } } });
  assert.equal(r.status, 200, r.text);
  const page = await front();
  assert.deepEqual(offered(page), ["preferences", "maps"], "preferences first when displayed");
  assert.match(page, /Preferencias<\/strong>/);
  const after = await setupConsent({ categories: ["maps"] });
  assert.notEqual(after.revision, base.revision, "asking about something new changes the revision");

  await wp("/settings", { method: "POST", body: { estrategia_web_cookies_settings: { detector: true, ask: [] } } });
  const aliased = await setupConsent({ categories: ["analytics", "advertising"] });
  assert.deepEqual(aliased.categories, ["statistics", "marketing"]);
  assert.deepEqual(offered(await front()), ["statistics", "marketing"]);
  assert.match(await front(), /Analítica<\/strong>[\s\S]*Publicidad<\/strong>/);
  assert.equal((await setupConsent({ categories: ["maps"] })).revision, base.revision, "without «ask», the revision is the pre-1.1.0 one");
});
