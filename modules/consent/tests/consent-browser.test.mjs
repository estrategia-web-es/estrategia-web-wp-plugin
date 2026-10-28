// NIVEL: 3 — browser. NOT LEVEL 2 BECAUSE: the notice is decided in the browser (cookie, Interactivity ES module), the API and the events are JS, focus and Tab order exist only in a page that runs, and visual weight, gaps and contrast are computed style.
//
// Cookie notice (0.5.0, came from the parent theme with its contract). What
// it is, in the header of includes/class-consent.php. What the server prints
// (categories, revision, links, «Powered by» markup) is consent.test.mjs.
//
// ONE visitor's journey (28-10-2026: it was eight tests and twelve page
// loads; now three loads, and every check keeps its reason):
//  1. first visit, no choice: «estrategia-web:consent-ready» fires ONCE with
//     null and the API already set; Accept and Reject weigh the same (AEPD);
//     the «Powered by» line is visible, smaller than the buttons and its
//     contrast with the notice background is AA (≥ 4.5:1), MEASURED; between
//     the text and the buttons there are ≥ 16 px at 402 and 1512 (a resize,
//     not a reload; the theme CSS of Lavandería is played by consent-setup);
//     the notice is the FIRST thing when tabbing, Tab leaves it (no trap) and
//     Escape does not close it without a choice; JS contract: categories,
//     get() null, has("necessary"), grant() adds ONE declared category, fires
//     «estrategia-web:consent» and leaves the notice open;
//  2. «Rechazar todas»: the cookie in the PARENT's format with the parent's
//     revision; then the cookie the PARENT's notice (≤ 0.6.0) would have
//     written is put in its place and the page reloaded: the notice does not
//     show again, get() gives the parent's choice, consent-ready fires with
//     it, and what was not accepted does not run;
//  3. the «Cookie settings» links (new name and the armazon/consent alias)
//     reopen it with focus inside, and Escape closes it once chosen;
//  4. open() + «Aceptar todas»: the event with both categories, the deferred
//     <script type="text/plain" data-estrategia-web-consent> runs; on reload
//     the event fires on boot with the stored choice and open() shows the
//     choices ticked.
// Hybrid Matomo against this REAL notice is matomo-browser.test.mjs.
//
// Seen failing on 05-10-2026 with six mutants, each one caught: rel with
// nofollow, a different revision (the parent's cookie stops being valid),
// alias not registered, without the estrategia-web:consent event, without
// estrategia-web:consent-ready and the «Powered by» in #aaa (contrast 2.3:1).
// Seen red (28-10-2026) with the parent's revision computed with '|2'
// (step 2: «unexpected cookie»: ours no longer carries the parent's
// revision, so the parent's cookie would not be valid either).
//
// Round 2 (28-10-2026): before() is ONE /setup request (reset, «the theme»,
// the page). Seen red again with the parent's revision computed with '|2'.
//
// How to maintain: a new behaviour of the notice is a new step of the
// journey where its state already is (no new visit unless it needs a fresh
// browser). The trap: the steps depend on each other on purpose — a red
// step stops the rest, read the FIRST failure.
import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import crypto from "node:crypto";
import { BASE, playwright, testApi } from "../../../tests/lib.mjs";

const md5 = (s) => crypto.createHash("md5").update(s).digest("hex").slice(0, 8);
const REVISION = md5("statistics,maps|1"); // The armazon-wp-padre 0.6.0 formula.

let browser;
let pageUrl;
before(async () => {
  // ONE request (/setup, tests/wp/estrategia-web-site-test.php): the reset,
  // «the theme» (theme_css: what the Lavandería theme.json emits, see the
  // gap check) and the page.
  const { pages } = await testApi("/setup", {
    method: "POST",
    body: {
      consent: { categories: ["statistics", "maps"], script: true, powered_by: { show: true }, theme_css: ":root :where(p){margin-top:0;margin-bottom:0}" },
      pages: [{ slug: "test-notice", content: '<!-- wp:paragraph --><p>Footer</p><!-- /wp:paragraph --><!-- wp:estrategia-web-site/consent /--><!-- wp:armazon/consent {"label":"Old alias"} /-->' }],
    },
  });
  pageUrl = pages[0].url;
  browser = await playwright().chromium.launch();
});
after(() => browser?.close());

/** WCAG contrast between two rgb()/rgba() colours (the foreground without alpha). */
function contrast(fg, bg) {
  const parse = (s) => s.match(/[\d.]+/g).slice(0, 3).map(Number);
  const lum = ([r, g, b]) => {
    const c = [r, g, b].map((v) => {
      v /= 255;
      return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4;
    });
    return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2];
  };
  const [a, b] = [lum(parse(fg)), lum(parse(bg))].sort((x, y) => y - x);
  return (a + 0.05) / (b + 0.05);
}

