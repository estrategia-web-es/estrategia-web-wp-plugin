// NIVEL: 2 — WordPress de Playground, porque el atributo lo pinta render_callback al servir la página.
//
// autocomplete (0.2.2, WCAG 1.3.5 «Identificar el propósito de la entrada»).
// Nació de la auditoría de Lavandería (06-10-2026): en Contacto, nombre y
// empresa no lo llevaban (correo y teléfono sí, fijo por tipo).
// - el atributo autocomplete de cada campo se pinta: name, organization…;
// - correo y teléfono llevan email y tel por defecto, y se pueden cambiar;
// - un campo de texto sin el atributo no lleva autocomplete (no se adivina);
// - también en el desplegable y el área de texto;
// - un valor con comillas o caracteres raros no se cuela en el HTML.
// Vista fallar el 06-10-2026 antes del código: nombre sin autocomplete.
import { test, before } from "node:test";
import assert from "node:assert/strict";
import { reset, seedPage, loadForm, TEAM } from "./lib.mjs";

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
  await reset();
  const page = await seedPage(FORM, "autocompletar");
  ({ html } = await loadForm(page.url));
});

/** El autocomplete del control (input, select o textarea) de un campo; null si no lo lleva. */
const autocomplete = (name) => {
  const tag = html.match(new RegExp(`<(?:input|select|textarea)[^>]*name="fields\\[${name}\\]"[^>]*>`))?.[0];
  assert.ok(tag, `no está el campo ${name}`);
  return tag.match(/\sautocomplete="([^"]*)"/)?.[1] ?? null;
};

test("el atributo autocomplete del campo se pinta (nombre, empresa)", () => {
  assert.equal(autocomplete("nombre"), "name");
  assert.equal(autocomplete("empresa"), "organization");
});

test("correo y teléfono: email y tel por defecto, y el atributo los cambia", () => {
  assert.equal(autocomplete("email"), "email");
  assert.equal(autocomplete("telefono"), "tel");
  assert.equal(autocomplete("movil"), "work tel");
  assert.equal(autocomplete("otro"), "off");
});

test("un texto sin el atributo no lleva autocomplete: no se adivina por el nombre", () => {
  assert.equal(autocomplete("referencia"), null);
});

test("también en el desplegable y en el área de texto", () => {
  assert.equal(autocomplete("pais"), "country-name");
  assert.equal(autocomplete("direccion"), "street-address");
});

test("un valor con comillas no se cuela en el HTML", () => {
  assert.ok(!/onfocus/i.test(html), "se coló un atributo");
  const value = autocomplete("malo");
  assert.ok(value === null || /^[a-z0-9 -]+$/.test(value), `valor sin sanear: ${value}`);
});
