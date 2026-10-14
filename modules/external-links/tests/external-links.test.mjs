// NIVEL: 2 — WordPress de Playground, porque lo probado es la PÁGINA SERVIDA: el filtro corre en el búfer de salida de la plantilla del núcleo (wp_template_enhancement_output_buffer), que solo existe pintando una página de verdad.
//
// Enlaces externos en pestaña nueva (0.5.2, includes/class-external-links.php):
// - externo (wa.me, como «Hablar con un asesor» de Lavandería): target,
//   rel con noopener y aria-label con el texto + «(se abre en una pestaña nueva)»;
// - internos, www, relativos, anclas, tel: y mailto:, intactos;
// - con target (aunque sea _self), intacto; con rel, se le añade noopener
//   sin quitar nofollow/sponsored; con aria-label, se le añade el aviso al
//   final; un enlace-imagen toma el alt;
// - el «Powered by» del aviso de cookies no se duplica;
// - el contenido por REST (lo que lee el editor) no se toca;
// - con el filtro estrategia_web_site_external_links a false, nada.
// Qué es externo caso por caso (otro dominio, subdominios, esquemas) está en
// el nivel 1 (tests/pure.test.mjs); aquí, lo que solo se ve pintando.
//
// Vista fallar el 06-10-2026 apagando el filtro (external-links-setup
// enabled:false con las aserciones del encendido): el externo salía sin target.
import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { BASE, reset, seedPage, testApi, html, wp } from "../../../tests/lib.mjs";

const setup = (enabled) => testApi("/external-links-setup", { method: "POST", body: { enabled } });
const NOTICE = "(se abre en una pestaña nueva)";

const CONTENT = `
<!-- wp:buttons -->
<div class="wp-block-buttons"><!-- wp:button -->
<div class="wp-block-button"><a id="x-ext" class="wp-block-button__link wp-element-button" href="https://wa.me/34600111222?text=Hola">Hablar con un asesor</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons -->
<p>
<a id="x-int" href="${BASE}/contacto/">Interno</a>
<a id="x-www" href="http://www.127.0.0.1:9404/contacto/">Con www</a>
<a id="x-rel" href="/contacto/">Relativo</a>
<a id="x-anchor" href="#arriba">Ancla</a>
<a id="x-tel" href="tel:+34971123456">Teléfono</a>
<a id="x-mail" href="mailto:hola@web.test">Correo</a>
<a id="x-target" href="https://facebook.com/x" target="_self">Con target</a>
<a id="x-relkeep" href="https://patrocinador.test/" rel="nofollow sponsored">Con rel</a>
<a id="x-aria" href="https://maps.google.com/?q=x" aria-label="Cómo llegar">Mapa</a>
<a id="x-img" href="https://instagram.com/x"><img src="/x.png" alt="Instagram"></a>
</p>`;

// Atributos de la etiqueta <a> con ese id, ya decodificados (&quot; &amp;…).
function anchor(page, id) {
  const tag = page.match(new RegExp(`<a\\b[^>]*\\bid="${id}"[^>]*>`))?.[0];
  assert.ok(tag, `no está el enlace ${id}`);
  const attrs = {};
  for (const m of tag.matchAll(/([\w-]+)(?:="([^"]*)"|='([^']*)')?/g).drop(1)) {
    attrs[m[1].toLowerCase()] = (m[2] ?? m[3] ?? "").replace(/&quot;/g, '"').replace(/&#039;|&#39;/g, "'").replace(/&amp;/g, "&");
  }
  return attrs;
}

let url;
before(async () => {
  await reset();
  // Con una categoría declarada sale el aviso de cookies, y con él el «Powered by».
  await testApi("/consent-setup", { method: "POST", body: { categories: ["maps"], powered_by: { show: true } } });
  ({ url } = await seedPage(CONTENT, "enlaces-externos"));
});
after(() => reset());

test("externo (el botón de WhatsApp): target _blank, rel noopener y aviso para lectores de pantalla", async () => {
  const { status, text } = await html(url);
  assert.equal(status, 200);
  const a = anchor(text, "x-ext");
  assert.equal(a.target, "_blank");
  assert.equal(a.rel, "noopener");
  assert.equal(a["aria-label"], `Hablar con un asesor ${NOTICE}`);
});

test("internos, www, relativos, anclas, tel: y mailto: quedan como estaban", async () => {
  const { text } = await html(url);
  for (const id of ["x-int", "x-www", "x-rel", "x-anchor", "x-tel", "x-mail"]) {
    const a = anchor(text, id);
    assert.equal(a.target, undefined, `${id} con target`);
    assert.equal(a.rel, undefined, `${id} con rel`);
    assert.equal(a["aria-label"], undefined, `${id} con aria-label`);
  }
});

test("con target no se toca; con rel se añade noopener; con aria-label se añade el aviso; una imagen da su alt", async () => {
  const { text } = await html(url);
  assert.deepEqual(anchor(text, "x-target"), { id: "x-target", href: "https://facebook.com/x", target: "_self" });
  const rel = anchor(text, "x-relkeep");
  assert.equal(rel.target, "_blank");
  assert.equal(rel.rel, "nofollow sponsored noopener");
  assert.equal(anchor(text, "x-aria")["aria-label"], `Cómo llegar ${NOTICE}`);
  assert.equal(anchor(text, "x-img")["aria-label"], `Instagram ${NOTICE}`);
});

test("el «Powered by» del aviso de cookies no se duplica", async () => {
  const { text } = await html(url);
  const tag = text.match(/<a\b[^>]*href="https:\/\/estrategiaweb\.es\/"[^>]*>/)?.[0];
  assert.ok(tag, "no sale el «Powered by»");
  assert.equal(tag.match(/\btarget=/g).length, 1);
  assert.match(tag, /\brel="noopener"/);
  assert.doesNotMatch(tag, /aria-label/);
});

test("solo el frontal: el contenido por REST (lo que lee el editor) no se toca", async () => {
  const page = (await wp(`/pages?slug=enlaces-externos&context=edit`)).json[0];
  assert.doesNotMatch(page.content.raw, /target="_blank"/);
  assert.doesNotMatch(page.content.rendered, /target="_blank"/);
});

test("apagado con el filtro estrategia_web_site_external_links: nada", async () => {
  await setup(false);
  try {
    const a = anchor((await html(url)).text, "x-ext");
    assert.equal(a.target, undefined);
    assert.equal(a.rel, undefined);
  } finally {
    await setup(null);
  }
});
