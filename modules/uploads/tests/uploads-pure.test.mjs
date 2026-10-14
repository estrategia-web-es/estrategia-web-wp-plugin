// NIVEL: 1 — sin navegador ni emulador. Los ajustes de las subidas (valores por defecto, saneado, el tope de tamaño) con el PHP de la máquina.
//
// Si no hay `php` en el PATH, la prueba FALLA (no se salta).
import { test } from "node:test";
import assert from "node:assert/strict";
import { spawnSync } from "node:child_process";
import path from "node:path";

const r = spawnSync("php", [path.join(import.meta.dirname, "php", "pure.php")], { encoding: "utf8" });
assert.equal(r.status, 0, "php no corrió: " + (r.error?.message || r.stderr || r.stdout));
const out = JSON.parse(r.stdout);

test("por defecto, los del MU-plugin de Lavandería: 20 MB, 2560 px, borrar el original", () => {
  assert.deepEqual(out.defaults, { max_mb: 20, threshold: 2560, delete_original: true });
  assert.deepEqual(out.partial, { max_mb: 20, threshold: 2560, delete_original: false }, "lo que no llega se queda por defecto");
});

test("saneado: nada negativo, umbral mínimo 320 salvo 0 (= nunca), texto que no es número se ignora", () => {
  assert.deepEqual(out.clamped, { max_mb: 0, threshold: 320, delete_original: true });
  assert.equal(out.never.threshold, 0);
  assert.deepEqual(out.text, { max_mb: 20, threshold: 3000, delete_original: false });
});

test("el formulario de Ajustes → Medios: la casilla sin marcar no llega y cuenta como «no»", () => {
  assert.deepEqual(out.form_unchecked, { max_mb: 30, threshold: 2560, delete_original: false });
  assert.equal(out.form_checked.delete_original, true);
});

test("el límite es un TOPE: manda el menor entre el nuestro y el del PHP; 0 = solo el del PHP", () => {
  assert.equal(out.limit_php_big, 20);
  assert.equal(out.limit_php_low, 8);
  assert.equal(out.limit_zero, 256);
});
