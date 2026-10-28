// NIVEL: 3 — browser. NOT LEVEL 2 BECAUSE: the accessible name, that the button is fixed, visible and in its corner, that it stays in the same place while the page scrolls and that the cookie notice is ON TOP of it where they overlap are computed style, positions and stacking after layout and scrolling; the HTML does not say them.
/**
 * estrategia-web/whatsapp, and the floating button with the cookie notice
 * (two blocks of this plugin that share the bottom corner of a phone). ONE
 * page (the seed of modules/whatsapp/tests/wp/estrategia-web-whatsapp-test.php:
 * a long page with the two buttons) and ONE load; the second width is a
 * resize. Merged on 28-10-2026 from whatsapp.test.mjs and
 * whatsapp-consent.test.mjs (three loads became one):
 * 1. the wa.me link is correct with attributes (number with spaces and «+»,
 *    message with accents) and with the site option (a block without
 *    attributes), it opens in a new tab, has an accessible name, is fixed,
 *    is at least 44 px, and each goes to its corner. Since 0.7.1 the
 *    distance to the corner adds the mobile safe area
 *    (env(safe-area-inset-*)): it is checked in the stylesheet, because in a
 *    desktop browser env() is 0 and the computed style does not tell it apart;
 * 2. at 1280 and 402 px the button is in the SAME place at the top of the
 *    page, halfway and at the end (really fixed, not just position: fixed in
 *    a stylesheet something overrides), and with the notice open, wherever
 *    the two overlap, the NOTICE is on top (it is what has to be answered
 *    first; at 402 they overlap). Moved from Lavandería on 25-10-2026;
 *    Lavandería keeps its design (the distance from its kit, its footer).
 *
 * The consent module is ON here (the notice is what step 2 needs); step 1
 * does not depend on it.
 * The trap: every scroll is behavior: "instant" — with scroll-behavior:
 * smooth on the root (some themes, Lavandería's), a plain scrollTo()
 * ANIMATES and 100 ms later the page is halfway (06-10-2026).
 *
 * How to maintain: if the notice or the button change their z-index or
 * position, step 2 says whether they still stack right.
 *
 * Seen red (28-10-2026) with «button on top» expected where they overlap
 * (step 2: «WhatsApp and the notice»).
 */
import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { BASE_URL } from "../../../tests/browser.mjs";
import { playwright, reset, testApi } from "../../../tests/lib.mjs";

let browser;
before(async () => {
  await reset();
  const [, seed] = await Promise.all([
    testApi("/consent-setup", { method: "POST", body: { categories: ["maps"] } }),
    fetch(`${BASE_URL}/wp-json/estrategia-web-whatsapp-test/v1/seed`, { method: "POST" }),
  ]);
  assert.ok(seed.ok, `whatsapp seed -> ${seed.status}`);
  browser = await playwright().chromium.launch();
});
after(() => browser?.close());

