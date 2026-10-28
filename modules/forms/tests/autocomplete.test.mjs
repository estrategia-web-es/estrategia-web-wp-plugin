// NIVEL: 2 — Playground WordPress. NOT LEVEL 1 BECAUSE: the attribute is written by the blocks' render_callback when WordPress serves the page (WP_Block, the block attributes as WordPress parses them from the saved content, its escaping functions).
//
// One request seeds (seed(), with the reset) and one reads the page: every
// case reads the same HTML (28-10-2026).
// autocomplete (0.2.2, WCAG 1.3.5 «Identify Input Purpose»).
// Born from the Lavandería audit (06-10-2026): on Contacto, name and
// company did not have it (email and phone did, fixed by type).
// - each field's autocomplete attribute is rendered: name, organization…;
// - email and phone carry email and tel by default, and can be changed;
// - a text field without the attribute has no autocomplete (no guessing);
// - also in the dropdown and the text area;
// - a value with quotes or odd characters does not leak into the HTML.
// Seen failing on 06-10-2026 before the code: name without autocomplete.
// Seen red (28-10-2026), every test, with these mutants of Blocks::autocomplete()
// at once: uppercased values, no character check, no default for email, and
// «on» guessed for text fields.
import { test, before } from "node:test";
import assert from "node:assert/strict";
import { seed, loadForm, TEAM } from "./lib.mjs";

const FORM = `<!-- wp:estrategia-web-forms/form {"formId":"autocompletar","title":"Autocompletar"} -->
<!-- wp:estrategia-web-forms/text {"label":"Nombre","name":"nombre","autocomplete":"name"} /-->
<!-- wp:estrategia-web-forms/text {"label":"Empresa","name":"empresa","autocomplete":"organization"} /-->
<!-- wp:estrategia-web-forms/text {"label":"Referencia","name":"referencia"} /-->
<!-- wp:estrategia-web-forms/email {"label":"Correo","name":"email"} /-->
<!-- wp:estrategia-web-forms/tel {"label":"Teléfono","name":"telefono"} /-->
<!-- wp:estrategia-web-forms/tel {"label":"Móvil del trabajo","name":"movil","autocomplete":"work tel"} /-->
<!-- wp:estrategia-web-forms/email {"label":"Otro correo","name":"otro","autocomplete":"off"} /-->
<!-- wp:estrategia-web-forms/select {"label":"País","name":"pais","options":"España\\nFrancia","autocomplete":"country-name"} /-->
<!-- wp:estrategia-web-forms/textarea {"label":"Dirección","name":"direccion","autocomplete":"street-address"} /-->
<!-- wp:estrategia-web-forms/text {"label":"Malo","name":"malo","autocomplete":"name\\" onfocus=\\"alert(1)"} /-->
<!-- wp:estrategia-web-forms/submit {"text":"Enviar"} /-->
<!-- wp:estrategia-web-forms/notification {"label":"Aviso","to":"${TEAM}","subject":"x"} /-->
<!-- /wp:estrategia-web-forms/form -->`;

let html;
before(async () => {
  const { pages } = await seed({ pages: [{ key: "page", slug: "autocompletar", content: FORM }] });
  ({ html } = await loadForm(pages.page.url));
});

/** The autocomplete of a field's control (input, select or textarea); null if it has none. */
const autocomplete = (name) => {
  const tag = html.match(new RegExp(`<(?:input|select|textarea)[^>]*name="fields\\[${name}\\]"[^>]*>`))?.[0];
  assert.ok(tag, `field ${name} is missing`);
  return tag.match(/\sautocomplete="([^"]*)"/)?.[1] ?? null;
};

test("the field's autocomplete attribute is rendered (name, company)", () => {
  assert.equal(autocomplete("nombre"), "name");
  assert.equal(autocomplete("empresa"), "organization");
});

test("email and phone: email and tel by default, and the attribute changes them", () => {
  assert.equal(autocomplete("email"), "email");
  assert.equal(autocomplete("telefono"), "tel");
  assert.equal(autocomplete("movil"), "work tel");
  assert.equal(autocomplete("otro"), "off");
});

test("a text field without the attribute has no autocomplete: it is not guessed from the name", () => {
  assert.equal(autocomplete("referencia"), null);
});

test("also in the dropdown and in the text area", () => {
  assert.equal(autocomplete("pais"), "country-name");
  assert.equal(autocomplete("direccion"), "street-address");
});

test("a value with quotes does not leak into the HTML", () => {
  assert.ok(!/onfocus/i.test(html), "an attribute leaked");
  const value = autocomplete("malo");
  assert.ok(value === null || /^[a-z0-9 -]+$/.test(value), `unsanitized value: ${value}`);
});
