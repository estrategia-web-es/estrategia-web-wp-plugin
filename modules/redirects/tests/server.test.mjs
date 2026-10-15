// NIVEL: 2 — Playground WordPress, because what is tested is what WordPress answers to a real request (404 → 301/410), with the table, the counter and the 404 log in its database.
//
// Imports Lavandería's REAL CSV (83 rules) and requests its sources as is.
// It also measures the cost: queries to the module's tables on a page that
// exists (zero) and on a 404, and the time of one lookup.
//
// Seen red (14-10-2026): with Runtime not checking is_404() (querying on
// every request), «a page that exists doesn't touch the table» fails: the
// CSV's /about/ rule swallows the live /about/ page with a 301.
import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { BASE, CSV, api, coreApi, reset, get, seedPage } from "./lib.mjs";

before(() => reset());
after(async () => {
  await api("/reset", { method: "POST" });
  await api("/setup", { method: "POST", body: { on: false } });
});

test("off by default: a site without the saved option doesn't load it", async () => {
  await coreApi("/modules", { method: "POST", body: { active: null } });
  const m = await coreApi("/modules");
  assert.ok(m.available.includes("redirects"));
  assert.ok(!m.loaded.includes("redirects"));
  await api("/setup", { method: "POST", body: { on: true } });
  assert.ok((await coreApi("/modules")).loaded.includes("redirects"));
});

test("Lavandería's CSV goes in whole; twice doesn't duplicate; --dry-run doesn't write", async () => {
  const dry = await api("/import", { method: "POST", body: { csv: CSV, dry_run: true } });
  assert.deepEqual([dry.created, dry.updated, dry.unchanged, dry.errors.length], [83, 0, 0, 0]);
  assert.equal((await api("/rules")).length, 0, "dry_run doesn't write");

  const first = await api("/import", { method: "POST", body: { csv: CSV } });
  assert.deepEqual(first.errors, []);
  assert.deepEqual([first.created, first.updated, first.unchanged], [83, 0, 0]);
  const again = await api("/import", { method: "POST", body: { csv: CSV } });
  assert.deepEqual([again.created, again.updated, again.unchanged, again.errors.length], [0, 0, 83, 0]);
  assert.equal((await api("/rules")).length, 83);
});

test("the CSV sources redirect, with their variants (%3F, ?, page_id, utm, upper case)", async () => {
  const cases = [
    ["/index.html", 301, "/"],
    ["/index.html%3Fp=6981.html", 301, "/quienes-somos/"],
    ["/index.html?p=6981.html", 301, "/quienes-somos/"],
    ["/?p=6981", 301, "/quienes-somos/"],
    ["/?page_id=6981", 301, "/quienes-somos/"],
    ["/?p=6981&utm_source=google", 301, "/quienes-somos/"],
    ["/NOSOTROS/", 301, "/quienes-somos/"],
    ["/nosotros", 301, "/quienes-somos/"],
    ["/en/?p=30671", 301, "/en/"],
    ["/en/index.html%3Fp=30694.html", 301, "/en/about-us/"],
    ["/feed/index.html", 301, "/feed/"],
  ];
  for (const [from, status, to] of cases) {
    const r = await get(from);
    assert.equal(r.status, status, from);
    assert.equal(r.location, BASE + to, from);
    assert.equal(r.by, "Estrategia Web", from);
  }
});

test("a 410 returns 410 and the theme's «not found» page, without redirecting", async () => {
  for (const from of ["/lavanderia-santa-ponsa/", "/?p=30859", "/de/"]) {
    const r = await get(from);
    assert.equal(r.status, 410, from);
    assert.equal(r.location, null);
    assert.match(r.text, /<html/i, "the site is rendered");
    assert.ok(!/Fatal error|Warning:|Notice:/.test(r.text));
  }
});

test("hit counter and last hit", async () => {
  await get("/contact/");
  await get("/contact/?x=1");
  const rule = (await api("/rules")).find((r) => r.source === "/contact/");
  assert.equal(Number(rule.hits), 2);
  assert.match(rule.last_hit, /^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/);
});

