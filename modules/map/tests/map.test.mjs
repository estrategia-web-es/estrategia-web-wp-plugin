// NIVEL: 3 — browser. NOT LEVEL 2 BECAUSE: what is tested is what only exists with the page painted and running: the network REQUESTS (nothing to Google before the click), which text is IN VIEW (innerText), where boxes are painted (card over the image when wide, below it when narrow; the HEIGHT of the map inside a flex container) and what «Ver mapa» does when clicked.
/**
 * estrategia-web/map WITHOUT the cookie notice (the consent module is off
 * here, on purpose: the map must work without a notice; with the notice it is
 * map-consent.test.mjs). ONE browser pass per seeded page (the seed is
 * modules/map/tests/wp/estrategia-web-map-test.php); every check keeps its
 * reason. Merged on 28-10-2026 from map, map-card, map-in-flex, map-query,
 * map-static and map-details (nine page loads became four: a second width is
 * a resize of the same page, not a new load).
 *
 * 1. /prueba-mapa/ — a map without an image (the normal case):
 *    - map-in-flex: inside a vertical Group (column flex, automatic height)
 *      with overflow:hidden the map does NOT shrink. The bug, 03-10-2026:
 *      Lavandería's child rounds the map with overflow:hidden inside a
 *      vertical Group; a flex child with overflow other than visible has a
 *      minimum size of 0 and the map ended up 2 px tall. The fix is
 *      `inline-size: 100%` on .estrategia-web-map (blocks/map/style.css:
 *      the frame was measured with width 0); `flex: none`, tried in 0.3.1,
 *      does not fix it (removing it alone leaves this green). THE TRAP,
 *      which cost a version: a container with a FIXED height made a different
 *      bug (shrinking) and the test passed with a fix that fixed nothing. The
 *      wrapper is undone after measuring, so the rest of the page is the
 *      seeded one.
 *    - map-card: instead of a map photo, a card with the place's DETAILS in
 *      real text (name, address, phone with tel:, hours with their line
 *      breaks), «Ver mapa» and «Abrir en Google Maps»; no <img>.
 *    - map: no notice and no window.estrategiaWebConsent without the consent
 *      module, no REQUEST to Google before the click (requests, not the text
 *      «google.com»: the «Abrir en Google Maps» link is there by design), and
 *      after «Ver mapa» the embed is requested and the iframe shows. Seen
 *      failing on 05-10-2026: view.js calling estrategiaWebConsent.grant()
 *      without «?.» blew up on click.
 * 2. /prueba-mapa-consulta/ — map-query: inside a Query each map carries ITS
 *    post's address (bound to core/post-meta) and, with no image in the block,
 *    the post's featured image; the post without one gets the card.
 * 3. /prueba-mapa-estatico/ — map-static (0.5.0, staticMapUrl): the image
 *    served by the site ITSELF with the card on top on the left (not over the
 *    marker), NOTHING requested from another domain, a staticMapUrl from
 *    another domain discarded; at 375 px the card goes BELOW the map; «Ver
 *    mapa» loads THAT map only, and without the notice plugin there is no
 *    consent cookie. Seen failing on 17-10-2026: in Twenty Twenty-Five the
 *    card measured 42 % PLUS its padding and reached the marker
 *    (box-sizing: border-box in blocks/map/style.css).
 * 4. /prueba-mapa-sin-datos/ — map-details (0.6.0, showDetails:false), at
 *    1200 and 402 px: the address is read ONCE on the page; with a static map
 *    the frame shows only the image and the two buttons below; without an
 *    image there is no empty box before the click, and «Ver mapa» paints the
 *    map with its height. Two links to different places never share a name
 *    (Lighthouse). Seen failing on 03-10-2026 with 0.5.0: the address came
 *    out twice.
 *
 * Google is answered by page.route: the test never goes out to the Internet,
 * it checks what is REQUESTED.
 *
 * Round 2 (28-10-2026): the reset and the modules in ONE /setup request
 * (tests/wp/estrategia-web-site-test.php), then the seed. Running the four
 * pages at the same time was tried and measured: no gain (the WordPress, not
 * the browser, is the bottleneck), so they stay in a row. Seen red again
 * with the same four breaks.
 *
 * How to maintain: a new check of the map without notice goes into the pass
 * of the page it looks at; a new page goes into the seed and gets its own
 * test here. Two widths of one page are a setViewportSize, not a second load.
 *
 * Seen red (28-10-2026) with: «inline-size: 100%» removed from
 * blocks/map/style.css (step 1, «figure 0 px, frame 0 px»; the old
 * map-in-flex header named «flex: none», which alone does not turn it red); the expected address of the second
 * Query post changed (step 2); «box-sizing: border-box» removed from the card
 * (step 3, the card covers the centre); the expected «times» of the address
 * set to 2 (step 4).
 */
