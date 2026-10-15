// NIVEL: 3 — browser. NEEDS A BROWSER BECAUSE: the notice is decided in the browser (cookie, Interactivity ES module), the API and the event are JS, and visual weight and contrast are computed style.
//
// Cookie notice (0.5.0, came from the parent theme with its contract). What
// it is, in the header of includes/class-consent.php. Here:
// - it shows; Accept and Reject weigh the same (AEPD); rejecting stores the
//   cookie in the parent's format and persists; it does not run what was
//   rejected;
// - the «Cookie settings» link (new name and old alias) reopens it;
// - JS contract: window.estrategiaWebConsent (categories, has, get, open, grant) and
//   the «estrategia-web:consent» event with detail.accepted; accepting
//   activates the <script type="text/plain" data-estrategia-web-consent>;
// - «estrategia-web:consent-ready» (0.5.0) fires once on boot, also without a
//   choice: Matomo needs it (see trap 1 in class-matomo.php);
// - a cookie stored by the PARENT's notice (same format and revision) is
//   valid: the notice does not show again;
// - the «Powered by» line: it is visible, smaller than the buttons, and its
//   contrast with the notice background is AA (≥ 4.5:1), MEASURED;
// - and hybrid Matomo against this REAL notice is in tests/matomo-browser.test.mjs.
//
// The theme is Playground's default: the notice does not depend on the theme.
//
// Seen failing on 05-10-2026 with six mutants, each one caught: rel with
// nofollow, a different revision (the parent's cookie stops being valid),
// alias not registered, without the estrategia-web:consent event, without
// estrategia-web:consent-ready and the «Powered by» in #aaa (contrast 2.3:1).
import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import crypto from "node:crypto";
import { BASE, playwright, reset, seedPage, testApi } from "../../../tests/lib.mjs";

const setup = (body) => testApi("/consent-setup", { method: "POST", body });
const md5 = (s) => crypto.createHash("md5").update(s).digest("hex").slice(0, 8);

let browser;
let pageUrl;
before(async () => {
  browser = await playwright().chromium.launch();
  await reset();
  // theme_css: what the Lavandería theme.json emits (see the gap test).
  await setup({ categories: ["statistics", "maps"], script: true, powered_by: { show: true }, theme_css: ":root :where(p){margin-top:0;margin-bottom:0}" });
  ({ url: pageUrl } = await seedPage('<!-- wp:paragraph --><p>Footer</p><!-- /wp:paragraph --><!-- wp:estrategia-web-site/consent /--><!-- wp:armazon/consent {"label":"Old alias"} /-->', "test-notice"));
});
after(() => browser?.close());

async function open(cookies = []) {
  const ctx = await browser.newContext({ viewport: { width: 1200, height: 900 } });
  if (cookies.length) await ctx.addCookies(cookies);
  const page = await ctx.newPage();
  page.errors = [];
  page.on("pageerror", (e) => page.errors.push(e.message));
  await page.goto(pageUrl, { waitUntil: "networkidle" });
  return { ctx, page };
}

const notice = (page) => page.locator("#estrategia-web-consent");

test("consent (0.5.0): it shows, accept and reject weigh the same, rejecting stores the parent's cookie and persists", async () => {
  const { ctx, page } = await open();
  await notice(page).waitFor({ state: "visible" });
  const look = (name) =>
    page.getByRole("button", { name, exact: true }).evaluate((el) => {
      const s = getComputedStyle(el);
      return [s.backgroundColor, s.color, s.fontSize, s.fontWeight, s.paddingTop, s.paddingLeft, s.borderTopWidth, el.offsetHeight].join("|");
    });
  assert.equal(await look("Aceptar todas"), await look("Rechazar todas"), "accept and reject do not weigh the same");

  await page.getByRole("button", { name: "Rechazar todas" }).click();
  await notice(page).waitFor({ state: "hidden" });
  const cookie = (await ctx.cookies()).find((c) => c.name === "estrategia_web_consent");
  assert.ok(cookie, "the estrategia_web_consent cookie was not stored");
  assert.match(decodeURIComponent(cookie.value), /^[0-9a-f]{8}\.\.\d+$/, `unexpected cookie format: ${cookie.value}`);
  await page.reload({ waitUntil: "networkidle" });
  await page.waitForFunction(() => window.estrategiaWebConsent);
  assert.ok(await notice(page).isHidden(), "the notice shows again after rejecting");
  assert.deepEqual(await page.evaluate(() => window.estrategiaWebConsent.get()), []);
  assert.equal(await page.evaluate(() => window.estrategiaWebTestStatistics), undefined, "a rejected statistics script ran");
  assert.deepEqual(page.errors, []);
  await ctx.close();
});

