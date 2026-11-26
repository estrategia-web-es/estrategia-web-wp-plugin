// NIVEL: 1 — no browser or emulator. The site-health registry (modules/site-health/includes/class-registry.php) with the machine's PHP and stubs: every file of checks/ follows the contract, a broken one is skipped, what goes into site_status_tests (direct, async with our REST route, which ones apply), and the formatted result (badge, escaping, actions, a check that throws).
//
// If there is no `php` on the PATH, the test FAILS (it is not skipped).
//
// What level 1 cannot see, in site-health.test.mjs (level 2): that WordPress
// really runs our tests on Tools › Site Health, and the REST route.
//
// Seen red (26-11-2026): with valid() not comparing the id to the file name
// (the fixtures test: «wrong-id» loaded; it first stayed GREEN, because the
// fixture repeated good-one's id and was overwritten — fixed in the
// fixture), with format() printing the
// description without esc_html() (the escaping test), with the async ones
// registered without «async_direct_test» (the cron), and with result()
// without its try/catch (php died: every test red, «php did not run»).
import { test } from "node:test";
import assert from "node:assert/strict";
import { spawnSync } from "node:child_process";
import path from "node:path";

const r = spawnSync("php", [path.join(import.meta.dirname, "php", "registry.php")], { encoding: "utf8" });
const out = r.status === 0 ? JSON.parse(r.stdout) : null;
const ok = () => assert.ok(out, "php did not run: " + (r.error?.message || r.stderr || r.stdout));

const TWELVE = ["company-details", "cookies-pending", "dmarc", "english-defaults", "legal-pages", "llms-and-faq", "locations", "login-protection", "page-cache", "search-engines", "security-headers", "social-profiles"];

test("every file in checks/ is a valid check, and these are the twelve of the Llar d'Estels audit", () => {
  ok();
  assert.deepEqual(out.real, out.files, "a file of checks/ that the registry skips: it breaks the contract (header of class-registry.php)");
  assert.deepEqual(out.real, TWELVE);
});

test("a broken check file is skipped, not fatal: wrong id, no judge, string «async», no array", () => {
  ok();
  assert.deepEqual(out.fixtures, ["good-one"]);
});

test("hook(): site_status_tests and the REST route", () => {
  ok();
  assert.deepEqual(out.hooked.sort(), ["rest_api_init", "site_status_tests"]);
});

test("site_status_tests keeps core's tests and adds ours: direct with a callable, async with our route and a cron callable", () => {
  ok();
  assert.ok(out.es.direct.php_version, "core's test is still there");
  assert.equal(out.es.direct.estrategia_web_search_engines.test, "callable");
  assert.equal(out.es.direct.estrategia_web_search_engines.label, "Search engines can index the site");
  for (const id of ["security_headers", "dmarc"]) {
    const t = out.es.async["estrategia_web_" + id];
    assert.equal(t.test, "https://example.com/wp-json/estrategia-web/v1/site-health/" + id.replace("_", "-"));
    assert.equal(t.has_rest, true);
    assert.equal(t.cron_test, true, "WordPress' weekly check runs async tests without JavaScript");
    assert.ok(!out.es.direct["estrategia_web_" + id], "an async check is not also direct");
  }
});

test("«applies»: consent off → no cookies check; no location type → no locations check; an English site → no english-defaults", () => {
  ok();
  assert.ok(!out.es.direct.estrategia_web_cookies_pending);
  assert.ok(!out.es.direct.estrategia_web_locations);
  assert.ok(out.es.direct.estrategia_web_english_defaults, "es_ES");
  assert.ok(!out.en.direct.estrategia_web_english_defaults, "en_US");
  assert.ok(out.en.direct.estrategia_web_locations, "the location type exists");
  assert.equal(Object.keys(out.es.direct).length + Object.keys(out.es.async || {}).length, 1 + 12 - 2);
});

test("format(): badge, test key, unknown status → recommended, everything escaped, actions as links or a sentence", () => {
  ok();
  const f = out.formatted;
  assert.deepEqual(f.badge, { label: "Estrategia Web", color: "blue" });
  assert.equal(f.test, "estrategia_web_company_details");
  assert.equal(f.status, "recommended");
  assert.equal(f.description, "<p>Why &lt;script&gt;alert(1)&lt;/script&gt; &amp; more</p><ul><li>A &lt;b&gt;</li><li>B</li></ul>");
  assert.equal(
    f.actions,
    '<p><a href="https://example.com/wp-admin/admin.php?page=x&amp;tab=y">Go &quot;there&quot;</a></p><p><a href="https://dmarc.org/">Out</a></p><p>Do it in the DNS</p>',
  );
  assert.equal(out.good.status, "good");
  assert.equal(out.good.actions, "");
});

test("a check whose gather() throws is «recommended: could not check» with the message, and the screen survives", () => {
  ok();
  assert.equal(out.thrown.status, "recommended");
  assert.equal(out.thrown.label, "Could not check: Thrower");
  assert.match(out.thrown.description, /unexpected &lt;option&gt; shape/);
  assert.equal(out.thrown.test, "estrategia_web_throws");
});
