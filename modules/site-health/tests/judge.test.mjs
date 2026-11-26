// NIVEL: 1 — no browser or emulator. Each site-health check's judge() (modules/site-health/checks/*.php) with the machine's PHP and plain data: the verdict (good / recommended / critical) and what it lists, for the cases written in each check's header.
//
// If there is no `php` on the PATH, the test FAILS (it is not skipped).
//
// ONE php process for every case (tests/php/judge.php reads them from
// stdin). A new check adds its cases to CASES and its test() below.
//
// What level 1 cannot see: gather() — what each check reads from a real
// WordPress (options, queries, the loopback, the DNS). Two of them end to
// end are in site-health.test.mjs (level 2); the rest is checked on the
// real site, the first day, on Tools › Site Health.
//
// Seen red (26-11-2026), each by breaking the check and running this file:
// - search-engines: the host test without «staging\d*» (staging3.x.com came
//   out critical), and without the estrategiaweb.es subdomain rule;
// - legal-pages: «"id":N» matched without the digit guard (id 12 counted
//   as linked by «"id":123»);
// - security-headers: HSTS asked over http (the http case failed);
// - dmarc: a failed lookup (false) judged as «missing»;
// - english-defaults: the UTC rule without the gmt_offset test
//   (Atlantic/Canary-like offset 0 with a timezone string reported);
// - llms-and-faq: «<!-- wp:accordion» read without the space guard
//   (accordion-item counted as an accordion without style).
import { test } from "node:test";
import assert from "node:assert/strict";
import { spawnSync } from "node:child_process";
import path from "node:path";

const NOW = 1795651200; // 26-11-2026
const YEAR = 365 * 86400;
const page = (id, slug, modified = NOW) => ({ id, title: slug, slug, modified });

