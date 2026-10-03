// NIVEL: 3 — navegador. NECESITA NAVEGADOR PORQUE: las cookies las pone (o no) el matomo.js de verdad al ejecutarse, y el consentimiento llega por un evento del DOM.
//
// Matomo (0.3.0). No se llama a ningún Matomo: las peticiones a
// https://stats.ejemplo.test/ se interceptan; matomo.js se sirve desde
// tests/fixtures/matomo.js (el rastreador REAL, BSD-3, 5.4.0) y matomo.php
// contesta 204. Así lo que se prueba es lo que hace el rastreador con
// nuestras órdenes, no un doble que se porta como creemos.
//
// El tema padre (que es quien lanza «armazon:consent») no está en este
// WordPress: la prueba hace su papel con su contrato tal cual está escrito en
// armazon-wp-padre/blocks/consent/view.js (evento en document con
// detail.accepted, y window.armazonConsent.get()). Si el padre cambia ese
// contrato, esta prueba no lo verá: lo verá la de consentimiento del padre.
//
// Cómo se mantiene: al actualizar el fixture, que la cabecera diga la
// versión; y la prueba de control («híbrido con consentimiento SÍ pone
// _pk_id») tiene que seguir en verde, o las de «sin cookies» no demuestran nada.
import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import { BASE, playwright, reset, saveCompany } from "./lib.mjs";

const MATOMO = "https://stats.ejemplo.test/";
const TRACKER = fs.readFileSync(path.join(import.meta.dirname, "fixtures", "matomo.js"), "utf8");

let browser;
before(async () => {
  browser = await playwright().chromium.launch();
});
after(() => browser?.close());

/** Contexto nuevo (sin cookies) con Matomo interceptado. Devuelve { ctx, page, hits, errors }. */
async function open({ init } = {}) {
  const ctx = await browser.newContext();
  const hits = [];
  const errors = [];
  await ctx.route(MATOMO + "**", (route) => {
    const url = route.request().url();
    if (url.includes("matomo.js")) return route.fulfill({ status: 200, contentType: "application/javascript", body: TRACKER });
    hits.push(url);
    return route.fulfill({ status: 204, body: "" });
  });
  if (init) await ctx.addInitScript(init);
  const page = await ctx.newPage();
  page.on("pageerror", (e) => errors.push(e.message));
  return { ctx, page, hits, errors };
}

const matomoCookies = async (ctx) => (await ctx.cookies()).filter((c) => /^(_pk_|mtm_)/.test(c.name)).map((c) => c.name).sort();

async function until(fn, what, ms = 8000) {
  const end = Date.now() + ms;
  while (Date.now() < end) {
    if (await fn()) return;
    await new Promise((r) => setTimeout(r, 100));
  }
  assert.fail("no llegó: " + what);
}

const consent = (page, accepted) =>
  page.evaluate((a) => document.dispatchEvent(new CustomEvent("armazon:consent", { detail: { accepted: a } })), accepted);

test("matomo (0.3.0): sin configuración no se pide matomo.js", async () => {
  await reset();
  const { ctx, page, hits } = await open();
  let asked = false;
  page.on("request", (r) => (asked ||= r.url().startsWith(MATOMO)));
  await page.goto(BASE + "/");
  await page.waitForLoadState("load");
  assert.equal(await page.locator("#armazon-site-matomo").count(), 0);
  assert.equal(asked, false, "ninguna petición a Matomo");
  assert.equal(hits.length, 0);
  await ctx.close();
});

test("matomo (0.3.0): modo SIN cookies mide (llega matomo.php) y no deja ninguna cookie de Matomo", async () => {
  await reset();
  await saveCompany({ matomo_url: MATOMO, matomo_site_id: 3, matomo_mode: "cookieless" });
  const { ctx, page, hits, errors } = await open();
  await page.goto(BASE + "/");
  await until(() => hits.some((u) => u.includes("matomo.php") && u.includes("idsite=3")), "la visita en matomo.php");
  await page.evaluate(() => window._paq.push(["trackEvent", "prueba", "clic"]));
  await until(() => hits.length >= 2, "el segundo envío");
  assert.deepEqual(await matomoCookies(ctx), [], "sin cookies de Matomo");
  assert.deepEqual(errors, []);
  await ctx.close();
});

test("matomo (0.3.0): HÍBRIDO mide sin cookies, al aceptar «statistics» recuerda el consentimiento y pone _pk_id, y al retirarlo las borra", async () => {
  await reset();
  await saveCompany({ matomo_url: MATOMO, matomo_site_id: 3, matomo_mode: "hybrid" });
  const { ctx, page, hits, errors } = await open();
  await page.goto(BASE + "/");
  await until(() => hits.some((u) => u.includes("matomo.php")), "la visita sin cookies");
  assert.deepEqual(await matomoCookies(ctx), [], "antes de aceptar, ninguna cookie");

  await consent(page, ["maps"]);
  await page.waitForTimeout(300);
  assert.deepEqual(await matomoCookies(ctx), [], "aceptar otra categoría no da consentimiento a Matomo");

  await consent(page, ["maps", "statistics"]);
  await until(async () => (await matomoCookies(ctx)).includes("mtm_cookie_consent"), "mtm_cookie_consent tras aceptar");
  // Control: con el consentimiento, el siguiente envío SÍ escribe el id de visitante.
  await page.evaluate(() => window._paq.push(["trackPageView"]));
  await until(async () => (await matomoCookies(ctx)).some((n) => n.startsWith("_pk_id")), "_pk_id tras aceptar");

  await consent(page, ["maps"]);
  await until(async () => (await matomoCookies(ctx)).length === 0, "que se borren al retirar el consentimiento");
  assert.deepEqual(errors, []);
  await ctx.close();
});

test("matomo (0.3.0): híbrido con el aviso ya resuelto al cargar (window.armazonConsent): recuerda sin esperar al evento", async () => {
  await reset();
  await saveCompany({ matomo_url: MATOMO, matomo_site_id: 3, matomo_mode: "hybrid" });
  const { ctx, page } = await open({ init: () => (window.armazonConsent = { get: () => ["statistics"], has: (c) => c === "statistics" }) });
  await page.goto(BASE + "/");
  await until(async () => (await matomoCookies(ctx)).includes("mtm_cookie_consent"), "mtm_cookie_consent al cargar");
  await ctx.close();
});

test("matomo (0.3.0): híbrido con la elección del aviso caducada (get() = null) OLVIDA un consentimiento de Matomo anterior", async () => {
  await reset();
  await saveCompany({ matomo_url: MATOMO, matomo_site_id: 3, matomo_mode: "hybrid" });
  const { ctx, page } = await open({ init: () => (window.armazonConsent = { get: () => null, has: () => false }) });
  await ctx.addCookies([{ name: "mtm_cookie_consent", value: String(Date.now()), url: BASE + "/" }]);
  await page.goto(BASE + "/");
  await until(async () => !(await matomoCookies(ctx)).includes("mtm_cookie_consent"), "que se borre mtm_cookie_consent");
  await ctx.close();
});

test("matomo (0.3.0): a un administrador con sesión no se le mide", async () => {
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
  assert.equal(await page.locator("#armazon-site-matomo").count(), 0, "sin código para quien edita");
  await ctx.close();

  const anon = await open();
  await anon.page.goto(BASE + "/");
  assert.equal(await anon.page.locator("#armazon-site-matomo").count(), 1, "control: a un visitante sí");
  await anon.ctx.close();
});
