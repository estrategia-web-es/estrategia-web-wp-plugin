// NIVEL: 1 — no browser or emulator. The three metas that got their prefix (client_url → estrategia_web_client_url…): the map, the read alias, the bindings alias and the migrator's content renaming, with the machine's PHP.
//
// Why it exists (17-10-2026, David: «los prefijos son obligatorios para
// WordPress»): WordPress.org requires a prefix, and three metas from before
// 1.0.0 had none. The map lives in ONE place (Unprefixed_Meta::MAP); a theme
// that still reads the old key, or a binding saved with it, must keep showing
// the same until 2.0.0. The migration itself, with a database, is
// meta-migration.test.mjs (level 2); the rehearsal with WPML and real content,
// lavanderia-mallorca (despliegue/prefijo-metas.php).
//
// Seen red (17-10-2026): with only the {"source":…,"args":…} pattern in
// rename_markup, «the bindings in the content, in both orders» fails; and
// with read() not checking whether the new row exists, «with both rows the
// NEW one wins» fails (the new key answered with the old value).
import { test } from "node:test";
import assert from "node:assert/strict";
import { spawnSync } from "node:child_process";
import fs from "node:fs";
import path from "node:path";

const r = spawnSync("php", [path.join(import.meta.dirname, "php", "meta-prefix.php")], { encoding: "utf8" });
assert.equal(r.status, 0, "php did not run: " + (r.error?.message || r.stderr || r.stdout));
const out = JSON.parse(r.stdout);
const types = fs.readFileSync(path.join(import.meta.dirname, "..", "includes", "class-content-types.php"), "utf8");

test("the map: the three old keys, each to estrategia_web_<old>, and Content_Types registers the new ones", () => {
  assert.deepEqual(Object.keys(out.map).sort(), ["client_url", "testimonial_client", "testimonial_role"]);
  for (const [old, now] of Object.entries(out.map)) {
    assert.equal(now, "estrategia_web_" + old);
    assert.match(types, new RegExp(`'${now}'\\s*=>\\s*array\\(`), `${now} is not registered`);
    assert.doesNotMatch(types, new RegExp(`'${old}'\\s*=>\\s*array\\(`), `${old} is still registered`);
  }
  assert.deepEqual(Object.keys(out.types).sort(), Object.keys(out.map).sort(), "each old key says its type");
  assert.ok(out.post_types.includes("testimonial") && out.post_types.length === 2, "new and pre-1.2.0 type names: " + out.post_types);
});

test("alias: the old key reads the new row (single and all values)", () => {
  assert.deepEqual(out.old_reads_new, ["https://new.test"]);
  assert.deepEqual(out.old_reads_new_all, ["https://new.test"]);
  assert.deepEqual(out.testimonial_client, ["7"]);
  assert.deepEqual(out.old_type_name, ["https://six.test"], "also on a post still with the pre-1.2.0 type name");
});

test("alias: until migrated, the new key reads the old row; the old key alone is left to core", () => {
  assert.deepEqual(out.new_reads_old, ["https://old.test"]);
  assert.equal(out.old_when_old, null);
  assert.deepEqual(out.testimonial_role, ["Gerente"]);
});

test("alias: with both rows the NEW one wins for both keys", () => {
  assert.deepEqual(out.both_old, ["https://new.test"]);
  assert.equal(out.both_new, null, "the new row exists: core reads it");
});

test("alias: other types, other keys, all keys and an earlier answer are not touched", () => {
  assert.equal(out.page_old, null, "a page's client_url belongs to someone else");
  assert.equal(out.page_new, null);
  assert.equal(out.unrelated, null);
  assert.equal(out.all_keys, null);
  assert.equal(out.answered_before, "x");
});

test("bindings alias: only core/post-meta with an old key", () => {
  assert.equal(out.bindings.content.args.key, "estrategia_web_client_url");
  assert.equal(out.bindings.url.args.key, "client_url", "another source's key is its own");
  assert.equal(out.bindings.text.args.key, "estrategia_web_testimonial_author");
  assert.equal(out.block_data.attrs.metadata.bindings.content.args.key, "estrategia_web_testimonial_client");
  assert.deepEqual(out.block_plain.attrs, []);
});

test("migrator: the bindings in the content, in both orders; idempotent", () => {
  assert.equal(out.markup_count, 3);
  assert.match(out.markup_once, /"source":"core\/post-meta","args":\{"key":"estrategia_web_client_url"\}/);
  assert.match(out.markup_once, /"url":\{"args":\{"key":"estrategia_web_client_url"\},"source":"core\/post-meta"\}/);
  assert.match(out.markup_once, /"args":\{"key":"estrategia_web_testimonial_role"\}/);
  assert.doesNotMatch(out.markup_once, /"key":"(client_url|testimonial_role)"/);
  assert.equal(out.markup_twice, out.markup_once);
  assert.equal(out.markup_count2, 0);
});

test("migrator: another source, the text of a paragraph and an already new key are not touched", () => {
  assert.equal(out.markup_others, out.others_in);
  assert.equal(out.markup_others_n, 0);
});
