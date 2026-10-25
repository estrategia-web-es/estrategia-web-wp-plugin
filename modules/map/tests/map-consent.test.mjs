// NIVEL: 3 — browser. NOT LEVEL 2 BECAUSE: which requests leave for other hosts, whether the static map IS SEEN (loaded, with a width, the srcset size the browser chose), and the iframe appearing after «Accept all» or «Ver mapa» only exist with the page running its JavaScript; the served HTML is map-consent-html.test.mjs.
//
// estrategia-web/map WITH the cookie notice (the consent module on and a
// theme that declares «maps», as Lavandería's functions.php does). map.test.mjs
// tests the map without a notice; this is the two together:
//  1. with the notice unanswered, the page asks NOTHING from another host
//     (Google Maps, fonts, a CDN…): that would send the visitor's IP to a
//     third party without consent. The notice is seen; no script has given
//     an iframe a src; the static map is seen and served by the site itself
//     (currentSrc: the size the browser picked from the srcset);
//  2. after «Accept all», the Google Maps iframe has its src and is seen;
//  3. in a new visit, «Ver mapa» with the notice unanswered loads that map
//     and GRANTS «maps» (window.estrategiaWebConsent.has("maps")).
// Requests to other hosts are ABORTED (recorded, never sent): the test does
// not depend on the network and sends nothing to Google.
//
// Moved from Lavandería (tests/consent-maps.test.mjs, its points 1, 4, 5 and
// 6) on 25-10-2026, on the map module's own page (/prueba-mapa-estatico/ of
// its seed). Lavandería keeps what is its site: none of ITS pages asks a
// third party (its fonts, its embeds), its hybrid Matomo's cookies, its
// location cards and the notice's contrast with its colours.
//
// How to maintain: the page is the map seed's (tests/wp/estrategia-web-map-test.php).
// Edge case: a request to another host AFTER accepting is legitimate (that is
// what accepting is for); only what leaves before is watched. The trap: the
// iframe has loading="lazy" and is not requested until seen, so step 2 checks
// its src and that it is seen, not the request.
//
// Seen red (25-10-2026): expecting the static map wider than 2000 px, step 1
// fails («the static map is not seen»); restored, green.
import { test, before, after } from "node:test";
import { withBrowser, assert, BASE_URL, BLOCK_MODULES } from "../../../tests/browser.mjs";
import { reset, testApi } from "../../../tests/lib.mjs";

const PAGE = `${BASE_URL}/prueba-mapa-estatico/`;
const OWN_HOST = new URL(BASE_URL).host;

before(async () => {
  await reset();
  await testApi("/modules", { method: "POST", body: { active: [...BLOCK_MODULES, "consent"] } });
  const res = await fetch(`${BASE_URL}/wp-json/estrategia-web-map-test/v1/seed`, { method: "POST" });
  assert.ok(res.ok, `map seed -> ${res.status}`);
  await testApi("/consent-setup", { method: "POST", body: { categories: ["maps"] } });
});
after(() => testApi("/modules", { method: "POST", body: { active: null } }));

/** A page in a new context that aborts and records every request to another host. */
async function guarded(newPage) {
  const page = await newPage({ viewport: { width: 1280, height: 900 } });
  page.outside = [];
  await page.context().route("**/*", (route) => {
    const url = new URL(route.request().url());
    if (["http:", "https:"].includes(url.protocol) && url.host !== OWN_HOST) {
      page.outside.push(route.request().url());
      return route.abort();
    }
    return route.continue();
  });
  return page;
}

test("map + notice: nothing to third parties before consent; the iframe after «Accept all» and after «Ver mapa»", () =>
  withBrowser(async ({ newPage }) => {
    // 1. Unanswered.
    const page = await guarded(newPage);
    await page.goto(PAGE, { waitUntil: "networkidle" });
    await page.mouse.wheel(0, 20000);
    await page.waitForTimeout(300);
    assert.ok(await page.locator("#estrategia-web-consent").isVisible(), "the cookie notice is not seen");
    const framed = await page.$$eval("iframe", (els) => els.map((e) => e.getAttribute("src") || "").filter(Boolean));
    assert.deepEqual(framed, [], "iframes with a src before accepting");
    const img = page.locator(".estrategia-web-map img.estrategia-web-map__static").first();
    assert.ok(await img.count(), "no static map");
    await img.scrollIntoViewIfNeeded();
    const info = await img.evaluate((e) => ({ src: e.currentSrc || e.src, natural: e.naturalWidth, width: e.getBoundingClientRect().width }));
    assert.ok(info.natural > 0 && info.width > 200, `the static map is not seen (${JSON.stringify(info)})`);
    assert.equal(new URL(info.src).host, OWN_HOST, `the static map is not served by the site itself (${info.src})`);
    assert.deepEqual(page.outside, [], `requests to third parties without consent:\n${page.outside.join("\n")}`);
    assert.deepEqual(page.errors, [], page.errors.join("\n"));

    // 2. Accept all → the iframe.
    await page.locator('#estrategia-web-consent [data-estrategia-web-consent-action="accept"]').click();
    const iframe = page.locator(".estrategia-web-map iframe.estrategia-web-map__iframe").first();
    await iframe.scrollIntoViewIfNeeded();
    await page.waitForFunction(() => document.querySelector(".estrategia-web-map iframe")?.getAttribute("src"));
    assert.match(await iframe.getAttribute("src"), /^https:\/\/www\.google\.com\/maps\/embed/, "after accepting, the iframe is not Google Maps'");
    assert.ok(await iframe.isVisible(), "after accepting, the iframe is not seen");
    await page.context().close();

    // 3. «Ver mapa» with the notice unanswered → that map, and «maps» granted.
    const other = await guarded(newPage);
    await other.goto(PAGE, { waitUntil: "networkidle" });
    const map = other.locator(".estrategia-web-map").first();
    await map.scrollIntoViewIfNeeded();
    await map.locator(".estrategia-web-map__load").click();
    await other.waitForFunction(() => document.querySelector(".estrategia-web-map iframe")?.getAttribute("src"));
    assert.ok(await map.locator("iframe").isVisible(), "«Ver mapa» does not show the iframe");
    assert.ok(await other.evaluate(() => window.estrategiaWebConsent?.has("maps")), "«Ver mapa» does not grant «maps»");
    await other.context().close();
  })
);