const CASES = {
  // 1. search-engines
  se_public: ["search-engines", { public: "1", environment: "production", host: "example.com" }],
  se_hidden_prod: ["search-engines", { public: "0", environment: "production", host: "llardestels.com" }],
  se_hidden_ip: ["search-engines", { public: "0", environment: "production", host: "127.0.0.1" }],
  se_env_staging: ["search-engines", { public: "0", environment: "staging", host: "llardestels.com" }],
  se_sg_staging: ["search-engines", { public: "0", environment: "production", host: "staging3.llardestels.com" }],
  se_dev_label: ["search-engines", { public: "0", environment: "production", host: "dev-llardestels.com" }],
  se_ew_sub: ["search-engines", { public: "0", environment: "production", host: "llar.estrategiaweb.es" }],
  se_ew_itself: ["search-engines", { public: "0", environment: "production", host: "estrategiaweb.es" }],
  se_local: ["search-engines", { public: "0", environment: "production", host: "llar.local" }],
  se_latest: ["search-engines", { public: "0", environment: "production", host: "latest-news.com" }],
  // 2. legal-pages
  lp_ok: ["legal-pages", { pages: { notice: page(10, "aviso-legal"), privacy: page(11, "privacidad"), cookies: page(12, "cookies") }, haystack: '"id":10, "url":"https://x.com/privacidad/" "id":12}', menu_ids: [], now: NOW }],
  lp_missing: ["legal-pages", { pages: { notice: null, privacy: page(11, "privacidad"), cookies: page(12, "cookies") }, haystack: '"id":11 "id":12,', menu_ids: [], now: NOW }],
  lp_unlinked_guard: ["legal-pages", { pages: { notice: page(10, "aviso-legal"), privacy: page(11, "privacidad"), cookies: page(12, "cookies") }, haystack: '"id":10 "id":11 "id":123', menu_ids: [], now: NOW }],
  lp_menu: ["legal-pages", { pages: { notice: page(10, "aviso-legal"), privacy: page(11, "privacidad"), cookies: page(12, "cookies") }, haystack: "", menu_ids: [10, 11, 12], now: NOW }],
  lp_old: ["legal-pages", { pages: { notice: page(10, "aviso-legal", NOW - YEAR - 86400), privacy: page(11, "privacidad"), cookies: page(12, "cookies") }, haystack: "", menu_ids: [10, 11, 12], now: NOW }],
  // 3. cookies-pending
  ck_ok: ["cookies-pending", { pending: [], table: true }],
  ck_pending: ["cookies-pending", { pending: ["_ga", "_fbp"], table: true }],
  ck_no_table: ["cookies-pending", { pending: [], table: false }],
  // 4. company-details
  co_ok: ["company-details", { legal_name: "Llar d'Estels, S.L.", tax_id: "B00000000", address: "Carrer 1", phone: "971 000 000" }],
  co_missing: ["company-details", { legal_name: "Llar d'Estels, S.L.", tax_id: " ", address: "", phone: "971 000 000" }],
  // 5. locations
  lo_ok: ["locations", { local_business: true, type: "eweb_location", locations: [{ title: "Palma", latitude: "39.5", longitude: "2.7", google: "https://g.page/x", page: true }] }],
  lo_bad: ["locations", { local_business: true, type: "eweb_location", locations: [{ title: "Palma", latitude: "0", longitude: "2.7", google: "", page: false }, { title: "Inca", latitude: "39.7", longitude: "2.9", google: "https://g.page/y", page: true }] }],
  lo_no_schema: ["locations", { local_business: false, type: "eweb_location", locations: [{ title: "Palma", latitude: "", longitude: "", google: "", page: true }] }],
  // 6. social-profiles
  so_ok: ["social-profiles", { module: true, count: 2 }],
  so_empty: ["social-profiles", { module: true, count: 0 }],
  so_off: ["social-profiles", { module: false, count: 0 }],
  // 7. security-headers
  sh_none: ["security-headers", { https: true, code: 200, headers: { "content-type": "text/html", "x-powered-by": "PHP/8.5" } }],
  sh_all: ["security-headers", { https: true, code: 200, headers: { "Strict-Transport-Security": "max-age=1", "x-frame-options": "SAMEORIGIN", "x-content-type-options": "nosniff", "referrer-policy": "x", "permissions-policy": "x" } }],
  sh_http: ["security-headers", { https: false, code: 200, headers: { "x-frame-options": "SAMEORIGIN", "x-content-type-options": "nosniff", "referrer-policy": "x", "permissions-policy": "x" } }],
  sh_error: ["security-headers", { error: "cURL error 28: timed out" }],
  sh_500: ["security-headers", { https: true, code: 500, headers: {} }],
  // 8. login-protection
  lg_sg: ["login-protection", { plugins: ["sg-security/sg-security.php"], xmlrpc: false }],
  lg_none: ["login-protection", { plugins: ["wordpress-seo/wp-seo.php"], xmlrpc: true }],
  // 9. page-cache
  pc_sg_host: ["page-cache", { siteground: true, plugins: [], file_caching: "0" }],
  pc_sgo_on: ["page-cache", { siteground: false, plugins: ["sg-cachepress/sg-cachepress.php"], file_caching: "1" }],
  pc_sgo_off: ["page-cache", { siteground: false, plugins: ["sg-cachepress/sg-cachepress.php"], file_caching: "0" }],
  pc_rocket: ["page-cache", { siteground: false, plugins: ["wp-rocket/wp-rocket.php"], file_caching: "0" }],
  pc_none: ["page-cache", { siteground: false, plugins: [], file_caching: "0" }],
  // 10. dmarc
  dm_ok: ["dmarc", { domain: "llardestels.com", lookups: { "llardestels.com": ["v=DMARC1; p=none"] } }],
  dm_parent: ["dmarc", { domain: "shop.llardestels.com", lookups: { "shop.llardestels.com": [], "llardestels.com": ["v=DMARC1; p=reject"] } }],
  dm_missing: ["dmarc", { domain: "llardestels.com", lookups: { "llardestels.com": ["v=spf1 -all"] } }],
  dm_failed: ["dmarc", { domain: "llardestels.com", lookups: { "llardestels.com": false } }],
  dm_ip: ["dmarc", { domain: "127.0.0.1", lookups: {} }],
  // 11. english-defaults
  en_yoast: ["english-defaults", { yoast: { "title-404-wpseo": "Page not found %%sep%% %%sitename%%", "breadcrumbs-home": "Inicio", "breadcrumbs-searchprefix": " You searched for " }, timezone: "Europe/Madrid", gmt_offset: 0, date_format: "j \\d\\e F \\d\\e Y" }],
  en_wp: ["english-defaults", { yoast: {}, timezone: "", gmt_offset: 0, date_format: "F j, Y" }],
  en_canary: ["english-defaults", { yoast: {}, timezone: "Atlantic/Canary", gmt_offset: 0, date_format: "d/m/Y" }],
  en_offset: ["english-defaults", { yoast: {}, timezone: "", gmt_offset: 1, date_format: "d/m/Y" }],
  // 12. llms-and-faq
  lf_ok: ["llms-and-faq", { llms: true, llms_text: true, faq: true, rows: [{ id: 1, title: "FAQ", content: '<!-- wp:accordion {"className":"is-style-faq"} --><!-- wp:accordion-item --><!-- wp:accordion-heading --><!-- /wp:accordion-item --><!-- /wp:accordion -->' }] }],
  lf_llms_off: ["llms-and-faq", { llms: false, llms_text: false, faq: false, rows: [] }],
  lf_llms_empty: ["llms-and-faq", { llms: true, llms_text: false, faq: false, rows: [] }],
  lf_plain_acc: ["llms-and-faq", { llms: true, llms_text: true, faq: true, rows: [{ id: 2, title: "Servicios", content: "<!-- wp:accordion --><!-- wp:accordion-item {\"openByDefault\":true} --><!-- /wp:accordion -->" }] }],
  lf_details: ["llms-and-faq", { llms: true, llms_text: true, faq: true, rows: [{ id: 3, title: "Preguntas frecuentes", content: "<!-- wp:details --><details><summary>¿A?</summary></details><!-- /wp:details --><!-- wp:details --><details><summary>¿B?</summary></details><!-- /wp:details -->" }, { id: 4, title: "Horario", content: "<!-- wp:details --><!-- /wp:details --><!-- wp:details --><!-- /wp:details -->" }] }],
  lf_faq_off: ["llms-and-faq", { llms: true, llms_text: true, faq: false, rows: [{ id: 2, title: "FAQ", content: "<!-- wp:accordion --><!-- /wp:accordion -->" }] }],
};

