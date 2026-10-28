// NIVEL: 3 — browser. NOT LEVEL 2 BECAUSE: the Interactivity API (submit without reloading, the aria-live region, focus on the first field with an error) and native HTML5 validation only exist with the page rendered and its JavaScript running; and the no-JS submit is that of a real <form> in a browser.
//
// ONE page load with JavaScript for the three JS cases (28-10-2026), in the
// order a visitor would hit them on the same form: the browser stops an
// empty required field; with «required» removed by hand the server answers
// and the page marks the field; then, filled, it is sent. One more load,
// without JavaScript, for the no-JS case. The log and the emails are read
// once per case that writes, in one request (state()). Round 2 (28-10-2026):
// the no-JS tab loads at once with the JS one, and both submit at once, with
// ONE read of the log for the two.
//
// Seen red (28-10-2026), every test, with these mutants at once: the form
// rendered with «novalidate» and method «get» (1: the request went out; 4:
// the no-JS submit never came back), and view.js without the focus on the
// first invalid field (2) and without clearing the form after sending (3).
// Seen red again after round 2 (28-10-2026), every test, with the mutants above (one by one where a test reads what the one before left).
import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { playwright, seed, state, GOOD, FORM, waitMinTime } from "./lib.mjs";

let browser, page, p;
const errors = [];
let posted = false;

async function fill(tab) {
  await tab.fill("#af-contacto-nombre", GOOD.nombre);
  await tab.fill("#af-contacto-email", GOOD.email);
  await tab.fill("#af-contacto-telefono", GOOD.telefono);
  await tab.selectOption("#af-contacto-servicio", GOOD.servicio);
  await tab.fill("#af-contacto-mensaje", GOOD.mensaje);
  await tab.check("#af-contacto-privacidad");
}

before(async () => {
  [{ pages: { page } }, browser] = await Promise.all([seed({ pages: [{ key: "page", slug: "contacto", title: "Contacto", content: FORM }] }), playwright().chromium.launch()]);
  p = await (await browser.newContext()).newPage();
  p.on("pageerror", (e) => errors.push(e.message));
  p.on("console", (m) => m.type() === "error" && errors.push(m.text()));
  p.on("request", (r) => r.method() === "POST" && r.url().includes("/estrategia-web-forms/v1/submit") && (posted = true));
  // Round 2 (28-10-2026): the no-JS tab is loaded at once with the JS one (and its minimum time passes meanwhile).
  tab = await (await browser.newContext({ javaScriptEnabled: false })).newPage();
  await Promise.all([p.goto(page.url), tab.goto(page.url)]);
  await p.evaluate(() => (window.__sameDocument = true));
  await waitMinTime(); // Before the first case that reaches the server.
});
let tab;
after(() => browser?.close());

test("with JS: the browser does not allow submitting an empty required field", async () => {
  await fill(p);
  await p.fill("#af-contacto-mensaje", "");
  await p.click("button[type=submit]");
  assert.equal(await p.$eval("#af-contacto-mensaje", (el) => el.validity.valueMissing), true);
  assert.equal(posted, false, "no request goes out");
});

test("with JS and bypassing the browser: the server marks the field and announces it", async () => {
  await p.$eval("#af-contacto-mensaje", (el) => el.removeAttribute("required"));
  await p.click("button[type=submit]");
  await p.locator("#af-contacto-mensaje[aria-invalid=true]").waitFor();
  assert.notEqual((await p.textContent("#af-contacto-mensaje-error")).trim(), "", "the field error is written");
  assert.notEqual((await p.textContent("#af-contacto-status")).trim(), "", "and the general message in the aria-live region");
  assert.equal(await p.evaluate(() => document.activeElement?.id), "af-contacto-mensaje", "focus goes to the first field with an error");
});

let noJsSent;
test("with JS: submits without reloading, announces via aria-live and is stored in the log", async () => {
  await p.fill("#af-contacto-mensaje", GOOD.mensaje);
  // At once, the no-JS tab's submission (the next test): both land in the log and are read once.
  await fill(tab);
  noJsSent = Promise.all([tab.waitForURL(/af_result=/), tab.click("button[type=submit]")]);
  await p.click("button[type=submit]");
  const status = p.locator("#af-contacto-status");
  await Promise.all([status.filter({ hasText: /Thank you|Gracias/ }).waitFor(), noJsSent]);
  assert.equal(await status.getAttribute("aria-live"), "polite");
  assert.equal(await p.evaluate(() => window.__sameDocument), true, "there was no reload");
  assert.equal(await p.inputValue("#af-contacto-nombre"), "", "the form is cleared after submitting");
  ({ submissions: logged, mails: sentMails } = await state());
  assert.equal(logged.length, 2, "this one and the no-JS one: the rejected one was not stored");
  assert.equal(sentMails.length, 6, "three notifications each");
  // The 422 of the case before (the server's answer to the rejected one) is the only expected console line.
  assert.deepEqual(errors.filter((e) => !/status of 422/.test(e)), [], "no console errors");
});

let logged, sentMails;
test("without JS: normal POST, redirect and message on the page", async () => {
  await noJsSent; // Sent in the test before, at once with the JS one; the log read there has both.
  assert.match(tab.url(), /\/contacto\/\?af_result=[a-z0-9]+#af-contacto-status$/);
  assert.match(await tab.textContent("#af-contacto-status"), /Thank you|Gracias/);
  assert.equal(logged.filter((row) => row.page_url.includes("/contacto/")).length, 2, "the JS one and this one");
});
