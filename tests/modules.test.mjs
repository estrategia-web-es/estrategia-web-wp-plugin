// NIVEL: 1 — sin navegador ni emulador. El cargador de módulos (includes/class-modules.php) con el PHP de la máquina: que lee la cabecera de TODAS las carpetas de modules/ y qué carga según la opción guardada.
//
// Si no hay `php` en el PATH, la prueba FALLA (no se salta). En esta máquina
// está en C:\wamp64\bin\php\php8.3.6.
//
// Vista en rojo (14-10-2026): con la cabecera de modules/webp/module.php
// cambiada a «Modul Name:», «toda carpeta de modules/ es un módulo» falla
// diciendo que webp no se lee.
import { test } from "node:test";
import assert from "node:assert/strict";
import { spawnSync } from "node:child_process";
import path from "node:path";

const r = spawnSync("php", [path.join(import.meta.dirname, "php", "modules.php")], { encoding: "utf8" });
assert.equal(r.status, 0, "php no corrió: " + (r.error?.message || r.stderr || r.stdout));
const out = JSON.parse(r.stdout);

test("toda carpeta de modules/ es un módulo con nombre (una cabecera mal escrita no puede desaparecer en silencio)", () => {
  assert.deepEqual(Object.keys(out.available).sort(), [...out.folders].sort(), "carpetas sin cabecera legible: " + out.folders.filter((f) => !out.available[f]).join(", "));
  for (const [slug, m] of Object.entries(out.available)) {
    assert.ok(m.description, `${slug}: sin Description`);
    for (const req of m.requires) assert.ok(out.available[req], `${slug} necesita «${req}», que no existe`);
  }
});

test("lo que usa hoy Lavandería está encendido por defecto y se carga sin bloqueos", () => {
  const [loaded, blocked] = out.real_default;
  for (const slug of ["site", "consent", "forms", "carousel", "map", "whatsapp", "language-switcher", "webp", "external-links", "wpml-front-page"]) {
    assert.ok(loaded.includes(slug), `${slug} no se cargaría en una web sin la opción guardada`);
  }
  assert.deepEqual(blocked, []);
});

test("sin opción guardada valen los Default de las cabeceras", () => {
  assert.deepEqual(out.no_option, ["a", "b", "d"]);
});

test("un módulo que la web ya conocía y apagó se queda apagado aunque sea Default: on", () => {
  assert.deepEqual(out.saved_respected, ["a"]);
});

test("caso frontera: un módulo NUEVO para la web (no está en _known) entra con su Default", () => {
  assert.deepEqual(out.new_default_on, ["a", "b", "d"], "b y d son nuevos y Default: on; c era conocido y está apagado");
});

test("slugs guardados que ya no existen se ignoran", () => {
  assert.deepEqual(out.unknown_dropped, ["a"]);
});

test("sin _known (lista escrita a mano), la lista manda entera: no se añade ningún Default", () => {
  assert.deepEqual(out.no_known, ["c"]);
});

test("dependencias: lo que necesita algo apagado no se carga, y la pantalla sabe qué le falta", () => {
  assert.deepEqual(out.resolve_defaults, [["a", "b"], { d: ["c"] }]);
  assert.deepEqual(out.resolve_chain, [[], { m: ["k"], z: ["m"] }], "z necesita m, m necesita k (apagado): caen los dos");
});

test("se carga primero lo que otro necesita, y un ciclo no cuelga", () => {
  assert.deepEqual(out.resolve_order[0], ["zeta", "alpha"]);
  assert.deepEqual([...out.resolve_cycle[0]].sort(), ["x", "y"]);
});
