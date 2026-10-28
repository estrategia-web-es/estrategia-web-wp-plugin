// NIVEL: 2 — Playground WordPress. NOT LEVEL 1 BECAUSE: what is tested is the admin REST route's permissions and answers, and the HTML the site prints (the policy table block and shortcode, the notice's categories and revision) from what is stored in WordPress; the registry's pure rules are level 1 (cookies-pure.test.mjs).
//
// Cookie registry: the admin route, the policy table and the categories the
// administrator adds to the notice (1.1.0). What it is and why: headers of
// includes/class-cookie-registry.php, class-cookie-rest.php and
// class-cookie-table.php. The detector (what visitors report) is
// cookies.test.mjs; the «Cookies» screen, cookies-browser.test.mjs.
//
// Split from cookies.test.mjs on 28-10-2026 (together they were 41 s, too
// close to the 45 s limit with several files at once). The policy rows are
// written in one request (/rows of tests/wp/estrategia-web-cookies-test.php,
// Cookie_Registry::save() — what the admin route calls, tested for real in
// case 1); «the theme» declares through ?ew-consent-setup on the page
// request. «Without ask, the revision is the pre-1.1.0 one» is
// consent.test.mjs case 2 (the parent's formula, no ask). Round 2
// (28-10-2026): «Preferences» is read with the settings on the request
// (?ew-options); saving the settings over REST is cookies.test.mjs (off).
// Seen red (28-10-2026), one break per case: the attribution licence
// expected «MIT» (1); the table titles expected in another order (2);
// «preferences» expected last (3).
//
// How to maintain: a new admin field gets its step in case 1; a new column
// of the table, its assertion in case 2. The trap: the site reset() does not
// touch the registry or its settings, so before() and after() call the
// cookies-test reset.
import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import crypto from "node:crypto";
import { BASE, credentials, html, testApi } from "../../../tests/lib.mjs";

const cookiesTest = async (path, body) => {
  const res = await fetch(BASE + "/wp-json/estrategia-web-cookies-test/v1" + path, { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify(body) });
  if (!res.ok) throw new Error(`${path} -> ${res.status} ${await res.text()}`);
  return res.json();
};
const front = async (path = "/") => (await html(BASE + path + (path.includes("?") ? "&" : "?") + "nocache=" + Date.now())).text;

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
const revision = (page) => page.match(/"revision":"([0-9a-f]+)"/)?.[1];

let tablePath;
before(async () => {
  // ONE request (/setup, tests/wp/estrategia-web-site-test.php): the reset,
  // an empty registry (the options the cookies-test reset deletes) and the page.
  const { pages } = await testApi("/setup", {
    method: "POST",
    body: {
      options: { estrategia_web_cookies: null, estrategia_web_cookies_settings: null, estrategia_web_cookies_test: null, estrategia_web_google_tags: null, estrategia_web_map_google_seen: null },
      pages: [
        {
          slug: "test-cookie-table",
          content: "<!-- wp:estrategia-web/cookie-table /-->" + '<!-- wp:shortcode -->[estrategia_web_cookie_table category="statistics" heading_level="4"]<!-- /wp:shortcode -->',
        },
      ],
    },
  });
  tablePath = new URL(pages[0].url).pathname;
  await Promise.all([cookiesTest("/rows", { rows: [{ name: "_gcl_au", status: "pending" }] }), credentials()]);
});
after(() => cookiesTest("/reset", {}));

test("1. admin route: manage_options only; creates (with the suggestion), refuses a bad name, lists, deletes", async () => {
  const [anonymous, author, made, bad] = await Promise.all([
    admin("GET", null, null),
    admin("GET", null, "author"),
    admin("POST", { name: "NID", category: "maps", purpose: "Google Maps sets it when the map loads." }),
    admin("POST", { name: "bad name" }),
  ]);
  assert.equal(anonymous.status, 401);
  assert.equal(author.status, 403);
  assert.equal(made.status, 200, JSON.stringify(made.json));
  assert.equal(made.json.entry.provider, "Google", "provider from the suggestion");
  assert.equal(made.json.entry.source, "manual");
  assert.equal(bad.status, 400);
  const list = await admin("GET");
  assert.ok(list.json.cookies.NID);
  assert.equal(list.json.attribution.license, "Apache-2.0");
  assert.equal((await admin("DELETE", null, "administrator", "?name=NID")).json.deleted, true);
});

// The purpose and the free duration below stay in Spanish on purpose: they
// stand for what the client writes on a Spanish site, and they come out as is.
test("2. policy table: NOTHING while nothing is confirmed; then only what is CONFIRMED, by category, in Spanish; block and shortcode", async () => {
  // Only a pending row so far (before()).
  assert.ok(!(await front(tablePath)).includes("estrategia-web-cookie-table"), "with nothing confirmed the table prints NOTHING on the site");

  await cookiesTest("/rows", {
    rows: [
      { name: "estrategia_web_consent", status: "confirmed" },
      { name: "_ga", status: "confirmed" },
      { name: "_ga_*", status: "confirmed", purpose: "Distingue visitantes para las estadísticas." },
      { name: "NID", category: "maps", duration: "Hasta cerrar el navegador", status: "confirmed" },
    ],
  });
  const page = await front(tablePath);
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

test("3. notice 1.1.0: the administrator's «Preferences» goes first and changes the usual revision; aliases work when declaring", async () => {
  // The administrator's settings asking about «preferences», for these requests (?ew-options): nothing is saved.
  const asks = encodeURIComponent(JSON.stringify({ estrategia_web_cookies_settings: { detector: true, ask: ["preferences"] } }));
  const visit = async (categories) => (await html(`${BASE}/?ew-consent-setup=${encodeURIComponent(JSON.stringify({ categories }))}&ew-options=${asks}&nocache=${Date.now()}`)).text;
  const [page, aliased] = await Promise.all([visit(["maps"]), visit(["analytics", "advertising"])]);
  assert.deepEqual(offered(page), ["preferences", "maps"], "preferences first when displayed");
  assert.match(page, /Preferencias<\/strong>/);
  const usual = crypto.createHash("md5").update("maps|1").digest("hex").slice(0, 8); // The parent's formula, without «ask».
  assert.notEqual(revision(page), usual, "asking about something new changes the revision");
  assert.deepEqual(offered(aliased), ["preferences", "statistics", "marketing"], "analytics and advertising are aliases");
  assert.match(aliased, /Analítica<\/strong>[\s\S]*Publicidad<\/strong>/);
});
