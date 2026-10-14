// NIVEL: 3 — navegador. NECESITA NAVEGADOR PORQUE: lo probado es qué pide el navegador a Google y cuándo, y lo que queda en dataLayer (Consent Mode) al pulsar los botones del aviso REAL.
//
// Etiquetas de Google (1.1.0). Qué hace: cabecera de
// modules/google-tags/includes/class-google-tags.php. Google NO se llama:
// todo lo que va a googletagmanager.com se intercepta, se apunta y se
// contesta con un script vacío. Se prueba NUESTRO lado (cuándo se pide y qué
// órdenes de consentimiento se dan), no lo que haga gtag.js con ellas.
//
// Vista fallar el 14-10-2026 con dos mutantes: load() llamado siempre (el
// modo básico pedía Google antes de aceptar) y el «update» de analytics
// atado a marketing.
//
// Cómo se mantiene: un caso por modo y por categoría; si cambia el reparto
// de categorías (cabecera de la clase), cambia aquí.
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
    return route.fulfill({ status: 200, contentType: "text/javascript", body: "/* gtag de mentira */" });
  });
  await page.goto(pageUrl, { waitUntil: "networkidle" });
  await page.waitForFunction(() => window.estrategiaWebConsent);
  return { ctx, page };
}
const layer = (page) => page.evaluate(() => window.dataLayer.map((a) => (typeof a.length === "number" ? Array.from(a) : a)));
const lastUpdate = async (page) => (await layer(page)).filter((e) => e[0] === "consent" && e[1] === "update").pop()?.[2];

test("google-tags (básico): NADA a Google sin aceptar, ni rechazando, ni aceptando solo mapas", async () => {
  await save({ ga4: "G-TEST12345", ads: "", gtm: "", mode: "basic" });
  const { ctx, page } = await open();
  const first = (await layer(page))[0];
  assert.deepEqual(first.slice(0, 2), ["consent", "default"], "el «default» es lo PRIMERO de dataLayer");
  assert.equal(first[2].analytics_storage, "denied");
  assert.equal(first[2].ad_storage, "denied");
  assert.deepEqual(page.google, [], "sin elegir");

  await page.getByRole("button", { name: "Configurar", exact: true }).click();
  await page.getByRole("checkbox", { name: /Mapas y contenido de terceros/ }).check();
  await page.getByRole("button", { name: "Guardar la selección" }).click();
  await page.waitForTimeout(300);
  assert.deepEqual(page.google, [], "solo mapas no es Analítica");
  assert.equal((await lastUpdate(page))?.analytics_storage, "denied");

  await page.evaluate(() => window.estrategiaWebConsent.open());
  await page.getByRole("button", { name: "Rechazar todas" }).click();
  await page.reload({ waitUntil: "networkidle" });
  assert.deepEqual(page.google, [], "rechazando");
  assert.deepEqual(page.errors, []);
  await ctx.close();
});

test("google-tags (básico): al aceptar Analítica se pide gtag.js con el id y se concede SOLO analytics_storage", async () => {
  await save({ ga4: "G-TEST12345", ads: "", gtm: "", mode: "basic" });
  const { ctx, page } = await open();
  await page.getByRole("button", { name: "Aceptar todas" }).click();
  await page.waitForFunction(() => window.dataLayer.some((a) => a[0] === "config"));
  assert.ok(page.google.some((u) => u.startsWith("https://www.googletagmanager.com/gtag/js?id=G-TEST12345")), page.google.join("\n"));
  const update = await lastUpdate(page);
  assert.equal(update.analytics_storage, "granted");
  assert.equal(update.ad_storage, "denied", "la web no pregunta Publicidad: no se concede");
  assert.ok((await layer(page)).some((e) => e[0] === "config" && e[1] === "G-TEST12345"));

  // Y al volver, con la elección guardada, se carga sin pulsar nada.
  page.google.length = 0;
  await page.reload({ waitUntil: "networkidle" });
  await page.waitForFunction(() => window.dataLayer.some((a) => a[0] === "config"));
  assert.equal(page.google.length, 1);
  await ctx.close();
});

test("google-tags: retirar Analítica deniega y borra _ga y _ga_*", async () => {
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
  assert.deepEqual(left, [], "quedan cookies de Google tras retirar el consentimiento");
  await ctx.close();
});

test("google-tags: Ads + conversión de formulario solo con Publicidad aceptada", async () => {
  await save({ ga4: "", ads: "AW-123456789", ads_form_label: "AW-123456789/AbCd_ef-12", gtm: "", mode: "basic" });
  const { ctx, page } = await open();
  const send = () => page.evaluate(() => document.dispatchEvent(new CustomEvent("estrategia-web:form-sent", { detail: { form: "contacto" } })));
  const conversions = async () => (await layer(page)).filter((e) => e[0] === "event" && e[1] === "conversion");
  await send();
  assert.equal((await conversions()).length, 0, "sin consentimiento no hay conversión");
  await page.getByRole("button", { name: "Aceptar todas" }).click();
  await page.waitForFunction(() => window.dataLayer.some((a) => a[0] === "config"));
  const update = await lastUpdate(page);
  assert.equal(update.ad_storage, "granted");
  assert.equal(update.ad_user_data, "granted");
  assert.equal(update.ad_personalization, "granted");
  assert.equal(update.analytics_storage, "denied", "la web no pregunta Analítica");
  await send();
  assert.deepEqual((await conversions())[0][2], { send_to: "AW-123456789/AbCd_ef-12" });
  await ctx.close();
});

test("google-tags (avanzado): Google se pide YA, con todo denied, antes de elegir", async () => {
  await save({ ga4: "G-TEST12345", ads: "", ads_form_label: "", gtm: "GTM-ABCD123", mode: "advanced" });
  const { ctx, page } = await open();
  assert.ok(page.google.some((u) => u.includes("gtag/js?id=G-TEST12345")), "gtag.js");
  assert.ok(page.google.some((u) => u.includes("gtm.js?id=GTM-ABCD123")), "gtm.js");
  assert.ok(!(await layer(page)).some((e) => e[0] === "consent" && e[1] === "update" && e[2].analytics_storage === "granted"));
  await ctx.close();
});
