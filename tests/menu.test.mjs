// NIVEL: 2 — Playground WordPress, because what is tested is the admin menu WordPress builds (position, order, where the top-level item opens), what old admin URLs answer, and which modules load with a real saved option. wp-admin pages are read with a cookie session (tests/lib.mjs, adminSession), without a browser: none of this needs the screens' JavaScript.
//
// What and why: includes/admin/class-menu.php (the menu, the old URLs) and
// the edge case «a core module missing from the saved list» in
// includes/class-modules.php. The pure parts (order(), legacy_target(),
// wanted() with core modules) are level 1: tests/admin-pure.test.mjs and
// tests/modules.test.mjs.
//
// How to maintain: a new page of the menu goes in EXPECTED_ORDER; an old
// slug added to Menu::LEGACY gets its line in «old URLs».
import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { adminSession, reset, testApi, wp } from "./lib.mjs";

let s;
const EXPECTED_ORDER = ["estrategia-web-site", "estrategia-web-content-types", "estrategia-web-social", "estrategia-web-settings", "estrategia-web-redirects", "estrategia-web"];

/** The submenu links of a top-level item, in order, from the sidebar HTML. */
const submenu = (page, id) => {
  const li = page.match(new RegExp(`<li[^>]*id="${id}"[\\s\\S]*?<ul class=['"]wp-submenu[\\s\\S]*?</ul>`));
  assert.ok(li, `no «${id}» in the sidebar`);
  return [...li[0].matchAll(/<a href=['"](?:admin\.php\?page=)?([^'"&]+)/g)].map((m) => m[1]).filter((h, i, a) => a.indexOf(h) === i);
};

before(async () => {
  await reset();
  // Every module on, Default: off ones too (google-tags, redirects), so every page exists.
  const { available } = await testApi("/modules");
  await testApi("/modules", { method: "POST", body: { active: available } });
  s = await adminSession();
});
after(() => testApi("/modules", { method: "POST", body: { active: null } }));

test("one «Estrategia Web» item right after the Dashboard, opening on Company details, with Modules last", async () => {
  const page = (await s.get("/wp-admin/index.php")).text;
  const ids = [...page.matchAll(/<li[^>]*\bid="((?:menu|toplevel_page)-?[^"]*)"/g)].map((m) => m[1]).filter((id) => !id.startsWith("menu-dashboard-") );
  assert.equal(ids[0], "menu-dashboard");
  assert.equal(ids[1], "toplevel_page_estrategia-web-site", "position 3: right after the Dashboard. Seen: " + ids.slice(0, 4).join(", "));
  assert.ok(!ids.includes("toplevel_page_estrategia-web"), "the old «Estrategia Web» item at 81 is gone");
  assert.match(page, /id="toplevel_page_estrategia-web-site"[\s\S]*?<a href=['"]admin\.php\?page=estrategia-web-site['"][^>]*>[\s\S]*?Estrategia Web/, "the top-level item is «Estrategia Web» and opens Company details");
  assert.deepEqual(submenu(page, "toplevel_page_estrategia-web-site"), EXPECTED_ORDER);
});

test("with a module off, its page leaves no gap and Modules is still last", async () => {
  const { available } = await testApi("/modules");
  await testApi("/modules", { method: "POST", body: { active: available.filter((m) => !["social", "redirects"].includes(m)) } });
  const page = (await s.get("/wp-admin/index.php")).text;
  assert.deepEqual(submenu(page, "toplevel_page_estrategia-web-site"), ["estrategia-web-site", "estrategia-web-content-types", "estrategia-web-settings", "estrategia-web"]);
  await testApi("/modules", { method: "POST", body: { active: available } });
});

test("old URLs: Company details and Modules keep theirs; Cookies and Google redirect to their Settings tab, keeping the other arguments", async () => {
  const company = await s.get("/wp-admin/admin.php?page=estrategia-web-site");
  assert.equal(company.status, 200);
  assert.match(company.text, /<h1>Datos de la empresa<\/h1>/);
  const modules = await s.get("/wp-admin/admin.php?page=estrategia-web");
  assert.equal(modules.status, 200);
  assert.match(modules.text, /<h1>Módulos<\/h1>/);

  const cookies = await s.get("/wp-admin/admin.php?page=estrategia-web-cookies&notice=saved");
  assert.equal(cookies.status, 302);
  assert.match(cookies.location, /admin\.php\?page=estrategia-web-settings&tab=cookies&notice=saved$/);
  const google = await s.get("/wp-admin/admin.php?page=estrategia-web-google");
  assert.equal(google.status, 302);
  assert.match(google.location, /admin\.php\?page=estrategia-web-settings&tab=analytics$/);
  assert.equal((await s.get(google.location)).status, 200, "and the tab opens");
});

test("old Google URL with google-tags AND matomo off: no Analytics tab, so no redirect (WordPress says not allowed, as before)", async () => {
  const { available } = await testApi("/modules");
  await testApi("/modules", { method: "POST", body: { active: available.filter((m) => !["google-tags", "matomo"].includes(m)) } });
  const google = await s.get("/wp-admin/admin.php?page=estrategia-web-google");
  assert.notEqual(google.status, 302);
  assert.equal(google.status, 403);
  await testApi("/modules", { method: "POST", body: { active: available } });
});

test("edge case: «site» loads even when the saved module list lacks it (and social, which requires it, too)", async () => {
  await testApi("/modules", { method: "POST", body: { active: ["forms", "social", "matomo"] } });
  const m = await testApi("/modules");
  assert.ok(!m.saved.includes("site"), "the saved list really lacks it: " + JSON.stringify(m.saved));
  assert.ok(m.known.includes("site"), "and the site knows it (it is not the new-module edge case)");
  for (const slug of ["site", "social", "matomo", "forms"]) assert.ok(m.loaded.includes(slug), `${slug} not loaded: ${m.loaded.join(", ")}`);
  assert.deepEqual(m.blocked, {});
  assert.ok("estrategia_web_site" in (await wp("/settings")).json, "the company details are registered");
  const page = (await s.get("/wp-admin/admin.php?page=estrategia-web")).text;
  assert.ok(!page.includes('value="site"'), "and it cannot be switched off on the Modules screen");
  assert.match(page, /<h2 id="estrategia-web-modules-content">Contenido<\/h2>/, "the screen is grouped by category");
  await testApi("/modules", { method: "POST", body: { active: (await testApi("/modules")).available } });
});
