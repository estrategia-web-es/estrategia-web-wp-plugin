// NIVEL: 1 — sin navegador ni emulador. Los view.min.js commiteados están al día con su view.js (tools/minify.mjs).
//
// Cada view.min.js lleva en su primera línea el sha256 de la fuente de la que
// salió. Si alguien edita view.js y no pasa `node tools/minify.mjs`, la web
// seguiría sirviendo el comportamiento VIEJO sin que nada fallara: esto lo
// para en el commit. No ejecuta esbuild (sería lento y pediría red): solo
// compara hashes (normalizando los finales de línea: git en Windows puede
// dejar el view.js con CRLF).
import { test } from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import { check, targets } from "../tools/minify.mjs";

test("hay al menos un bloque minificado (el carrusel) y sirve view.min.js con su asset", () => {
  const list = targets();
  assert.ok(list.some((t) => t.block === "carousel"), "el carrusel debería servir view.min.js");
  for (const { dir } of list) {
    assert.ok(fs.existsSync(path.join(dir, "view.min.asset.php")), `falta view.min.asset.php en ${dir}`);
  }
});

test("cada view.min.js sale del view.js de hoy", () => {
  for (const c of check()) assert.ok(c.ok, `${c.dir}: ${c.why} — pasa \`node tools/minify.mjs\``);
});