import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { BASE_URL, BLOCK_MODULES } from "../../../tests/browser.mjs";
import { playwright, testApi } from "../../../tests/lib.mjs";

const own = new URL(BASE_URL).host;
const isGoogle = (url) => /google\.|gstatic\.|googleapis\./.test(new URL(url).hostname);

let browser;
before(async () => {
  // The reset and the block modules in ONE request (/setup, tests/wp/estrategia-web-site-test.php), then the seed.
  await testApi("/setup", { method: "POST", body: { modules: BLOCK_MODULES } });
  const seed = await fetch(`${BASE_URL}/wp-json/estrategia-web-map-test/v1/seed`, { method: "POST" });
  assert.ok(seed.ok, `map seed -> ${seed.status}`);
  browser = await playwright().chromium.launch();
});
after(() => browser?.close());

/** A page in a new context that records errors, every request to another host, and answers Google with a stub. */
async function open(path, viewport = { width: 1200, height: 900 }) {
  const context = await browser.newContext({ viewport });
  const page = await context.newPage();
  page.errors = [];
  page.foreign = [];
  page.on("console", (m) => m.type() === "error" && page.errors.push(`console: ${m.text()}`));
  page.on("pageerror", (e) => page.errors.push(`pageerror: ${e.message}`));
  page.on("request", (r) => r.url().startsWith("http") && new URL(r.url()).host !== own && page.foreign.push(r.url()));
  await page.route(/google\.com/, (route) => route.fulfill({ status: 200, contentType: "text/html", body: "<p>mapa</p>" }));
  await page.goto(`${BASE_URL}${path}`, { waitUntil: "networkidle" });
  return page;
}
const loaded = (page, image) => image.elementHandle().then((el) => page.waitForFunction((e) => e.complete && e.naturalWidth > 0, el));

test("1. /prueba-mapa/: no shrinking in a vertical Group, the details card, nothing to Google until «Ver mapa»", async () => {
  const page = await open("/prueba-mapa/");

  // map-in-flex: wrap it as a left-aligned WordPress vertical Group renders, measure, undo.
  const measure = await page.evaluate(() => {
    const map = document.querySelector(".estrategia-web-map");
    const wrap = document.createElement("div");
    wrap.style.cssText = "display:flex;flex-direction:column;align-items:flex-start";
    map.before(wrap);
    wrap.append(map);
    map.style.overflow = "hidden";
    // The child's «map-card» style: the caption goes ON TOP, out of the flow
    // (with in-flow content of its own width the bug does not show).
    const caption = map.querySelector(".estrategia-web-map__caption");
    if (caption) caption.style.position = "absolute";
    const frame = map.querySelector(".estrategia-web-map__frame");
    const out = { figure: map.getBoundingClientRect().height, frame: frame.getBoundingClientRect().height };
    if (caption) caption.style.position = "";
    map.style.overflow = "";
    wrap.replaceWith(map);
    return out;
  });
  // With the bug the frame is measured with width 0 and its aspect-ratio height is 0.
  assert.ok(measure.frame > 100 && measure.figure > 100, `the map gets squashed inside a vertical Group: figure ${measure.figure} px, frame ${measure.frame} px`);
  assert.ok(measure.figure >= measure.frame, `the map shrinks inside a flex: the figure is ${measure.figure} px and its frame ${measure.frame} px`);

  // Without the consent module: neither notice nor API.
  assert.equal(await page.locator("#estrategia-web-consent").count(), 0, "the parent no longer paints the cookie notice");
  assert.equal(await page.evaluate(() => typeof window.estrategiaWebConsent), "undefined", "without the plugin there should be no window.estrategiaWebConsent");

  // map-card.
  const map = page.locator(".estrategia-web-map");
  await map.scrollIntoViewIfNeeded();
  await page.waitForLoadState("networkidle");
  assert.equal(await map.locator("img").count(), 0, "with no image chosen there should be no <img>");
  const card = map.locator(".estrategia-web-map__card");
  assert.ok(await card.isVisible(), "the details card is missing");
  const text = await card.innerText();
  for (const expected of ["Ayuntamiento de prueba", "Plaça de Cort 1, 07001 Palma", "+34 971 22 59 00", "De lunes a viernes\nDe 9:00 a 14:00"]) {
    assert.ok(text.includes(expected), `the card does not say "${expected}":\n${text}`);
  }
  assert.equal(await card.locator('a[href="tel:+34971225900"]').count(), 1, "the phone does not link to tel:");
  assert.ok(await map.getByRole("link", { name: /Abrir en Google Maps/ }).isVisible(), "«Abrir en Google Maps» is missing");

  // Nothing to Google before the click; the embed after it.
  const google = page.foreign.filter(isGoogle);
  assert.deepEqual(google, [], `requests to Google before the click:\n${google.join("\n")}`);
  // No src or src="" (about:blank): hydration leaves "" — see blocks/map/view.js.
  const srcBefore = await page.locator(".estrategia-web-map__iframe").getAttribute("src");
  assert.ok(!srcBefore, `the iframe already has a src before the click: ${srcBefore}`);
  const embed = page.waitForRequest((r) => /google\.com\/maps\/embed/.test(r.url()));
  await page.getByRole("button", { name: "Ver mapa" }).click();
  await embed;
  assert.ok(await page.locator(".estrategia-web-map__iframe").isVisible(), "the iframe is not visible after the click");
  assert.ok(await page.getByRole("button", { name: "Ver mapa" }).isHidden(), "the button is still visible");
  assert.deepEqual(page.errors, [], page.errors.join("\n"));
  await page.context().close();
});

