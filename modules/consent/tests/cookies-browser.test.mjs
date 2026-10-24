// NIVEL: 3 — browser. NEEDS A BROWSER BECAUSE: the detector reads document.cookie in the visitor's browser and decides when to send; reopening the panel from a link is a click; and the «Cookies» screen only exists rendered and with an administrator session.
//
// Cookie registry (1.1.0). What it is and why: headers of
// includes/class-cookie-registry.php and class-cookie-rest.php. Here:
// - on ACCEPTING, the browser sends the NAMES of its cookies and no value
//   (a cookie with a «secret» value set beforehand, and the request body is
//   checked exactly as it left);
// - rejecting sends nothing; and once accepted, the next page does not send
//   again (localStorage) if nothing changed;
// - a link to #estrategia-web-consent or an element with the
//   estrategia-web-consent-open class reopens the panel;
// - on the screen, what was detected shows as pending with its suggestion;
//   it is confirmed with its button and appears in the policy table.
//
// Seen failing on 14-10-2026 with two mutants: cookieNames() returning the
// whole pairs (the value leaks) and scheduleScan without checking whether
// anything was accepted.
//
// How to maintain: if what the detector sends changes, the «not a single
// value» case keeps checking the request BODY, not what was stored.
import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { BASE, playwright, reset, seedPage, testApi } from "../../../tests/lib.mjs";

let browser;
let pageUrl;
let policyUrl;
const cleanAll = async () => {
  await reset();
  await fetch(BASE + "/wp-json/estrategia-web-cookies-test/v1/reset", { method: "POST" });
};
const cookiesTest = (path, body) =>
  fetch(BASE + "/wp-json/estrategia-web-cookies-test/v1" + path, {
    method: body === undefined ? "GET" : "POST",
    headers: { "Content-Type": "application/json" },
    body: body === undefined ? undefined : JSON.stringify(body),
  }).then((r) => r.json());

before(async () => {
  browser = await playwright().chromium.launch();
  await cleanAll();
  await cookiesTest("/setup", { interval: 0 });
  await testApi("/consent-setup", { method: "POST", body: { categories: ["maps", "statistics"] } });
  ({ url: pageUrl } = await seedPage(
    '<!-- wp:paragraph --><p><a href="#estrategia-web-consent">Menu cookies</a></p><!-- /wp:paragraph -->' +
      '<!-- wp:paragraph {"className":"estrategia-web-consent-open"} --><p class="estrategia-web-consent-open">Button with class</p><!-- /wp:paragraph -->',
    "test-detector"
  ));
  ({ url: policyUrl } = await seedPage("<!-- wp:estrategia-web/cookie-table /-->", "test-policy"));
});
after(async () => {
  await browser?.close();
  await cleanAll();
});

async function open(url = pageUrl) {
  const ctx = await browser.newContext({ viewport: { width: 1200, height: 900 } });
  await ctx.addCookies([{ name: "prueba_valor", value: "SECRET123", url: BASE }]);
  const page = await ctx.newPage();
  page.reports = [];
  page.errors = [];
  page.on("pageerror", (e) => page.errors.push(e.message));
  page.on("request", (r) => {
    if (r.url().includes("/estrategia-web/v1/cookies/seen")) page.reports.push(r.postData() || "");
  });
  await page.goto(url, { waitUntil: "networkidle" });
  await page.waitForFunction(() => window.estrategiaWebConsent);
  return { ctx, page };
}

test("detector: on accepting it sends ONLY names (not a single value) and the next page does not repeat", async () => {
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
  await page.waitForTimeout(5000);
  assert.deepEqual(page.reports, [], "same list and same names: it does not send again");
  assert.deepEqual(page.errors, []);
  await ctx.close();
});

test("detector: rejecting sends nothing", async () => {
  const { ctx, page } = await open();
  await page.getByRole("button", { name: "Rechazar todas" }).click();
  await page.waitForTimeout(5000);
  assert.deepEqual(page.reports, []);
  await ctx.close();
});

test("reopen: a link to #estrategia-web-consent and an element with the class open the panel", async () => {
  const { ctx, page } = await open();
  const notice = page.locator("#estrategia-web-consent");
  await page.getByRole("button", { name: "Rechazar todas" }).click();
  await notice.waitFor({ state: "hidden" });
  await page.getByRole("link", { name: "Menu cookies" }).click();
  await notice.waitFor({ state: "visible" });
  assert.ok(!page.url().endsWith("#estrategia-web-consent"), "the link does not navigate");
  await page.waitForFunction(() => document.activeElement?.id === "estrategia-web-consent-title");
  await page.keyboard.press("Escape");
  await notice.waitFor({ state: "hidden" });
  await page.getByText("Button with class").click();
  await notice.waitFor({ state: "visible" });
  await ctx.close();
});

test("Cookies screen: what was detected shows as pending with its suggestion; once confirmed, it shows in the policy", async () => {
  const ctx = await browser.newContext({ viewport: { width: 1400, height: 1000 } });
  const page = await ctx.newPage();
  // 1.7.0: the screen is the «Cookies» tab of Estrategia Web › Settings.
  const screen = BASE + "/wp-admin/admin.php?page=estrategia-web-settings&tab=cookies";
  await page.goto(screen);
  if (page.url().includes("wp-login.php")) {
    await page.fill("#user_login", "admin");
    await page.fill("#user_pass", "password");
    await Promise.all([page.waitForURL(/wp-admin/), page.click("#wp-submit")]);
    await page.goto(screen);
  }
  await page.locator('.nav-tab-active[data-tab="cookies"]').waitFor();
  const row = page.locator("tr", { has: page.locator("code", { hasText: /^estrategia_web_consent$/ }) });
  assert.equal(await row.locator('select[name="status"]').inputValue(), "pending");
  assert.equal(await row.locator('select[name="category"]').inputValue(), "necessary");
  assert.match(await row.innerText(), /Propuesta: Estrategia Web/);
  await row.locator('select[name="status"]').selectOption("confirmed");
  await Promise.all([page.waitForURL(/notice=saved/), row.getByRole("button", { name: "Guardar" }).click()]);
  assert.match(await page.locator(".notice-success").innerText(), /Cambios guardados/);

  const policy = await ctx.newPage();
  await policy.goto(policyUrl);
  const table = policy.locator(".estrategia-web-cookie-table");
  assert.match(await table.innerText(), /Necesarias[\s\S]*estrategia_web_consent[\s\S]*Este sitio web[\s\S]*1 año/);
  assert.ok(!(await table.innerText()).includes("prueba_valor"), "pending items do not show");
  await ctx.close();
});
