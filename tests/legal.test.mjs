// NIVEL: 2 — WordPress de Playground, porque ESCRIBE los datos legales y una página; lo que se mira es el HTML que pinta el servidor, sin navegador.
//
// Datos legales (0.4.0): la clave `registry` y el shortcode [estrategia_web_company],
// que es lo que pone un dato DENTRO de una frase del aviso legal (un binding
// de WP 7.1 sustituye el párrafo entero: ver includes/class-shortcode.php).
import { test, before } from "node:test";
import assert from "node:assert/strict";
import { reset, saveCompany, seedPage, html } from "./lib.mjs";

const REGISTRY = "Registro Mercantil de Mallorca, tomo 1234, folio 56, hoja PM-7890, inscripción 1.ª";

const sc = (key) => `[estrategia_web_company key="${key}"]`;
const PAGE = `<!-- wp:paragraph {"className":"t-legal"} -->
<p class="t-legal">Titular: ${sc("legal_name")}, NIF ${sc("tax_id")}, domicilio en ${sc("address")}. Teléfono ${sc("phone")}, correo ${sc("email")}. Inscrita en el ${sc("registry")}.</p>
<!-- /wp:paragraph -->
<!-- wp:paragraph {"className":"t-unknown"} -->
<p class="t-unknown">(${sc("phone_mobile")})</p>
<!-- /wp:paragraph -->
<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"estrategia-web-site/company","args":{"key":"registry"}}}}} -->
<p class="t-bound">x</p>
<!-- /wp:paragraph -->`;

let page;
before(async () => {
  await reset();
  await saveCompany({
    legal_name: "Lavandería <b>Tom</b> & Cía SL",
    tax_id: "B07000000",
    address: "Calle Mayor 1\n07001 Palma",
    phone: "971 12 34 56",
    email: "hola@ejemplo.test",
    registry: REGISTRY,
  });
  page = await seedPage(PAGE, "aviso-legal-prueba");
});

const between = (text, cls) => text.match(new RegExp(`<p[^>]*${cls}[^>]*>([\\s\\S]*?)</p>`))?.[1];

test("registry (0.4.0): se guarda por REST sin HTML y la fusión no la borra", async () => {
  const a = await saveCompany({ registry: "Registro <i>Mercantil</i> de Palma" });
  assert.equal(a.registry, "Registro Mercantil de Palma", "sin etiquetas HTML");
  const b = await saveCompany({ phone: "971 12 34 56" });
  assert.equal(b.registry, "Registro Mercantil de Palma", "guardar otra clave no la toca");
  await saveCompany({ registry: REGISTRY });
});

test("frase legal con [estrategia_web_company]: razón social, NIF, domicilio en una línea, teléfono, correo y registro", async () => {
  const { status, text } = await html(page.url);
  assert.equal(status, 200);
  assert.equal(
    between(text, "t-legal"),
    "Titular: Lavandería Tom &amp; Cía SL, NIF B07000000, domicilio en Calle Mayor 1, 07001 Palma. " +
      "Teléfono 971 12 34 56, correo hola@ejemplo.test. Inscrita en el " + REGISTRY + "."
  );
  assert.ok(!text.includes("[estrategia_web_company"), "ningún shortcode sin pintar");
});

test("una clave que no existe sale vacía, no el shortcode ni un error", async () => {
  const { text } = await html(page.url);
  // Entre paréntesis y no corchetes: «[[estrategia_web_company …]]» es como se ESCAPA
  // un shortcode en WordPress (sale literal), no un shortcode entre corchetes.
  assert.equal(between(text, "t-unknown"), "()");
});

test("registry también es dato enlazable: un párrafo con binding enseña el registro", async () => {
  const { text } = await html(page.url);
  assert.equal(between(text, "t-bound"), REGISTRY);
});
