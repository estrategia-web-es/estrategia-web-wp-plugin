// NIVEL: 1 — no browser or emulator. The map from coordinates (blocks/map/coords.php) with the machine's PHP.
//
// What it tests: valid coordinates give the keyless «?output=embed» URL in the page's language; text
// coordinates with a decimal comma are normalised; empty, out of range, non-numeric and «0,0» (WP Geo's
// «never filled in») give '' and therefore no map.
// Real case: Gastronòmicament's restaurants, coordinates in _wp_geo_latitude/_wp_geo_longitude (03-11-2026).
// If there is no `php` in the PATH, the test FAILS (it is not skipped).
import { test } from "node:test";
import assert from "node:assert/strict";
import { spawnSync } from "node:child_process";
import path from "node:path";

const file = path.join(import.meta.dirname, "..", "blocks", "map", "coords.php").replace(/\\/g, "/");
const php = (lat, lng, lang = "ca") => {
  const code = `define('ABSPATH', 1);
require '${file}';
echo json_encode(estrategia_web_map_coords_embed(${JSON.stringify(lat)}, ${JSON.stringify(lng)}, ${JSON.stringify(lang)}));`;
  const r = spawnSync("php", ["-r", code], { encoding: "utf8" });
  assert.equal(r.status, 0, "php did not run: " + (r.error?.message || r.stderr));
  return JSON.parse(r.stdout);
};

test("valid coordinates give the keyless embed, rounded to 6 decimals, in the page's language", () => {
  assert.equal(php("39.58287017383472", "2.6339614391326904"), "https://www.google.com/maps?q=39.58287,2.633961&hl=ca&z=17&output=embed");
  assert.equal(php("-33.8", "151.2", "en"), "https://www.google.com/maps?q=-33.8,151.2&hl=en&z=17&output=embed");
});

test("a decimal comma is accepted; a strange language falls back to en", () => {
  assert.equal(php("39,57", "2,65", "ca_ES"), "https://www.google.com/maps?q=39.57,2.65&hl=en&z=17&output=embed");
});

test("no map for empty, out of range, non-numeric or 0,0", () => {
  for (const [lat, lng] of [["", ""], ["39.5", ""], ["91", "2"], ["39", "181"], ["39.5; DROP", "2"], ["0", "0"], ["0.0", "0.000"]]) {
    assert.equal(php(lat, lng), "", `${lat} / ${lng}`);
  }
});