test("whatsapp: links, new tab, name, fixed in its corner with the safe area; fixed while scrolling and under the cookie notice at 1280 and 402", async () => {
  const context = await browser.newContext({ viewport: { width: 1280, height: 900 } });
  const page = await context.newPage();
  const errors = [];
  page.on("console", (m) => m.type() === "error" && errors.push(`console: ${m.text()}`));
  page.on("pageerror", (e) => errors.push(`pageerror: ${e.message}`));
  await page.goto(`${BASE_URL}/prueba-whatsapp/`, { waitUntil: "networkidle" });

  // 1. The two buttons.
  const buttons = page.getByRole("link", { name: /Escríbenos por WhatsApp/ });
  assert.equal(await buttons.count(), 2, "two were expected: the one with attributes and the one without");
  const hrefs = await buttons.evaluateAll((links) => links.map((a) => a.getAttribute("href")));
  assert.ok(hrefs.includes("https://wa.me/34600111222?text=" + encodeURIComponent("Hola, ¿tenéis hora mañana?")), `the link with attributes is missing: ${hrefs.join(" ")}`);
  assert.ok(hrefs.includes("https://wa.me/34611223344?text=" + encodeURIComponent("Mensaje desde la opción del sitio")), `the site option's link is missing: ${hrefs.join(" ")}`);
  for (const link of await buttons.all()) {
    assert.equal(await link.getAttribute("target"), "_blank");
    assert.match(await link.getAttribute("rel"), /noopener/);
    assert.equal(await link.evaluate((el) => getComputedStyle(el).position), "fixed");
    const box = await link.boundingBox();
    assert.ok(box && box.width >= 44 && box.height >= 44, "touch target smaller than 44 px");
  }
  // The one with attributes goes on the left, the one without on the right.
  const left = await page.locator(".estrategia-web-whatsapp.is-position-bottom-left").boundingBox();
  const right = await page.locator(".estrategia-web-whatsapp.is-position-bottom-right").boundingBox();
  assert.ok(left.x < 100 && right.x > 1000, `unexpected positions: ${left.x} / ${right.x}`);

  // Safe area (0.7.1): at the bottom and on its corner's side, each its own.
  const insets = await page.evaluate(() => {
    const found = {};
    const walk = (rules) => {
      for (const rule of rules) {
        if (rule.cssRules) walk(rule.cssRules);
        if (!rule.selectorText || !rule.selectorText.includes(".estrategia-web-whatsapp")) continue;
        for (const prop of ["inset-block-end", "inset-inline-start", "inset-inline-end"]) {
          const value = rule.style.getPropertyValue(prop);
          if (value) found[`${rule.selectorText} ${prop}`] = value;
        }
      }
    };
    for (const sheet of document.styleSheets) {
      try {
        walk(sheet.cssRules);
      } catch {}
    }
    return found;
  });
  const insetOf = (selector, prop) => insets[`${selector} ${prop}`] || "";
  assert.match(insetOf(".estrategia-web-whatsapp", "inset-block-end"), /safe-area-inset-bottom/, JSON.stringify(insets));
  assert.match(insetOf(".estrategia-web-whatsapp.is-position-bottom-left", "inset-inline-start"), /safe-area-inset-left/, JSON.stringify(insets));
  assert.match(insetOf(".estrategia-web-whatsapp.is-position-bottom-right", "inset-inline-end"), /safe-area-inset-right/, JSON.stringify(insets));

  // 2. Fixed while scrolling, and the notice on top where they overlap (the bottom-left button).
  const box = () =>
    page.evaluate(() => {
      const r = document.querySelector(".estrategia-web-whatsapp")?.getBoundingClientRect();
      return r && { left: Math.round(r.left), bottom: Math.round(innerHeight - r.bottom), width: Math.round(r.width), height: Math.round(r.height) };
    });
  const bad = [];
  for (const width of [1280, 402]) {
    await page.setViewportSize({ width, height: 900 });
    await page.evaluate(() => scrollTo({ top: 0, behavior: "instant" }));
    await page.waitForTimeout(100);
    const start = await box();
    assert.ok(start, `${width}: no WhatsApp button`);
    assert.ok(await page.evaluate(() => document.documentElement.scrollHeight > innerHeight * 2), `${width}: the page is not long enough to scroll`);
    for (const to of [0.5, 1]) {
      await page.evaluate((f) => scrollTo({ top: (document.documentElement.scrollHeight - innerHeight) * f, behavior: "instant" }), to);
      await page.waitForTimeout(100);
      const now = await box();
      if (JSON.stringify(now) !== JSON.stringify(start)) bad.push(`${width}: it moves when scrolling (${JSON.stringify(start)} → ${JSON.stringify(now)})`);
    }
    const covered = await page.evaluate(() => {
      const a = document.querySelector(".estrategia-web-whatsapp").getBoundingClientRect();
      const notice = document.querySelector("#estrategia-web-consent");
      if (!notice || !notice.getBoundingClientRect().height) return "no notice";
      const n = notice.getBoundingClientRect();
      const x = Math.max(a.left, n.left) + 1;
      const y = Math.max(a.top, n.top) + 1;
      const overlap = x < Math.min(a.right, n.right) && y < Math.min(a.bottom, n.bottom);
      return overlap ? (notice.contains(document.elementFromPoint(x, y)) ? "notice on top" : "button on top") : "no overlap";
    });
    if (covered === "button on top" || covered === "no notice") bad.push(`${width}: with the cookie notice open → ${covered}`);
    if (width === 402 && covered === "no overlap") bad.push("402: the notice and the button do not overlap, so step 2 checks nothing (did the layout change?)");
  }
  assert.deepEqual(bad, [], `WhatsApp and the notice:\n${bad.join("\n")}`);
  assert.deepEqual(errors, [], errors.join("\n"));
  await context.close();
});