test("a page that exists doesn't touch the table; a 404 makes one query", async () => {
  // /about/ has a rule in the CSV (→ /quienes-somos/). The page is deleted in the module's reset:
  // if it stayed, the following tests in the same Playground would see a live page.
  const page = await seedPage("<!-- wp:paragraph --><p>Viva</p><!-- /wp:paragraph -->", "about");
  const live = await get(new URL(page.url).pathname);
  assert.equal(live.status, 200, "the live page beats the /about/ rule (rules only act on 404s)");
  const l = await api("/last");
  assert.equal(l.queries, 0, "zero queries to the module's tables on a page that exists");
  console.log(`  · existing page: ${l.total} queries in total, 0 from the module`);

  await get("/esto-no-existe/");
  const n = await api("/last");
  assert.equal(n.queries, 1, "a 404 with no rule: one query (404 log off)");
  console.log(`  · 404 with no rule: ${n.total} queries in total, ${n.queries} from the module`);
});

test("validation through the same path as the screen: loop, same target, duplicate", async () => {
  assert.equal((await api("/rule", { method: "POST", body: { source: "/quienes-somos/", target: "/nosotros/" } })).error, "loop", "/nosotros/ → /quienes-somos/ already exists");
  assert.equal((await api("/rule", { method: "POST", body: { source: "/x/", target: "/X" } })).error, "same_target");
  assert.equal((await api("/rule", { method: "POST", body: { source: "/Index.html/", target: "/otra/" } })).error, "duplicate");
  const ok = await api("/rule", { method: "POST", body: { source: "/temporal/", target: "https://ejemplo.org/oferta", type: 302 } });
  assert.equal(ok.error, "");
  const r = await get("/temporal/?utm_campaign=x");
  assert.equal(r.status, 302);
  assert.equal(r.location, "https://ejemplo.org/oferta", "external target, without the request's query");
});

test("404 log: off records nothing; on records the path without the query or personal data; the purge empties it", async () => {
  await get("/ruta-perdida/?email=ana@ejemplo.test");
  assert.equal((await api("/log")).length, 0, "off by default");

  await api("/settings", { method: "POST", body: { log_404: true, log_days: 30 } });
  await get("/ruta-perdida/?email=ana@ejemplo.test");
  await get("/Ruta-Perdida");
  // /index.html%3Fp=99999.html would NOT do: it matches the rule /index.html → / (query not in the source is ignored).
  assert.equal((await get("/index.html%3Fp=99999.html")).location, BASE + "/");
  await get("/vieja.html%3Fp=99999.html");
  const log = await api("/log");
  assert.deepEqual(log.map((r) => [r.path, Number(r.hits)]), [["/ruta-perdida", 2], ["/vieja.html?p=99999.html", 1]]);
  assert.ok(!JSON.stringify(log).includes("ana@"), "no trace of the query");
  assert.ok(!Object.keys(log[0]).some((k) => /ip|agent|referer/i.test(k)), "no IP or browser columns");

  await get("/quienes-somos-no/");
  assert.equal((await api("/last")).queries, 4, "NEW 404 with no rule and log on: look up rule + update (0 rows) + count the cap + insert");
  await get("/quienes-somos-no/");
  assert.equal((await api("/last")).queries, 2, "the same 404 again: look up rule + update");

  await api("/log-backdate", { method: "POST" });
  const p = await api("/purge", { method: "POST" });
  assert.equal(p.scheduled, true, "the daily purge is scheduled");
  assert.equal((await api("/log")).length, 0);
});

test("switching the module off stops redirecting and doesn't delete the rules", async () => {
  await api("/setup", { method: "POST", body: { on: false } });
  assert.equal((await get("/index.html%3Fp=6981.html")).status, 404);
  await api("/setup", { method: "POST", body: { on: true } });
  assert.equal((await api("/rules")).length, 84);
  assert.equal((await get("/index.html%3Fp=6981.html")).status, 301);
});

test("the cost of a lookup (recorded, not enforced)", async () => {
  const small = await api("/bench", { method: "POST", body: { n: 300, rules: 0 } });
  const big = await api("/bench", { method: "POST", body: { n: 300, rules: 5000 } });
  console.log(`  · ${small.rules} rules: ${small.ms_each} ms/lookup · ${big.rules} rules: ${big.ms_each} ms/lookup (${big.db_engine})`);
  assert.ok(big.ms_each < 50, "an indexed lookup doesn't depend on the number of rules");
});
