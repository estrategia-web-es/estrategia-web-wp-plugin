// NIVEL: 2 — WordPress de Playground, porque ESCRIBE la opción estrategia_web_modules y lo que se mira es qué registra WordPress en la petición SIGUIENTE (bloques, rutas REST) y cómo pinta una página con un bloque de un módulo apagado.
//
// La pantalla en sí (los interruptores) no se prueba aquí: guarda por
// options.php con la misma opción y el mismo sanitize() que usa esta prueba.
// Vista en rojo (14-10-2026): con Modules::saved() sin escribir _known, «al
// guardar se apunta qué módulos existían» falla.
import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { BASE, reset, seedPage, testApi, html, wp } from "./lib.mjs";

// Los módulos que existen y no son de prueba ni Default: off con su propia
// prueba (redirects, google-tags). Un módulo nuevo entra aquí y, si nace
// apagado, también en OFF.
const ALL = ["carousel", "comments-off", "consent", "external-links", "featured-images", "forms", "language-switcher", "map", "matomo", "site", "social", "uploads", "webp", "whatsapp", "wpml-front-page"];
// Los que nacen apagados (Default: off): sin opción guardada no se cargan.
const OFF = ["comments-off"];
const ON = ALL.filter((s) => !OFF.includes(s));

before(reset);
after(() => testApi("/modules", { method: "POST", body: { active: null } }));

test("sin opción guardada se cargan los módulos con Default: on, y solo esos", async () => {
  const m = await testApi("/modules");
  assert.equal(m.saved, null);
  assert.deepEqual([...m.loaded].sort(), ON);
  assert.equal((await wp("/block-types/estrategia-web/carousel")).status, 200);
  assert.equal((await wp("/block-types/armazon/carousel")).status, 200, "el alias del nombre viejo existe con el módulo encendido");
});

test("al guardar se apunta qué módulos existían, y un módulo apagado no registra nada", async () => {
  await testApi("/modules", { method: "POST", body: { active: ALL.filter((s) => s !== "carousel" && s !== "forms").concat("no-existe") } });
  const m = await testApi("/modules");
  // Contra lo que hay en disco, no contra ALL: un módulo Default: off (redirects) existe sin cargarse.
  assert.deepEqual([...m.known].sort(), [...m.available].sort(), "al guardar se apunta qué módulos existían");
  for (const slug of ALL) assert.ok(m.known.includes(slug), slug);
  assert.ok(!m.saved.includes("no-existe"), "un slug que no existe no se guarda");
  assert.ok(!m.loaded.includes("carousel") && !m.loaded.includes("forms"));

  assert.equal((await wp("/block-types/estrategia-web/carousel")).status, 404, "sin bloque");
  assert.equal((await wp("/block-types/armazon/carousel")).status, 404, "ni su alias");
  assert.equal((await wp("/block-types/estrategia-web-forms/form")).status, 404);
  const token = await fetch(BASE + "/wp-json/estrategia-web-forms/v1/token");
  assert.equal(token.status, 404, "ni las rutas REST del módulo");
  assert.equal((await wp("/block-types/estrategia-web/map")).status, 200, "los demás siguen");
});

test("una página con el bloque de un módulo apagado se pinta sin él y sin error", async () => {
  const page = await seedPage(
    '<!-- wp:paragraph --><p>Antes</p><!-- /wp:paragraph --><!-- wp:estrategia-web/carousel {"label":"Fotos"} --><!-- wp:paragraph --><p>Diapositiva</p><!-- /wp:paragraph --><!-- /wp:estrategia-web/carousel --><!-- wp:paragraph --><p>Después</p><!-- /wp:paragraph -->',
    "modulo-apagado"
  );
  const r = await html(page.url);
  assert.equal(r.status, 200);
  assert.ok(r.text.includes("Antes") && r.text.includes("Después"));
  assert.ok(!r.text.includes("estrategia-web-carousel__track"), "el carrusel no se pinta");
  assert.ok(!/Fatal error|Warning:|Notice:/.test(r.text), "sin errores de PHP en la página");
});

test("volver a encenderlo lo devuelve tal cual", async () => {
  await testApi("/modules", { method: "POST", body: { active: ALL } });
  assert.equal((await wp("/block-types/estrategia-web/carousel")).status, 200);
  assert.equal((await wp("/block-types/estrategia-web-forms/form")).status, 200);
});
