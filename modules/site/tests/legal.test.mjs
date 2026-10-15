// NIVEL: 2 — Playground WordPress, because it WRITES the legal data and a page; what is checked is the HTML the server renders, no browser.
//
// Legal data (0.4.0): the `registry` key and the [estrategia_web_company] shortcode,
// which is what puts a field INSIDE a sentence of the legal notice (a WP 7.1
// binding replaces the whole paragraph: see includes/class-shortcode.php).
import { test, before } from "node:test";
import assert from "node:assert/strict";
import { reset, saveCompany, seedPage, html } from "../../../tests/lib.mjs";

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

test("registry (0.4.0): saved via REST without HTML, and the merge does not erase it", async () => {
  const a = await saveCompany({ registry: "Registro <i>Mercantil</i> de Palma" });
  assert.equal(a.registry, "Registro Mercantil de Palma", "no HTML tags");
  const b = await saveCompany({ phone: "971 12 34 56" });
  assert.equal(b.registry, "Registro Mercantil de Palma", "saving another key does not touch it");
  await saveCompany({ registry: REGISTRY });
});

test("legal sentence with [estrategia_web_company]: legal name, tax ID, address on one line, phone, email and registry", async () => {
  const { status, text } = await html(page.url);
  assert.equal(status, 200);
  assert.equal(
    between(text, "t-legal"),
    "Titular: Lavandería Tom &amp; Cía SL, NIF B07000000, domicilio en Calle Mayor 1, 07001 Palma. " +
      "Teléfono 971 12 34 56, correo hola@ejemplo.test. Inscrita en el " + REGISTRY + "."
  );
  assert.ok(!text.includes("[estrategia_web_company"), "no unrendered shortcode");
});

test("a key that does not exist comes out empty, not the shortcode nor an error", async () => {
  const { text } = await html(page.url);
  // Parentheses and not brackets: «[[estrategia_web_company …]]» is how a shortcode is
  // ESCAPED in WordPress (it comes out literally), not a shortcode inside brackets.
  assert.equal(between(text, "t-unknown"), "()");
});

test("registry is also a bindable field: a paragraph with a binding shows the registry", async () => {
  const { text } = await html(page.url);
  assert.equal(between(text, "t-bound"), REGISTRY);
});