test("consent (0.5.0): the footer link reopens it (new name and armazon/consent alias), with focus inside, and Escape closes it", async () => {
  const { ctx, page } = await open();
  await page.getByRole("button", { name: "Rechazar todas" }).click();
  await notice(page).waitFor({ state: "hidden" });
  for (const name of ["Configurar cookies", "Old alias"]) {
    await page.getByRole("button", { name, exact: true }).click();
    await notice(page).waitFor({ state: "visible" });
    await page.waitForFunction(() => document.activeElement?.id === "estrategia-web-consent-title");
    await page.keyboard.press("Escape");
    await notice(page).waitFor({ state: "hidden" });
  }
  await ctx.close();
});

test("consent (0.5.0): JS contract — estrategia-web:consent event with detail.accepted, has/get/categories, deferred scripts, grant and open", async () => {
  const { ctx, page } = await open();
  await notice(page).waitFor({ state: "visible" });
  assert.deepEqual(await page.evaluate(() => window.estrategiaWebConsent.categories), ["statistics", "maps"]);
  assert.equal(await page.evaluate(() => window.estrategiaWebConsent.get()), null, "with no choice, get() is null");
  assert.equal(await page.evaluate(() => window.estrategiaWebConsent.has("necessary")), true);

  // grant: adds ONE category, stores it and fires the event; the notice stays open.
  await page.evaluate(() => {
    window.seen = [];
    document.addEventListener("estrategia-web:consent", (e) => window.seen.push(e.detail.accepted));
  });
  await page.evaluate(() => window.estrategiaWebConsent.grant("maps"));
  assert.deepEqual(await page.evaluate(() => window.seen), [["maps"]]);
  assert.deepEqual(await page.evaluate(() => window.estrategiaWebConsent.get()), ["maps"]);
  assert.ok(await notice(page).isVisible(), "grant does not close the notice");
  await page.evaluate(() => window.estrategiaWebConsent.grant("chat"));
  assert.deepEqual(await page.evaluate(() => window.estrategiaWebConsent.get()), ["maps"], "an undeclared category is not granted");

  // Accept all: event with both, deferred script activated.
  await page.getByRole("button", { name: "Aceptar todas" }).click();
  await page.waitForFunction(() => window.estrategiaWebTestStatistics === true);
  assert.deepEqual(await page.evaluate(() => window.seen.at(-1)), ["statistics", "maps"]);
  assert.equal(await page.evaluate(() => window.estrategiaWebConsent.has("statistics")), true);

  // On reload: no notice, event on boot with the stored choice.
  await page.addInitScript(() => {
    window.seen = [];
    document.addEventListener("estrategia-web:consent", (e) => window.seen.push(e.detail.accepted));
  });
  await page.reload({ waitUntil: "networkidle" });
  await page.waitForFunction(() => window.estrategiaWebTestStatistics === true);
  assert.ok(await notice(page).isHidden(), "the notice shows again after accepting");
  assert.deepEqual(await page.evaluate(() => window.seen), [["statistics", "maps"]], "the event fires on boot if there was already a choice");

  // open() reopens it with the choices ticked.
  await page.evaluate(() => window.estrategiaWebConsent.open());
  await notice(page).waitFor({ state: "visible" });
  assert.equal(await page.getByRole("checkbox", { name: /Analítica/ }).isChecked(), true, "1.1.0: «statistics» is labelled «Analítica» in es_ES");
  assert.deepEqual(page.errors, []);
  await ctx.close();
});

test("consent (0.5.0): a choice stored by the PARENT's notice (≤ 0.6.0) is still valid: it does not show again", async () => {
  // The cookie as armazon-wp-padre/blocks/consent/view.js wrote it.
  const value = `${md5("statistics,maps|1")}.maps.${Math.floor(Date.now() / 1000)}`;
  const { ctx, page } = await open([{ name: "estrategia_web_consent", value: encodeURIComponent(value), url: BASE + "/" }]);
  await page.waitForFunction(() => window.estrategiaWebConsent);
  assert.ok(await notice(page).isHidden(), "the notice shows again to someone who already chose with the parent");
  assert.deepEqual(await page.evaluate(() => window.estrategiaWebConsent.get()), ["maps"]);
  await ctx.close();
});

