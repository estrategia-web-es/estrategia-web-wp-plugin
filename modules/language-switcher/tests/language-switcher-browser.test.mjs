// NIVEL: 3 — browser, on the normal WordPress with the WPML double. NOT LEVEL 2 BECAUSE: that the list is hidden until the button is pressed, that pressing opens it (aria-expanded true, the list in view), that Escape closes it and gives the focus back to the button, and that the cookie notice IS SEEN on an English page, only exist on the painted page with its JavaScript (view.js, the Interactivity API). What the server sends with REAL WPML is language-switcher.test.mjs.
//
// estrategia-web/language-switcher on an English page, ONE page load:
//  1. the list is not seen until the button is pressed; pressing it,
//     aria-expanded turns true and the list is seen; Escape closes it and the
//     focus goes back to the button; no JavaScript errors;
//  2. a new visitor of the English page SEES the cookie notice.
//
// Moved from Lavandería (tests/language-switcher.test.mjs) on 25-10-2026,
// where it looked for the switcher inside its header bar (that placement is
// Lavandería's and is checked by its language-switcher-html.test.mjs).
//
// 28-10-2026: from real WPML, two languages and three page loads to the
// normal WordPress and one load. What is tested here is the block's
// JavaScript, which does not know WPML: it only needs the block to render,
// and the block renders whatever wpml_active_languages returns. So the page
// is read with ?wpml-double=en&wpml-double-switcher (the WPML double of
// tests/wp/estrategia-web-site-test.php, which then answers that filter with
// es and en): the plugin in English, as in /en/. What only real WPML gives
// (the exact URL of the translation, <html lang>, the notice's markup in
// /en/) is the level 2 test, against real WPML. The Spanish page re-proved
// the same view.js, and the page is also the «new visitor» (nothing in it
// gives consent: opening and closing the switcher does not), so the second,
// mobile-sized load is gone too.
//
// How to maintain: the selectors are the block's (render.php); they change
// here and in language-switcher.test.mjs at once.
//
// Seen red (25-10-2026): expecting aria-expanded "false" after pressing,
// step 1 fails on the Spanish page; restored, green.
// Seen red (28-10-2026), on the double: with the focus expected AWAY from the
// button after Escape, and (another run) with the notice expected hidden: it
// fails at each.
import { test, before } from "node:test";
import { withBrowser, assert, BASE_URL } from "../../../tests/browser.mjs";
import { testApi } from "../../../tests/lib.mjs";

const BLOCK = '<!-- wp:estrategia-web/language-switcher /-->\n<!-- wp:paragraph --><p>Idiomas</p><!-- /wp:paragraph -->';
let path;

before(async () => {
  const { pages } = await testApi("/setup", { method: "POST", body: { consent: { categories: ["maps"] }, pages: [{ slug: "prueba-idiomas", content: BLOCK }] } });
  const url = new URL(pages[0].url);
  path = url.pathname + (url.search ? url.search + "&" : "?") + "wpml-double=en&wpml-double-switcher=1";
});

test("language switcher on an English page: opens, closes with Escape and gives the focus back; a new visitor sees the cookie notice", () =>
  withBrowser(async ({ newPage }) => {
    const page = await newPage({ viewport: { width: 1280, height: 900 } });
    const switcher = page.locator(".estrategia-web-language").first();
    const toggle = switcher.locator(".estrategia-web-language__toggle");
    const list = switcher.locator(".estrategia-web-language__list");
    await page.goto(BASE_URL + path, { waitUntil: "networkidle" });
    assert.ok(await page.locator(".estrategia-web-consent").first().isVisible(), `a new visitor of ${path} does not see the cookie notice`);
    assert.equal(await toggle.locator("span[lang]").textContent().then((t) => t.trim()), "EN", "the double did not put the page in English");
    assert.ok(await list.isHidden(), `${path}: the list is seen before opening the switcher`);
    await toggle.click();
    assert.equal(await toggle.getAttribute("aria-expanded"), "true", `${path}: pressing does not turn aria-expanded true`);
    assert.ok(await list.isVisible(), `${path}: pressing does not show the list`);
    await page.keyboard.press("Escape");
    assert.equal(await toggle.getAttribute("aria-expanded"), "false", `${path}: Escape does not close the switcher`);
    assert.ok(await toggle.evaluate((el) => el === document.activeElement), `${path}: after Escape the focus does not return to the button`);
    assert.deepEqual(page.errors, [], `errors in ${path}:\n${page.errors.join("\n")}`);
  })
);
