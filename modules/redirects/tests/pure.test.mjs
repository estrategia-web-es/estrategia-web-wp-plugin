// NIVEL: 1 — no browser or emulator. The pure part of the redirects module (includes/class-rules.php) with the machine's PHP: normalise, match, read Lavandería's REAL CSV (83 rules) and validate loops, duplicates and targets.
//
// If there is no `php` on the PATH, the test FAILS (it is not skipped).
//
// Seen red (14-10-2026): without splitting on «?» again after decoding
// (step 3 of class-rules.php), «the %3F of old URLs and a literal ? are the
// same key» fails with /en/%3Fp=30671 versus /en/?p=30671.
// Careful: with /index.html%3Fp=6981.html it does NOT fail, because the
// unsplit key matches the split one letter by letter; hence the cases with
// a trailing slash and with page_id, which are the ones that give it away.
// Seen red (28-10-2026), the Conflicts::detect() case: with Rank Math counted
// without its redirects module («Rank Math without its redirects module is
// not a conflict»).
import { test } from "node:test";
import assert from "node:assert/strict";
import { spawnSync } from "node:child_process";
import path from "node:path";

const r = spawnSync("php", [path.join(import.meta.dirname, "php", "pure.php")], { encoding: "utf8" });
assert.equal(r.status, 0, "php did not run: " + (r.error?.message || r.stderr || r.stdout));
const out = JSON.parse(r.stdout);

test("normalise: no domain, no trailing slash, lower case, %xx decoded", () => {
  const k = out.key;
  assert.equal(k["/Contacto/"], "/contacto");
  assert.equal(k["https://www.ejemplo.com/contacto/"], "/contacto");
  assert.equal(k["/contacto/#form"], "/contacto");
  assert.equal(k["/a//b/"], "/a/b");
  assert.equal(k["/caf%C3%A9/"], "/café");
  assert.equal(k[""], "", "an empty source is not a key");
  assert.equal(out.key_base, "/viejo", "WordPress in a subfolder: the base is stripped");
});

test("the %3F of old URLs and a literal ? are the same key; page_id is p", () => {
  assert.equal(out.key["/index.html%3Fp=6981.html"], out.key["/index.html?p=6981.html"]);
  assert.equal(out.key["/?page_id=5"], out.key["/?p=5"]);
  assert.equal(out.key["/en/%3Fp=30671"], out.key["/en/?p=30671"], "the slash before %3F, as before ?");
  assert.equal(out.key["/index.html%3Fpage_id=6981"], out.key["/index.html?p=6981"], "page_id = p also after a %3F");
  assert.equal(out.cand_3f[0], out.key["/index.html%3Fp=6981.html"], "the request with %3F looks up its exact key first");
});

test("query that is not part of the source is ignored when matching", () => {
  assert.ok(out.cand_utm.includes("/contacto"), "/contacto/?utm_source=… matches /contacto/");
  assert.ok(out.cand_p_lang.includes("/?p=6981"), "/?p=6981&lang=es matches /?p=6981");
  assert.equal(out.cand_p_lang.indexOf("/?p=6981"), 1, "p comes right after the whole query");
  assert.equal(out.cand_p_lang.at(-1), "/", "the bare path, last");
  assert.ok(out.cand_long <= 13, "a 200-parameter query does not produce 200 keys: " + out.cand_long);
});

test("Lavandería's real CSV is read in full and validates without a single error", () => {
  const c = out.csv;
  assert.equal(c.rows, 83);
  assert.deepEqual(c.errors, []);
  assert.equal(c.valid, 83);
  assert.deepEqual(c.types, { 301: 69, 410: 14 });
  assert.equal(c.self_match, 83, "each source, requested as is, matches its rule");
  assert.equal(c.inactive, 0, "none has «revisar»: all active");
  assert.deepEqual(c.first, { line: 2, source: "/index.html", target: "/", type: "301", note: "Portada (variante de /)", active: true });
  assert.match(c.quoted, /hoteles, barcos, restaurantes/, "quotes with commas inside");
  assert.equal(c.empty_410, "");
});

test("validate: loops, target equal to source, duplicates, targets and types", () => {
  const v = out.validate;
  assert.equal(v.loop2, "loop", "A→B and B→A");
  assert.equal(v.loop3, "loop", "A→B→C and C→A");
  assert.equal(v.loop_abs, "loop", "the loop with an absolute URL of the site itself");
  assert.equal(v.loop_inactive, "loop", "a disabled rule counts too: enabling it in bulk doesn't go through here");
  assert.equal(v.chain_ok, "", "a chain without a loop is allowed");
  assert.equal(v.same, "same_target");
  assert.equal(v.same_abs, "same_target");
  assert.equal(v.external_ok, "");
  assert.equal(v.duplicate, "duplicate", "/A/ and /a are the same source");
  assert.equal(v.dup_3f, "duplicate", "%3F and ? are the same source");
  assert.equal(v.empty_target, "empty_target");
  assert.equal(v.bad_target, "bad_target");
  assert.equal(v.bad_target_js, "bad_target");
  assert.equal(v.bad_type, "bad_type");
  assert.equal(v.default_type, 301, "no type, 301");
  assert.equal(v.home, "home_source");
  assert.equal(v.home_p_ok, "", "/?p=ID is allowed");
  assert.equal(v.foreign_source, "foreign_source");
  assert.equal(v.gone_target, "", "a 410 stores no target");
});

// 2.0.1: Rank Math (Redirections on) and Yoast Premium OWN the redirects (the module steps
// aside); the rest are conflicts (a warning on the screen). Seen red (28-10-2026) with owner()
// reading the option without checking that Rank Math is loaded («owner_rank_math_absent»).
test("who owns the redirects (Conflicts::owner) and what is only a conflict (Conflicts::detect)", () => {
  assert.deepEqual(out.owner_none, ["", []]);
  assert.deepEqual(out.owner_rank_math_absent, ["", []], "rank_math_modules left by an uninstalled Rank Math: nobody");
  assert.deepEqual(out.owner_rank_math_off, ["", []], "Rank Math with its Redirections module off: ours works");
  assert.deepEqual(out.owner_rank_math, ["Rank Math", []], "Rank Math owns them: not a conflict any more");
  assert.deepEqual(out.owner_rank_math_and_redirection, ["Rank Math", ["Redirection"]]);
  assert.deepEqual(out.owner_yoast_premium, ["Yoast SEO Premium", ["Redirection"]], "Yoast Premium: no off switch, it owns them");
});

test("CSV with semicolons, BOM, a «revisar» row (comes in disabled) and no header", () => {
  assert.equal(out.semi.length, 2, "the empty line doesn't count");
  assert.deepEqual(out.semi[0], { line: 2, source: "/a/", target: "/b/", type: "302", note: "=SUMA(1) [revisar: dudoso]", active: false });
  assert.equal(out.semi[1].type, "410");
  assert.deepEqual(out.noheader[0], { line: 1, source: "/a/", target: "/b/", type: "301", note: "nota", active: true });
  assert.deepEqual(out.cell, ["'=1+1", "/a/", "'-x"], "the exported CSV carries no formulas for Excel");
});
