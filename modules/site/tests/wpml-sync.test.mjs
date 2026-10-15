// NIVEL: 1 — no browser or emulator. That a «copy» field from wpml-config.xml written on the ORIGINAL location, client or testimonial asks WPML for the copy (wpml_sync_custom_field), with the machine's PHP and a WPML double.
//
// Why it exists (14-10-2026): with real WPML 5.1, changing the location's phone
// in the block editor panel left the English location with the old phone.
// WPML copies on save_post (priority 100) only the metas that changed BEFORE
// in that same request, and the REST API saves the metas AFTER save_post.
// The real test, with WPML and the editor, was done on local Lavandería
// (see the header of class-meta-panel.php); this is the one that runs on
// every commit, because Playground has no WPML.
//
// If there is no `php` in the PATH, the test FAILS (it is not skipped). On this
// machine it is in C:\wamp64\bin\php\php8.3.6.
//
// Seen red (14-10-2026): without the add_action of sync_copy_field in
// Meta_Panel::boot(), «phone of the original location» fails with [] instead of
// the requested copy.
import { test } from "node:test";
import assert from "node:assert/strict";
import { spawnSync } from "node:child_process";
import path from "node:path";

const run = (mode) => {
  const r = spawnSync("php", [path.join(import.meta.dirname, "php", "wpml-sync.php"), mode], { encoding: "utf8" });
  assert.equal(r.status, 0, "php did not run: " + (r.error?.message || r.stderr || r.stdout));
  return JSON.parse(r.stdout);
};
const wpml = run("wpml");
const without = run("no-wpml");
const PHONE = "estrategia_web_location_phone";

test("phone of the original location: the copy is requested once (on creation and on change) and the translation receives it", () => {
  assert.deepEqual(wpml.phone_new, [`10:${PHONE}`]);
  assert.deepEqual(wpml.phone_changed, [`10:${PHONE}`], "a single copy: the write on the translation does not request it again");
  assert.equal(wpml.phone_in_en, "+34 600 000 002");
  assert.deepEqual(wpml.map_embed, ["10:estrategia_web_location_map_embed"]);
});

test("deleting the phone on the original is copied too", () => {
  assert.deepEqual(wpml.phone_deleted, [`10:${PHONE}`]);
  assert.equal(wpml.phone_en_deleted, false, "the translation ends up without a phone");
});

test("client and testimonial: their «copy» fields too", () => {
  assert.deepEqual(wpml.client_url, ["20:client_url"]);
  assert.deepEqual(wpml.client_fit, ["20:estrategia_web_client_image_fit"]);
  assert.deepEqual(wpml.testimonial_stars, ["30:estrategia_web_testimonial_rating"]);
});

test("no copy is requested: «translate» fields, writes on the translation, other types", () => {
  assert.deepEqual(wpml.address_translate, [], "the address is translated");
  assert.deepEqual(wpml.client_business, [], "the business type is translated");
  assert.deepEqual(wpml.testimonial_role, [], "the role is translated");
  assert.deepEqual(wpml.on_translation, [], "on the translation nothing is copied anywhere");
  assert.equal(wpml.phone_after_trans, "+34 600 000 002", "and the original is not touched");
  assert.deepEqual(wpml.page_same_key, [], "a page with the same key is not this module's business");
});

test("untranslated location: it is requested (WPML does nothing if there are no translations)", () => {
  assert.deepEqual(wpml.untranslated, [`50:${PHONE}`]);
});

test("without WPML nothing is requested", () => {
  for (const [key, value] of Object.entries(without)) {
    if (Array.isArray(value)) {
      assert.deepEqual(value, [], key);
    }
  }
});
