// NIVEL: 1 — no browser or emulator. The decisions of includes/class-webp.php (which formats become WebP, the theme's off switch, the quality) with the machine's PHP.
//
// If there is no `php` on the PATH, the test FAILS (it is not skipped).
// What only the real image editor shows (the files it writes, the trap of
// regenerating) is level 2: webp.test.mjs.
// Seen red (28-10-2026) with image/png removed from Webp::FROM: «JPEG and
// PNG become WebP» failed (it was the level 2 PNG upload that guarded it).
import { test } from "node:test";
import assert from "node:assert/strict";
import { spawnSync } from "node:child_process";
import path from "node:path";

const r = spawnSync("php", [path.join(import.meta.dirname, "php", "pure.php")], { encoding: "utf8" });
assert.equal(r.status, 0, "php did not run: " + (r.error?.message || r.stderr || r.stdout));
const out = JSON.parse(r.stdout);

test("JPEG and PNG become WebP; another format's mapping is kept", () => {
  assert.deepEqual(out.on, { "image/jpeg": "image/webp", "image/png": "image/webp" });
  assert.deepEqual(out.keeps, { "image/gif": "image/png", "image/jpeg": "image/webp", "image/png": "image/webp" });
});

test("nothing changes with the estrategia_web_site_webp filter set to false, nor when this PHP cannot write WebP", () => {
  assert.deepEqual(out.filter_off, []);
  assert.deepEqual(out.unsupported, []);
});

test("quality: ours (75) for WebP only; the theme's filter, capped at 100; 0 leaves WordPress's", () => {
  assert.equal(out.quality_webp, 75);
  assert.equal(out.quality_jpeg, 82);
  assert.equal(out.quality_zero, 82);
  assert.equal(out.quality_high, 100);
});
