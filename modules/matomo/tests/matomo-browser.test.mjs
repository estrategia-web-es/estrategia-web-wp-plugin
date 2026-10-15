// NIVEL: 3 — browser. NEEDS A BROWSER BECAUSE: the cookies are set (or not) by the real matomo.js when it runs, and consent comes from the real notice through a DOM event.
//
// Matomo (0.3.0). No Matomo server is called: requests to
// https://stats.ejemplo.test/ are intercepted; matomo.js is served from
// modules/matomo/tests/fixtures/matomo.js (the REAL tracker, BSD-3, 5.4.0) and
// matomo.php answers 204. So what is tested is what the tracker does with our
// commands, not a double that behaves the way we think it does.
//
// Consent comes from the REAL NOTICE (0.5.0: the notice belongs to this
// plugin, includes/class-consent.php): its buttons are clicked and its cookie
// is stored. Up to 0.4.0 the notice belonged to the parent theme, which was
// not present here, and this test SIMULATED it by firing the event by hand;
// that could not see whether the notice and Matomo really understood each
// other (including the load order of the two scripts).
// And it did not see it: when switched to the real notice, «expired choice»
// failed 1 in 3 times. The notice hydrated AFTER window.load and Matomo never
// learned about «no choice yet». Fixed with estrategia-web:consent-ready
// (pitfall 1 in includes/class-matomo.php); green 5 out of 5 afterwards. Also
// seen failing by removing the estrategia-web:consent event from the notice.
//
// How to maintain: when updating the fixture, make its header state the
// version; and the control test («hybrid with consent DOES set _pk_id») must
// stay green, or the «no cookies» tests prove nothing.
import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import crypto from "node:crypto";
import fs from "node:fs";
import path from "node:path";
import { BASE, playwright, reset, saveCompany, testApi } from "../../../tests/lib.mjs";

const MATOMO = "https://stats.ejemplo.test/";
const TRACKER = fs.readFileSync(path.join(import.meta.dirname, "fixtures", "matomo.js"), "utf8");
const md5 = (s) => crypto.createHash("md5").update(s).digest("hex").slice(0, 8);

let browser;
before(async () => {
  browser = await playwright().chromium.launch();
});
after(() => browser?.close());

/** New context with Matomo intercepted (and, if given, cookies set beforehand). Returns { ctx, page, hits, errors }. */
async function open({ cookies = [] } = {}) {
  const ctx = await browser.newContext({ viewport: { width: 1200, height: 900 } });
  const hits = [];
  const errors = [];
  await ctx.route(MATOMO + "**", (route) => {
    const url = route.request().url();
    if (url.includes("matomo.js")) return route.fulfill({ status: 200, contentType: "application/javascript", body: TRACKER });
    hits.push(url);
    return route.fulfill({ status: 204, body: "" });
  });
  if (cookies.length) await ctx.addCookies(cookies);
  const page = await ctx.newPage();
  page.on("pageerror", (e) => errors.push(e.message));
  return { ctx, page, hits, errors };
}

const matomoCookies = async (ctx) => (await ctx.cookies()).filter((c) => /^(_pk_|mtm_)/.test(c.name)).map((c) => c.name).sort();
const consentCookie = (revision, accepted) => ({ name: "estrategia_web_consent", value: encodeURIComponent(`${revision}.${accepted.join("~")}.${Math.floor(Date.now() / 1000)}`), url: BASE + "/" });

async function until(fn, what, ms = 8000) {
  const end = Date.now() + ms;
  while (Date.now() < end) {
    if (await fn()) return;
    await new Promise((r) => setTimeout(r, 100));
  }
  assert.fail("never arrived: " + what);
}

test("matomo (0.3.0): without configuration matomo.js is not requested", async () => {
  await reset();
  const { ctx, page, hits } = await open();
  let asked = false;
  page.on("request", (r) => (asked ||= r.url().startsWith(MATOMO)));
  await page.goto(BASE + "/");
  await page.waitForLoadState("load");
  assert.equal(await page.locator("#estrategia-web-site-matomo").count(), 0);
  assert.equal(asked, false, "no request to Matomo");
  assert.equal(hits.length, 0);
  await ctx.close();
});

test("matomo (0.3.0): COOKIELESS mode measures (matomo.php is hit), leaves no Matomo cookie and there is no notice", async () => {
  await reset();
  await saveCompany({ matomo_url: MATOMO, matomo_site_id: 3, matomo_mode: "cookieless" });
  const { ctx, page, hits, errors } = await open();
  await page.goto(BASE + "/");
  await until(() => hits.some((u) => u.includes("matomo.php") && u.includes("idsite=3")), "the visit in matomo.php");
  await page.evaluate(() => window._paq.push(["trackEvent", "prueba", "clic"]));
  await until(() => hits.length >= 2, "the second hit");
  assert.deepEqual(await matomoCookies(ctx), [], "no Matomo cookies");
  assert.equal(await page.locator("#estrategia-web-consent").count(), 0, "without cookies there is nothing to ask: no notice");
  assert.deepEqual(errors, []);
  await ctx.close();
});

