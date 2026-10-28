// NIVEL: 3 — browser. NOT LEVEL 2 BECAUSE: the detector reads document.cookie in the visitor's browser and decides when to send (after a delay, once per list); reopening the panel from a link is a click; and the «Cookies» screen's save is a form in an administrator's browser session.
//
// Cookie registry (1.1.0). What it is and why: headers of
// includes/class-cookie-registry.php and class-cookie-rest.php. Here:
// - on ACCEPTING, the browser sends the NAMES of its cookies and no value
//   (a cookie with a «secret» value set beforehand, and the request body is
//   checked exactly as it left); once accepted, the next page does not send
//   again (localStorage) if nothing changed;
// - rejecting sends nothing; a link to #estrategia-web-consent or an element
//   with the estrategia-web-consent-open class reopens the panel;
// - on the screen, what was detected shows as pending with its suggestion;
//   it is confirmed with its button and appears in the policy table (read
//   as the server sends it: what the table prints is cookie-registry.test.mjs).
//
// 28-10-2026: the «accept» and «reject» visitors run AT THE SAME TIME,
// «reject» and «reopen» are one visitor, the page is served with the
// detector's delay at 300 ms instead of 4 s (it is the server's constant,
// not what is tested; open() fails if it cannot find it), the login goes
// straight to the screen, and the policy page is fetched, not loaded in the
// browser.
// Seen failing on 14-10-2026 with two mutants: cookieNames() returning the
// whole pairs (the value leaks) and scheduleScan without checking whether
// anything was accepted. Seen red (28-10-2026) with one report expected
// from the «reject» visitor (test 1: «rejecting sends nothing»), and with
// the suggestion expected as «Propuesta: Google» (test 2).
//
// Round 2 (28-10-2026): before() is ONE /setup request (reset, notice, empty
// registry, interval 0, the pages), and the administrator logs in without
// the browser (adminSession, while the visitors run) and the browser gets
// its cookies: no login page, no dashboard. Seen red again with one report
// expected from the «reject» visitor and with «Propuesta: Google» expected.
//
// How to maintain: if what the detector sends changes, the «not a single
// value» check keeps looking at the request BODY, not what was stored. The
// trap: the «Cookies screen» test needs the row the «accept» visitor made, so
// it runs after them.
import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { BASE, adminSession, html, playwright, testApi } from "../../../tests/lib.mjs";

const REAL_DELAY = 4000; // includes/class-consent.php, state.detector.delay.
const DELAY = 300;
let browser;
let pageUrl;
let policyUrl;
let session;
const cookiesTest = (path, body) =>
  fetch(BASE + "/wp-json/estrategia-web-cookies-test/v1" + path, {
    method: body === undefined ? "GET" : "POST",
    headers: { "Content-Type": "application/json" },
    body: body === undefined ? undefined : JSON.stringify(body),
  }).then((r) => r.json());

before(async () => {
  // ONE request (/setup, tests/wp/estrategia-web-site-test.php): the reset,
  // the notice declaring two categories, an empty registry (the options the
  // cookies-test reset deletes), the detector's per-IP interval at 0 (the
  // two visitors share the IP) and the two pages.
  const { pages } = await testApi("/setup", {
    method: "POST",
    body: {
      consent: { categories: ["maps", "statistics"] },
      options: { estrategia_web_cookies: null, estrategia_web_cookies_settings: null, estrategia_web_cookies_test: { interval: 0 } },
      pages: [
        {
          slug: "test-detector",
          content:
            '<!-- wp:paragraph --><p><a href="#estrategia-web-consent">Menu cookies</a></p><!-- /wp:paragraph -->' +
            '<!-- wp:paragraph {"className":"estrategia-web-consent-open"} --><p class="estrategia-web-consent-open">Button with class</p><!-- /wp:paragraph -->',
        },
        { slug: "test-policy", content: "<!-- wp:estrategia-web/cookie-table /-->" },
      ],
    },
  });
  [pageUrl, policyUrl] = pages.map((p) => p.url);
  // The administrator logs in while the visitors run (one request, no browser): see the «Cookies screen» test.
  session = adminSession();
  session.catch(() => {}); // Awaited (and reported) by that test.
  browser = await playwright().chromium.launch();
});
// The site reset() does not delete the registry nor the detector setup: the other files share this WordPress.
after(async () => {
  await browser?.close();
  await cookiesTest("/reset", {});
});

async function open() {
  const ctx = await browser.newContext({ viewport: { width: 1200, height: 900 } });
  await ctx.addCookies([{ name: "prueba_valor", value: "SECRET123", url: BASE }]);
  const page = await ctx.newPage();
  page.reports = [];
  page.errors = [];
  page.on("pageerror", (e) => page.errors.push(e.message));
  page.on("request", (r) => {
    if (r.url().includes("/estrategia-web/v1/cookies/seen")) page.reports.push(r.postData() || "");
  });
  // The detector waits state.detector.delay (4000 ms) before reading the
  // cookies; the page is served with 300 ms so the waits below are short.
  // When it is NOT found the test fails: «nothing sent» after a wait shorter
  // than the real delay would prove nothing.
  await page.route((u) => u.href.startsWith(pageUrl), async (route) => {
    const response = await route.fetch();
    const body = await response.text();
    if (route.request().resourceType() !== "document") return route.fulfill({ response, body });
    page.delayFound = body.includes(`"delay":${REAL_DELAY}`);
    return route.fulfill({ response, body: body.replace(`"delay":${REAL_DELAY}`, `"delay":${DELAY}`) });
  });
  await page.goto(pageUrl, { waitUntil: "networkidle" });
  assert.ok(page.delayFound, `the page state has no "delay":${REAL_DELAY} to shorten (did the detector delay change? includes/class-consent.php)`);
  await page.waitForFunction(() => window.estrategiaWebConsent);
  return { ctx, page };
}

