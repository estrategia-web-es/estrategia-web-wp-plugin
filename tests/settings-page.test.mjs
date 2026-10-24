// NIVEL: 2 — Playground WordPress, because what is tested is which tabs WordPress shows depending on the modules that are on, and what options.php really stores when each screen's form is sent (a cookie session and the screens' own HTML forms, tests/lib.mjs adminSession; no browser: the forms are plain HTML).
//
// What and why: includes/admin/class-settings-page.php (the registry, «the
// edge case» of two screens saving one option and «the trap» of options.php
// sending null). The pure layout() is level 1 (tests/admin-pure.test.mjs).
//
// How to maintain: a new tab gets its line in «each tab iff its module»; a
// new option shared by two screens gets its round trip in the save tests.
import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { adminSession, reset, saveCompany, testApi, wp } from "./lib.mjs";

let s, available;
const SETTINGS = "/wp-admin/admin.php?page=estrategia-web-settings";
const onlyWithout = (off) => testApi("/modules", { method: "POST", body: { active: available.filter((m) => !off.includes(m)) } });
const tabsOf = (html) => [...html.matchAll(/<a [^>]*class="nav-tab[^"]*" data-tab="([^"]+)"/g)].map((m) => m[1]);

before(async () => {
  await reset();
  ({ available } = await testApi("/modules"));
  s = await adminSession();
});
after(async () => {
  await testApi("/modules", { method: "POST", body: { active: null } });
  await wp("/settings", { method: "POST", body: { estrategia_web_uploads: { max_mb: 20, threshold: 2560, delete_original: true }, estrategia_web_google_tags: { ga4: "", ads: "", ads_form_label: "", gtm: "", mode: "basic" } } });
  await reset();
});

test("each tab appears iff its module is on", async () => {
  await onlyWithout([]);
  let page = (await s.get(SETTINGS)).text;
  assert.deepEqual(tabsOf(page), ["cookies", "analytics", "media"], "everything on");
  assert.match(page, /nav-tab-active" data-tab="cookies"/, "without ?tab, the first one");

  const analytics = (await s.get(SETTINGS + "&tab=analytics")).text;
  assert.ok(analytics.includes('name="estrategia_web_google_tags[ga4]"') && analytics.includes('name="estrategia_web_site[matomo_url]"'), "Google tags and Matomo share the tab");
  assert.equal((analytics.match(/<form [^>]*action="options\.php"/g) || []).length, 1, "ONE form and one Save for the tab");
  const media = (await s.get(SETTINGS + "&tab=media")).text;
  assert.ok(media.includes('name="estrategia_web_site[allow_svg]"') && media.includes('name="estrategia_web_uploads[max_mb]"'), "SVG and uploads share Media");

  await onlyWithout(["google-tags"]);
  page = (await s.get(SETTINGS + "&tab=analytics")).text;
  assert.deepEqual(tabsOf(page), ["cookies", "analytics", "media"]);
  assert.ok(!page.includes("estrategia_web_google_tags[") && page.includes("estrategia_web_site[matomo_url]"), "google-tags off: only Matomo");

  await onlyWithout(["google-tags", "matomo"]);
  assert.deepEqual(tabsOf((await s.get(SETTINGS)).text), ["cookies", "media"], "both analytics modules off: no Analytics");

  await onlyWithout(["consent", "google-tags"]); // google-tags requires consent.
  page = (await s.get(SETTINGS)).text;
  assert.deepEqual(tabsOf(page), ["analytics", "media"], "consent off: no Cookies");
  assert.equal((await s.get("/wp-admin/admin.php?page=estrategia-web-cookies")).status, 403, "and its old URL is not redirected anywhere");

  await onlyWithout(["uploads"]);
  page = (await s.get(SETTINGS + "&tab=media")).text;
  assert.ok(page.includes("estrategia_web_site[allow_svg]") && !page.includes("estrategia_web_uploads["), "uploads off: Media has only SVG (site is core)");

  page = (await s.get(SETTINGS + "&tab=nonexistent")).text;
  assert.match(page, /nav-tab-active" data-tab="cookies"/, "an unknown tab opens the first one");
  await onlyWithout([]);
});

const COMPANY = { company_name: "Lavandería de prueba", phone: "971 00 00 00", email: "hola@ejemplo.test", matomo_url: "https://stats.ejemplo.test/", matomo_site_id: 7, matomo_mode: "hybrid", allow_svg: false };
const stored = async () => (await wp("/settings")).json;

test("saving Settings › Media does not wipe the company details or Matomo (same option), and stores SVG and uploads", async () => {
  await onlyWithout([]);
  await saveCompany(COMPANY);
  const page = (await s.get(SETTINGS + "&tab=media")).text;
  const r = await s.submit(page, "options.php", { "estrategia_web_site[allow_svg]": ["0", "1"], "estrategia_web_uploads[max_mb]": "12" });
  assert.equal(r.status, 302);
  assert.match(r.location, /tab=media.*settings-updated=true/, "back to the same tab");
  const after = await stored();
  assert.equal(after.estrategia_web_site.allow_svg, true);
  assert.equal(after.estrategia_web_uploads.max_mb, 12);
  for (const [k, v] of Object.entries(COMPANY)) if (k !== "allow_svg") assert.equal(after.estrategia_web_site[k], v, `${k} survived`);
});

test("saving Company details does not wipe SVG or Matomo, and vice versa from Settings › Analytics", async () => {
  await saveCompany({ ...COMPANY, allow_svg: true });
  const company = (await s.get("/wp-admin/admin.php?page=estrategia-web-site")).text;
  assert.ok(!company.includes("estrategia_web_site[allow_svg]") && !company.includes("estrategia_web_site[matomo_url]"), "Company details has neither any more");
  let r = await s.submit(company, "options.php", { "estrategia_web_site[phone]": "971 11 11 11" });
  assert.equal(r.status, 302);
  let after = (await stored()).estrategia_web_site;
  assert.equal(after.phone, "971 11 11 11");
  assert.equal(after.allow_svg, true, "allow_svg survived");
  assert.equal(after.matomo_url, COMPANY.matomo_url);
  assert.equal(after.matomo_site_id, 7);
  assert.equal(after.matomo_mode, "hybrid");

  const analytics = (await s.get(SETTINGS + "&tab=analytics")).text;
  r = await s.submit(analytics, "options.php", { "estrategia_web_site[matomo_site_id]": "9", "estrategia_web_google_tags[ga4]": "G-ABC1234567" });
  assert.equal(r.status, 302);
  const all = await stored();
  after = all.estrategia_web_site;
  assert.equal(after.matomo_site_id, 9);
  assert.equal(all.estrategia_web_google_tags.ga4, "G-ABC1234567", "Google tags saved in the same form");
  assert.equal(after.phone, "971 11 11 11", "the company details survived");
  assert.equal(after.company_name, COMPANY.company_name);
  assert.equal(after.allow_svg, true, "and SVG too");
});

test("the trap: saving WordPress's own Settings → Media no longer resets the uploads settings", async () => {
  await wp("/settings", { method: "POST", body: { estrategia_web_uploads: { max_mb: 7, threshold: 1600, delete_original: false } } });
  const media = (await s.get("/wp-admin/options-media.php")).text;
  assert.ok(!media.includes("estrategia_web_uploads["), "its fields are not on WordPress's screen any more");
  const r = await s.submit(media, "options.php");
  assert.equal(r.status, 302);
  assert.deepEqual((await stored()).estrategia_web_uploads, { max_mb: 7, threshold: 1600, delete_original: false });
});
