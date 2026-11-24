// NIVEL: 1 — no browser, no emulator. The classic meta box's save (Fields_Panel::save_meta_box(), includes/class-fields-panel.php) keeps a backslash in every field type, not only in the repeater: $_POST arrives slashed, Registrar::posted() unslashes it, and update_post_meta() unslashes AGAIN, so what is stored must go through wp_slash(). With the machine's PHP, the real Loader reading examples/ and the shims of tests/php/meta-box.php.
//
// If there is no `php` on the PATH the test FAILS (it is not skipped).
//
// Seen red (24-11-2026): with save_meta_box() writing the non-repeater fields
// with update_post_meta( …, $value ) as before 2.14.0 (no wp_slash()),
// «text fields keep their backslashes» failed («C:x» stored for «C:\x»);
// restored, green.
import { test } from "node:test";
import assert from "node:assert/strict";
import { spawnSync } from "node:child_process";
import path from "node:path";

const save = (type, post) => {
  const r = spawnSync("php", [path.join(import.meta.dirname, "php", "meta-box.php")], { encoding: "utf8", input: JSON.stringify({ type, post }) });
  assert.equal(r.status, 0, "php did not run: " + (r.error?.message || r.stderr || r.stdout));
  return JSON.parse(r.stdout);
};

test("text fields keep their backslashes (textarea, url, select)", () => {
  const got = save("gment_restaurant", {
    gment_address: 'Carrer "Major" 1\\2\nC:\\x',
    gment_website: "https://example.test/a\\b",
    gment_price_range: "€€",
  });
  assert.equal(got.gment_address, 'Carrer "Major" 1\\2\nC:\\x');
  assert.equal(got.gment_website, "https://example.test/a\\b");
  assert.equal(got.gment_price_range, "€€");
});

test("the repeater still stores valid JSON with its quotes", () => {
  const got = save("llest_esquela", { llest_actes: { 0: { tipus: "Vetlla", lloc: 'Tanatori "Son Valentí" C:\\x' } } });
  assert.deepEqual(JSON.parse(got.llest_actes), [{ tipus: "Vetlla", lloc: 'Tanatori "Son Valentí" C:\\x' }]);
});