test("detector: accepting sends ONLY names and the next page does not repeat; rejecting sends nothing; links reopen the panel", async () => {
  const accepting = async () => {
    const { ctx, page } = await open();
    await page.getByRole("button", { name: "Aceptar todas" }).click();
    await page.waitForRequest((r) => r.url().includes("/cookies/seen"), { timeout: 10000 });
    await page.waitForTimeout(500);
    assert.equal(page.reports.length, 1);
    const body = page.reports[0];
    assert.ok(!body.includes("SECRET123"), `a cookie value left the browser: ${body}`);
    const names = JSON.parse(body).names;
    assert.ok(names.includes("prueba_valor") && names.includes("estrategia_web_consent"), body);
    assert.ok(names.every((n) => !n.includes("=")), body);

    const raw = (await cookiesTest("/raw")).raw;
    assert.equal(raw.estrategia_web_consent.category, "necessary");
    assert.equal(raw.estrategia_web_consent.status, "pending");
    assert.ok(raw.prueba_valor, "an unknown cookie gets in anyway, without a suggestion");

    page.reports.length = 0;
    await page.reload({ waitUntil: "networkidle" });
    await page.waitForTimeout(DELAY + 1000);
    assert.deepEqual(page.reports, [], "same list and same names: it does not send again");
    assert.deepEqual(page.errors, []);
    await ctx.close();
  };
  const rejecting = async () => {
    const { ctx, page } = await open();
    const notice = page.locator("#estrategia-web-consent");
    await page.getByRole("button", { name: "Rechazar todas" }).click();
    const rejectedAt = Date.now();
    await notice.waitFor({ state: "hidden" });
    // Reopen: a link to #estrategia-web-consent (it does not navigate) and an element with the class.
    await page.getByRole("link", { name: "Menu cookies" }).click();
    await notice.waitFor({ state: "visible" });
    assert.ok(!page.url().endsWith("#estrategia-web-consent"), "the link does not navigate");
    await page.waitForFunction(() => document.activeElement?.id === "estrategia-web-consent-title");
    await page.keyboard.press("Escape");
    await notice.waitFor({ state: "hidden" });
    await page.getByText("Button with class").click();
    await notice.waitFor({ state: "visible" });
    // Past the detector's delay since rejecting: nothing sent.
    await page.waitForTimeout(Math.max(0, DELAY + 1000 - (Date.now() - rejectedAt)));
    assert.deepEqual(page.reports, [], "rejecting sends nothing");
    assert.deepEqual(page.errors, []);
    await ctx.close();
  };
  await Promise.all([accepting(), rejecting()]);
});

test("Cookies screen: what was detected shows as pending with its suggestion; once confirmed, it shows in the policy", async () => {
  const ctx = await browser.newContext({ viewport: { width: 1400, height: 1000 } });
  const page = await ctx.newPage();
  // 1.7.0: the screen is the «Cookies» tab of Estrategia Web › Settings.
  const screen = BASE + "/wp-admin/admin.php?page=estrategia-web-settings&tab=cookies";
  // The session adminSession() opened (wp-login.php without a browser), handed to the browser: straight to the screen.
  const cookies = (await session).cookie().split("; ").map((pair) => {
    const i = pair.indexOf("=");
    return { name: pair.slice(0, i), value: pair.slice(i + 1), url: BASE };
  });
  await ctx.addCookies(cookies);
  await page.goto(screen);
  await page.locator('.nav-tab-active[data-tab="cookies"]').waitFor();
  const row = page.locator("tr", { has: page.locator("code", { hasText: /^estrategia_web_consent$/ }) });
  assert.equal(await row.locator('select[name="status"]').inputValue(), "pending");
  assert.equal(await row.locator('select[name="category"]').inputValue(), "necessary");
  assert.match(await row.innerText(), /Propuesta: Estrategia Web/);
  await row.locator('select[name="status"]').selectOption("confirmed");
  await Promise.all([page.waitForURL(/notice=saved/), row.getByRole("button", { name: "Guardar" }).click()]);
  assert.match(await page.locator(".notice-success").innerText(), /Cambios guardados/);
  await ctx.close();

  const policy = (await html(policyUrl + "?nocache=" + Date.now())).text;
  const table = policy.match(/<div [^>]*wp-block-estrategia-web-cookie-table[\s\S]*?<\/figure><\/div>/)?.[0] || "";
  assert.match(table, /Necesarias[\s\S]*estrategia_web_consent[\s\S]*Este sitio web[\s\S]*1 año/, "the confirmed row is not in the policy");
  assert.ok(!table.includes("prueba_valor"), "pending items do not show");
});
