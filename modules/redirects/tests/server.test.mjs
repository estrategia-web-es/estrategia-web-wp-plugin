// NIVEL: 2 — Playground WordPress. NOT LEVEL 1 BECAUSE: what is tested is what WordPress answers to a real request (404 → 301/410, the URL as WordPress hands it over), with the table, the counter and the 404 log in its database.
//
// Imports Lavandería's REAL CSV (83 rules) and requests sources of every
// SHAPE as is (a plain path, %3F in the path, ?p= on the home page, under
// /feed/, a path with a query; ?page_id= went in round 2: it is the same
// WordPress query shape as ?p=, and «page_id is p» is pure.test.mjs's). That each of the 83 sources
// matches its rule, and how a source becomes a key (%3F, page_id, case,
// trailing slash, extra query), is level 1 (pure.test.mjs); here, that
// requests of those shapes reach the rule through WordPress.
// It also measures the cost: queries to the module's tables on a page that
// exists (zero) and on a 404, and the time of one lookup.
//
// Requests (28-10-2026, 135 s → see the runner): the redirects, the 410 and
// the hits are requested at once (Promise.all: they only read, or add to a
// counter with hits = hits + 1); the per-request query counts are read
// ONCE per test (/requests: a row per public request, so requests made at
// once can be counted too); the dry run, «off by default» and «off records
// nothing» no longer take a request of their own; «importing twice doesn't
// duplicate» is admin.test.mjs's (it re-imports the same CSV through the
// screen: «83 sin cambios»); the bench seeds 500 rows per INSERT and
// measures only the 5000-rule table (the 0-rule one was only printed); the
// URLs that only differ in their KEY (upper case, no trailing slash, a
// second %3F, utm) are level 1's. The module's reset (tables, the /about/
// page) stays in after(): the core reset() does not undo them.
//
// Seen red (14-10-2026): with Runtime not checking is_404() (querying on
// every request), «a page that exists doesn't touch the table» fails: the
// CSV's /about/ rule swallows the live /about/ page with a 301.
// Round 2 (28-10-2026): before() is ONE request (the module's /reset with {core, on, about});
// the dry run and the import are one (/import {dry_first}). Seen red with the module's header
// at «Default: on» («off by default…») and with the dry run writing («if the dry run had
// written, these would be «unchanged»»). The two lines of «requests and rules» run at the same
// time; seen red there with the hit counter not counting and the validation expecting «mutated».
import { test, describe, before, after } from "node:test";
import assert from "node:assert/strict";
import { BASE, CSV, api, get } from "./lib.mjs";

/** The query counts of the public requests of the given URIs, from the last `k` (oldest first). */
// The LAST row of each URI (round 2: tests run at the same time, so «the last k rows» no longer
// works; every URI a test reads here is requested by that test only).
const counts = async () => Object.fromEntries((await api("/requests")).map((r) => [r.uri, r]));
let bench;

before(async () => {
  // ONE request (round 2): the core reset, the module's tables empty, the module on for the
  // next requests, and a LIVE /about/ page (/about/ has a rule in the CSV → /quienes-somos/;
  // the module's reset deletes it). This /reset request reads the modules list as not saved:
  // whether it loaded the module is «off by default».
  const r = await api("/reset", { method: "POST", body: { core: true, on: true, about: true } });
  assert.ok(r.available.includes("redirects"));
  assert.equal(r.loaded, false, "off by default: a site without the saved option doesn't load it");
});
after(() => api("/reset", { method: "POST" }));

test("Lavandería's CSV goes in whole; --dry-run doesn't write", async () => {
  // The dry run and then the real import, in one request (the route runs Importer::import twice).
  const { dry, real: first } = await api("/import", { method: "POST", body: { csv: CSV, dry_first: true } });
  assert.deepEqual([dry.created, dry.updated, dry.unchanged, dry.errors.length], [83, 0, 0, 0]);
  assert.deepEqual(first.errors, []);
  assert.deepEqual([first.created, first.updated, first.unchanged], [83, 0, 0], "if the dry run had written, these would be «unchanged»");
});

