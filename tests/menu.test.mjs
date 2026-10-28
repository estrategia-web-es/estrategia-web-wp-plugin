// NIVEL: 2 — Playground WordPress. NOT LEVEL 1 BECAUSE: what is tested is the admin menu WordPress builds (position, order, where the top-level item opens), what old admin URLs answer, and which modules load with a real saved option; the pure parts are level 1 (below). wp-admin pages are read with a cookie session (tests/lib.mjs, adminSession), without a browser: none of this needs the screens' JavaScript.
//
// What and why: includes/admin/class-menu.php (the menu, the old URLs) and
// the edge case «a core module missing from the saved list» in
// includes/class-modules.php. The pure parts (order(), legacy_target(),
// wanted() with core modules) are level 1: tests/admin-pure.test.mjs and
// tests/modules.test.mjs.
//
// 28-10-2026: from ~26 requests to 10, read in ONE parallel batch. The reset
// and «every module on» are one request (/setup), the login goes alongside,
// and the screens of the other module lists are read with
// ?ew-test-modules=a,b,c (tests/wp/estrategia-web-site-test.php: that request
// reads the list as saved, through get_option, without saving it), so no
// read waits for a «save the list» request. «A module off leaves no gap» and
// «Google's old URL with no Analytics tab» share ONE list (social, redirects,
// google-tags and matomo off). Gone: «and the tab opens» after Google's
// redirect (tests/settings-page.test.mjs opens &tab=analytics) and the check
// that the company details are registered via /wp/v2/settings (that «site»
// is LOADED is what the edge case is about, and it is asserted). Saving the
// list for real (and its _known) is tests/modules-page.test.mjs.
// Seen red (28-10-2026) with Settings expected before Social in
// EXPECTED_ORDER, with the cookies redirect expected to drop its arguments,
// with «social» expected in the gapless submenu and with «site» expected
// absent from the loaded modules: each of the four tests fails (and again
// after ?ew-test-modules).
//
// How to maintain: a new page of the menu goes in EXPECTED_ORDER; an old
// slug added to Menu::LEGACY gets its line in «old URLs».
import { test, before } from "node:test";
import assert from "node:assert/strict";
import { adminSession, testApi } from "./lib.mjs";

const EXPECTED_ORDER = ["estrategia-web-site", "estrategia-web-content-types", "estrategia-web-social", "estrategia-web-settings", "estrategia-web-redirects", "estrategia-web"];
const EDGE = ["forms", "social", "matomo"]; // A saved list without «site».

/** The submenu links of a top-level item, in order, from the sidebar HTML. */
const submenu = (page, id) => {
  const li = page.match(new RegExp(`<li[^>]*id="${id}"[\\s\\S]*?<ul class=['"]wp-submenu[\\s\\S]*?</ul>`));
  assert.ok(li, `no «${id}» in the sidebar`);
  return [...li[0].matchAll(/<a href=['"](?:admin\.php\?page=)?([^'"&]+)/g)].map((m) => m[1]).filter((h, i, a) => a.indexOf(h) === i);
};

let r;
before(async () => {
  // Every module on, Default: off ones too (google-tags, redirects), so every page exists.
  const [{ available }, s] = await Promise.all([testApi("/setup", { method: "POST", body: { modules: "all" } }), adminSession()]);
  const off = "ew-test-modules=" + available.filter((m) => !["social", "redirects", "google-tags", "matomo"].includes(m)).join(",");
  const edge = "ew-test-modules=" + EDGE.join(",");
  const reads = {
    index: s.get("/wp-admin/index.php"),
    company: s.get("/wp-admin/admin.php?page=estrategia-web-site"),
    cookies: s.get("/wp-admin/admin.php?page=estrategia-web-cookies&notice=saved"),
    google: s.get("/wp-admin/admin.php?page=estrategia-web-google"),
    offIndex: s.get("/wp-admin/index.php?" + off),
    offGoogle: s.get("/wp-admin/admin.php?page=estrategia-web-google&" + off),
    edgeModules: testApi("/modules?" + edge),
    edgeScreen: s.get("/wp-admin/admin.php?page=estrategia-web&" + edge),
  };
  r = Object.fromEntries(await Promise.all(Object.entries(reads).map(async ([k, p]) => [k, await p])));
});

test("one «Estrategia Web» item right after the Dashboard, opening on Company details, with Modules last", () => {
  const page = r.index.text;
  const ids = [...page.matchAll(/<li[^>]*\bid="((?:menu|toplevel_page)-?[^"]*)"/g)].map((m) => m[1]).filter((id) => !id.startsWith("menu-dashboard-"));
  assert.equal(ids[0], "menu-dashboard");
  assert.equal(ids[1], "toplevel_page_estrategia-web-site", "position 3: right after the Dashboard. Seen: " + ids.slice(0, 4).join(", "));
  assert.ok(!ids.includes("toplevel_page_estrategia-web"), "the old «Estrategia Web» item at 81 is gone");
  assert.match(page, /id="toplevel_page_estrategia-web-site"[\s\S]*?<a href=['"]admin\.php\?page=estrategia-web-site['"][^>]*>[\s\S]*?Estrategia Web/, "the top-level item is «Estrategia Web» and opens Company details");
  assert.deepEqual(submenu(page, "toplevel_page_estrategia-web-site"), EXPECTED_ORDER);
});

test("old URLs: Company details keeps its own; Cookies and Google redirect to their Settings tab, keeping the other arguments", () => {
  const { company, cookies, google } = r;
  assert.equal(company.status, 200);
  assert.match(company.text, /<h1[^>]*>Datos de la empresa<\/h1>/);
  assert.equal(cookies.status, 302);
  assert.match(cookies.location, /admin\.php\?page=estrategia-web-settings&tab=cookies&notice=saved$/);
  assert.equal(google.status, 302);
  assert.match(google.location, /admin\.php\?page=estrategia-web-settings&tab=analytics$/);
});

test("modules off: their pages leave no gap, Modules is still last; with google-tags AND matomo off there is no Analytics tab, so Google's old URL is not redirected (403, as before)", () => {
  assert.deepEqual(submenu(r.offIndex.text, "toplevel_page_estrategia-web-site"), ["estrategia-web-site", "estrategia-web-content-types", "estrategia-web-settings", "estrategia-web"]);
  assert.notEqual(r.offGoogle.status, 302);
  assert.equal(r.offGoogle.status, 403);
});

test("edge case: «site» loads even when the saved module list lacks it (and social, which requires it, too); Modules keeps its old URL", () => {
  const m = r.edgeModules;
  const modules = r.edgeScreen;
  assert.deepEqual(m.saved, EDGE, "the saved list really lacks it: " + JSON.stringify(m.saved));
  assert.ok(m.known.includes("site"), "and the site knows it (it is not the new-module edge case)");
  for (const slug of ["site", "social", "matomo", "forms"]) assert.ok(m.loaded.includes(slug), `${slug} not loaded: ${m.loaded.join(", ")}`);
  assert.deepEqual(m.blocked, {});
  assert.equal(modules.status, 200);
  assert.match(modules.text, /<h1[^>]*>Módulos<\/h1>/);
  assert.ok(!modules.text.includes('value="site"'), "and it cannot be switched off on the Modules screen");
  assert.match(modules.text, /<h2 class="ew-box-title" id="estrategia-web-modules-content">Contenido<\/h2>/, "the screen is grouped by category");
});
