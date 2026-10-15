// NIVEL: 1 — no browser or emulator. The geometry of the carousel's mobile crop (includes/carousel-mobile-crop.php) with the machine's PHP.
//
// If there is no `php` in the PATH, the test FAILS (it is not skipped).
import { test } from "node:test";
import assert from "node:assert/strict";
import { spawnSync } from "node:child_process";
import path from "node:path";

const r = spawnSync("php", [path.join(import.meta.dirname, "php", "mobile-crop.php")], { encoding: "utf8" });
assert.equal(r.status, 0, "php did not run: " + (r.error?.message || r.stderr || r.stdout));
const out = JSON.parse(r.stdout);

test("the ratio is written as 370/460, 4:5 or 0.8; anything else asks for no crop", () => {
  assert.equal(out.ratio_slash, 0.8043);
  assert.equal(out.ratio_colon, 0.8);
  assert.equal(out.ratio_number, 0.8);
  assert.deepEqual(out.ratio_bad, [null, null, null, null]);
});

test("the crop has the box's shape, centred on the focal point and always inside the image", () => {
  // 1707 × 370/460 = 1373 wide, centred: (2560 − 1373) / 2 = 593.5.
  assert.deepEqual(out.box_center, [594, 0, 1373, 1707]);
  assert.deepEqual(out.box_left, [0, 0, 1373, 1707], "against the left edge");
  assert.deepEqual(out.box_right, [1187, 0, 1373, 1707], "against the right edge: 0.9 × 2560 − 686 would overflow");
  // Vertical 1000×2000: full width, height 1000 / 0.804 = 1243; focal point at 30 % of the height.
  assert.deepEqual(out.box_tall, [0, 0, 1000, 1243]);
});

test("widths: those that fit in the crop, no upscaling; if none fits, one at the crop width", () => {
  assert.deepEqual(out.widths_big, [600, 900, 1200]);
  assert.deepEqual(out.widths_mid, [600, 900]);
  assert.deepEqual(out.widths_small, [400]);
});

test("the focal point comes from the <img>'s object-position in %, or the centre if there is none", () => {
  assert.deepEqual(out.focal_cover, [0.22, 0.5]);
  assert.deepEqual(out.focal_spaces, [0.895, 0.3]);
  assert.deepEqual(out.focal_none, [0.5, 0.5]);
  assert.deepEqual(out.focal_keyword, [0.5, 0.5], "keywords (left top) are not parsed: centre");
});
