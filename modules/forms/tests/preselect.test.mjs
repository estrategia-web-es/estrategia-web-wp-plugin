// NIVEL: 2 — WordPress de Playground, porque la preselección depende de cómo WordPress pinta la página: el bloque sincronizado (core/block) y su contexto pattern/overrides, la URL que llega a PHP y la vuelta del envío sin JS.
//
// Sin navegador: se lee el HTML que pinta el servidor, que es lo que ve
// también quien navega sin JavaScript.
import { test, before } from "node:test";
import assert from "node:assert/strict";
import { reset, seedPage, loadForm, submit, testApi, waitMinTime } from "./lib.mjs";

const OPTIONS = "Lavandería para hoteles\\nLavandería para restaurantes\\nLavandería para barcos y yates";
const form = (selectAtts = "") => `<!-- wp:estrategia-web-forms/form {"formId":"presupuesto","title":"Presupuesto"} -->
<!-- wp:estrategia-web-forms/email {"label":"Correo","name":"email","required":true} /-->
<!-- wp:estrategia-web-forms/select {"label":"Servicio","name":"servicio","required":true,"options":"${OPTIONS}"${selectAtts}} /-->
<!-- wp:estrategia-web-forms/submit /-->
<!-- /wp:estrategia-web-forms/form -->`;

/** El texto de la opción con «selected» en el select «servicio», o "" si ninguna. */
function selected(html) {
  const select = html.match(/<select[^>]*name="fields\[servicio\]"[^>]*>([\s\S]*?)<\/select>/);
  assert.ok(select, "el select se pinta");
  const opts = [...select[1].matchAll(/<option value="([^"]*)"([^>]*)>/g)].filter((m) => /\bselected\b/.test(m[2]));
  assert.ok(opts.length <= 1, "como mucho una opción seleccionada");
  return opts[0]?.[1] ?? "";
}

let plain, withDefault;
before(async () => {
  await reset();
  plain = await seedPage(form(), "presupuesto");
  withDefault = await seedPage(form(`,"defaultOption":2`), "restauracion");
});

test("sin nada que lo pida, no se preselecciona ninguna opción", async () => {
  assert.equal(selected((await loadForm(plain.url)).html), "");
});

test("defaultOption del bloque: la opción en esa posición viene marcada", async () => {
  assert.equal(selected((await loadForm(withDefault.url)).html), "Lavandería para restaurantes");
});

test("parámetro de URL af_<campo>: por slug y por posición; gana al valor de la página", async () => {
  assert.equal(selected((await loadForm(plain.url + "?af_servicio=lavanderia-para-barcos-y-yates")).html), "Lavandería para barcos y yates");
  assert.equal(selected((await loadForm(withDefault.url + "?af_servicio=1")).html), "Lavandería para hoteles");
});

test("URL con un valor que no es opción: no se cuela en el HTML y queda el de la página", async () => {
  const { html } = await loadForm(withDefault.url + "?af_servicio=" + encodeURIComponent('"><b>Tintorería'));
  assert.equal(selected(html), "Lavandería para restaurantes");
  assert.ok(!html.includes("Tintorería"), "el valor de la URL no aparece en la página");
});

test("bloque sincronizado: el mismo formulario en dos páginas, cada una con su opción (content del core/block)", async () => {
  const pattern = await testApi("/page", { method: "POST", body: { type: "wp_block", slug: "formulario-presupuesto", title: "Formulario de presupuesto", content: form() } });
  const hoteles = await seedPage(`<!-- wp:block {"ref":${pattern.id},"content":{"servicio":{"defaultOption":1}}} /-->`, "hosteleria");
  const barcos = await seedPage(`<!-- wp:block {"ref":${pattern.id},"content":{"servicio":{"defaultOption":3}}} /-->`, "barcos");
  const nada = await seedPage(`<!-- wp:block {"ref":${pattern.id}} /-->`, "otra");
  assert.equal(selected((await loadForm(hoteles.url)).html), "Lavandería para hoteles");
  assert.equal(selected((await loadForm(barcos.url)).html), "Lavandería para barcos y yates");
  assert.equal(selected((await loadForm(nada.url)).html), "", "sin content, sin preselección");
});

test("vuelta sin JS con errores: manda lo que eligió el visitante, no la preselección", async () => {
  const { hidden } = await loadForm(withDefault.url);
  await waitMinTime();
  const r = await submit(hidden, { email: "no-es-correo", servicio: "Lavandería para barcos y yates" }, { js: false });
  assert.equal(r.status, 303);
  assert.equal(selected((await loadForm(r.location)).html), "Lavandería para barcos y yates");
});
