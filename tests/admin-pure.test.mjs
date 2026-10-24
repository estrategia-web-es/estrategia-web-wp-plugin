// NIVEL: 1 — no browser or emulator. The pure functions of the 1.7.0 admin frame with the machine's PHP: the Settings page's tab registry (includes/admin/class-settings-page.php, layout()), the order of the «Estrategia Web» submenu and where old URLs go (includes/admin/class-menu.php).
//
// The real menu, the real tabs and the redirects are tested against
// WordPress in tests/menu.test.mjs and tests/settings-page.test.mjs (level 2).
// If there is no `php` in the PATH, the test FAILS (it is not skipped).
import { test } from "node:test";
import assert from "node:assert/strict";
import { spawnSync } from "node:child_process";
import path from "node:path";

const r = spawnSync("php", [path.join(import.meta.dirname, "php", "admin.php")], { encoding: "utf8" });
assert.equal(r.status, 0, "php did not run: " + (r.error?.message || r.stderr || r.stdout));
const out = JSON.parse(r.stdout);

test("tab registry: tabs by order, sections by priority, options merged without duplicates", () => {
  assert.deepEqual(out.layout_order, ["cookies", "analytics", "media"]);
  assert.deepEqual(out.layout.analytics, { label: "Analytics", sections: ["google-tags", "matomo", "other"], options: ["estrategia_web_google_tags", "estrategia_web_site"] });
  assert.deepEqual(out.layout.media, { label: "Media", sections: ["svg", "uploads"], options: ["estrategia_web_site", "estrategia_web_uploads"] }, "a string «options» is one option");
  assert.deepEqual(out.layout.cookies, { label: "Cookies", sections: ["cookies"], options: [] }, "a section with its own forms declares none");
});

test("tab registry: a tab without sections is not shown; a section of an unknown tab or without a callable render is dropped", () => {
  assert.ok(!("empty" in out.layout), "registered but nothing in it");
  assert.ok(!out.layout.media.sections.includes("broken"));
  assert.ok(!Object.values(out.layout).some((t) => t.sections.includes("orphan")));
  assert.deepEqual(out.nothing, [], "no sections at all: no tabs (and the page is not registered)");
});

test("tab registry: with google-tags off, Analytics still shows Matomo alone; each tab has its own settings group", () => {
  assert.deepEqual(Object.keys(out.only_matomo), ["analytics"]);
  assert.deepEqual(out.only_matomo.analytics.sections, ["matomo"]);
  assert.equal(out.group, "estrategia-web-settings-media");
});

test("submenu: Company details, Content types, Social networks, Settings, Redirects, and Modules LAST", () => {
  assert.deepEqual(out.order_full, ["estrategia-web-site", "estrategia-web-content-types", "estrategia-web-social", "estrategia-web-settings", "estrategia-web-redirects", "estrategia-web"]);
  assert.deepEqual(out.order_unknown, ["estrategia-web-site", "estrategia-web-social", "child-theme-page", "another-page", "estrategia-web"], "unknown pages after the known ones, in arrival order, before Modules");
  assert.deepEqual(out.order_some, ["estrategia-web-site", "estrategia-web-redirects", "estrategia-web"], "missing ones (module off) leave no gap");
});

test("old URLs: the cookies and Google screens go to their tab; with the tab gone, nowhere", () => {
  assert.equal(out.legacy_cookies, "cookies");
  assert.equal(out.legacy_google, "analytics");
  assert.equal(out.legacy_google_no, null, "google-tags and matomo off: no Analytics tab, no redirect");
  assert.equal(out.legacy_other, null, "Company details keeps its own URL: nothing to redirect");
});

test("the menu's parent is Company details' slug, and the old Modules_Page::MENU points to it", () => {
  assert.equal(out.parent, "estrategia-web-site");
  assert.equal(out.modules_menu, out.parent, "a child theme's add_submenu_page( Modules_Page::MENU, … ) still lands in the menu");
});