const names = Object.keys(CASES);
const r = spawnSync("php", [path.join(import.meta.dirname, "php", "judge.php")], {
  encoding: "utf8",
  input: JSON.stringify(names.map((k) => ({ check: CASES[k][0], data: CASES[k][1] }))),
});
const list = r.status === 0 ? JSON.parse(r.stdout) : null;
const out = list ? Object.fromEntries(names.map((k, i) => [k, list[i]])) : {};
const ok = () => assert.ok(list, "php did not run: " + (r.error?.message || r.stderr || r.stdout));
const status = (k) => (ok(), out[k].status);
const items = (k) => (ok(), (out[k].items || []).join(" | "));

test("search-engines: critical only on a real site; a staging copy (by environment or host name) is good", () => {
  assert.equal(status("se_public"), "good");
  assert.equal(status("se_hidden_prod"), "critical");
  assert.equal(out.se_hidden_prod.actions[0].path, "options-reading.php");
  assert.equal(status("se_hidden_ip"), "critical", "a bare IP is not a staging (edge case in the header)");
  for (const k of ["se_env_staging", "se_sg_staging", "se_dev_label", "se_ew_sub", "se_local"]) assert.equal(status(k), "good", k);
  assert.equal(status("se_ew_itself"), "critical", "estrategiaweb.es itself is a real site");
  assert.equal(status("se_latest"), "critical", "«latest» is not a «test» label");
});

test("legal-pages: missing or unlinked is critical, a year old is recommended, linked by id, path or classic menu is good", () => {
  assert.equal(status("lp_ok"), "good", "id, path and id followed by «}»");
  assert.equal(status("lp_missing"), "critical");
  assert.match(items("lp_missing"), /Legal notice: no published page/);
  assert.equal(status("lp_unlinked_guard"), "critical", "«\"id\":123» does not link page 12");
  assert.match(items("lp_unlinked_guard"), /Cookie policy \(«cookies»\) is not linked/);
  assert.equal(status("lp_menu"), "good");
  assert.equal(status("lp_old"), "recommended");
  assert.match(items("lp_old"), /Legal notice .*12 months/);
});

test("cookies-pending: pending cookies or no page with the table are recommended", () => {
  assert.equal(status("ck_ok"), "good");
  assert.equal(status("ck_pending"), "recommended");
  assert.match(items("ck_pending"), /2 cookies waiting for confirmation: _ga, _fbp/);
  assert.equal(status("ck_no_table"), "recommended");
  assert.match(out.ck_no_table.actions[0].path, /tab=cookies/);
});

test("company-details: the empty ones are listed (blank counts as empty)", () => {
  assert.equal(status("co_ok"), "good");
  assert.equal(status("co_missing"), "recommended");
  assert.deepEqual(out.co_missing.items, ["Tax ID", "Address"]);
});

