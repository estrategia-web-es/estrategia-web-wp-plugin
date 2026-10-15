// NIVEL: 1 — no browser, no emulator. The WPML config built from the definitions (what goes into WPML's `wpml_config_array`) and the copy on save of «copy» fields, with the machine's PHP, the real Loader reading examples/ and a WPML double.
//
// Why the copy on save (a59bf65, 14-10-2026, generalized here): with WPML 5.1
// the REST API saves the meta AFTER save_post, where WPML copies, so the
// translation kept the old value. The real-WPML check is in the report of
// this module's branch; this is the one that runs on every commit.
//
// If there is no `php` on the PATH the test FAILS (it is not skipped).
//
// Seen red (15-10-2026): without the post-ids attr for relation/image,
// «relation and image with copy» failed; without the add_action of sync in
// Wpml::boot(), «phone of the original» failed with [].
import { test } from "node:test";
import assert from "node:assert/strict";
import { spawnSync } from "node:child_process";
import path from "node:path";

const run = (mode) => {
  const r = spawnSync("php", [path.join(import.meta.dirname, "php", "wpml.php"), mode], { encoding: "utf8" });
  assert.equal(r.status, 0, "php did not run: " + (r.error?.message || r.stderr || r.stdout));
  return JSON.parse(r.stdout);
};
const config = run("config");
const wpml = run("wpml");
const without = run("no-wpml");
const cfg = config.filtered["wpml-config"];
const field = (name) => cfg["custom-fields"]["custom-field"].filter((f) => f.value === name);

test("the example is read from disk without errors", () => {
  assert.deepEqual(config.errors, {});
});

test("fields: the example's copy / translate, and what WPML already had is kept (a single entry becomes a list)", () => {
  assert.deepEqual(field("estrategia_web_location_phone"), [{ value: "estrategia_web_location_phone", attr: { action: "copy" } }]);
  assert.deepEqual(field("gmct_phone"), [{ value: "gmct_phone", attr: { action: "copy" } }], "phone: copy, and only once although gmct_wine declares it as ignore");
  assert.deepEqual(field("gmct_address"), [{ value: "gmct_address", attr: { action: "translate" } }]);
  assert.deepEqual(field("gmct_price_range"), [{ value: "gmct_price_range", attr: { action: "copy" } }]);
  assert.deepEqual(field("gmct_website"), [{ value: "gmct_website", attr: { action: "copy" } }]);
  assert.deepEqual(cfg["gutenberg-blocks"], { "gutenberg-block": [] }, "other sections untouched");
});

test("relation and image with copy: WPML copies the ID of the TRANSLATED post (post-ids)", () => {
  assert.deepEqual(field("gmct_winery")[0].attr, { action: "copy", type: "post-ids", "sub-type": "gmct_restaurant" });
  assert.deepEqual(field("gmct_label")[0].attr, { action: "copy", type: "post-ids", "sub-type": "attachment" });
  assert.deepEqual(field("gmct_pairs")[0].attr, { action: "translate" }, "translate: no post-ids");
});

test("types and taxonomies: translate=1, display-as-translated=2; a referenced taxonomy is not declared twice", () => {
  assert.deepEqual(cfg["custom-types"]["custom-type"], [
    { value: "gmct_restaurant", attr: { translate: "1" } },
    { value: "gmct_wine", attr: { translate: "2" } },
  ]);
  assert.deepEqual(cfg.taxonomies.taxonomy, [{ value: "gmct_cuisine", attr: { translate: "1" } }]);
  assert.equal(config.not_array, "x", "something that is not an array goes back untouched");
});

test("copy on save: a «copy» field written in the ORIGINAL asks WPML once, and the translation gets it", () => {
  assert.deepEqual(wpml.phone_new, ["10:gmct_phone"]);
  assert.deepEqual(wpml.phone_changed, ["10:gmct_phone"], "one copy: the write in the translation does not ask again");
  assert.equal(wpml.phone_in_ca, "971 00 00 02");
  assert.deepEqual(wpml.price, ["10:gmct_price_range"]);
  assert.deepEqual(wpml.website, ["10:gmct_website"]);
  assert.deepEqual(wpml.phone_deleted, ["10:gmct_phone"]);
  assert.equal(wpml.phone_ca_deleted, false, "deleting in the original deletes in the translation");
  assert.deepEqual(wpml.untranslated, ["50:gmct_phone"], "not translated yet: asked anyway (WPML does nothing)");
});

test("no copy: «translate» fields, writes in the translation, other types", () => {
  assert.deepEqual(wpml.address_translate, []);
  assert.deepEqual(wpml.on_translation, []);
  assert.equal(wpml.phone_after_trans, "971 00 00 02", "the original is not touched");
  assert.deepEqual(wpml.page_same_key, [], "a page with the same key is not ours");
});

test("without WPML nothing is asked", () => {
  for (const [key, value] of Object.entries(without)) {
    if (Array.isArray(value)) assert.deepEqual(value, [], key);
  }
});
