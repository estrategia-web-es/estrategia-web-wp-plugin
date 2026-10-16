// NIVEL: 1 — no browser or emulator. Every front-end view.js of the plugin is served minified, and each view.min.js is up to date with its view.js (tools/minify.mjs).
//
// Each view.min.js carries on its first line the sha256 of the source it was
// built from. If someone edits view.js and does not run
// `node tools/minify.mjs`, the site would keep serving the OLD behavior with
// nothing failing: this stops it at commit time. It does not run esbuild (it
// would be slow and need the network): it only compares hashes (normalizing
// line endings: git on Windows may leave view.js with CRLF).
//
// Since 1.4.2 it also fails if a block.json serves its view.js unminified
// (PageSpeed «Minify JavaScript», 16-10-2026, on consent's view.js): a new
// block with a view.js points its block.json to "file:./view.min.js".
import { test } from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import { check, importsOf, targets, unminified } from "../tools/minify.mjs";

test("no block serves an unminified script on the front end", () => {
  const bad = unminified().map((u) => `${u.module}/${u.block}`);
  assert.deepEqual(bad, [], `these block.json serve the source; point them to "file:./view.min.js" and run \`node tools/minify.mjs\``);
});

test("every block with a view.js serves its view.min.js, with its asset and no leftover hand-written view.asset.php", () => {
  const list = targets();
  for (const block of ["carousel", "consent", "form", "language-switcher", "map"]) {
    assert.ok(list.some((t) => t.block === block), `${block} should serve view.min.js`);
  }
  for (const { dir } of list) {
    assert.ok(fs.existsSync(path.join(dir, "view.js")), `view.js (the source) missing in ${dir}`);
    assert.ok(fs.existsSync(path.join(dir, "view.min.asset.php")), `view.min.asset.php missing in ${dir}`);
    assert.ok(!fs.existsSync(path.join(dir, "view.asset.php")), `${dir}/view.asset.php is no longer read by WordPress: delete it`);
  }
});

test("each view.min.js comes from today's view.js", () => {
  for (const c of check()) assert.ok(c.ok, `${c.dir}: ${c.why} — run \`node tools/minify.mjs\``);
});

test("the asset's dependencies are the bare imports of the source", () => {
  assert.deepEqual(importsOf("import { store } from '@wordpress/interactivity';\nimport './x.js';\n"), {
    bare: ["@wordpress/interactivity"],
    relative: ["./x.js"],
  });
  for (const { dir } of targets()) {
    const { bare } = importsOf(fs.readFileSync(path.join(dir, "view.js"), "utf8"));
    const asset = fs.readFileSync(path.join(dir, "view.min.asset.php"), "utf8");
    for (const dep of bare) assert.ok(asset.includes(`'${dep}'`), `${dir}: view.min.asset.php does not declare ${dep}`);
  }
});
