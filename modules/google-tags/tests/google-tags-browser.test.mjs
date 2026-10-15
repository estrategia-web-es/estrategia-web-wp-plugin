// NIVEL: 3 — browser. NEEDS A BROWSER BECAUSE: what is tested is what the browser requests from Google and when, and what ends up in dataLayer (Consent Mode) when the buttons of the REAL notice are clicked.
//
// Google tags (1.1.0). What it does: header of
// modules/google-tags/includes/class-google-tags.php. Google is NOT called:
// everything going to googletagmanager.com is intercepted, logged and
// answered with an empty script. OUR side is tested (when it is requested and
// which consent commands are issued), not what gtag.js does with them.
//
// Seen failing on 2026-10-14 with two mutants: load() always called (basic
// mode requested Google before accepting) and the analytics «update» tied to
// marketing.
//
// How to maintain: one case per mode and per category; if the category
// mapping changes (class header), it changes here.
import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { BASE, playwright, reset, seedPage, testApi, wp } from "../../../tests/lib.mjs";

let browser;
let pageUrl;
const save = (value) => wp("/settings", { method: "POST", body: { estrategia_web_google_tags: value } });
const cleanAll = async () => {
  await reset();
  await fetch(BASE + "/wp-json/estrategia-web-cookies-test/v1/reset", { method: "POST" });
};

before(async () => {
  browser = await playwright().chromium.launch();
  await cleanAll();
  await testApi("/modules", { method: "POST", body: { active: null } });
  const { loaded } = await testApi("/modules");
  await testApi("/modules", { method: "POST", body: { active: [...loaded, "google-tags"] } });
  await testApi("/consent-setup", { method: "POST", body: { categories: ["maps"] } });
  ({ url: pageUrl } = await seedPage("<!-- wp:paragraph --><p>Hola</p><!-- /wp:paragraph -->", "prueba-google"));
});
after(async () => {
  await browser?.close();
  await cleanAll();
});

async function open() {
  const ctx = await browser.newContext({ viewport: { width: 1200, height: 900 } });
  const page = await ctx.newPage();
  page.google = [];
  page.errors = [];
  page.on("pageerror", (e) => page.errors.push(e.message));
  await ctx.route("https://www.googletagmanager.com/**", (route) => {
    page.google.push(route.request().url());
    return route.fulfill({ status: 200, contentType: "text/javascript", body: "/* fake gtag */" });
  });
  await page.goto(pageUrl, { waitUntil: "networkidle" });
  await page.waitForFunction(() => window.estrategiaWebConsent);
  return { ctx, page };
}
const layer = (page) => page.evaluate(() => window.dataLayer.map((a) => (typeof a.length === "number" ? Array.from(a) : a)));
const lastUpdate = async (page) => (await layer(page)).filter((e) => e[0] === "consent" && e[1] === "update").pop()?.[2];

test("google-tags (basic): NOTHING to Google without accepting, nor when rejecting, nor accepting only maps", async () => {
  await save({ ga4: "G-TEST12345", ads: "", gtm: "", mode: "basic" });
  const { ctx, page } = await open();
  const first = (await layer(page))[0];
  assert.deepEqual(first.slice(0, 2), ["consent", "default"], "the «default» is the FIRST thing in dataLayer");
  assert.equal(first[2].analytics_storage, "denied");
  assert.equal(first[2].ad_storage, "denied");
  assert.deepEqual(page.google, [], "no choice yet");

  await page.getByRole("button", { name: "Configurar", exact: true }).click();
  await page.getByRole("checkbox", { name: /Mapas y contenido de terceros/ }).check();
  await page.getByRole("button", { name: "Guardar la selección" }).click();
  await page.waitForTimeout(300);
  assert.deepEqual(page.google, [], "maps only is not Analytics");
  assert.equal((await lastUpdate(page))?.analytics_storage, "denied");

  await page.evaluate(() => window.estrategiaWebConsent.open());
  await page.getByRole("button", { name: "Rechazar todas" }).click();
  await page.reload({ waitUntil: "networkidle" });
  assert.deepEqual(page.google, [], "rejecting");
  assert.deepEqual(page.errors, []);
  await ctx.close();
});

