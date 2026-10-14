// NIVEL: 2 — WordPress de Playground, porque ESCRIBE: guarda la lista de redes por REST, sube SVG a la mediateca y pinta el bloque en una página.
import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { reset, wp, saveCompany, seedPage, html, upload, png } from "../../../tests/lib.mjs";

const OPTION = "estrategia_web_social_links";
const saveLinks = async (value) => {
  const r = await wp("/settings", { method: "POST", body: { [OPTION]: value } });
  assert.equal(r.status, 200, r.text);
  return r.json[OPTION];
};
const block = (attrs = {}) => `<!-- wp:estrategia-web/social-links ${JSON.stringify(attrs)} /-->`;
const render = async (attrs, slug) => {
  const page = await seedPage(block(attrs), slug);
  const { status, text } = await html(page.url);
  assert.equal(status, 200);
  return text.match(/<ul[^>]*estrategia-web-social-links[\s\S]*?<\/ul>/)?.[0] ?? "";
};

before(async () => {
  await reset();
  await saveLinks(null); // Sin guardar: como una web que acaba de encender el módulo.
  await saveCompany({ company_name: "Lavandería Prueba", social: { instagram: "https://instagram.com/prueba", facebook: "" } });
});
after(() => saveLinks(null));

test("sin lista guardada, el bloque pinta las redes de «Datos de la empresa», accesible y en pestaña nueva", async () => {
  const ul = await render({}, "redes-empresa");
  const links = [...ul.matchAll(/<a\b[\s\S]*?<\/a>/g)].map((m) => m[0]);
  assert.equal(links.length, 1, ul);
  assert.match(links[0], /href="https:\/\/instagram\.com\/prueba"/);
  assert.match(links[0], /target="_blank" rel="noopener noreferrer"/);
  assert.match(links[0], /<svg[^>]*aria-hidden="true"[^>]*>/);
  assert.match(links[0], /<svg[^>]*fill="currentColor"/);
  assert.doesNotMatch(links[0], /<title>/, "sin <title> en el dibujo: el nombre lo lleva el enlace");
  assert.match(links[0], /<span class="estrategia-web-social-links__sr">Instagram de Lavandería Prueba \(se abre en una pestaña nueva\)<\/span>/);
  assert.match(ul, /is-display-icons is-size-medium/);
  assert.match(ul, /is-network-instagram/);
});

test("la lista guardada manda, en su orden: icono adivinado del enlace, correo a mailto: sin pestaña nueva, fuera javascript: y el icono inventado", async () => {
  const saved = await saveLinks([
    { name: "", url: "https://www.tiktok.com/@prueba" },
    { name: "Correo", url: "hola@ejemplo.test" },
    { name: "Mala", url: "javascript:alert(1)" },
    { name: "Nuestra web <b>", url: "https://ejemplo.test/", icon: "inventado" },
    { name: "Mapa", url: "https://www.google.com/maps/place/x", icon: "" },
  ]);
  assert.deepEqual(
    saved.map((i) => [i.name, i.url, i.icon, i.icon_id]),
    [
      ["", "https://www.tiktok.com/@prueba", "", 0],
      ["Correo", "mailto:hola@ejemplo.test", "", 0],
      ["Nuestra web", "https://ejemplo.test/", "", 0],
      ["Mapa", "https://www.google.com/maps/place/x", "", 0],
    ]
  );
  const ul = await render({ display: "icons-names", size: "large" }, "redes-lista");
  const items = [...ul.matchAll(/<li class="estrategia-web-social-links__item is-network-([a-z-]+)">([\s\S]*?)<\/li>/g)].map((m) => [m[1], m[2]]);
  assert.deepEqual(items.map((i) => i[0]), ["tiktok", "email", "website", "google-maps"], "ya no salen las de la empresa");
  assert.match(items[0][1], /<span class="estrategia-web-social-links__name" aria-hidden="true">TikTok<\/span>/, "sin nombre, el del icono");
  assert.match(items[1][1], /href="mailto:hola@ejemplo\.test"/);
  assert.doesNotMatch(items[1][1], /target=/, "un mailto: no abre pestaña");
  assert.match(items[1][1], /Correo de Lavandería Prueba<\/span>/);
  assert.match(ul, /is-display-icons-names is-size-large/);
});

test("SVG propio: solo un SVG de la mediateca vale como icono, y se pinta saneado y con sus colores", async () => {
  await saveCompany({ allow_svg: true });
  const svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10" width="300" height="300"><script>alert(1)</script><path d="M0 0h10v10z" fill="#e1306c" onclick="alert(2)"/></svg>';
  const up = await upload("red-propia.svg", "image/svg+xml", svg);
  assert.equal(up.status, 201, up.text);
  const pic = await upload("no-svg.png", "image/png", png());
  assert.equal(pic.status, 201, pic.text);

  const saved = await saveLinks([
    { name: "Mi red", url: "https://mi-red.test/", icon_id: up.json.id },
    { name: "Con PNG", url: "https://otra.test/", icon_id: pic.json.id },
  ]);
  assert.equal(saved[0].icon_id, up.json.id);
  assert.equal(saved[1].icon_id, 0, "un PNG no es un icono");

  const ul = await render({}, "redes-svg");
  const first = ul.match(/<li[\s\S]*?<\/li>/)[0];
  assert.match(first, /<path d="M0 0h10v10z" fill="#e1306c"\/>/, "con su color");
  assert.doesNotMatch(first, /script|onclick|width="300"/i);
  assert.match(first, /<svg[^>]*class="estrategia-web-social-links__icon"/);
  await saveCompany({ allow_svg: false });
});

test("lista guardada vacía: el bloque no pinta nada (y no vuelve a las de la empresa)", async () => {
  assert.deepEqual(await saveLinks([]), []);
  const page = await seedPage(block(), "redes-vacia");
  const { text } = await html(page.url);
  assert.doesNotMatch(text, /estrategia-web-social-links__item/);
});

test("un autor no puede leer ni cambiar la lista por REST (es de /wp/v2/settings)", async () => {
  const r = await wp("/settings", { as: "author", method: "POST", body: { [OPTION]: [{ url: "https://malo.test" }] } });
  assert.equal(r.status, 403);
});
