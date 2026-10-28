// NIVEL: 1 — no browser or emulator. The text conversion of the map block (blocks/map/text.php) with the machine's PHP.
//
// What it tests: an address that arrives as «line<br>\nline» (how the location
// source of 2.0.0 derives it) becomes ONE line break per <br>, not two; and the
// older forms («<br>», «<br />», no newline) still work. Real case: Lavandería's
// Maioris address after migrate-location-data (28-10-2026): blank lines in the
// address box and «Maioris, , Calle…» in the static map's alt.
// Seen RED: with the old pattern '/<br\s*\/?>/i' the first case gives two
// line breaks between the lines.
// If there is no `php` in the PATH, the test FAILS (it is not skipped).
import { test } from "node:test";
import assert from "node:assert/strict";
import { spawnSync } from "node:child_process";
import path from "node:path";

const file = path.join(import.meta.dirname, "..", "blocks", "map", "text.php").replace(/\\/g, "/");
const php = (input) => {
  const code = `define('ABSPATH', 1);
function wp_strip_all_tags($s) { return strip_tags($s); }
require '${file}';
echo json_encode(estrategia_web_map_text(${JSON.stringify(input)}));`;
  const r = spawnSync("php", ["-r", code], { encoding: "utf8" });
  assert.equal(r.status, 0, "php did not run: " + (r.error?.message || r.stderr));
  return JSON.parse(r.stdout);
};

test("an address derived as «line<br>\\nline» keeps ONE break per <br>", () => {
  const input = "Centro Comercial Maioris<br>\nCalle Imperial, 2, Local 12, Urb. Maioris Décima<br>\n07609 Llucmajor, Islas Baleares";
  assert.equal(php(input), "Centro Comercial Maioris\nCalle Imperial, 2, Local 12, Urb. Maioris Décima\n07609 Llucmajor, Islas Baleares");
});

test("the older forms still work: <br>, <br />, \\r\\n, entities", () => {
  assert.equal(php("a<br>b"), "a\nb");
  assert.equal(php("a<br />b"), "a\nb");
  assert.equal(php("a<BR/>\r\nb"), "a\nb");
  assert.equal(php("Caf&eacute; &amp; Co"), "Café & Co");
});
