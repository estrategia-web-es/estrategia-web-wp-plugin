// NIVEL: BANCO DE TRABAJO — not a test. Minifies the view.js of the plugin's blocks.
//
//   node tools/minify.mjs          regenerates every view.min.js
//   node tools/minify.mjs --check  only checks (same as the level 1 test)
//
// What it minifies: the view.js of EVERY block whose block.json points to
// "file:./view.min.js" (1.4.2: carousel, consent, form, language-switcher and
// map — every script of the plugin that reaches the front end). There is no
// list to maintain: it walks modules/*/blocks/*/block.json.
//
// The rule (1.4.2, PageSpeed «Minify JavaScript» on consent's view.js): a
// block that ships a view.js serves its view.min.js, never the source.
// tests/minified.test.mjs FAILS if a block.json points to "file:./view.js".
// A new block with a view.js: set "viewScriptModule": "file:./view.min.js"
// in its block.json and run this.
//
// What it leaves next to each view.js:
//   view.min.js          what WordPress serves. Its first line says which
//                        source it comes from and its sha256: tests/minified.test.mjs
//                        compares that hash with today's view.js and FAILS if
//                        someone changed the source and did not regenerate.
//   view.min.asset.php   the dependencies (WordPress looks for <script>.asset.php
//                        next to the module it serves): the bare imports of
//                        view.js (`import … from '@wordpress/interactivity'`),
//                        read from the source; the version is the .min's date.
//                        Since they come from the source, the hash covers them.
//
// With what: esbuild, through npx and with a PINNED version (no package.json or
// node_modules in the repo: the plugin has no build step and does not get one
// for a few KB). Transform only (no --bundle): the @wordpress/interactivity
// import stays as is and WordPress' import map resolves it. Comments removed;
// local function names shortened.
//
// How to maintain:
// - Who: whoever edits a view.js. When: ALWAYS after editing it, in the same
//   commit (the level 1 test is the reminder).
// - Edit the SOURCE (view.js), never the .min or the .asset.php.
// - Edge case, by name: a relative import (`import x from './helper.js'`) is
//   NOT a dependency for WordPress and would break once minified without
//   --bundle (the .min would ask for ./helper.js). Today no view.js has one;
//   build() refuses it so nobody finds out on the site.
// - The pitfall: the first time, npx downloads esbuild (~10 MB, a few seconds)
//   and needs the network; after that it uses the npm cache. If it ever fails
//   for lack of network, the committed .min keeps working: the site does not
//   break, it just is not regenerated.
import { spawnSync } from "node:child_process";
import crypto from "node:crypto";
import fs from "node:fs";
import path from "node:path";

const ROOT = path.resolve(import.meta.dirname, "..");
const ESBUILD = "esbuild@0.25.0";

export const sha = (text) => crypto.createHash("sha256").update(text.replace(/\r\n/g, "\n")).digest("hex");
export const headerOf = (source, hash) => `/*! ${source} minified by tools/minify.mjs (${ESBUILD}) — sha256 of the source: ${hash}. Do not edit: edit ${source} and run it again. */`;

/** Every block of the plugin: [{ dir, block, module, json }] (json = block.json text). */
export function blocks() {
  const out = [];
  for (const module of fs.readdirSync(path.join(ROOT, "modules"))) {
    const dir = path.join(ROOT, "modules", module, "blocks");
    if (!fs.existsSync(dir)) continue;
    for (const block of fs.readdirSync(dir)) {
      const json = path.join(dir, block, "block.json");
      if (fs.existsSync(json)) out.push({ dir: path.join(dir, block), block, module, json: fs.readFileSync(json, "utf8") });
    }
  }
  return out;
}

/** Blocks whose block.json serves view.min.js: [{ dir, block, module }]. */
export function targets() {
  return blocks()
    .filter((b) => /"file:\.\/view\.min\.js"/.test(b.json))
    .map(({ dir, block, module }) => ({ dir, block, module }));
}

/** Blocks that still serve an unminified script on the front end: [{ dir, block, module }]. */
export function unminified() {
  return blocks()
    .filter((b) => /"(?:viewScriptModule|viewScript|script)"\s*:\s*"file:\.\/(?!view\.min\.js")[^"]+\.js"/.test(b.json))
    .map(({ dir, block, module }) => ({ dir, block, module }));
}

/** The modules a source imports: { bare: ['@wordpress/interactivity'], relative: ['./x.js'] }. */
export function importsOf(source) {
  const ids = new Set();
  const re = /^\s*import\s+(?:[^'";]*?\sfrom\s+)?['"]([^'"]+)['"]/gm;
  for (let m; (m = re.exec(source)); ) ids.add(m[1]);
  const all = [...ids];
  return { bare: all.filter((id) => !/^[./]/.test(id)), relative: all.filter((id) => /^[./]/.test(id)) };
}

function build({ dir }) {
  const source = fs.readFileSync(path.join(dir, "view.js"), "utf8");
  const { bare, relative } = importsOf(source);
  if (relative.length) {
    throw new Error(`${dir}/view.js imports ${relative.join(", ")}: relative imports are not bundled (see the header).`);
  }
  const r = spawnSync(
    "npx",
    ["-y", ESBUILD, "view.js", "--minify", "--format=esm", "--target=es2020", "--legal-comments=none", "--log-level=warning"],
    { cwd: dir, encoding: "utf8", shell: process.platform === "win32" }
  );
  if (r.status !== 0 || !r.stdout.trim()) {
    throw new Error(`esbuild failed in ${dir}:\n${r.stderr || r.error}`);
  }
  fs.writeFileSync(path.join(dir, "view.min.js"), headerOf("view.js", sha(source)) + "\n" + r.stdout);
  fs.writeFileSync(
    path.join(dir, "view.min.asset.php"),
    `<?php\ndefined( 'ABSPATH' ) || exit;\n\n/**\n * Dependencies of view.min.js. GENERATED by tools/minify.mjs from the imports\n * of view.js: do not edit, edit view.js and run it again. The version is the\n * .min's date.\n */\nreturn array(\n\t'dependencies' => array( ${bare.map((d) => `'${d}'`).join(", ")} ),\n\t'version'      => (string) filemtime( __DIR__ . '/view.min.js' ),\n);\n`
  );
  const kb = (f) => (fs.statSync(path.join(dir, f)).size / 1024).toFixed(1);
  console.log(`✔ ${path.relative(ROOT, dir)}: view.js ${kb("view.js")} KB → view.min.js ${kb("view.min.js")} KB`);
}

/** [{ dir, ok, why }] — whether each .min is up to date with its source. */
export function check() {
  return targets().map(({ dir }) => {
    const min = path.join(dir, "view.min.js");
    if (!fs.existsSync(min)) return { dir, ok: false, why: "view.min.js does not exist" };
    const first = fs.readFileSync(min, "utf8").split("\n")[0];
    const want = sha(fs.readFileSync(path.join(dir, "view.js"), "utf8"));
    return first.includes(want) ? { dir, ok: true } : { dir, ok: false, why: "view.js changed and view.min.js was not regenerated" };
  });
}

if (process.argv[1] && path.resolve(process.argv[1]) === path.resolve(import.meta.filename)) {
  if (process.argv.includes("--check")) {
    const bad = check().filter((c) => !c.ok);
    bad.forEach((b) => console.error(`✖ ${path.relative(ROOT, b.dir)}: ${b.why}`));
    unminified().forEach((u) => console.error(`✖ ${path.relative(ROOT, u.dir)}: block.json serves an unminified script`));
    process.exit(bad.length || unminified().length ? 1 : 0);
  }
  targets().forEach(build);
}
