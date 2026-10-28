// NIVEL: 2 — Playground WordPress. NOT LEVEL 1 BECAUSE: what is checked is the HTML WordPress serves for each plugin screen in wp-admin (its menu, its enqueued stylesheet, each module's sections inside the Settings page), read with an administrator session; the screens have no JavaScript that matters here, so it is not level 3.
//
// ONE LOOK FOR EVERY PLUGIN SCREEN (2.0.1, David, 28-10-2026: «la página de
// ajustes parece de otro plugin»; includes/admin/class-admin-ui.php and
// assets/admin.css). For every screen: the shared stylesheet, the same
// header (title + one line saying what it is for) and, on the screens with a
// form, every section in a white box with its title and the save button
// under them (never inside a table); the Settings and Redirects tabs are the
// content types builder's. How it LOOKS is for David's eyes (screenshots,
// the CHANGELOG says where); this test keeps a new screen or section from
// coming back without the pieces.
//
// Seen red (28-10-2026) twice: Social networks with an <h2> that was not the
// screen's (the media modal's templates after the footer: the check now
// stops at #wpfooter), and with Settings_Page::render() printing
// submit_button() instead of Admin_UI::save() (Analytics: «the save button
// right under a box»).
import { test, before } from "node:test";
import assert from "node:assert/strict";
import { adminSession, testApi } from "./lib.mjs";

const SCREENS = {
  company: { url: "estrategia-web-site", form: true },
  cookies: { url: "estrategia-web-settings&tab=cookies", form: true, tabs: true },
  analytics: { url: "estrategia-web-settings&tab=analytics", form: true, tabs: true },
  media: { url: "estrategia-web-settings&tab=media", form: true, tabs: true },
  social: { url: "estrategia-web-social", form: true },
  modules: { url: "estrategia-web", form: true },
  formsList: { url: "estrategia-web-forms-list" },
  formsSettings: { url: "estrategia-web-forms-settings", form: true },
  submissions: { url: "estrategia-web-forms" },
  contentTypes: { url: "estrategia-web-content-types" },
  redirects: { url: "estrategia-web-redirects", tabs: true },
};
const html = {};

before(async () => {
  const [, s] = await Promise.all([testApi("/setup", { method: "POST", body: { modules: "all" } }), adminSession()]);
  await Promise.all(Object.entries(SCREENS).map(async ([key, { url }]) => {
    const r = await s.get("/wp-admin/admin.php?page=" + url);
    assert.equal(r.status, 200, `${key}: ${r.status}`);
    html[key] = r.text;
  }));
});

test("every plugin screen: the shared stylesheet and the same header (title + what it is for)", () => {
  for (const key of Object.keys(SCREENS)) {
    const page = html[key];
    assert.match(page, /id=['"]estrategia-web-admin-css['"][^>]*assets\/admin\.css/, `${key}: assets/admin.css`);
    assert.match(page, /<div class="wrap ew-screen[ "]/, `${key}: the wrap`);
    assert.match(page, /<h1 class="wp-heading-inline"[^>]*>[^<]+<\/h1>[\s\S]*?<hr class="wp-header-end">/, `${key}: the title`);
    assert.match(page, /<p class="ew-lead">[^<]{20,}<\/p>/, `${key}: the line that says what it is for`);
  }
});

test("screens with a form: every section in a box with its title, the save button under the boxes", () => {
  for (const [key, { form }] of Object.entries(SCREENS)) {
    if (!form) continue;
    // The screen only: from its wrap to WordPress's footer (after it come the media modal's templates, with their own <h2>).
    const page = html[key].slice(html[key].indexOf('<div class="wrap ew-screen'), html[key].indexOf('id="wpfooter"'));
    assert.match(page, /<section class="ew-box" aria-labelledby="([^"]+)"><h2 class="ew-box-title" id="\1">[^<]+<\/h2>/, `${key}: a box with its title`);
    assert.doesNotMatch(page, /<h2>/, `${key}: no section title outside a box`);
    assert.match(page, /<\/section>\s*<div class="ew-actions">\s*<input type="submit"/, `${key}: the save button right under a box`);
    assert.doesNotMatch(page, /<p class="submit">/, `${key}: no WordPress submit paragraph left`);
  }
});

test("the tabs are the builder's, everywhere: Settings and Redirects", () => {
  for (const [key, { tabs }] of Object.entries(SCREENS)) {
    if (!tabs) continue;
    assert.match(html[key], /<nav class="nav-tab-wrapper ew-tabs" aria-label="[^"]+">/, `${key}: ew-tabs`);
    assert.match(html[key], /class="nav-tab nav-tab-active"[^>]*aria-current="page"/, `${key}: the current tab`);
  }
});
