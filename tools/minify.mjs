// NIVEL: BANCO DE TRABAJO — no es una prueba. Minifica los view.js de los bloques que lo piden.
//
//   node tools/minify.mjs          regenera todos los view.min.js que tocan
//   node tools/minify.mjs --check  solo comprueba (lo mismo que la prueba de nivel 1)
//
// Qué minifica: el view.js de cada bloque cuyo block.json apunta a
// «file:./view.min.js» (hoy, solo el carrusel: 24 KB de fuente, la mitad
// comentarios). Para minificar otro bloque se cambia su block.json y se
// pasa esto; no hay lista que mantener.
//
// Qué deja al lado de cada view.js:
//   view.min.js          lo que sirve WordPress. Su primera línea dice de qué
//                        fuente sale y su sha256: tests/minified.test.mjs
//                        compara ese hash con el view.js de hoy y FALLA si
//                        alguien cambió la fuente y no regeneró.
//   view.min.asset.php   las dependencias (WordPress busca <script>.asset.php
//                        junto al módulo que sirve): se copian del array que
//                        esté en DEPS más abajo y la versión es la fecha del .min.
//
// Con qué: esbuild, por npx y con la versión FIJA (sin package.json ni
// node_modules en el repo: el plugin no tiene paso de compilación y no se le
// pone uno por 4 KB). Solo transforma (sin --bundle): el import de
// @wordpress/interactivity se queda tal cual y lo resuelve el import map de
// WordPress. Comentarios fuera; nombres de las funciones locales acortados.
//
// Cómo se mantiene:
// - Quién: quien edite un view.js minificado. Cuándo: SIEMPRE después de
//   editarlo, en el mismo commit (la prueba de nivel 1 lo recuerda).
// - Se edita la FUENTE (view.js), nunca el .min. Un import nuevo en view.js
//   se añade a DEPS aquí.
// - La trampa: la primera vez npx descarga esbuild (~10 MB, unos segundos) y
//   pide red; después tira de la caché de npm. Si un día falla por red, el
//   .min commiteado sigue sirviendo: no se rompe la web, solo no se regenera.
import { spawnSync } from "node:child_process";
import crypto from "node:crypto";
import fs from "node:fs";
import path from "node:path";

const ROOT = path.resolve(import.meta.dirname, "..");
const ESBUILD = "esbuild@0.25.0";
const DEPS = { carousel: ["@wordpress/interactivity"] };

export const sha = (text) => crypto.createHash("sha256").update(text.replace(/\r\n/g, "\n")).digest("hex");
export const headerOf = (source, hash) => `/*! ${source} minificado por tools/minify.mjs (${ESBUILD}) — sha256 de la fuente: ${hash}. No se edita: se edita ${source} y se vuelve a pasar. */`;

/** Bloques cuyo block.json sirve view.min.js: [{ dir, block, module }]. */
export function targets() {
  const out = [];
  for (const module of fs.readdirSync(path.join(ROOT, "modules"))) {
    const blocks = path.join(ROOT, "modules", module, "blocks");
    if (!fs.existsSync(blocks)) continue;
    for (const block of fs.readdirSync(blocks)) {
      const json = path.join(blocks, block, "block.json");
      if (fs.existsSync(json) && /"file:\.\/view\.min\.js"/.test(fs.readFileSync(json, "utf8"))) {
        out.push({ dir: path.join(blocks, block), block, module });
      }
    }
  }
  return out;
}

function build({ dir, block }) {
  const source = fs.readFileSync(path.join(dir, "view.js"), "utf8");
  const r = spawnSync(
    "npx",
    ["-y", ESBUILD, "view.js", "--minify", "--format=esm", "--target=es2020", "--legal-comments=none", "--log-level=warning"],
    { cwd: dir, encoding: "utf8", shell: process.platform === "win32" }
  );
  if (r.status !== 0 || !r.stdout.trim()) {
    throw new Error(`esbuild falló en ${dir}:\n${r.stderr || r.error}`);
  }
  fs.writeFileSync(path.join(dir, "view.min.js"), headerOf("view.js", sha(source)) + "\n" + r.stdout);
  const deps = DEPS[block] ?? [];
  fs.writeFileSync(
    path.join(dir, "view.min.asset.php"),
    `<?php\n/**\n * Dependencias de view.min.js. GENERADO por tools/minify.mjs: se cambian en\n * DEPS de ese fichero, no aquí. La versión es la fecha del .min.\n */\nreturn array(\n\t'dependencies' => array( ${deps.map((d) => `'${d}'`).join(", ")} ),\n\t'version'      => (string) filemtime( __DIR__ . '/view.min.js' ),\n);\n`
  );
  const kb = (f) => (fs.statSync(path.join(dir, f)).size / 1024).toFixed(1);
  console.log(`✔ ${path.relative(ROOT, dir)}: view.js ${kb("view.js")} KB → view.min.js ${kb("view.min.js")} KB`);
}

/** [{ dir, ok, why }] — si cada .min está al día con su fuente. */
export function check() {
  return targets().map(({ dir }) => {
    const min = path.join(dir, "view.min.js");
    if (!fs.existsSync(min)) return { dir, ok: false, why: "no existe view.min.js" };
    const first = fs.readFileSync(min, "utf8").split("\n")[0];
    const want = sha(fs.readFileSync(path.join(dir, "view.js"), "utf8"));
    return first.includes(want) ? { dir, ok: true } : { dir, ok: false, why: "view.js cambió y view.min.js no se regeneró" };
  });
}

if (process.argv[1] && path.resolve(process.argv[1]) === path.resolve(import.meta.filename)) {
  if (process.argv.includes("--check")) {
    const bad = check().filter((c) => !c.ok);
    bad.forEach((b) => console.error(`✖ ${path.relative(ROOT, b.dir)}: ${b.why}`));
    process.exit(bad.length ? 1 : 0);
  }
  targets().forEach(build);
}
