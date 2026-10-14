// NIVEL: 3 — navegador. NECESITA NAVEGADOR PORQUE: las cookies las pone (o no) el matomo.js de verdad al ejecutarse, y el consentimiento llega del aviso real por un evento del DOM.
//
// Matomo (0.3.0). No se llama a ningún Matomo: las peticiones a
// https://stats.ejemplo.test/ se interceptan; matomo.js se sirve desde
// modules/matomo/tests/fixtures/matomo.js (el rastreador REAL, BSD-3, 5.4.0) y matomo.php
// contesta 204. Así lo que se prueba es lo que hace el rastreador con
// nuestras órdenes, no un doble que se porta como creemos.
//
// El consentimiento llega del AVISO REAL (0.5.0: el aviso es de este plugin,
// includes/class-consent.php): se pulsan sus botones y se guarda su cookie.
// Hasta 0.4.0 el aviso era del tema padre, que aquí no estaba, y esta prueba
// lo SIMULABA lanzando el evento a mano; eso no veía si el aviso y Matomo se
// entendían de verdad (orden de carga de los dos scripts incluido).
// Y no lo veía: al pasarla al aviso real, «elección caducada» falló 1 de cada
// 3 veces. El aviso hidrataba DESPUÉS de window.load y Matomo nunca se
// enteraba de «sin elegir». Arreglado con estrategia-web:consent-ready (trampa 1 de
// includes/class-matomo.php); verde 5 de 5 después. También vista fallar
// quitando el evento estrategia-web:consent del aviso.
//
// Cómo se mantiene: al actualizar el fixture, que la cabecera diga la
// versión; y la prueba de control («híbrido con consentimiento SÍ pone
// _pk_id») tiene que seguir en verde, o las de «sin cookies» no demuestran nada.
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

/** Contexto nuevo con Matomo interceptado (y, si se dan, cookies puestas antes). Devuelve { ctx, page, hits, errors }. */
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
  assert.fail("no llegó: " + what);
}

test("matomo (0.3.0): sin configuración no se pide matomo.js", async () => {
  await reset();
  const { ctx, page, hits } = await open();
  let asked = false;
  page.on("request", (r) => (asked ||= r.url().startsWith(MATOMO)));
  await page.goto(BASE + "/");
  await page.waitForLoadState("load");
  assert.equal(await page.locator("#estrategia-web-site-matomo").count(), 0);
  assert.equal(asked, false, "ninguna petición a Matomo");
  assert.equal(hits.length, 0);
  await ctx.close();
});

test("matomo (0.3.0): modo SIN cookies mide (llega matomo.php), no deja ninguna cookie de Matomo y no hay aviso", async () => {
  await reset();
  await saveCompany({ matomo_url: MATOMO, matomo_site_id: 3, matomo_mode: "cookieless" });
  const { ctx, page, hits, errors } = await open();
  await page.goto(BASE + "/");
  await until(() => hits.some((u) => u.includes("matomo.php") && u.includes("idsite=3")), "la visita en matomo.php");
  await page.evaluate(() => window._paq.push(["trackEvent", "prueba", "clic"]));
  await until(() => hits.length >= 2, "el segundo envío");
  assert.deepEqual(await matomoCookies(ctx), [], "sin cookies de Matomo");
  assert.equal(await page.locator("#estrategia-web-consent").count(), 0, "sin cookies no hay nada que preguntar: no hay aviso");
  assert.deepEqual(errors, []);
  await ctx.close();
});

test("matomo (0.5.0): HÍBRIDO con el aviso REAL — sin cookies hasta «Aceptar todas», _pk_id después, y «Rechazar todas» las borra", async () => {
  await reset();
  await saveCompany({ matomo_url: MATOMO, matomo_site_id: 3, matomo_mode: "hybrid" });
  await testApi("/consent-setup", { method: "POST", body: { categories: ["maps"] } });
  const { ctx, page, hits, errors } = await open();
  await page.goto(BASE + "/");
  await until(() => hits.some((u) => u.includes("matomo.php")), "la visita sin cookies");
  const notice = page.locator("#estrategia-web-consent");
  await notice.waitFor({ state: "visible" });
  assert.deepEqual(await matomoCookies(ctx), [], "antes de contestar, ninguna cookie");

  // Solo «mapas»: no es consentimiento para Matomo.
  await page.getByRole("button", { name: "Configurar", exact: true }).click();
  await page.getByRole("checkbox", { name: /Mapas y contenido de terceros/ }).check();
  await page.getByRole("button", { name: "Guardar la selección" }).click();
  await notice.waitFor({ state: "hidden" });
  await page.evaluate(() => window._paq.push(["trackPageView"]));
  await until(() => hits.filter((u) => u.includes("matomo.php")).length >= 2, "el segundo envío");
  assert.deepEqual(await matomoCookies(ctx), [], "aceptar otra categoría no da consentimiento a Matomo");

  // Aceptar todas.
  await page.evaluate(() => window.estrategiaWebConsent.open());
  await notice.waitFor({ state: "visible" });
  await page.getByRole("button", { name: "Aceptar todas" }).click();
  await until(async () => (await matomoCookies(ctx)).includes("mtm_cookie_consent"), "mtm_cookie_consent tras aceptar");
  // Control: con el consentimiento, el siguiente envío SÍ escribe el id de visitante.
  await page.evaluate(() => window._paq.push(["trackPageView"]));
  await until(async () => (await matomoCookies(ctx)).some((n) => n.startsWith("_pk_id")), "_pk_id tras aceptar");

  // Rechazar todas.
  await page.evaluate(() => window.estrategiaWebConsent.open());
  await notice.waitFor({ state: "visible" });
  await page.getByRole("button", { name: "Rechazar todas" }).click();
  await until(async () => (await matomoCookies(ctx)).length === 0, "que se borren al retirar el consentimiento");
  assert.deepEqual(errors, []);
  await ctx.close();
});

test("matomo (0.5.0): híbrido con el aviso ya contestado (su cookie) al cargar: recuerda sin que nadie pulse nada", async () => {
  await reset();
  await saveCompany({ matomo_url: MATOMO, matomo_site_id: 3, matomo_mode: "hybrid" });
  const { ctx, page } = await open({ cookies: [consentCookie(md5("statistics|1"), ["statistics"])] });
  await page.goto(BASE + "/");
  await until(async () => (await matomoCookies(ctx)).includes("mtm_cookie_consent"), "mtm_cookie_consent al cargar");
  assert.ok(await page.locator("#estrategia-web-consent").isHidden(), "el aviso no sale con la elección guardada");
  await ctx.close();
});

test("matomo (0.5.0): híbrido con la elección del aviso caducada (otra revisión) OLVIDA un consentimiento de Matomo anterior", async () => {
  await reset();
  await saveCompany({ matomo_url: MATOMO, matomo_site_id: 3, matomo_mode: "hybrid" });
  const { ctx, page } = await open({
    cookies: [consentCookie("deadbeef", ["statistics"]), { name: "mtm_cookie_consent", value: String(Date.now()), url: BASE + "/" }],
  });
  await page.goto(BASE + "/");
  await page.locator("#estrategia-web-consent").waitFor({ state: "visible" });
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
  assert.equal(await page.locator("#estrategia-web-site-matomo").count(), 0, "sin código para quien edita");
  await ctx.close();

  const anon = await open();
  await anon.page.goto(BASE + "/");
  assert.equal(await anon.page.locator("#estrategia-web-site-matomo").count(), 1, "control: a un visitante sí");
  await anon.ctx.close();
});
