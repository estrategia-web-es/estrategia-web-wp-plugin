// NIVEL: 1 — no browser or emulator. Which requests get page_on_front translated (includes/class-wpml-front-page.php), with the machine's PHP.
//
// If there is no `php` in the PATH, the test FAILS (it is not skipped).
//
// Why it exists (Lavandería, 15-10-2026): boot() checked REST_REQUEST, which
// is not defined yet at plugins_loaded, so REST requests got the filter too
// and get_option( 'page_on_front' ) came back as an int there. Lavandería's
// content load fingerprints the front-page options over REST; the type
// changed between two loads and the second one took its own front page for
// an edit made in the dashboard («CONFLICTO portada»).
import { test } from "node:test";
import assert from "node:assert/strict";
import { spawnSync } from "node:child_process";
import path from "node:path";

const r = spawnSync("php", [path.join(import.meta.dirname, "php", "front-page.php")], { encoding: "utf8" });
assert.equal(r.status, 0, "php did not run: " + (r.error?.message || r.stderr || r.stdout));
const out = JSON.parse(r.stdout);

test("on the public side the front page is translated (wpml_object_id)", () => {
  assert.equal(out.public, 120);
});

test("in a REST request the value is left exactly as stored, also when boot() ran before REST_REQUEST existed", () => {
  assert.equal(out.rest, "109");
});

test("no front page: the value is not touched", () => {
  assert.equal(out.none, "0");
});
