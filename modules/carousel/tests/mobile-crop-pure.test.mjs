// NIVEL: 1 — sin navegador ni emulador. La geometría del recorte para el móvil del carrusel (includes/carousel-mobile-crop.php) con el PHP de la máquina.
//
// Si no hay `php` en el PATH, la prueba FALLA (no se salta).
import { test } from "node:test";
import assert from "node:assert/strict";
import { spawnSync } from "node:child_process";
import path from "node:path";

const r = spawnSync("php", [path.join(import.meta.dirname, "php", "mobile-crop.php")], { encoding: "utf8" });
assert.equal(r.status, 0, "php no corrió: " + (r.error?.message || r.stderr || r.stdout));
const out = JSON.parse(r.stdout);

test("la proporción se escribe como 370/460, 4:5 o 0.8; lo demás no pide recorte", () => {
  assert.equal(out.ratio_slash, 0.8043);
  assert.equal(out.ratio_colon, 0.8);
  assert.equal(out.ratio_number, 0.8);
  assert.deepEqual(out.ratio_bad, [null, null, null, null]);
});

test("el recorte tiene la forma de la caja, centrado en el enfoque y siempre dentro de la foto", () => {
  // 1707 × 370/460 = 1373 de ancho, centrado: (2560 − 1373) / 2 = 593,5.
  assert.deepEqual(out.box_center, [594, 0, 1373, 1707]);
  assert.deepEqual(out.box_left, [0, 0, 1373, 1707], "pegado al borde izquierdo");
  assert.deepEqual(out.box_right, [1187, 0, 1373, 1707], "pegado al derecho: 0,9 × 2560 − 686 se saldría");
  // Vertical 1000×2000: ancho entero, alto 1000 / 0,804 = 1243; enfoque al 30 % de alto.
  assert.deepEqual(out.box_tall, [0, 0, 1000, 1243]);
});

test("anchos: los que caben en el recorte, sin ampliar; si no cabe ninguno, uno al ancho del recorte", () => {
  assert.deepEqual(out.widths_big, [600, 900, 1200]);
  assert.deepEqual(out.widths_mid, [600, 900]);
  assert.deepEqual(out.widths_small, [400]);
});

test("el enfoque sale del object-position del <img> en %, y si no hay, el centro", () => {
  assert.deepEqual(out.focal_cover, [0.22, 0.5]);
  assert.deepEqual(out.focal_spaces, [0.895, 0.3]);
  assert.deepEqual(out.focal_none, [0.5, 0.5]);
  assert.deepEqual(out.focal_keyword, [0.5, 0.5], "las palabras (left top) no se interpretan: centro");
});
