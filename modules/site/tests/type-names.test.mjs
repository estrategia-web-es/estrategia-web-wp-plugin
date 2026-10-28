// NIVEL: 1 — no browser or emulator. The prefixed names of the types and taxonomies (1.2.0), with the machine's PHP.
//
// Why it exists (15-10-2026): WordPress.org requires a prefix and WordPress cuts
// a type at 20 characters. The prefix lives in ONE place (Type_Names::PREFIX,
// «eweb_» since 15-10-2026); nothing here hard-codes it: it is read and
// compared against, so the test stays valid if it ever changed.
//
// Until 2.0.1 it also tested the 1.2.0 migrator's content renaming and the
// alias for Queries saved with the unprefixed names: both removed in 2.0.1
// (CHANGELOG), with their cases.
import { test } from "node:test";
import assert from "node:assert/strict";
import { spawnSync } from "node:child_process";
import path from "node:path";

const r = spawnSync("php", [path.join(import.meta.dirname, "php", "prefix.php")], { encoding: "utf8" });
assert.equal(r.status, 0, "php did not run: " + (r.error?.message || r.stderr || r.stdout));
const out = JSON.parse(r.stdout);
const P = out.prefix;
const n = (key) => P + key;

test("the prefix fits: type ≤ 20 characters and taxonomy ≤ 32 (WordPress limits)", () => {
  assert.ok(P.length > 0 && P.length <= 9, `prefix «${P}»: between 1 and 9 characters`);
  assert.match(P, /^[a-z][a-z0-9_]*$/, "lowercase, digits and underscore");
  for (const [, name] of Object.entries(out.renamed_types)) assert.ok(name.length <= 20, `${name} exceeds 20`);
  for (const [, name] of Object.entries(out.renamed_taxes)) assert.ok(name.length <= 32, `${name} exceeds 32`);
});

test("the keys are the option's: Type_Names::KEYS = Settings::REWRITE_NAMES, and the types = Settings::TYPES", () => {
  assert.deepEqual([...out.keys].sort(), [...out.rewrite_names].sort());
  assert.deepEqual(Object.keys(out.renamed_types).sort(), [...out.types].sort());
});

test("name() and key() round-trip; what is not ours comes out unchanged or empty", () => {
  assert.equal(out.name_client, n("client"));
  assert.equal(out.name_page, "page");
  assert.equal(out.key_back, "client_sector");
  assert.equal(out.key_foreign, "", "«client» without prefix is not a registered name of ours");
});
