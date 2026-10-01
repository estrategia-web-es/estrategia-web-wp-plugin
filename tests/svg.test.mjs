// NIVEL: 2 — WordPress de Playground, porque ESCRIBE: sube ficheros a la mediateca por la API REST como administrador y como autor.
import { test, before } from "node:test";
import assert from "node:assert/strict";
import { reset, saveCompany, upload, wp, png } from "./lib.mjs";

const HOSTILE = `<?xml version="1.0"?>
<svg xmlns="http://www.w3.org/2000/svg" width="120" height="60" viewBox="0 0 120 60" onload="alert('onload')">
  <script>alert('script')</script>
  <foreignObject><div xmlns="http://www.w3.org/1999/xhtml">x</div></foreignObject>
  <rect width="120" height="60" fill="#c00"/>
</svg>`;

// El núcleo convierte CUALQUIER error de subida por REST en 500
// (rest_upload_unknown_error), también el «no» de nuestro prefilter. Lo que
// prueba que el «no» es nuestro y no un fallo cualquiera es el mensaje.
function assertDenied(r) {
  assert.notEqual(r.status, 201, `se subió un SVG que no debía: ${r.text}`);
  assert.match(r.json?.message || "", /No tienes permiso para subir ficheros SVG/, r.text);
}

before(async () => {
  await reset();
  await saveCompany({ allow_svg: true });
});

test("un SVG con <script> lo sube un administrador SANEADO: sin script, sin on*, con sus medidas", async () => {
  const r = await upload("prueba-logo.svg", "image/svg+xml", HOSTILE);
  assert.equal(r.status, 201, r.text);
  assert.equal(r.json.mime_type, "image/svg+xml");
  assert.equal(r.json.media_details.width, 120);
  assert.equal(r.json.media_details.height, 60);

  const stored = await (await fetch(r.json.source_url)).text();
  assert.match(stored, /<rect width="120" height="60" fill="#c00"\/>/, "el dibujo se queda");
  for (const bad of ["<script", "alert", "onload", "foreignObject"]) assert.ok(!stored.includes(bad), `el fichero guardado aún tiene «${bad}»`);
});

test("por el otro camino de la API (cuerpo crudo = sideload) también sale saneado", async () => {
  const r = await wp("/media", {
    method: "POST",
    raw: HOSTILE,
    headers: { "Content-Type": "image/svg+xml", "Content-Disposition": 'attachment; filename="prueba-crudo.svg"' },
  });
  assert.equal(r.status, 201, r.text);
  const stored = await (await fetch(r.json.source_url)).text();
  assert.ok(!stored.includes("<script"), "sideload sin sanear");
});

test("la mediateca (wp.media) recibe ancho, alto y tamaño full del SVG", async () => {
  const r = await upload("prueba-medidas.svg", "image/svg+xml", '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 300 100"><circle cx="50" cy="50" r="40"/></svg>');
  assert.equal(r.status, 201, r.text);
  assert.equal(r.json.media_details.width, 300);
  assert.equal(r.json.media_details.sizes.full.width, 300);
  assert.equal(r.json.media_details.sizes.full.height, 100);
});

test("un usuario autor NO puede subir SVG (aunque allow_svg esté activado); un PNG sí", async () => {
  const r = await upload("prueba-autor.svg", "image/svg+xml", '<svg xmlns="http://www.w3.org/2000/svg"/>', "author");
  assertDenied(r);
  const ok = await upload("prueba-autor.png", "image/png", png(), "author");
  assert.equal(ok.status, 201, "el autor sí puede subir ficheros: el no es por el SVG");
});

test("con allow_svg desactivado, ni el administrador puede", async () => {
  await saveCompany({ allow_svg: false });
  const r = await upload("prueba-off.svg", "image/svg+xml", '<svg xmlns="http://www.w3.org/2000/svg"/>');
  assertDenied(r);
});
