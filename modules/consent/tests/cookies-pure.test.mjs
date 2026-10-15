// NIVEL: 1 — no browser or emulator. The cookie registry (suggestions from our own database and from the Open Cookie Database, wildcards, flood limits) and the category aliases, with the machine's PHP.
//
// What the registry is and why: header of includes/class-cookie-registry.php.
// If there is no `php` in the PATH, the test FAILS (it is not skipped), like the site one.
//
// Seen failing on 14-10-2026 with three mutants: match_key with the SHORTEST
// prefix (_g* wins over _ga_*), _pk_id.* removed from base() («Piwik» comes
// from the external database) and name_ok without excluding «=» («x=secret»
// would be stored).
//
// How to maintain: a new case, its key in tests/php/pure.php and its assert here.
import { test } from "node:test";
import assert from "node:assert/strict";
import { spawnSync } from "node:child_process";
import path from "node:path";

const r = spawnSync("php", [path.join(import.meta.dirname, "php", "pure.php")], { encoding: "utf8" });
assert.equal(r.status, 0, "php did not run: " + (r.error?.message || r.stderr));
const out = JSON.parse(r.stdout);

test("cookies: the exact name beats any pattern; among patterns, the longest prefix", () => {
  assert.equal(out.match.exact, "_ga");
  assert.equal(out.match.prefix, "_ga_*");
  assert.equal(out.match.longest, "wp-settings-time-*");
  assert.equal(out.match.short, "_g*");
  assert.equal(out.match.none, null);
});

test("cookies: wp-wpml_current_language (set BEFORE consent) is NECESSARY, from WPML, 1 day", () => {
  const s = out.suggest["wp-wpml_current_language"];
  assert.equal(s.category, "necessary");
  assert.equal(s.provider, "WPML");
  assert.equal(s.duration, "1 day");
  assert.equal(s.suggested, "base", "decided by our own list, not by the external database");
});

test("cookies: Google Analytics from the Open Cookie Database, with _ga_<id> as the _ga_* pattern", () => {
  assert.equal(out.suggest._ga.key, "_ga");
  assert.equal(out.suggest._ga.category, "statistics");
  assert.equal(out.suggest._ga_ABC123.key, "_ga_*");
  assert.equal(out.suggest._ga_ABC123.provider, "Google Analytics");
  assert.equal(out.suggest._gcl_au.category, "marketing");
  assert.equal(out.suggest.inventada_xyz, null);
});

test("cookies: _pk_id.<site>.<id> is MATOMO's (our own database), not Piwik PRO's by longest prefix", () => {
  const s = out.suggest["_pk_id.87.a1b2"];
  assert.equal(s.key, "_pk_id.*");
  assert.equal(s.provider, "Matomo");
  assert.equal(s.category, "statistics");
});

test("cookies: the notice's own cookie is necessary", () => {
  assert.equal(out.suggest.estrategia_web_consent.category, "necessary");
});

test("cookies: only names with cookie characters (no values, no spaces, no HTML, ≤ 128)", () => {
  assert.deepEqual(out.name_ok, { normal: true, pattern: true, value: false, space: false, long: false, html: false });
});

test("cookies: the detector groups _ga_ABC123 and _ga_ZZZ999 under _ga_*, updates «seen» and does NOT store values", () => {
  assert.deepEqual(Object.keys(out.recorded).sort(), ["_ga", "_ga_*", "wp-wpml_current_language"]);
  assert.equal(out.recorded["_ga_*"].example, "_ga_ABC123");
  assert.equal(out.recorded["_ga_*"].first_seen, 1000);
  assert.equal(out.recorded["_ga_*"].last_seen, 2000);
  assert.equal(out.recorded["wp-wpml_current_language"].last_seen, 1000);
  assert.ok(!JSON.stringify(out.recorded).includes("secret"), "a «name=value» does not get in");
  for (const entry of Object.values(out.recorded)) assert.equal(entry.status, "pending", "what is detected waits for the administrator to confirm it");
});

test("cookies: the registry does not go beyond MAX_ENTRIES even if more names arrive", () => {
  assert.equal(out.full_count, 300);
  assert.equal(out.full.added, 300);
});

test("cookies: by hand, empty fields are filled from the suggestion and what was written is kept; a bad name is an error", () => {
  assert.equal(out.save_manual.category, "maps");
  assert.equal(out.save_manual.purpose, "Google Maps");
  assert.equal(out.save_manual.provider, "Google", "provider from the suggestion");
  assert.equal(out.save_manual.source, "manual");
  assert.equal(out.save_bad, true);
});

test("cookies: durations that translate by themselves, and free text", () => {
  assert.deepEqual(out.duration.years, [2, "year"]);
  assert.deepEqual(out.duration.one, [1, "day"]);
  assert.deepEqual(out.duration.session, [0, "session"]);
  assert.equal(out.duration.free, null);
});

test("consent 1.1.0: category aliases and display order WITHOUT touching the revision order", () => {
  assert.deepEqual(out.normalize, ["statistics", "marketing", "marketing", "maps"]);
  assert.deepEqual(out.display, ["preferences", "statistics", "marketing", "maps"]);
  assert.deepEqual(out.known_order, ["statistics", "maps", "marketing", "preferences"], "preferences goes LAST: the order is part of the revision");
});

test("cookies: the extract carries its license and its attribution (Apache 2.0)", () => {
  assert.equal(out.ocd.license, "Apache-2.0");
  assert.match(out.ocd.attribution, /Open Cookie Database/);
  assert.ok(out.ocd.count > 1000, `the extract has ${out.ocd.count} rows`);
});
