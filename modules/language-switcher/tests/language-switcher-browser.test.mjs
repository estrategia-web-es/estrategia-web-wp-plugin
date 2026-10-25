// NIVEL: 3 — browser, with REAL WPML. NOT LEVEL 2 BECAUSE: that the list is hidden until the button is pressed, that pressing opens it (aria-expanded true, the list in view), that Escape closes it and gives the focus back to the button, and that the cookie notice IS SEEN in /en/, only exist on the painted page with its JavaScript (view.js, the Interactivity API). What the server sends is language-switcher.test.mjs.
// WPML: REAL — runs against the WordPress with WPML (tests/run.mjs, «WPML»).
//
// estrategia-web/language-switcher, on a Spanish page and its English
// translation (real WPML):
//  1. the list is not seen until the button is pressed; pressing it,
//     aria-expanded turns true and the list is seen; Escape closes it and the
//     focus goes back to the button; no JavaScript errors;
//  2. in /en/, a new visitor SEES the cookie notice.
// The behaviour is the same view.js on every page: one of each language.
//
// Moved from Lavandería (tests/language-switcher.test.mjs) on 25-10-2026,
// where it looked for the switcher inside its header bar (that placement is
// Lavandería's and is checked by its language-switcher-html.test.mjs). There
// it was skipped («OMITIDA») without WPML; here it FAILS, like every WPML test
// of the plugin (tests/run.mjs, «WPML»).
//
// How to maintain: the selectors are the block's (render.php); they change
// here and in language-switcher.test.mjs at once.
//
// Seen red (25-10-2026): expecting aria-expanded "false" after pressing,
// step 1 fails on the Spanish page; restored, green.
import { test, before } from "node:test";
import { withBrowser, assert, BASE_URL } from "../../../tests/browser.mjs";
import { reset, wp, testApi, wpml, requireWpml } from "../../../tests/lib.mjs";

const BLOCK = '<!-- wp:estrategia-web/language-switcher /-->\n<!-- wp:paragraph --><p>Idiomas</p><!-- /wp:paragraph -->';
let paths;

before(async () => {
  await requireWpml({ st: false });
  await reset();
  await wpml("/purge", { slugs: ["prueba-idiomas", "language-test"] });
  await testApi("/consent-setup", { method: "POST", body: { categories: ["maps"] } });
  const r = await wp("/pages", { method: "POST", body: { title: "Prueba idiomas", slug: "prueba-idiomas", status: "publish", content: BLOCK } });
  assert.equal(r.status, 201, r.text);
  const tr = await wpml("/translate", { kind: "post", type: "page", original: r.json.id, title: "Language test", slug: "language-test", content: BLOCK });
  paths = ["/prueba-idiomas/", new URL(tr.url).pathname];
});

test("language switcher: opens, closes with Escape and gives the focus back; the notice is seen in /en/", () =>
  withBrowser(async ({ newPage }) => {
    const page = await newPage({ viewport: { width: 1280, height: 900 } });
    const switcher = page.locator(".estrategia-web-language").first();
    const toggle = switcher.locator(".estrategia-web-language__toggle");
    const list = switcher.locator(".estrategia-web-language__list");
    for (const from of paths) {
      await page.goto(BASE_URL + from, { waitUntil: "networkidle" });
      assert.ok(await list.isHidden(), `${from}: the list is seen before opening the switcher`);
      await toggle.click();
      assert.equal(await toggle.getAttribute("aria-expanded"), "true", `${from}: pressing does not turn aria-expanded true`);
      assert.ok(await list.isVisible(), `${from}: pressing does not show the list`);
      await page.keyboard.press("Escape");
      assert.equal(await toggle.getAttribute("aria-expanded"), "false", `${from}: Escape does not close the switcher`);
      assert.ok(await toggle.evaluate((el) => el === document.activeElement), `${from}: after Escape the focus does not return to the button`);
      assert.deepEqual(page.errors, [], `errors in ${from}:\n${page.errors.join("\n")}`);
    }
    const visitor = await newPage({ viewport: { width: 402, height: 860 } });
    await visitor.goto(BASE_URL + paths[1], { waitUntil: "networkidle" });
    assert.ok(await visitor.locator(".estrategia-web-consent").first().isVisible(), `a new visitor of ${paths[1]} does not see the cookie notice`);
  })
);