test("the notice, one visitor: first visit, reject, the parent's cookie, the links, accept", async () => {
  const ctx = await browser.newContext({ viewport: { width: 1200, height: 900 } });
  // On every load: record consent-ready and the consent events from the very start.
  await ctx.addInitScript(() => {
    window.ready = [];
    window.seen = [];
    document.addEventListener("estrategia-web:consent-ready", (e) => window.ready.push([e.detail.accepted, typeof window.estrategiaWebConsent?.get]));
    document.addEventListener("estrategia-web:consent", (e) => window.seen.push(e.detail.accepted));
  });
  const page = await ctx.newPage();
  const errors = [];
  page.on("pageerror", (e) => errors.push(e.message));
  const notice = page.locator("#estrategia-web-consent");
  const js = (fn, arg) => page.evaluate(fn, arg);

  // 1. First visit, no choice.
  await page.goto(pageUrl, { waitUntil: "networkidle" });
  await notice.waitFor({ state: "visible" });
  await page.waitForFunction(() => window.ready.length > 0);
  assert.deepEqual(await js(() => window.ready), [[null, "function"]], "consent-ready fires ONCE on boot, also without a choice (null), with the API already set");

  const look = (name) =>
    page.getByRole("button", { name, exact: true }).evaluate((el) => {
      const s = getComputedStyle(el);
      return [s.backgroundColor, s.color, s.fontSize, s.fontWeight, s.paddingTop, s.paddingLeft, s.borderTopWidth, el.offsetHeight].join("|");
    });
  assert.equal(await look("Aceptar todas"), await look("Rechazar todas"), "accept and reject do not weigh the same");

  const link = page.getByRole("link", { name: /Powered by Estrategia Web/ });
  assert.ok(await link.isVisible(), "the «Powered by» link is not visible");
  assert.equal(await link.getAttribute("rel"), "noopener");
  assert.equal(await link.getAttribute("target"), "_blank");
  const m = await link.evaluate((el) => {
    let bg = "rgba(0, 0, 0, 0)";
    for (let n = el; n; n = n.parentElement) {
      const c = getComputedStyle(n).backgroundColor;
      if (!/rgba\(.*,\s*0\)$/.test(c) && c !== "transparent") {
        bg = c;
        break;
      }
    }
    const s = getComputedStyle(el);
    const button = getComputedStyle(document.querySelector(".estrategia-web-consent__button"));
    return { color: s.color, bg, opacity: s.opacity, size: parseFloat(s.fontSize), buttonSize: parseFloat(button.fontSize) };
  });
  assert.equal(m.opacity, "1", "the line must not have opacity (the measured contrast would no longer hold)");
  assert.ok(!/rgba\(.*,\s*0?\.\d+\)$/.test(m.color), `colour with transparency: ${m.color}`);
  const ratio = contrast(m.color, m.bg);
  assert.ok(ratio >= 4.5, `contrast ${ratio.toFixed(2)}:1 (${m.color} on ${m.bg}); AA requires 4.5:1`);
  assert.ok(m.size < m.buttonSize, `the line (${m.size}px) is not smaller than the buttons (${m.buttonSize}px)`);
  console.log(`# Powered by: ${m.color} on ${m.bg} = ${ratio.toFixed(2)}:1, ${m.size}px (buttons ${m.buttonSize}px)`);

  // 0.5.1: between the text and the buttons at least 16 px, at 402 and at 1512.
  for (const width of [402, 1512]) {
    await page.setViewportSize({ width, height: 900 });
    const gap = await js(() => {
      const text = document.querySelector(".estrategia-web-consent__text");
      const title = document.querySelector(".estrategia-web-consent__title");
      const range = document.createRange();
      range.selectNodeContents(text);
      const lastLine = Math.max(...[...range.getClientRects()].map((r) => r.bottom));
      const button = document.querySelector(".estrategia-web-consent__actions").getBoundingClientRect().top;
      return { gap: button - lastLine, titleGap: text.getBoundingClientRect().top - title.getBoundingClientRect().bottom };
    });
    assert.ok(gap.gap >= 16, `${width}: between the last line of text and the buttons there are ${gap.gap.toFixed(1)} px`);
    assert.ok(gap.titleGap >= 4, `${width}: title and text stuck together (${gap.titleGap.toFixed(1)} px)`);
    console.log(`# ${width}: text → buttons ${gap.gap.toFixed(1)} px; title → text ${gap.titleGap.toFixed(1)} px`);
  }
  await page.setViewportSize({ width: 1200, height: 900 });

  // 0.5.1: the FIRST thing when tabbing; Tab leaves it (no trap); Escape does not close it without choosing.
  await page.keyboard.press("Tab");
  const inside = () => js(() => !!document.activeElement?.closest("#estrategia-web-consent"));
  assert.ok(await inside(), `the first Tab goes to ${await js(() => document.activeElement?.outerHTML.slice(0, 80))}, not to the notice`);
  const stops = await js(() => document.querySelectorAll("#estrategia-web-consent a[href], #estrategia-web-consent button:not([disabled])").length);
  let left = false;
  for (let i = 0; i <= stops && !left; i++) {
    await page.keyboard.press("Tab");
    left = !(await inside());
  }
  assert.ok(left, `after ${stops + 1} Tab presses focus is still inside the notice: focus trap`);
  assert.ok(await notice.isVisible(), "leaving with Tab must not close the notice");
  await page.keyboard.press("Shift+Tab");
  assert.ok(await inside(), "Shift+Tab does not go back to the notice");
  await page.keyboard.press("Escape");
  assert.ok(await notice.isVisible(), "the first time, Escape does not close it: a choice is required");

  // JS contract before any choice; grant() adds ONE declared category, fires the event, leaves the notice open.
  assert.deepEqual(await js(() => window.estrategiaWebConsent.categories), ["statistics", "maps"]);
  assert.equal(await js(() => window.estrategiaWebConsent.get()), null, "with no choice, get() is null");
  assert.equal(await js(() => window.estrategiaWebConsent.has("necessary")), true);
  await js(() => window.estrategiaWebConsent.grant("maps"));
  assert.deepEqual(await js(() => window.seen), [["maps"]]);
  assert.deepEqual(await js(() => window.estrategiaWebConsent.get()), ["maps"]);
  assert.ok(await notice.isVisible(), "grant does not close the notice");
  await js(() => window.estrategiaWebConsent.grant("chat"));
  assert.deepEqual(await js(() => window.estrategiaWebConsent.get()), ["maps"], "an undeclared category is not granted");

  // 2. Reject: the parent's cookie format, with the parent's revision.
  await page.getByRole("button", { name: "Rechazar todas" }).click();
  await notice.waitFor({ state: "hidden" });
  const cookie = (await ctx.cookies()).find((c) => c.name === "estrategia_web_consent");
  assert.ok(cookie, "the estrategia_web_consent cookie was not stored");
  assert.match(decodeURIComponent(cookie.value), new RegExp(`^${REVISION}\\.\\.\\d+$`), `unexpected cookie: ${cookie.value}`);
  assert.equal(await js(() => window.estrategiaWebTestStatistics), undefined, "a rejected statistics script ran");

  // The cookie as armazon-wp-padre/blocks/consent/view.js wrote it (≤ 0.6.0), in its place: still valid.
  await ctx.addCookies([{ name: "estrategia_web_consent", value: encodeURIComponent(`${REVISION}.maps.${Math.floor(Date.now() / 1000)}`), url: BASE + "/" }]);
  await page.reload({ waitUntil: "networkidle" });
  await page.waitForFunction(() => window.ready.length > 0);
  assert.ok(await notice.isHidden(), "the notice shows again to someone who already chose (with the parent's notice)");
  assert.deepEqual(await js(() => window.estrategiaWebConsent.get()), ["maps"]);
  assert.deepEqual(await js(() => window.ready), [[["maps"], "function"]], "consent-ready fires once with the stored choice");
  assert.equal(await js(() => window.estrategiaWebTestStatistics), undefined, "statistics was not accepted: its script must not run");

  // 3. The footer links reopen it (new name and armazon/consent alias), focus inside, Escape closes it once chosen.
  for (const name of ["Configurar cookies", "Old alias"]) {
    await page.getByRole("button", { name, exact: true }).click();
    await notice.waitFor({ state: "visible" });
    await page.waitForFunction(() => document.activeElement?.id === "estrategia-web-consent-title");
    await page.keyboard.press("Escape");
    await notice.waitFor({ state: "hidden" });
  }

  // 4. Accept all: the event with both, the deferred script activated.
  await js(() => window.estrategiaWebConsent.open());
  await notice.waitFor({ state: "visible" });
  await page.getByRole("button", { name: "Aceptar todas" }).click();
  await page.waitForFunction(() => window.estrategiaWebTestStatistics === true);
  assert.deepEqual(await js(() => window.seen.at(-1)), ["statistics", "maps"]);
  assert.equal(await js(() => window.estrategiaWebConsent.has("statistics")), true);

  // On reload: no notice, the event on boot with the stored choice; open() reopens it with the choices ticked.
  await page.reload({ waitUntil: "networkidle" });
  await page.waitForFunction(() => window.estrategiaWebTestStatistics === true);
  assert.ok(await notice.isHidden(), "the notice shows again after accepting");
  assert.deepEqual(await js(() => window.seen), [["statistics", "maps"]], "the event fires on boot if there was already a choice");
  await js(() => window.estrategiaWebConsent.open());
  await notice.waitFor({ state: "visible" });
  assert.equal(await page.getByRole("checkbox", { name: /Analítica/ }).isChecked(), true, "1.1.0: «statistics» is labelled «Analítica» in es_ES");
  assert.deepEqual(errors, []);
  await ctx.close();
});