test("2. /prueba-mapa-consulta/: inside a Query each map has its post's address and featured image (or the card)", async () => {
  const page = await open("/prueba-mapa-consulta/");
  const maps = page.locator(".estrategia-web-map");
  assert.equal(await maps.count(), 2, "there should be one map per post");
  // With an image the address goes in the map's caption; without one, in the card (0.3.0).
  const addresses = await page.locator(".estrategia-web-map__address, .estrategia-web-map__card-address").allTextContents();
  assert.deepEqual(addresses, ["Calle de Prueba 1, Palma", "Calle de Prueba 2, Palma"], "each map should carry its post's address");
  assert.equal(await maps.nth(0).locator("img.estrategia-web-map__image").count(), 1, "the first post has a featured image: the map should use it");
  assert.ok((await maps.nth(0).locator("img").getAttribute("src")).includes("mapa-estatico-prueba"), "the image is not the featured one");
  assert.equal(await maps.nth(1).locator(".estrategia-web-map__card").count(), 1, "without a featured image the details card should come out");
  // The «Abrir en Google Maps» link comes from the bound address.
  const href = await maps.nth(1).locator(".estrategia-web-map__link").getAttribute("href");
  assert.ok(href.includes(encodeURIComponent("Calle de Prueba 2, Palma")), `link without the address: ${href}`);
  assert.deepEqual(page.errors, [], page.errors.join("\n"));
  await page.context().close();
});