test("consent (0.5.0): «estrategia-web:consent-ready» fires ONCE on boot, also without a choice (null), with the API already set", async () => {
  const ctx = await browser.newContext();
  await ctx.addInitScript(() => {
    window.ready = [];
    document.addEventListener("estrategia-web:consent-ready", (e) => window.ready.push([e.detail.accepted, typeof window.estrategiaWebConsent?.get]));
  });
  const page = await ctx.newPage();
  await page.goto(pageUrl, { waitUntil: "networkidle" });
  await page.waitForFunction(() => window.ready.length > 0);
  assert.deepEqual(await page.evaluate(() => window.ready), [[null, "function"]]);
  await page.getByRole("button", { name: "Rechazar todas" }).click();
  await page.reload({ waitUntil: "networkidle" });
  await page.waitForFunction(() => window.ready.length > 0);
  assert.deepEqual(await page.evaluate(() => window.ready), [[[], "function"]]);
  await ctx.close();
});

test("consent (0.5.1): when open, it is the FIRST thing when tabbing; Tab leaves it (no trap) and Escape does not close it without choosing", async () => {
  const { ctx, page } = await open();
  await notice(page).waitFor({ state: "visible" });
  await page.keyboard.press("Tab");
  const inside = () => page.evaluate(() => !!document.activeElement?.closest("#estrategia-web-consent"));
  assert.ok(await inside(), `the first Tab goes to ${await page.evaluate(() => document.activeElement?.outerHTML.slice(0, 80))}, not to the notice`);
  // No trap: Tab leaves it in at most as many steps as it has focusable elements.
  const stops = await page.evaluate(() => document.querySelectorAll("#estrategia-web-consent a[href], #estrategia-web-consent button:not([disabled])").length);
  let left = false;
  for (let i = 0; i <= stops && !left; i++) {
    await page.keyboard.press("Tab");
    left = !(await inside());
  }
  assert.ok(left, `after ${stops + 1} Tab presses focus is still inside the notice: focus trap`);
  assert.ok(await notice(page).isVisible(), "leaving with Tab must not close the notice");
  await page.keyboard.press("Shift+Tab");
  assert.ok(await inside(), "Shift+Tab does not go back to the notice");
  await page.keyboard.press("Escape");
  assert.ok(await notice(page).isVisible(), "the first time, Escape does not close it: a choice is required");
  assert.deepEqual(page.errors, []);
  await ctx.close();
});

test("consent (0.5.1): between the text and the buttons there are at least 16 px, at 402 and at 1512", async () => {
  for (const width of [402, 1512]) {
    const ctx = await browser.newContext({ viewport: { width, height: 900 } });
    const page = await ctx.newPage();
    await page.goto(pageUrl, { waitUntil: "networkidle" });
    await notice(page).waitFor({ state: "visible" });
    const m = await page.evaluate(() => {
      const text = document.querySelector(".estrategia-web-consent__text");
      const title = document.querySelector(".estrategia-web-consent__title");
      const range = document.createRange();
      range.selectNodeContents(text);
      const lastLine = Math.max(...[...range.getClientRects()].map((r) => r.bottom));
      const button = document.querySelector(".estrategia-web-consent__actions").getBoundingClientRect().top;
      return { gap: button - lastLine, titleGap: text.getBoundingClientRect().top - title.getBoundingClientRect().bottom };
    });
    assert.ok(m.gap >= 16, `${width}: between the last line of text and the buttons there are ${m.gap.toFixed(1)} px`);
    assert.ok(m.titleGap >= 4, `${width}: title and text stuck together (${m.titleGap.toFixed(1)} px)`);
    console.log(`# ${width}: text → buttons ${m.gap.toFixed(1)} px; title → text ${m.titleGap.toFixed(1)} px`);
    await ctx.close();
  }
});

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

test("consent (0.5.0): «Powered by» visible, small (does not compete with the buttons) and with MEASURED AA contrast", async () => {
  const { ctx, page } = await open();
  await notice(page).waitFor({ state: "visible" });
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
  await ctx.close();
});
