// NIVEL: 2 — WordPress de Playground, porque ESCRIBE: activa el tipo `location`, crea sedes por REST y una página; lo que se mira es el HTML que pinta el servidor, sin navegador.
import { test, before } from "node:test";
import assert from "node:assert/strict";
import { reset, wp, saveCompany, seedPage, html } from "../../../tests/lib.mjs";

const SRC = "estrategia-web-site/location";
const meta = (args) => ({ metadata: { bindings: { content: { source: SRC, args } } } });
const attrs = (cls, bindings) => JSON.stringify({ className: cls, metadata: { bindings } });

let maioris;
before(async () => {
  await reset();
  await saveCompany({ enabled_types: ["location"], phone: "971 00 00 00" });
  const make = async (title, slug, status, m) => {
    const r = await wp("/location", { method: "POST", body: { title, slug, status, meta: m } });
    assert.equal(r.status, 201, r.text);
    return r.json;
  };
  maioris = await make("Maioris & Llucmajor", "maioris", "publish", {
    estrategia_web_location_address: "Carrer de Maioris 3\n07620 Llucmajor",
    estrategia_web_location_phone: "971 11 22 33",
    estrategia_web_location_email: "maioris@ejemplo.test",
    estrategia_web_location_schedule: "L-V 8-20",
    estrategia_web_location_map_url: "https://maps.google.com/?q=Maioris",
  });
  await make("Sede Palma", "palma", "publish", { estrategia_web_location_address: "Carrer de Palma 1\n07001 Palma", estrategia_web_location_phone: "971 44 55 66" });
  await make("Sede en borrador", "borrador", "draft", { estrategia_web_location_phone: "971 99 99 99", estrategia_web_location_address: "No debe salir" });
});

const between = (text, cls, tag) => text.match(new RegExp(`<${tag}[^>]*${cls}[^>]*>([\\s\\S]*?)</${tag}>`))?.[1];
const href = (text, cls) => text.match(new RegExp(`${cls}[^"]*"><a[^>]*href="([^"]*)"`))?.[1];

