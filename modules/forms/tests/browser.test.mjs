// NIVEL: 3 — browser. NEEDS A BROWSER BECAUSE: the Interactivity API (submit without reloading, aria-live region) and native HTML5 validation only exist with the page rendered; and the no-JS submit is that of a real <form>.
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

test("with JS: submits without reloading, announces via aria-live and is stored in the log", async () => {
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
  assert.equal(await p.evaluate(() => window.__sameDocument), true, "there was no reload");
  assert.equal(await p.inputValue("#af-contacto-nombre"), "", "the form is cleared after submitting");
  assert.equal((await submissions()).length, 1);
  assert.equal((await mails()).length, 3);
  assert.deepEqual(errors, [], "no console errors");
  await ctx.close();
});

test("with JS: the browser does not allow submitting an empty required field", async () => {
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
  assert.equal(posted, false, "no request goes out");
  await ctx.close();
});

test("with JS and bypassing the browser: the server marks the field and announces it", async () => {
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
  assert.notEqual((await p.textContent("#af-contacto-mensaje-error")).trim(), "", "the field error is written");
  assert.notEqual((await p.textContent("#af-contacto-status")).trim(), "", "and the general message in the aria-live region");
  assert.equal(await p.evaluate(() => document.activeElement?.id), "af-contacto-mensaje", "focus goes to the first field with an error");
  assert.equal((await submissions()).length, 0);
  await ctx.close();
});

test("without JS: normal POST, redirect and message on the page", async () => {
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
