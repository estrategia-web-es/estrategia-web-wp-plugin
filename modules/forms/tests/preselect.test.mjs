// NIVEL: 2 — Playground WordPress. NOT LEVEL 1 BECAUSE: preselection depends on how WordPress renders the page — the synced block (core/block) and its pattern/overrides context, the query string that reaches PHP and the return of the no-JS submit. WHICH option a value matches (position, text, slug) is level 1: pure.test.mjs «preselection».
//
// No browser: the HTML the server renders is read, which is also what
// someone browsing without JavaScript sees. Every page is seeded in ONE
// request and the independent loads go two at a time (28-10-2026); round 2:
// the no-JS submission goes at once with the last load. The URL
// case «by slug» was deleted that day: matching a slug is Preselect's
// decision, already proven in pure.test.mjs; here one URL case is enough to
// prove the parameter reaches it and beats the page's value.
//
// Seen red (28-10-2026), every test, with these mutants of Blocks::preselected()
// and the field render at once: the block's defaultOption ignored, the
// af_<field> parameter ignored, the pattern's «content» ignored, and the
// submission's returned values dropped.
// Seen red again after round 2 (28-10-2026), every test, with the mutants above (one by one where a test reads what the one before left).
import { test, before } from "node:test";
import assert from "node:assert/strict";
import { seed, loadForm, submit } from "./lib.mjs";

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
const selectedAt = async (url) => selected((await loadForm(url)).html);

let pages, withDefault, hidden;
before(async () => {
  ({ pages } = await seed({
    pages: [
      { key: "plain", slug: "presupuesto", content: form() },
      { key: "withDefault", slug: "restauracion", content: form(`,"defaultOption":2`) },
      { key: "pattern", type: "wp_block", slug: "formulario-presupuesto", title: "Quote form", content: form() },
      { key: "hotels", slug: "hosteleria", content: `<!-- wp:block {"ref":{{id:pattern}},"content":{"servicio":{"defaultOption":1}}} /-->` },
      { key: "boats", slug: "barcos", content: `<!-- wp:block {"ref":{{id:pattern}},"content":{"servicio":{"defaultOption":3}}} /-->` },
      { key: "none", slug: "otra", content: `<!-- wp:block {"ref":{{id:pattern}}} /-->` },
    ],
  }));
  withDefault = pages.withDefault;
});

test("nothing asks for it: no option; the block's defaultOption: the option at that position", async () => {
  const [none, byBlock] = await Promise.all([selectedAt(pages.plain.url), loadForm(withDefault.url)]);
  hidden = byBlock.hidden;
  assert.equal(none, "");
  assert.equal(selected(byBlock.html), "Lavandería para restaurantes");
});

test("URL parameter af_<field>: it reaches the preselection and beats the page's value; a value that is not an option does not leak and the page's one stays", async () => {
  const [byUrl, bad] = await Promise.all([loadForm(withDefault.url + "?af_servicio=1"), loadForm(withDefault.url + "?af_servicio=" + encodeURIComponent('"><b>Tintorería'))]);
  assert.equal(selected(byUrl.html), "Lavandería para hoteles");
  assert.equal(selected(bad.html), "Lavandería para restaurantes");
  assert.ok(!bad.html.includes("Tintorería"), "the URL value does not appear on the page");
});

test("synced block: the same form on two pages, each with its own option (core/block content)", async () => {
  const [hotels, boats] = await Promise.all([selectedAt(pages.hotels.url), selectedAt(pages.boats.url)]);
  assert.equal(hotels, "Lavandería para hoteles");
  assert.equal(boats, "Lavandería para barcos y yates");
  // At once, the next test's no-JS submission (the hidden fields of the first test's load: older than min_seconds by now).
  let none;
  [none, noJs] = await Promise.all([selectedAt(pages.none.url), submit(hidden, { email: "not-an-email", servicio: "Lavandería para barcos y yates" }, { js: false })]);
  assert.equal(none, "", "no content, no preselection");
});

let noJs;
test("no-JS return with errors: sends what the visitor chose, not the preselection", async () => {
  const r = noJs;
  assert.equal(r.status, 303);
  assert.equal(await selectedAt(r.location), "Lavandería para barcos y yates");
});