test("google-tags (basic): accepting Analytics requests gtag.js with the id and grants ONLY analytics_storage", async () => {
  await save({ ga4: "G-TEST12345", ads: "", gtm: "", mode: "basic" });
  const { ctx, page } = await open();
  await page.getByRole("button", { name: "Aceptar todas" }).click();
  await page.waitForFunction(() => window.dataLayer.some((a) => a[0] === "config"));
  assert.ok(page.google.some((u) => u.startsWith("https://www.googletagmanager.com/gtag/js?id=G-TEST12345")), page.google.join("\n"));
  const update = await lastUpdate(page);
  assert.equal(update.analytics_storage, "granted");
  assert.equal(update.ad_storage, "denied", "the site does not ask about Advertising: it is not granted");
  assert.ok((await layer(page)).some((e) => e[0] === "config" && e[1] === "G-TEST12345"));

  // And on returning, with the choice saved, it loads without clicking anything.
  page.google.length = 0;
  await page.reload({ waitUntil: "networkidle" });
  await page.waitForFunction(() => window.dataLayer.some((a) => a[0] === "config"));
  assert.equal(page.google.length, 1);
  await ctx.close();
});

test("google-tags: withdrawing Analytics denies and deletes _ga and _ga_*", async () => {
  await save({ ga4: "G-TEST12345", ads: "", gtm: "", mode: "basic" });
  const { ctx, page } = await open();
  await page.getByRole("button", { name: "Aceptar todas" }).click();
  await page.evaluate(() => {
    document.cookie = "_ga=GA1.1.123.456; Path=/";
    document.cookie = "_ga_TEST12345=GS1.1.789; Path=/";
  });
  await page.evaluate(() => window.estrategiaWebConsent.open());
  await page.getByRole("button", { name: "Rechazar todas" }).click();
  await page.waitForTimeout(200);
  assert.equal((await lastUpdate(page)).analytics_storage, "denied");
  const left = (await ctx.cookies()).map((c) => c.name).filter((n) => n.startsWith("_ga"));
  assert.deepEqual(left, [], "Google cookies remain after withdrawing consent");
  await ctx.close();
});

test("google-tags: Ads + form conversion only with Advertising accepted", async () => {
  await save({ ga4: "", ads: "AW-123456789", ads_form_label: "AW-123456789/AbCd_ef-12", gtm: "", mode: "basic" });
  const { ctx, page } = await open();
  const send = () => page.evaluate(() => document.dispatchEvent(new CustomEvent("estrategia-web:form-sent", { detail: { form: "contacto" } })));
  const conversions = async () => (await layer(page)).filter((e) => e[0] === "event" && e[1] === "conversion");
  await send();
  assert.equal((await conversions()).length, 0, "without consent there is no conversion");
  await page.getByRole("button", { name: "Aceptar todas" }).click();
  await page.waitForFunction(() => window.dataLayer.some((a) => a[0] === "config"));
  const update = await lastUpdate(page);
  assert.equal(update.ad_storage, "granted");
  assert.equal(update.ad_user_data, "granted");
  assert.equal(update.ad_personalization, "granted");
  assert.equal(update.analytics_storage, "denied", "the site does not ask about Analytics");
  await send();
  assert.deepEqual((await conversions())[0][2], { send_to: "AW-123456789/AbCd_ef-12" });
  await ctx.close();
});

test("google-tags (advanced): Google is requested RIGHT AWAY, with everything denied, before choosing", async () => {
  await save({ ga4: "G-TEST12345", ads: "", ads_form_label: "", gtm: "GTM-ABCD123", mode: "advanced" });
  const { ctx, page } = await open();
  assert.ok(page.google.some((u) => u.includes("gtag/js?id=G-TEST12345")), "gtag.js");
  assert.ok(page.google.some((u) => u.includes("gtm.js?id=GTM-ABCD123")), "gtm.js");
  assert.ok(!(await layer(page)).some((e) => e[0] === "consent" && e[1] === "update" && e[2].analytics_storage === "granted"));
  await ctx.close();
});
