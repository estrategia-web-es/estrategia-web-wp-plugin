// NIVEL: 1 — no browser or emulator. The upload settings (defaults, sanitizing, the size cap) with the machine's PHP.
//
// If there is no `php` on the PATH, the test FAILS (it is not skipped).
import { test } from "node:test";
import assert from "node:assert/strict";
import { spawnSync } from "node:child_process";
import path from "node:path";

const r = spawnSync("php", [path.join(import.meta.dirname, "php", "pure.php")], { encoding: "utf8" });
assert.equal(r.status, 0, "php did not run: " + (r.error?.message || r.stderr || r.stdout));
const out = JSON.parse(r.stdout);

test("defaults are those of Lavandería's MU-plugin: 20 MB, 2560 px, delete the original", () => {
  assert.deepEqual(out.defaults, { max_mb: 20, threshold: 2560, delete_original: true });
  assert.deepEqual(out.partial, { max_mb: 20, threshold: 2560, delete_original: false }, "whatever is not sent keeps its default");
});

test("sanitizing: nothing negative, minimum threshold 320 except 0 (= never), non-numeric text is ignored", () => {
  assert.deepEqual(out.clamped, { max_mb: 0, threshold: 320, delete_original: true });
  assert.equal(out.never.threshold, 0);
  assert.deepEqual(out.text, { max_mb: 20, threshold: 3000, delete_original: false });
});

test("the Settings → Media form: an unchecked checkbox is not sent and counts as \"no\"", () => {
  assert.deepEqual(out.form_unchecked, { max_mb: 30, threshold: 2560, delete_original: false });
  assert.equal(out.form_checked.delete_original, true);
});

test("the limit is a CAP: the lower of ours and PHP's wins; 0 = only PHP's", () => {
  assert.equal(out.limit_php_big, 20);
  assert.equal(out.limit_php_low, 8);
  assert.equal(out.limit_zero, 256);
});
