// NIVEL: 1 — no browser or emulator. The module loader (includes/class-modules.php) with the machine's PHP: that it reads the header of ALL folders in modules/ and what it loads depending on the saved option.
//
// If there is no `php` in the PATH, the test FAILS (it is not skipped). On
// this machine it is in C:\wamp64\bin\php\php8.3.6.
//
// Seen in red (14-10-2026): with the header of modules/webp/module.php
// changed to «Modul Name:», «every folder in modules/ is a module» fails
// saying that webp is not read.
import { test } from "node:test";
import assert from "node:assert/strict";
import { spawnSync } from "node:child_process";
import path from "node:path";

const r = spawnSync("php", [path.join(import.meta.dirname, "php", "modules.php")], { encoding: "utf8" });
assert.equal(r.status, 0, "php did not run: " + (r.error?.message || r.stderr || r.stdout));
const out = JSON.parse(r.stdout);

test("every folder in modules/ is a module with a name (a misspelled header cannot vanish silently)", () => {
  assert.deepEqual(Object.keys(out.available).sort(), [...out.folders].sort(), "folders without a readable header: " + out.folders.filter((f) => !out.available[f]).join(", "));
  for (const [slug, m] of Object.entries(out.available)) {
    assert.ok(m.description, `${slug}: no Description`);
    for (const req of m.requires) assert.ok(out.available[req], `${slug} needs «${req}», which does not exist`);
  }
});

test("what Lavandería uses today is on by default and loads with nothing blocked", () => {
  const [loaded, blocked] = out.real_default;
  for (const slug of ["site", "consent", "forms", "carousel", "map", "whatsapp", "language-switcher", "webp", "external-links", "wpml-front-page"]) {
    assert.ok(loaded.includes(slug), `${slug} would not load on a site without the saved option`);
  }
  assert.deepEqual(blocked, []);
});

test("with no saved option the headers' Default values apply", () => {
  assert.deepEqual(out.no_option, ["a", "b", "d"]);
});

test("a module the site already knew and turned off stays off even if it is Default: on", () => {
  assert.deepEqual(out.saved_respected, ["a"]);
});

test("edge case: a module NEW to the site (not in _known) comes in with its Default", () => {
  assert.deepEqual(out.new_default_on, ["a", "b", "d"], "b and d are new and Default: on; c was known and is off");
});

test("saved slugs that no longer exist are ignored", () => {
  assert.deepEqual(out.unknown_dropped, ["a"]);
});

test("without _known (list written by hand), the list rules entirely: no Default is added", () => {
  assert.deepEqual(out.no_known, ["c"]);
});

test("dependencies: whatever needs something that is off is not loaded, and the screen knows what it is missing", () => {
  assert.deepEqual(out.resolve_defaults, [["a", "b"], { d: ["c"] }]);
  assert.deepEqual(out.resolve_chain, [[], { m: ["k"], z: ["m"] }], "z needs m, m needs k (off): both drop");
});

test("what another module needs is loaded first, and a cycle does not hang", () => {
  assert.deepEqual(out.resolve_order[0], ["zeta", "alpha"]);
  assert.deepEqual([...out.resolve_cycle[0]].sort(), ["x", "y"]);
});
