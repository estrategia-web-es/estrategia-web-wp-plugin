// NIVEL: 3 — navegador. NECESITA NAVEGADOR PORQUE: la Interactivity API (envío sin recargar, zona aria-live) y la validación nativa HTML5 solo existen con la página pintada; y el envío sin JS es el de un <form> de verdad.
import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { playwright, reset, mails, submissions, seedPage, GOOD, waitMinTime } from "./lib.mjs";

let browser, page;
before(async () => {
  await reset();
  page = await seedPage();
  browser = await playwright().chromium.launch();
});
after(() => browser?.close());

async function fill(p) {
  await p.fill("#af-contacto-nombre", GOOD.nombre);
  await p.fill("#af-contacto-email", GOOD.email);
  await p.fill("#af-contacto-telefono", GOOD.telefono);
  await p.selectOption("#af-contacto-servicio", GOOD.servicio);
  await p.fill("#af-contacto-mensaje", GOOD.mensaje);
  await p.check("#af-contacto-privacidad");
}

test("con JS: envía sin recargar, avisa por aria-live y queda en el registro", async () => {
  await reset();
  const ctx = await browser.newContext();
  const p = await ctx.newPage();
  const errors = [];
  p.on("pageerror", (e) => errors.push(e.message));
  p.on("console", (m) => m.type() === "error" && errors.push(m.text()));
  await p.goto(page.url);
  await p.evaluate(() => (window.__sameDocument = true));
  await fill(p);
  await waitMinTime();
  await p.click("button[type=submit]");
  const status = p.locator("#af-contacto-status");
  await status.filter({ hasText: /Thank you|Gracias/ }).waitFor();
  assert.equal(await status.getAttribute("aria-live"), "polite");
  assert.equal(await p.evaluate(() => window.__sameDocument), true, "no hubo recarga");
  assert.equal(await p.inputValue("#af-contacto-nombre"), "", "el formulario se vacía tras enviar");
  assert.equal((await submissions()).length, 1);
  assert.equal((await mails()).length, 3);
  assert.deepEqual(errors, [], "sin errores de consola");
  await ctx.close();
});

test("con JS: el navegador no deja enviar un obligatorio vacío", async () => {
  await reset();
  const ctx = await browser.newContext();
  const p = await ctx.newPage();
  let posted = false;
  p.on("request", (r) => r.method() === "POST" && r.url().includes("/estrategia-web-forms/v1/submit") && (posted = true));
  await p.goto(page.url);
  await fill(p);
  await p.fill("#af-contacto-mensaje", "");
  await p.click("button[type=submit]");
  assert.equal(await p.$eval("#af-contacto-mensaje", (el) => el.validity.valueMissing), true);
  assert.equal(posted, false, "no sale ninguna petición");
  await ctx.close();
});

test("con JS y saltándose el navegador: el servidor marca el campo y lo anuncia", async () => {
  await reset();
  const ctx = await browser.newContext();
  const p = await ctx.newPage();
  await p.goto(page.url);
  await fill(p);
  await p.fill("#af-contacto-mensaje", "");
  await p.$eval("#af-contacto-mensaje", (el) => el.removeAttribute("required"));
  await waitMinTime();
  await p.click("button[type=submit]");
  await p.locator("#af-contacto-mensaje[aria-invalid=true]").waitFor();
  assert.notEqual((await p.textContent("#af-contacto-mensaje-error")).trim(), "", "el error del campo se escribe");
  assert.notEqual((await p.textContent("#af-contacto-status")).trim(), "", "y el mensaje general en la zona aria-live");
  assert.equal(await p.evaluate(() => document.activeElement?.id), "af-contacto-mensaje", "el foco va al primer campo con error");
  assert.equal((await submissions()).length, 0);
  await ctx.close();
});

test("sin JS: POST normal, redirección y mensaje en la página", async () => {
  await reset();
  const ctx = await browser.newContext({ javaScriptEnabled: false });
  const p = await ctx.newPage();
  await p.goto(page.url);
  await fill(p);
  await waitMinTime();
  await Promise.all([p.waitForURL(/af_result=/), p.click("button[type=submit]")]);
  assert.match(p.url(), /\/contacto\/\?af_result=[a-z0-9]+#af-contacto-status$/);
  assert.match(await p.textContent("#af-contacto-status"), /Thank you|Gracias/);
  assert.equal((await submissions()).length, 1);
  assert.equal((await mails()).length, 3);
  await ctx.close();
});
