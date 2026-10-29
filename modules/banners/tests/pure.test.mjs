// NIVEL: 1 — no browser or emulator. The pure part of the banners module (includes/class-rules.php) with the machine's PHP: a banner's state, the weighted pick, and what Gastronòmicament's REAL AdRotate ads become.
//
// If there is no `php` on the PATH, the test FAILS (it is not skipped).
//
// Seen red (29-10-2026): without stripslashes in Rules::from_adrotate() the
// three AdRotate tests fail (ads 5 and 7 come out as code, the demo link keeps
// «http:\/\/», the AMIC iframe keeps its backslashes). The order of decoding
// and unslashing does NOT matter: both were tried and both pass.
import { test } from "node:test";
import assert from "node:assert/strict";
import { spawnSync } from "node:child_process";
import path from "node:path";

const r = spawnSync("php", [path.join(import.meta.dirname, "php", "pure.php")], { encoding: "utf8" });
assert.equal(r.status, 0, "php did not run: " + (r.error?.message || r.stderr || r.stdout));
const out = JSON.parse(r.stdout);

test("AdRotate: a link wrapping an image is an image banner, with its link and image", () => {
  const a5 = out.adrotate["5"];
  assert.equal(a5.kind, "image");
  assert.equal(a5.link, "https://gastronomicament.cat/ca/restaurants/destacatper/local-amb-carta-en-catala");
  assert.equal(a5.image, "https://gastronomicament.cat/wp-content/uploads/2020/10/Restaurants-en-catala.jpg");
  const a7 = out.adrotate["7"];
  assert.equal(a7.kind, "image", "%asset% is replaced by the image column, across line breaks");
  assert.equal(a7.link, "https://www.instagram.com/sacomunaburger");
  assert.equal(a7.image, "https://gastronomicament.cat/wp-content/uploads/2026/10/Sa-Comuna-a-Inca-banner-gros.jpeg");
});

test("AdRotate: the slashed «http:\\/\\/» of the demo ad is unslashed", () => {
  assert.equal(out.adrotate["1"].link, "http://ajdg.net/landing.php?src=adrotate");
});

test("AdRotate: an iframe and an adserver script stay as code, decoded and unslashed", () => {
  const a47 = out.adrotate["47"];
  assert.equal(a47.kind, "code");
  assert.match(a47.code, /^<iframe id="AMIC" src="https:\/\/amic\.media\/continguts\/campanya\.php\?id=2557"/);
  const a52 = out.adrotate["52"];
  assert.equal(a52.kind, "code");
  assert.ok(a52.code.includes("defineSlot('/30029749/gastronomicament.cat/gastronomicament.cat_300x600_1'"), a52.code);
  assert.ok(!a52.code.includes("\\"), "no backslash left");
  assert.ok(!a52.code.includes("&lt;"), "no entity left");
});

test("state: off, active, scheduled, expired; the end second itself still shows", () => {
  assert.deepEqual(out.state, {
    draft: "off",
    no_limits: "active",
    inside: "active",
    before: "scheduled",
    after: "expired",
    on_the_end: "active",
  });
});

test("pick: all when the zone shows all or more than there are, in order", () => {
  assert.deepEqual(out.pick.all_when_zero, [3, 1, 2]);
  assert.deepEqual(out.pick.all_when_more, [3, 1]);
});

test("pick: no repetition, and the weights decide how often", () => {
  assert.deepEqual(out.pick.two_no_repeat, [10, 11], "the first one taken is not offered again");
  assert.deepEqual(out.pick.weights_1_1_8, [1, 1, 8], "over every value, weights 1, 1, 8 come first 1, 1 and 8 times");
  assert.deepEqual(out.pick.weight_below_1, [11], "a weight below 1 counts as 1");
});
