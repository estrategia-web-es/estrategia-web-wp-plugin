// NIVEL: 1 — no browser or emulator. The committed view.min.js files are up to date with their view.js (tools/minify.mjs).
//
// Each view.min.js carries on its first line the sha256 of the source it was
// built from. If someone edits view.js and does not run
// `node tools/minify.mjs`, the site would keep serving the OLD behavior with
// nothing failing: this stops it at commit time. It does not run esbuild (it
// would be slow and need the network): it only compares hashes (normalizing
// line endings: git on Windows may leave view.js with CRLF).
import { test } from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import { check, targets } from "../tools/minify.mjs";

test("there is at least one minified block (the carousel) and it serves view.min.js with its asset", () => {
  const list = targets();
  assert.ok(list.some((t) => t.block === "carousel"), "the carousel should serve view.min.js");
  for (const { dir } of list) {
    assert.ok(fs.existsSync(path.join(dir, "view.min.asset.php")), `view.min.asset.php missing in ${dir}`);
  }
});

test("each view.min.js comes from today's view.js", () => {
  for (const c of check()) assert.ok(c.ok, `${c.dir}: ${c.why} — run \`node tools/minify.mjs\``);
});
