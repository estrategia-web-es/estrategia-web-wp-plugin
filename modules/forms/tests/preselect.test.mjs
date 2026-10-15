// NIVEL: 2 — Playground WordPress, because preselection depends on how WordPress renders the page: the synced block (core/block) and its pattern/overrides context, the URL that reaches PHP and the return of the no-JS submit.
//
// No browser: the HTML the server renders is read, which is also what
// someone browsing without JavaScript sees.
import { test, before } from "node:test";
import assert from "node:assert/strict";
import { reset, seedPage, loadForm, submit, testApi, waitMinTime } from "./lib.mjs";

const OPTIONS = "Lavandería para hoteles\\nLavandería para restaurantes\\nLavandería para barcos y yates";
const form = (selectAtts = "") => `<!-- wp:estrategia-web-forms/form {"formId":"presupuesto","title":"Presupuesto"} -->
<!-- wp:estrategia-web-forms/email {"label":"Correo","name":"email","required":true} /-->
<!-- wp:estrategia-web-forms/select {"label":"Servicio","name":"servicio","required":true,"options":"${OPTIONS}"${selectAtts}} /-->
<!-- wp:estrategia-web-forms/submit /-->
<!-- /wp:estrategia-web-forms/form -->`;

/** The text of the option with «selected» in the «servicio» select, or "" if none. */
function selected(html) {
  const select = html.match(/<select[^>]*name="fields\[servicio\]"[^>]*>([\s\S]*?)<\/select>/);
  assert.ok(select, "the select is rendered");
  const opts = [...select[1].matchAll(/<option value="([^"]*)"([^>]*)>/g)].filter((m) => /\bselected\b/.test(m[2]));
  assert.ok(opts.length <= 1, "at most one selected option");
  return opts[0]?.[1] ?? "";
}

let plain, withDefault;
before(async () => {
  await reset();
  plain = await seedPage(form(), "presupuesto");
  withDefault = await seedPage(form(`,"defaultOption":2`), "restauracion");
});

test("with nothing asking for it, no option is preselected", async () => {
  assert.equal(selected((await loadForm(plain.url)).html), "");
});

test("block defaultOption: the option at that position comes marked", async () => {
  assert.equal(selected((await loadForm(withDefault.url)).html), "Lavandería para restaurantes");
});

test("URL parameter af_<field>: by slug and by position; it beats the page's value", async () => {
  assert.equal(selected((await loadForm(plain.url + "?af_servicio=lavanderia-para-barcos-y-yates")).html), "Lavandería para barcos y yates");
  assert.equal(selected((await loadForm(withDefault.url + "?af_servicio=1")).html), "Lavandería para hoteles");
});

test("URL with a value that is not an option: it does not leak into the HTML and the page's one stays", async () => {
  const { html } = await loadForm(withDefault.url + "?af_servicio=" + encodeURIComponent('"><b>Tintorería'));
  assert.equal(selected(html), "Lavandería para restaurantes");
  assert.ok(!html.includes("Tintorería"), "the URL value does not appear on the page");
});

test("synced block: the same form on two pages, each with its own option (core/block content)", async () => {
  const pattern = await testApi("/page", { method: "POST", body: { type: "wp_block", slug: "formulario-presupuesto", title: "Quote form", content: form() } });
  const hotels = await seedPage(`<!-- wp:block {"ref":${pattern.id},"content":{"servicio":{"defaultOption":1}}} /-->`, "hosteleria");
  const boats = await seedPage(`<!-- wp:block {"ref":${pattern.id},"content":{"servicio":{"defaultOption":3}}} /-->`, "barcos");
  const none = await seedPage(`<!-- wp:block {"ref":${pattern.id}} /-->`, "otra");
  assert.equal(selected((await loadForm(hotels.url)).html), "Lavandería para hoteles");
  assert.equal(selected((await loadForm(boats.url)).html), "Lavandería para barcos y yates");
  assert.equal(selected((await loadForm(none.url)).html), "", "no content, no preselection");
});

test("no-JS return with errors: sends what the visitor chose, not the preselection", async () => {
  const { hidden } = await loadForm(withDefault.url);
  await waitMinTime();
  const r = await submit(hidden, { email: "not-an-email", servicio: "Lavandería para barcos y yates" }, { js: false });
  assert.equal(r.status, 303);
  assert.equal(selected((await loadForm(r.location)).html), "Lavandería para barcos y yates");
});