test("matomo (0.5.0): HYBRID with the REAL notice — no cookies until «Aceptar todas», _pk_id afterwards, and «Rechazar todas» deletes them", async () => {
  await reset();
  await saveCompany({ matomo_url: MATOMO, matomo_site_id: 3, matomo_mode: "hybrid" });
  await testApi("/consent-setup", { method: "POST", body: { categories: ["maps"] } });
  const { ctx, page, hits, errors } = await open();
  await page.goto(BASE + "/");
  await until(() => hits.some((u) => u.includes("matomo.php")), "the cookieless visit");
  const notice = page.locator("#estrategia-web-consent");
  await notice.waitFor({ state: "visible" });
  assert.deepEqual(await matomoCookies(ctx), [], "before answering, no cookie");

  // Only «maps»: that is not consent for Matomo.
  await page.getByRole("button", { name: "Configurar", exact: true }).click();
  await page.getByRole("checkbox", { name: /Mapas y contenido de terceros/ }).check();
  await page.getByRole("button", { name: "Guardar la selección" }).click();
  await notice.waitFor({ state: "hidden" });
  await page.evaluate(() => window._paq.push(["trackPageView"]));
  await until(() => hits.filter((u) => u.includes("matomo.php")).length >= 2, "the second hit");
  assert.deepEqual(await matomoCookies(ctx), [], "accepting another category does not give consent to Matomo");

  // Accept all.
  await page.evaluate(() => window.estrategiaWebConsent.open());
  await notice.waitFor({ state: "visible" });
  await page.getByRole("button", { name: "Aceptar todas" }).click();
  await until(async () => (await matomoCookies(ctx)).includes("mtm_cookie_consent"), "mtm_cookie_consent after accepting");
  // Control: with consent, the next hit DOES write the visitor id.
  await page.evaluate(() => window._paq.push(["trackPageView"]));
  await until(async () => (await matomoCookies(ctx)).some((n) => n.startsWith("_pk_id")), "_pk_id after accepting");

  // Reject all.
  await page.evaluate(() => window.estrategiaWebConsent.open());
  await notice.waitFor({ state: "visible" });
  await page.getByRole("button", { name: "Rechazar todas" }).click();
  await until(async () => (await matomoCookies(ctx)).length === 0, "them being deleted when consent is withdrawn");
  assert.deepEqual(errors, []);
  await ctx.close();
});

test("matomo (0.5.0): hybrid with the notice already answered (its cookie) on load: remembers without anyone clicking", async () => {
  await reset();
  await saveCompany({ matomo_url: MATOMO, matomo_site_id: 3, matomo_mode: "hybrid" });
  const { ctx, page } = await open({ cookies: [consentCookie(md5("statistics|1"), ["statistics"])] });
  await page.goto(BASE + "/");
  await until(async () => (await matomoCookies(ctx)).includes("mtm_cookie_consent"), "mtm_cookie_consent on load");
  assert.ok(await page.locator("#estrategia-web-consent").isHidden(), "the notice does not show with the choice saved");
  await ctx.close();
});

test("matomo (0.5.0): hybrid with the notice's choice expired (another revision) FORGETS an earlier Matomo consent", async () => {
  await reset();
  await saveCompany({ matomo_url: MATOMO, matomo_site_id: 3, matomo_mode: "hybrid" });
  const { ctx, page } = await open({
    cookies: [consentCookie("deadbeef", ["statistics"]), { name: "mtm_cookie_consent", value: String(Date.now()), url: BASE + "/" }],
  });
  await page.goto(BASE + "/");
  await page.locator("#estrategia-web-consent").waitFor({ state: "visible" });
  await until(async () => !(await matomoCookies(ctx)).includes("mtm_cookie_consent"), "mtm_cookie_consent being deleted");
  await ctx.close();
});

test("matomo (0.3.0): a logged-in administrator is not measured", async () => {
  await reset();
  await saveCompany({ matomo_url: MATOMO, matomo_site_id: 3 });
  const { ctx, page } = await open();
  await page.goto(BASE + "/wp-admin/");
  if (page.url().includes("wp-login.php")) {
    await page.fill("#user_login", "admin");
    await page.fill("#user_pass", "password");
    await Promise.all([page.waitForURL(/wp-admin/), page.click("#wp-submit")]);
  }
  await page.goto(BASE + "/");
  assert.equal(await page.locator("#estrategia-web-site-matomo").count(), 0, "no code for whoever edits");
  await ctx.close();

  const anon = await open();
  await anon.page.goto(BASE + "/");
  assert.equal(await anon.page.locator("#estrategia-web-site-matomo").count(), 1, "control: a visitor is measured");
  await anon.ctx.close();
});