// Two independent lines at the same time (round 2): the requests of every shape and the rules
// saved as the screen does. Neither reads what the other writes (the new rules are not among the
// URLs requested, and the query counts are read per URI); the 404 log stays OFF for both.
describe("requests and rules", { concurrency: 2 }, () => {
test("the CSV sources redirect, whatever their shape (%3F, ?p, /feed/); a 410 returns 410 and the theme's «not found» page; the hit is counted; a page that exists doesn't touch the table and a 404 makes one query", async () => {
  const redirects = [
    ["/contact/", "/contacto/"],
    ["/index.html%3Fp=6981.html", "/quienes-somos/"],
    ["/?p=6981", "/quienes-somos/"],
    ["/feed/index.html", "/feed/"],
  ];
  const others = ["/lavanderia-santa-ponsa/", "/about/", "/esto-no-existe/"];
  const answers = await Promise.all([...redirects.map(([from]) => get(from)), ...others.map(get)]);
  redirects.forEach(([from, to], i) => {
    const r = answers[i];
    assert.equal(r.status, 301, from);
    assert.equal(r.location, BASE + to, from);
    assert.equal(r.by, "Estrategia Web", from);
  });
  const [gone, live, missing] = answers.slice(redirects.length);
  assert.equal(gone.status, 410, "a 410 rule");
  assert.equal(gone.location, null);
  assert.match(gone.text, /<html/i, "the site is rendered");
  assert.ok(!/Fatal error|Warning:|Notice:/.test(gone.text));
  assert.equal(live.status, 200, "the live page beats the /about/ rule (rules only act on 404s)");
  assert.equal(missing.status, 404);

  const [rules, seen] = await Promise.all([api("/rules"), counts()]);
  const rule = rules.find((r) => r.source === "/contact/");
  assert.equal(Number(rule.hits), 1, "hit counter");
  assert.match(rule.last_hit, /^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/);
  assert.equal(seen["/about/"].queries, 0, "zero queries to the module's tables on a page that exists");
  assert.equal(seen["/esto-no-existe/"].queries, 1, "a 404 with no rule: one query (404 log off)");
  console.log(`  · existing page: ${seen["/about/"].total} queries in total, 0 from the module`);
  console.log(`  · 404 with no rule: ${seen["/esto-no-existe/"].total} queries in total, 1 from the module`);
});

test("validation through the same path as the screen: loop, same target, duplicate", async () => {
  const answers = await Promise.all([
    api("/rule", { method: "POST", body: { source: "/quienes-somos/", target: "/nosotros/" } }),
    api("/rule", { method: "POST", body: { source: "/x/", target: "/X" } }),
    api("/rule", { method: "POST", body: { source: "/Index.html/", target: "/otra/" } }),
    api("/rule", { method: "POST", body: { source: "/temporal/", target: "https://ejemplo.org/oferta", type: 302 } }),
  ]);
  assert.deepEqual(answers.map((a) => a.error), ["loop", "same_target", "duplicate", ""], "/nosotros/ → /quienes-somos/ already exists; /x/ is /X; /index.html already exists");
  const r = await get("/temporal/?utm_campaign=x");
  assert.equal(r.status, 302);
  assert.equal(r.location, "https://ejemplo.org/oferta", "external target, without the request's query");
});
});

test("404 log: off records nothing; on records the path without the query or personal data; the purge empties it", async () => {
  // Off: the earlier /esto-no-existe/ was a 404 with the log off; the log below does not have it.
  await api("/settings", { method: "POST", body: { log_404: true, log_days: 30 } });
  // With the bench (its own test below) at the same time: 5000 filler rules /relleno-N/ that no
  // request here matches, and nothing here adds rules (so the count it seeds up to is exact).
  [, , bench] = await Promise.all([get("/ruta-perdida/?email=ana@ejemplo.test"), get("/vieja.html%3Fp=99999.html"), api("/bench", { method: "POST", body: { n: 300, rules: 5000 } })]);
  await get("/Ruta-Perdida"); // The same path again: after the first, or the two would race to insert it.
  const [log, seen] = await Promise.all([api("/log"), counts()]);
  assert.deepEqual(log.map((r) => [r.path, Number(r.hits)]).sort(), [["/ruta-perdida", 2], ["/vieja.html?p=99999.html", 1]]);
  assert.ok(!JSON.stringify(log).includes("ana@"), "no trace of the query");
  assert.ok(!Object.keys(log[0]).some((k) => /ip|agent|referer/i.test(k)), "no IP or browser columns");
  assert.equal(seen["/ruta-perdida/?email=ana@ejemplo.test"].queries, 4, "NEW 404 with no rule and log on: look up rule + update (0 rows) + count the cap + insert");
  assert.equal(seen["/Ruta-Perdida"].queries, 2, "the same 404 again: look up rule + update");

  const p = await api("/purge", { method: "POST", body: { backdate: true } });
  assert.equal(p.scheduled, true, "the daily purge is scheduled");
  assert.equal(p.left, 0, "the purge empties what is older than log_days");
});

test("the cost of a lookup (recorded, not enforced… except the indexed limit)", () => {
  const big = bench; // Measured during the 404 log test.
  console.log(`  · ${big.rules} rules: ${big.ms_each} ms/lookup (${big.db_engine})`);
  assert.equal(big.rules, 5000);
  assert.ok(big.ms_each < 50, "an indexed lookup doesn't depend on the number of rules");
});

test("switching the module off stops redirecting and doesn't delete the rules", async () => {
  await api("/setup", { method: "POST", body: { on: false } });
  const [r, rules] = await Promise.all([get("/index.html%3Fp=6981.html"), api("/rules?count=1")]);
  assert.equal(r.status, 404);
  assert.equal(rules.count, 5000, "the CSV's 83, /temporal/ and the bench's: none deleted");
});