test("3. /prueba-mapa-estatico/: the site's own image under the card, nothing to other domains, card below when narrow, «Ver mapa» loads only that map", async () => {
  const page = await open("/prueba-mapa-estatico/");
  const first = page.locator(".estrategia-web-map").nth(0);
  const second = page.locator(".estrategia-web-map").nth(1);

  // Own image, loaded, with the card on top on the left.
  const image = first.locator("img.estrategia-web-map__static");
  await image.scrollIntoViewIfNeeded();
  await loaded(page, image);
  const src = await image.getAttribute("src");
  assert.equal(new URL(src).host, own, `the image is not from the site: ${src}`);
  assert.match(await image.getAttribute("alt"), /Pla(ç|c)a de Cort/, "the alternative text does not give the address");
  const card = first.locator(".estrategia-web-map__card");
  assert.ok(await card.isVisible(), "with a static map the details card must stay");
  assert.match(await card.innerText(), /Sede con mapa estático/);
  const imageBox = await image.boundingBox();
  const cardBox = await card.boundingBox();
  assert.ok(cardBox.y < imageBox.y + imageBox.height && cardBox.y >= imageBox.y, "when wide, the card goes ON TOP of the map");
  assert.ok(cardBox.x + cardBox.width < imageBox.x + imageBox.width / 2, "the card covers the centre (the marker)");

  // The URL from another domain is discarded; nothing left for another host.
  assert.equal(await second.locator("img").count(), 0, "a staticMapUrl from another domain is not discarded");
  assert.deepEqual(page.foreign, [], `third-party requests without consent: ${page.foreign.join(" ")}`);

  // Narrow (375 px): the card below the map.
  await page.setViewportSize({ width: 375, height: 800 });
  await image.scrollIntoViewIfNeeded();
  const a = await image.boundingBox();
  const b = await card.boundingBox();
  assert.ok(a.height > 100, `when narrow the map is ${a.height} px high`);
  assert.ok(b.y >= a.y + a.height - 1, `when narrow the card (y=${b.y}) does not go below the map (which ends at ${a.y + a.height})`);
  await page.setViewportSize({ width: 1200, height: 900 });

  // Without the notice plugin (0.7.0): «Ver mapa» loads THIS map and only
  // this one; there is no consent to grant nor cookie to store.
  await first.getByRole("button", { name: "Ver mapa" }).click();
  await page.waitForFunction(() => {
    const frame = document.querySelectorAll(".estrategia-web-map__iframe")[0];
    return frame && !frame.hidden && /google\.com/.test(frame.src);
  });
  assert.ok(await image.isHidden(), "once the map is loaded, the static image is still in view");
  assert.ok(!(await second.locator(".estrategia-web-map__iframe").getAttribute("src")), "without the notice, clicking one map should not load the others");
  assert.equal((await page.context().cookies()).find((c) => c.name === "estrategia_web_consent"), undefined, "without the plugin there is no consent cookie");
  assert.deepEqual(page.errors, [], page.errors.join("\n"));
  await page.context().close();
});

test("4. /prueba-mapa-sin-datos/ at 1200 and 402: the address once, only the image in the frame, no empty box, «Ver mapa» paints the map", async () => {
  const page = await open("/prueba-mapa-sin-datos/");
  const first = page.locator(".estrategia-web-map").nth(0);
  const second = page.locator(".estrategia-web-map").nth(1);
  const image = first.locator("img.estrategia-web-map__static");
  for (const width of [1200, 402]) {
    await page.setViewportSize({ width, height: 900 });
    // The address, only once in view.
    const text = await page.locator("body").innerText();
    const times = text.split("Plaça de Cort 1").length - 1;
    assert.equal(times, 1, `at ${width} px the address comes out ${times} times`);
    assert.ok(!text.includes("Sede sin recuadro"), `at ${width} px the location name comes out inside the map`);

    // With a static map: only the image, and the two buttons.
    assert.equal(await first.locator(".estrategia-web-map__card").count(), 0, "with showDetails:false the card is not painted");
    await image.scrollIntoViewIfNeeded();
    await loaded(page, image);
    const frame = await first.locator(".estrategia-web-map__frame").boundingBox();
    const img = await image.boundingBox();
    assert.ok(Math.abs(img.height - frame.height) < 2 && img.height > 100, `at ${width} px the image does not fill the frame (${img.height} of ${frame.height})`);
    assert.ok(await first.getByRole("button", { name: "Ver mapa" }).isVisible());
    assert.ok(await first.getByRole("link", { name: /Abrir en Google Maps/ }).isVisible());

    // Without an image: no empty box before the click.
    assert.equal(await second.locator(".estrategia-web-map__card").count(), 0);
    assert.ok(await second.locator(".estrategia-web-map__frame").isHidden(), `at ${width} px without an image an empty box is left before the click`);
    assert.ok(await second.getByRole("link", { name: /Abrir en Google Maps/ }).isVisible());
  }
  // Plugin 1.1.x: two links to different destinations cannot have the same
  // name (Lighthouse). The name starts with the visible text.
  const names = await page.locator(".estrategia-web-map__link").evaluateAll((links) => links.map((a) => a.getAttribute("aria-label") || a.textContent.trim()));
  assert.equal(new Set(names).size, names.length, `repeated link names: ${names.join(" | ")}`);
  for (const name of names) assert.ok(name.startsWith("Abrir en Google Maps"), `the name does not start with the visible text: ${name}`);

  // «Ver mapa» paints the map with its height (at 402 px).
  await second.getByRole("button", { name: "Ver mapa" }).click();
  const iframe = await second.locator(".estrategia-web-map__iframe").elementHandle();
  await page.waitForFunction((el) => !el.hidden && /google\.com/.test(el.src) && el.getBoundingClientRect().height > 100, iframe);
  assert.deepEqual(page.errors, [], page.errors.join("\n"));
  await page.context().close();
});
