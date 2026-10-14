// NIVEL: 3 — navegador. NECESITA NAVEGADOR PORQUE: el detector lee document.cookie en el navegador del visitante y decide cuándo mandar; reabrir el panel desde un enlace es un clic; y la pantalla «Cookies» solo existe pintada y con sesión de administrador.
//
// Registro de cookies (1.1.0). Qué es y por qué: cabeceras de
// includes/class-cookie-registry.php y class-cookie-rest.php. Aquí:
// - al ACEPTAR, el navegador manda los NOMBRES de sus cookies y ningún valor
//   (una cookie con un valor «secreto» puesta antes, y se mira el cuerpo de
//   la petición tal cual salió);
// - rechazando no manda nada; y aceptado, la siguiente página no vuelve a
//   mandar (localStorage) si nada cambió;
// - un enlace a #estrategia-web-consent o un elemento con la clase
//   estrategia-web-consent-open reabre el panel;
// - en la pantalla, lo detectado sale pendiente con su propuesta; se confirma
//   con su botón y aparece en la tabla de la política.
//
// Vista fallar el 14-10-2026 con dos mutantes: cookieNames() devolviendo los
// pares enteros (sale el valor) y scheduleScan sin mirar si se aceptó algo.
//
// Cómo se mantiene: si cambia lo que manda el detector, el caso «ni un
// valor» sigue mirando el CUERPO de la petición, no lo guardado.
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
    '<!-- wp:paragraph --><p><a href="#estrategia-web-consent">Cookies del menú</a></p><!-- /wp:paragraph -->' +
      '<!-- wp:paragraph {"className":"estrategia-web-consent-open"} --><p class="estrategia-web-consent-open">Botón con clase</p><!-- /wp:paragraph -->',
    "prueba-detector"
  ));
  ({ url: policyUrl } = await seedPage("<!-- wp:estrategia-web/cookie-table /-->", "prueba-politica"));
});
after(async () => {
  await browser?.close();
  await cleanAll();
});

async function open(url = pageUrl) {
  const ctx = await browser.newContext({ viewport: { width: 1200, height: 900 } });
  await ctx.addCookies([{ name: "prueba_valor", value: "SECRETO123", url: BASE }]);
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

test("detector: al aceptar manda SOLO nombres (ni un valor) y la página siguiente no repite", async () => {
  const { ctx, page } = await open();
  await page.getByRole("button", { name: "Aceptar todas" }).click();
  await page.waitForRequest((r) => r.url().includes("/cookies/seen"), { timeout: 10000 });
  await page.waitForTimeout(500);
  assert.equal(page.reports.length, 1);
  const body = page.reports[0];
  assert.ok(!body.includes("SECRETO123"), `el valor de una cookie salió del navegador: ${body}`);
  const names = JSON.parse(body).names;
  assert.ok(names.includes("prueba_valor") && names.includes("estrategia_web_consent"), body);
  assert.ok(names.every((n) => !n.includes("=")), body);

  const raw = (await cookiesTest("/raw")).raw;
  assert.equal(raw.estrategia_web_consent.category, "necessary");
  assert.equal(raw.estrategia_web_consent.status, "pending");
  assert.ok(raw.prueba_valor, "una cookie desconocida entra igual, sin propuesta");

  page.reports.length = 0;
  await page.reload({ waitUntil: "networkidle" });
  await page.waitForTimeout(5000);
  assert.deepEqual(page.reports, [], "misma lista y mismos nombres: no vuelve a mandar");
  assert.deepEqual(page.errors, []);
  await ctx.close();
});

test("detector: rechazando no manda nada", async () => {
  const { ctx, page } = await open();
  await page.getByRole("button", { name: "Rechazar todas" }).click();
  await page.waitForTimeout(5000);
  assert.deepEqual(page.reports, []);
  await ctx.close();
});

test("reabrir: un enlace a #estrategia-web-consent y un elemento con la clase abren el panel", async () => {
  const { ctx, page } = await open();
  const notice = page.locator("#estrategia-web-consent");
  await page.getByRole("button", { name: "Rechazar todas" }).click();
  await notice.waitFor({ state: "hidden" });
  await page.getByRole("link", { name: "Cookies del menú" }).click();
  await notice.waitFor({ state: "visible" });
  assert.ok(!page.url().endsWith("#estrategia-web-consent"), "el enlace no navega");
  await page.waitForFunction(() => document.activeElement?.id === "estrategia-web-consent-title");
  await page.keyboard.press("Escape");
  await notice.waitFor({ state: "hidden" });
  await page.getByText("Botón con clase").click();
  await notice.waitFor({ state: "visible" });
  await ctx.close();
});

test("pantalla Cookies: lo detectado sale pendiente con su propuesta; confirmado, sale en la política", async () => {
  const ctx = await browser.newContext({ viewport: { width: 1400, height: 1000 } });
  const page = await ctx.newPage();
  await page.goto(BASE + "/wp-admin/admin.php?page=estrategia-web-cookies");
  if (page.url().includes("wp-login.php")) {
    await page.fill("#user_login", "admin");
    await page.fill("#user_pass", "password");
    await Promise.all([page.waitForURL(/wp-admin/), page.click("#wp-submit")]);
    await page.goto(BASE + "/wp-admin/admin.php?page=estrategia-web-cookies");
  }
  await page.getByRole("heading", { name: "Cookies", level: 1 }).waitFor();
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
  assert.ok(!(await table.innerText()).includes("prueba_valor"), "lo pendiente no sale");
  await ctx.close();
});