test("por slug, desde cualquier página: botón con texto y url al teléfono de la sede «maioris»; título, dirección con <br>, correo y mapa", async () => {
  const page = await seedPage(
    `<!-- wp:buttons -->
<div class="wp-block-buttons"><!-- wp:button ${attrs("t-tel", { text: { source: SRC, args: { slug: "maioris", key: "estrategia_web_location_phone" } }, url: { source: SRC, args: { slug: "maioris", key: "estrategia_web_location_phone_href" } } })} -->
<div class="wp-block-button t-tel"><a class="wp-block-button__link wp-element-button" href="#">relleno</a></div>
<!-- /wp:button -->
<!-- wp:button ${attrs("t-mail", { url: { source: SRC, args: { slug: "maioris", key: "estrategia_web_location_email_href" } } })} -->
<div class="wp-block-button t-mail"><a class="wp-block-button__link wp-element-button" href="#">Correo</a></div>
<!-- /wp:button -->
<!-- wp:button ${attrs("t-map", { url: { source: SRC, args: { slug: "maioris", key: "estrategia_web_location_map_url" } } })} -->
<div class="wp-block-button t-map"><a class="wp-block-button__link wp-element-button" href="#">Mapa</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons -->
<!-- wp:heading ${JSON.stringify({ className: "t-title", ...meta({ slug: "maioris", key: "title" }) })} -->
<h2 class="wp-block-heading t-title">x</h2>
<!-- /wp:heading -->
<!-- wp:paragraph ${JSON.stringify({ className: "t-address", ...meta({ slug: "maioris", key: "estrategia_web_location_address" }) })} -->
<p class="t-address">x</p>
<!-- /wp:paragraph -->`,
    "autoservicio"
  );
  const { status, text } = await html(page.url);
  assert.equal(status, 200);
  assert.equal(href(text, "t-tel"), "tel:+34971112233");
  assert.match(text, /t-tel[^"]*"><a[^>]*>971 11 22 33<\/a>/, "el texto del botón es el teléfono");
  assert.equal(href(text, "t-mail"), "mailto:maioris@ejemplo.test");
  assert.equal(href(text, "t-map"), "https://maps.google.com/?q=Maioris");
  assert.equal(between(text, "t-title", "h2"), "Maioris &amp; Llucmajor", "el título, escapado");
  assert.equal(between(text, "t-address", "p").replace(/\n/g, ""), "Carrer de Maioris 3<br>07620 Llucmajor");
});

test("una sede inexistente, en borrador o sin clave sale vacía; fuera de una sede, una clave sin slug también", async () => {
  const page = await seedPage(
    `<!-- wp:paragraph ${JSON.stringify({ className: "t-none", ...meta({ slug: "no-existe", key: "estrategia_web_location_phone" }) })} -->
<p class="t-none">relleno-none</p>
<!-- /wp:paragraph -->
<!-- wp:paragraph ${JSON.stringify({ className: "t-draft", ...meta({ slug: "borrador", key: "estrategia_web_location_phone" }) })} -->
<p class="t-draft">relleno-draft</p>
<!-- /wp:paragraph -->
<!-- wp:paragraph ${JSON.stringify({ className: "t-nokey", ...meta({ slug: "maioris", key: "phone" }) })} -->
<p class="t-nokey">relleno-nokey</p>
<!-- /wp:paragraph -->
<!-- wp:paragraph ${JSON.stringify({ className: "t-ctx", ...meta({ key: "estrategia_web_location_phone" }) })} -->
<p class="t-ctx">relleno-ctx</p>
<!-- /wp:paragraph -->`,
    "sedes-vacias"
  );
  const { text } = await html(page.url);
  for (const cls of ["t-none", "t-draft", "t-nokey", "t-ctx"]) {
    assert.equal(between(text, cls, "p"), "", `${cls} vacío`);
  }
  assert.ok(!text.includes("971 99 99 99") && !text.includes("No debe salir"), "nada del borrador");
});

test("sin slug, dentro de una Consulta de sedes: cada <li> enseña la dirección de SU sede (contexto postId)", async () => {
  const query = JSON.stringify({ queryId: 3, query: { perPage: 10, pages: 0, offset: 0, postType: "location", order: "asc", orderBy: "title", inherit: false } });
  const page = await seedPage(
    `<!-- wp:query ${query} -->
<div class="wp-block-query"><!-- wp:post-template -->
<!-- wp:post-title /-->
<!-- wp:paragraph ${JSON.stringify({ className: "t-addr", ...meta({ key: "estrategia_web_location_address" }) })} -->
<p class="t-addr">x</p>
<!-- /wp:paragraph -->
<!-- wp:paragraph ${JSON.stringify({ className: "t-phone", ...meta({ key: "estrategia_web_location_phone_href" }) })} -->
<p class="t-phone">x</p>
<!-- /wp:paragraph -->
<!-- /wp:post-template --></div>
<!-- /wp:query -->`,
    "sedes-consulta"
  );
  const { text } = await html(page.url);
  const items = [...(text.match(/<ul[^>]*wp-block-post-template[\s\S]*?<\/ul>/)?.[0] ?? "").matchAll(/<li[\s\S]*?<\/li>/g)].map((m) => m[0]);
  assert.equal(items.length, 2, "las dos publicadas");
  assert.match(items[0], /Maioris/);
  assert.equal(between(items[0], "t-addr", "p").replace(/\n/g, ""), "Carrer de Maioris 3<br>07620 Llucmajor");
  assert.equal(between(items[0], "t-phone", "p"), "tel:+34971112233");
  assert.equal(between(items[1], "t-addr", "p").replace(/\n/g, ""), "Carrer de Palma 1<br>07001 Palma");
  assert.ok(maioris.id);
});