test("locations: lists each incomplete location; coordinates and profile only with local-business on", () => {
  assert.equal(status("lo_ok"), "good");
  assert.equal(status("lo_bad"), "recommended");
  assert.deepEqual(out.lo_bad.items, ["Palma: no coordinates, no Google Business profile, no page of its own"]);
  assert.equal(status("lo_no_schema"), "good", "local-business off: its fields are not asked for");
});

test("social-profiles: an empty list or the module off is recommended, with the right screen", () => {
  assert.equal(status("so_ok"), "good");
  assert.equal(status("so_empty"), "recommended");
  assert.match(out.so_empty.actions[0].path, /estrategia-web-social/);
  assert.equal(status("so_off"), "recommended");
  assert.equal(out.so_off.actions[0].path, "admin.php?page=estrategia-web");
});

test("security-headers: missing ones and X-Powered-By listed; HSTS only over https; a failed loopback is «could not check»", () => {
  assert.equal(status("sh_none"), "recommended");
  assert.match(items("sh_none"), /Missing: Strict-Transport-Security .*Missing: Permissions-Policy .*Exposed: X-Powered-By: PHP\/8.5/);
  assert.equal(status("sh_all"), "good", "header names compared case-insensitively");
  assert.equal(status("sh_http"), "good", "over http HSTS is not asked");
  assert.equal(status("sh_error"), "recommended");
  assert.match(out.sh_error.label, /Could not check/);
  assert.match(out.sh_500.label, /Could not check/);
});

test("login-protection: a known limiter is good; none is recommended and says whether XML-RPC is open", () => {
  assert.equal(status("lg_sg"), "good");
  assert.equal(status("lg_none"), "recommended");
  assert.match(items("lg_none"), /XML-RPC is open/);
});

test("page-cache: SiteGround hosting, Speed Optimizer with its file cache or another cache plugin is good", () => {
  assert.equal(status("pc_sg_host"), "good");
  assert.equal(status("pc_sgo_on"), "good");
  assert.equal(status("pc_rocket"), "good");
  assert.equal(status("pc_sgo_off"), "recommended");
  assert.equal(out.pc_sgo_off.actions[0].path, "admin.php?page=sgo_caching");
  assert.equal(status("pc_none"), "recommended");
});

test("dmarc: found (also on the parent domain) is good; none is recommended; a failed lookup is «could not check»", () => {
  assert.equal(status("dm_ok"), "good");
  assert.equal(status("dm_parent"), "good");
  assert.equal(status("dm_missing"), "recommended");
  assert.match(out.dm_missing.label, /no DMARC record/);
  assert.ok(!("path" in out.dm_missing.actions[0]) && !("url" in out.dm_missing.actions[0]), "the fix is in the DNS: a sentence, no link");
  assert.match(out.dm_failed.label, /Could not check/);
  assert.match(out.dm_ip.label, /Could not check/);
});

test("english-defaults: only values that ARE the English ones; UTC only with no offset and no real zone", () => {
  assert.equal(status("en_yoast"), "recommended");
  assert.deepEqual(out.en_yoast.items, ["Yoast SEO, title-404-wpseo: «Page not found %%sep%% %%sitename%%»", "Yoast SEO, breadcrumbs-searchprefix: «You searched for»"]);
  assert.deepEqual(out.en_yoast.actions.map((a) => a.path), ["admin.php?page=wpseo_page_settings"]);
  assert.equal(status("en_wp"), "recommended");
  assert.equal(out.en_wp.items.length, 2);
  assert.deepEqual(out.en_wp.actions.map((a) => a.path), ["options-general.php"]);
  assert.equal(status("en_canary"), "good", "Atlantic/Canary is a real choice");
  assert.equal(status("en_offset"), "good", "a manual UTC+1 is a choice");
});

test("llms-and-faq: llms off or empty; accordions without the FAQ style and details used as a FAQ", () => {
  assert.equal(status("lf_ok"), "good", "accordion-item is not read as an accordion without style");
  assert.equal(status("lf_llms_off"), "recommended");
  assert.match(items("lf_llms_off"), /module is off/);
  assert.match(items("lf_llms_empty"), /text is empty/);
  assert.equal(status("lf_plain_acc"), "recommended");
  assert.match(items("lf_plain_acc"), /«Servicios»: an accordion without a FAQ style/);
  assert.equal(out.lf_details.items.length, 1, "«Horario» has details but does not say it is a FAQ");
  assert.match(items("lf_details"), /«Preguntas frecuentes»: questions in Details blocks/);
  assert.equal(status("lf_faq_off"), "good", "faq-schema off: its blocks are not judged");
});
