// NIVEL: 2 — Playground WordPress. NOT LEVEL 1 BECAUSE: what is tested is the HTML the server sends for a page with a map when the cookie notice is on, and that only exists rendered by WordPress (the map block and the notice in wp_footer). NOT LEVEL 3: it is read from the served HTML, without running its JavaScript; what needs the browser (requests, what is seen, the iframe after accepting) is map-consent.test.mjs.
//
// estrategia-web/map WITH the cookie notice, in the served HTML (the notice
// is printed in wp_footer with «hidden» and its JS opens it):
//  1. the notice is there and offers «maps»;
//  2. no iframe has a src before anything is accepted;
//  3. the static map is there and is served by the site itself (and the one
//     from a third-party domain of the same page is dropped).
//
// Moved from Lavandería (tests/consent-maps-html.test.mjs, its points 2, 3 and
// 4) on 25-10-2026, on the map seed's /prueba-mapa-estatico/. Lavandería keeps
// what is its site: which categories ITS notice offers (its hybrid Matomo
// adds «statistics»), its «Powered by» and its policy page, and its location
// cards. The «Powered by» markup is consent.test.mjs.
//
// How to maintain: the page is the map seed's; the selectors are the map's
// render.php and the notice's.
//
// Seen red (25-10-2026): expecting the category «statistics», step 1 fails;
// restored, green. 28-10-2026: the three setup requests go together (the
// seed only writes posts, consent-setup an option); seen red again the
// same way.
import { test, before } from "node:test";
import assert from "node:assert/strict";
import { reset, testApi, page, BASE } from "../../../tests/lib.mjs";
import { BLOCK_MODULES } from "../../../tests/browser.mjs";

let served;
before(async () => {
  await reset();
  // Independent of each other (the seed only writes posts; consent-setup an option).
  const [, res] = await Promise.all([
    testApi("/modules", { method: "POST", body: { active: [...BLOCK_MODULES, "consent"] } }),
    fetch(`${BASE}/wp-json/estrategia-web-map-test/v1/seed`, { method: "POST" }),
    testApi("/consent-setup", { method: "POST", body: { categories: ["maps"] } }),
  ]);
  assert.ok(res.ok, `map seed -> ${res.status}`);
  served = await page("/prueba-mapa-estatico/");
  assert.equal(served.status, 200);
});

test("1. the notice is in the HTML and offers «maps»", () => {
  const notice = served.document.querySelector("#estrategia-web-consent");
  assert.ok(notice, "no cookie notice in the HTML");
  const offered = [...notice.querySelectorAll('input[name="estrategia-web-consent-category"]')].map((e) => e.value);
  assert.ok(offered.includes("maps"), `the notice does not offer «maps» (it offers: ${offered.join(", ") || "nothing"})`);
});

test("2. no iframe with a src in the served HTML", () => {
  const framed = [...served.document.querySelectorAll("iframe")].map((e) => e.getAttribute("src") || "").filter(Boolean);
  assert.deepEqual(framed, [], "iframes with a src in the served HTML");
});

test("3. the static map, served by the site itself", () => {
  const imgs = [...served.document.querySelectorAll(".estrategia-web-map img.estrategia-web-map__static")];
  assert.ok(imgs.length, "no static map");
  for (const img of imgs) {
    assert.equal(new URL(img.getAttribute("src"), BASE).host, new URL(BASE).host, `a static map not served by the site (${img.getAttribute("src")})`);
  }
});
