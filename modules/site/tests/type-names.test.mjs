// NIVEL: 1 — no browser or emulator. The prefixed names of the types and taxonomies (1.2.0), the migrator's content renaming and the alias for old Queries, with the machine's PHP.
//
// Why it exists (15-10-2026): WordPress.org requires a prefix and WordPress cuts
// a type at 20 characters. The prefix lives in ONE place (Type_Names::PREFIX,
// «eweb_» since 15-10-2026); nothing here hard-codes it: it is read and
// compared against, so the test stays valid if it ever changed.
//
// Seen red (15-10-2026): with Unprefixed_Names::tax_query() not going into
// «include»/«exclude» (the WordPress 6.8 format, already used by the Lavandería
// seed), «alias: a Query saved…» fails.
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

test("migrator: postType and taxQuery of a Query, and NOT the page text", () => {
  assert.ok(out.markup_once.includes(`"postType":"${n("client")}"`));
  assert.ok(out.markup_once.includes(`"taxQuery":{"${n("client_sector")}":[12]}`));
  assert.ok(out.markup_once.includes("<p>Our clients: client, location</p>"), "the text is not touched");
  assert.equal(out.markup_count, 2);
});

test("migrator: idempotent (the second pass changes nothing)", () => {
  assert.equal(out.markup_twice, out.markup_once);
  assert.equal(out.markup_count2, 0);
});

test("migrator: taxQuery with «include» (WordPress 6.8)", () => {
  assert.ok(out.markup_include.includes(`"include":{"${n("client_sector")}":[3,4]}`), out.markup_include);
});

test("migrator: post-terms and navigation-link yes; postType post and a paragraph quoting it, no", () => {
  assert.ok(out.markup_others.includes(`"postType":"post"`));
  assert.ok(out.markup_others.includes(`{"term":"${n("client_sector")}"}`));
  assert.ok(out.markup_others.includes(`"type":"${n("location")}"`));
  assert.ok(out.markup_others.includes(`<p>"postType":"client"</p>`), "a paragraph is not a Query block");
  assert.equal(out.markup_others_n, 2);
});

test("alias: a Query saved with the old names asks for the new ones", () => {
  assert.deepEqual(out.alias_query, { postType: n("client"), taxQuery: { [n("client_sector")]: [12] } });
  assert.deepEqual(out.alias_include, { postType: n("location"), taxQuery: { include: { [n("client_sector")]: [3] } } });
  assert.deepEqual(out.alias_new, { postType: n("client") });
  assert.deepEqual(out.alias_post, { postType: "post" });
  assert.equal(out.alias_terms.attrs.term, n("client_sector"));
});
