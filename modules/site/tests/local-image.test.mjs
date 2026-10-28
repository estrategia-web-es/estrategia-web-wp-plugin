// NIVEL: 1 — no browser or emulator. The path rules of the «local_image» field type (Sanitize::local_image, modules/content-types: what a location's static map was up to 1.7.0) with the machine's PHP.
//
// Moved down from static-map.test.mjs (level 2) on 28-10-2026: there each
// case was a REST save (21 requests, ~45 s) to prove a function that takes a
// string and returns a string. That the field type is wired to this sanitizer
// through REST is content-types/tests/constructor-templates.test.mjs (level 2).
// Seen red (28-10-2026) with the «..» check removed from local_image() (the
// two traversal cases are saved).
//
// If there is no `php` in the PATH, the test FAILS (it is not skipped).
import { test } from "node:test";
import assert from "node:assert/strict";
import { spawnSync } from "node:child_process";
import path from "node:path";

const HOME = "http://127.0.0.1:9404"; // php/local-image.php's home_url().
const run = (values) => {
  const r = spawnSync("php", [path.join(import.meta.dirname, "php", "local-image.php"), JSON.stringify(values)], { encoding: "utf8" });
  assert.equal(r.status, 0, "php did not run: " + (r.error?.message || r.stderr || r.stdout));
  return JSON.parse(r.stdout);
};

test("local_image: the theme path, the site path and a same-domain URL are saved (theme path without «./»)", () => {
  const cases = [
    ["assets/maps/sede.webp", "assets/maps/sede.webp"],
    ["  ./assets/maps/sede.png ", "assets/maps/sede.png"],
    ["/wp-content/uploads/mapa.webp", "/wp-content/uploads/mapa.webp"],
    [HOME + "/wp-content/uploads/mapa.jpg", HOME + "/wp-content/uploads/mapa.jpg"],
    ["", ""],
  ];
  assert.deepEqual(run(cases.map(([v]) => v)), cases.map(([, e]) => e));
});

test("local_image: another domain, no scheme, javascript:, traversal, not an image, HTML or spaces are refused (empty)", () => {
  const bad = [
    "https://tile.openstreetmap.org/16/33248/24911.png",
    "//otro.test/mapa.png",
    "javascript:alert(1)",
    "assets/../../wp-config.php",
    "../mapa.png",
    "assets/maps/sede.php",
    "/wp-content/uploads/mapa.webp?x=<script>",
    "assets/maps/se de.webp",
  ];
  const out = run(bad);
  bad.forEach((v, i) => assert.equal(out[i], "", JSON.stringify(v)));
});
