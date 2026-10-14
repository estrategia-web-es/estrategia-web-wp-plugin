// NIVEL: 2 — WordPress de Playground, porque ESCRIBE los datos de la empresa y una página; lo que se mira es el HTML que pinta el servidor, sin navegador.
import { test, before } from "node:test";
import assert from "node:assert/strict";
import { reset, saveCompany, seedPage, html } from "../../../tests/lib.mjs";

const bind = (attr, key) => JSON.stringify({ metadata: { bindings: { [attr]: { source: "estrategia-web-site/company", args: { key } } } } });

const PAGE = `<!-- wp:paragraph ${bind("content", "phone")} -->
<p class="t-phone">Teléfono de relleno</p>
<!-- /wp:paragraph -->
<!-- wp:paragraph ${bind("content", "address")} -->
<p class="t-address">x</p>
<!-- /wp:paragraph -->
<!-- wp:heading ${bind("content", "company_name")} -->
<h2 class="wp-block-heading t-name">x</h2>
<!-- /wp:heading -->
<!-- wp:paragraph ${bind("content", "social.instagram")} -->
<p class="t-social">x</p>
<!-- /wp:paragraph -->
<!-- wp:buttons -->
<div class="wp-block-buttons"><!-- wp:button {"className":"t-wa",${bind("url", "whatsapp_href").slice(1, -1)}} -->
<div class="wp-block-button t-wa"><a class="wp-block-button__link wp-element-button" href="#">WhatsApp</a></div>
<!-- /wp:button -->
<!-- wp:button {"className":"t-tel",${bind("url", "phone_href").slice(1, -1)}} -->
<div class="wp-block-button t-tel"><a class="wp-block-button__link wp-element-button" href="#">Llamar</a></div>
<!-- /wp:button -->
<!-- wp:button {"className":"t-mail",${bind("url", "email_href").slice(1, -1)}} -->
<div class="wp-block-button t-mail"><a class="wp-block-button__link wp-element-button" href="#">Correo</a></div>
<!-- /wp:button -->
<!-- wp:button {"className":"t-tel2",${bind("url", "phone_secondary_href").slice(1, -1)}} -->
<div class="wp-block-button t-tel2"><a class="wp-block-button__link wp-element-button" href="#">Llamar al otro</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons -->`;

let page;
before(async () => {
  await reset();
  await saveCompany({
    company_name: "Tom & Jerry <SL>",
    phone: "971 12 34 56",
    phone_secondary: "+34 600 99 88 77",
    email: "hola@ejemplo.test",
    whatsapp: "34600111222",
    whatsapp_message: "Hola, quería información",
    address: "Calle Mayor 1\n07001 Palma",
    social: { instagram: "https://instagram.com/prueba" },
  });
  page = await seedPage(PAGE, "bindings");
});

const between = (text, cls, tag) => text.match(new RegExp(`<${tag}[^>]*${cls}[^>]*>([\\s\\S]*?)</${tag}>`))?.[1];

test("un párrafo enlazado a estrategia-web-site/company:phone muestra el teléfono, no el texto de relleno", async () => {
  const { status, text } = await html(page.url);
  assert.equal(status, 200);
  assert.equal(between(text, "t-phone", "p"), "971 12 34 56");
  assert.ok(!text.includes("Teléfono de relleno"));
});

test("el botón con whatsapp_href lleva el wa.me correcto; phone_href y email_href también", async () => {
  const { text } = await html(page.url);
  const href = (cls) => text.match(new RegExp(`${cls}[^"]*"><a[^>]*href="([^"]*)"`))?.[1];
  assert.equal(href("t-wa"), "https://wa.me/34600111222?text=Hola%2C%20quer%C3%ADa%20informaci%C3%B3n");
  assert.equal(href("t-tel"), "tel:+34971123456");
  assert.equal(href("t-mail"), "mailto:hola@ejemplo.test");
});

test("phone_secondary_href (0.2.0): el tel: del segundo teléfono", async () => {
  const { text } = await html(page.url);
  assert.equal(text.match(/t-tel2[^"]*"><a[^>]*href="([^"]*)"/)?.[1], "tel:+34600998877");
});

test("texto: varias líneas salen con <br>, el HTML del dato se escapa, social.<red> con punto funciona", async () => {
  const { text } = await html(page.url);
  assert.equal(between(text, "t-address", "p").replace(/\n/g, ""), "Calle Mayor 1<br>07001 Palma");
  // sanitize_text_field quita «<SL>»; lo que quede con & se escapa.
  assert.match(between(text, "t-name", "h2"), /^Tom &amp; Jerry/);
  assert.equal(between(text, "t-social", "p"), "https://instagram.com/prueba");
});
