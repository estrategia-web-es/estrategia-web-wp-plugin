// NIVEL: 1 — sin navegador ni emulador. Las clases puras (enlaces tel/mailto/wa.me, el saneado de SVG y qué enlace es externo) con el PHP de la máquina.
//
// Si no hay `php` en el PATH, la prueba FALLA (no se salta): una prueba que
// se salta en silencio parece que cubre algo. En esta máquina está en
// C:\wamp64\bin\php\php8.3.6.
import { test } from "node:test";
import assert from "node:assert/strict";
import { spawnSync } from "node:child_process";
import path from "node:path";

const r = spawnSync("php", [path.join(import.meta.dirname, "php", "pure.php")], { encoding: "utf8" });
assert.equal(r.status, 0, "php no corrió: " + (r.error?.message || r.stderr));
const out = JSON.parse(r.stdout);

test("tel: con prefijo por defecto, con + y con 00; vacío si no hay número", () => {
  assert.equal(out.tel_local, "tel:+34971123456");
  assert.equal(out.tel_plus, "tel:+442079460000");
  assert.equal(out.tel_00, "tel:+442079460000");
  assert.equal(out.tel_empty, "");
});

test("mailto y wa.me: mensaje codificado, número solo con dígitos", () => {
  assert.equal(out.mailto, "mailto:hola@ejemplo.test");
  assert.equal(out.wa, "https://wa.me/34600111222?text=Hola%2C%20quer%C3%ADa%20informaci%C3%B3n");
  assert.equal(out.wa_local, "https://wa.me/34600111222");
  assert.equal(out.wa_empty, "", "sin número no hay enlace, aunque haya mensaje");
});

test("SVG hostil: fuera script, on*, foreignObject, javascript:, enlaces externos, @import y comentarios", () => {
  const svg = out.clean;
  assert.ok(svg, "un SVG con cosas malas se limpia, no se rechaza");
  for (const bad of ["<script", "onload", "onclick", "foreignObject", "javascript", "evil.test", "@import", "behavior", "sodipodi:namedview", "<a ", "<!--", "xml-stylesheet"]) {
    assert.ok(!svg.includes(bad), `queda «${bad}» en:\n${svg}`);
  }
});

test("SVG hostil: lo legítimo se queda (degradado con mayúsculas, url(#id), xlink:href interno, imagen incrustada, texto)", () => {
  const svg = out.clean;
  assert.match(svg, /<linearGradient id="grad">/);
  assert.match(svg, /<path d="M0 0h10" fill="url\(#grad\)"\/>/);
  assert.match(svg, /\.b\{fill:url\(#grad\);background:none\}/);
  assert.match(svg, /<use xlink:href="#grad"\/>/);
  assert.match(svg, /href="data:image\/png;base64,/);
  assert.match(svg, /Logo &amp; Co/);
  assert.match(svg, /viewBox="0 0 120 60"/);
});

test("SVG que no se acepta: entidades (XXE), raíz que no es svg, XML roto", () => {
  assert.equal(out.xxe, null);
  assert.equal(out.not_svg, null);
  assert.equal(out.not_xml, null);
});

test("enlaces externos (0.5.2): otro host sí; el mismo, www, subdominios, relativos, anclas y otros esquemas no", () => {
  const ext = Object.entries(out.external).filter(([, v]) => v).map(([k]) => k);
  assert.deepEqual(ext, [
    "https://wa.me/34600111222",
    "https://api.whatsapp.com/send?phone=34600111222",
    "http://maps.google.com/?q=x",
    "//facebook.com/x",
    "HTTPS://WEB-VIEJA.TEST/",
    "https://otroweb.test/",
    "https://web.test.evil.test/",
  ]);
});

test("rel (0.5.2): se añade noopener sin quitar lo que había ni repetirlo", () => {
  assert.equal(out.rel_empty, "noopener");
  assert.equal(out.rel_keep, "nofollow sponsored noopener");
  assert.equal(out.rel_dup, "NoOpener nofollow");
});

test("medidas: width/height, viewBox, un solo lado con proporción, porcentajes, nada", () => {
  assert.deepEqual(out.dims_attrs, [120, 60]);
  assert.deepEqual(out.dims_viewbox, [301, 150]);
  assert.deepEqual(out.dims_one_side, [200, 100]);
  assert.deepEqual(out.dims_percent, [40, 20]);
  assert.deepEqual(out.dims_none, [0, 0]);
});
