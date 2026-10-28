// NIVEL: 2 — Playground WordPress. NOT LEVEL 1 BECAUSE: what is tested is which tabs WordPress shows depending on the modules that are on, and what options.php really stores when each screen's form is sent (a cookie session and the screens' own HTML forms, tests/lib.mjs adminSession; no browser: the forms are plain HTML). The pure layout() is level 1 (tests/admin-pure.test.mjs).
//
// What and why: includes/admin/class-settings-page.php (the registry, «the
// edge case» of two screens saving one option and «the trap» of options.php
// sending null).
//
// 28-10-2026: from ~38 requests to 17, and no request waits for one it does
// not need. The reset, every module on and the company details are one
// request (/setup), the login goes alongside, and then EVERY screen is
// requested at once: the ones with everything on (used both for the tab
// checks and to send their forms) and the ones with modules off, read with
// ?ew-test-modules=a,b,c (tests/wp/estrategia-web-site-test.php: that request
// reads the module list as saved, without saving it). The saves start as soon
// as their screen arrives, while the other screens are still being served.
// What is stored is read raw (/options), without /wp/v2/settings and its
// application password. The six module combinations of the tab test are two:
// «google-tags off» is covered by «consent and google-tags off» (google-tags
// requires consent), and «uploads off» shares its list with «google-tags and
// matomo off»; «no ?tab» and «an unknown tab» are one check (both fall back
// to the first tab), on the consent-off read (the first tab is then
// Analytics). Gone: Cookies' old URL answering 403 with consent off: it is
// the same «old URL whose tab does not exist» path as Google's 403 in
// tests/menu.test.mjs (and Menu::legacy_target() is level 1,
// tests/admin-pure.test.mjs). WordPress's own Media screen (the trap) is
// sent alongside the Company details save (another option) and shares its
// read. The last read also deletes the two options /reset does not
// (/options with «delete»); after() only cleans if a test failed first.
// Seen red (28-10-2026) with the tabs expected in another order, «max_mb»
// expected 13, the uploads expected back to their defaults after WordPress's
// Media save and the Cookies tab expected with consent off: each of the four
// tests fails (and again after ?ew-test-modules).
//
// How to maintain: a new tab gets its line in «each tab iff its module»; a
// new option shared by two screens gets its round trip in the save tests.
// The cleanup in after() is because /reset does not delete
// estrategia_web_uploads nor estrategia_web_google_tags.
import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { adminSession, testApi } from "./lib.mjs";

const SETTINGS = "/wp-admin/admin.php?page=estrategia-web-settings";
const COMPANY = { company_name: "Lavandería de prueba", phone: "971 00 00 00", email: "hola@ejemplo.test", matomo_url: "https://stats.ejemplo.test/", matomo_site_id: 7, matomo_mode: "hybrid", allow_svg: false };
const tabsOf = (html) => [...html.matchAll(/<a [^>]*class="nav-tab[^"]*" data-tab="([^"]+)"/g)].map((m) => m[1]);
const CLEANUP = ["estrategia_web_uploads", "estrategia_web_google_tags"]; // /reset does not delete them.
let cleaned = false;
// The last read also deletes what /reset does not (cleanup: true); after() only cleans if a test failed before it.
const stored = ({ cleanup = false } = {}) => testApi("/options", { method: "POST", body: { names: ["estrategia_web_site", ...CLEANUP], ...(cleanup ? { delete: CLEANUP } : {}) } });

let s, screens;
before(async () => {
  let available;
  [{ available }, s] = await Promise.all([testApi("/setup", { method: "POST", body: { modules: "all", company: COMPANY } }), adminSession()]);
  const without = (off) => "&ew-test-modules=" + available.filter((m) => !off.includes(m)).join(",");
  const noConsent = without(["consent", "google-tags"]); // google-tags requires consent.
  // Requested now, awaited by each test: the server works through them while the tests run.
  screens = Object.fromEntries(
    Object.entries({
      analytics: SETTINGS + "&tab=analytics",
      media: SETTINGS + "&tab=media",
      company: "/wp-admin/admin.php?page=estrategia-web-site",
      wpMedia: "/wp-admin/options-media.php",
      noConsent: SETTINGS + "&tab=nonexistent" + noConsent,
      noAnalyticsNoUploads: SETTINGS + "&tab=media" + without(["google-tags", "matomo", "uploads"]),
    }).map(([k, url]) => [k, s.get(url)])
  );
  // A test that fails early must not leave the rest as unhandled rejections.
  for (const p of Object.values(screens)) p.catch(() => {});
});
after(() => cleaned || testApi("/setup", { method: "POST", body: { options: Object.fromEntries(CLEANUP.map((n) => [n, null])) } }));
const text = async (k) => (await screens[k]).text;

test("everything on: three tabs; Analytics and Media each ONE form for two modules", async () => {
  const [analytics, media] = await Promise.all(["analytics", "media"].map(text));
  assert.deepEqual(tabsOf(analytics), ["cookies", "analytics", "media"], "everything on");
  assert.ok(analytics.includes('name="estrategia_web_google_tags[ga4]"') && analytics.includes('name="estrategia_web_site[matomo_url]"'), "Google tags and Matomo share the tab");
  assert.equal((analytics.match(/<form [^>]*action="options\.php"/g) || []).length, 1, "ONE form and one Save for the tab");
  assert.ok(media.includes('name="estrategia_web_site[allow_svg]"') && media.includes('name="estrategia_web_uploads[max_mb]"'), "SVG and uploads share Media");
});

let afterMedia;
test("saving Settings › Media does not wipe the company details or Matomo (same option), and stores SVG and uploads", async () => {
  const r = await s.submit(await text("media"), "options.php", { "estrategia_web_site[allow_svg]": ["0", "1"], "estrategia_web_uploads[max_mb]": "12" });
  assert.equal(r.status, 302);
  assert.match(r.location, /tab=media.*settings-updated=true/, "back to the same tab");
  afterMedia = await stored();
  assert.equal(afterMedia.estrategia_web_site.allow_svg, true);
  assert.equal(afterMedia.estrategia_web_uploads.max_mb, 12);
  for (const [k, v] of Object.entries(COMPANY)) if (k !== "allow_svg") assert.equal(afterMedia.estrategia_web_site[k], v, `${k} survived`);
});

test("saving Company details does not wipe SVG or Matomo, and vice versa from Settings › Analytics; the trap: saving WordPress's own Settings → Media no longer resets the uploads settings", async () => {
  assert.ok(afterMedia, "needs the Media test's save (uploads not at their defaults: max_mb 12)");
  const [company, wpMedia, analytics] = await Promise.all(["company", "wpMedia", "analytics"].map(text));
  assert.ok(!wpMedia.includes("estrategia_web_uploads["), "the uploads fields are not on WordPress's Media screen any more");
  assert.ok(!company.includes("estrategia_web_site[allow_svg]") && !company.includes("estrategia_web_site[matomo_url]"), "Company details has neither any more");
  // Two different options: Company details and WordPress's Media sent at once, one read for both.
  const [c, m] = await Promise.all([s.submit(company, "options.php", { "estrategia_web_site[phone]": "971 11 11 11" }), s.submit(wpMedia, "options.php")]);
  assert.equal(c.status, 302);
  assert.equal(m.status, 302);
  let all = await stored();
  assert.deepEqual(all.estrategia_web_uploads, afterMedia.estrategia_web_uploads, "WordPress's Media save reset the uploads settings");
  let site = all.estrategia_web_site;
  assert.equal(site.phone, "971 11 11 11");
  assert.equal(site.allow_svg, true, "allow_svg survived (saved by the Media test)");
  assert.equal(site.matomo_url, COMPANY.matomo_url);
  assert.equal(site.matomo_site_id, 7);
  assert.equal(site.matomo_mode, "hybrid");

  const a = await s.submit(analytics, "options.php", { "estrategia_web_site[matomo_site_id]": "9", "estrategia_web_google_tags[ga4]": "G-ABC1234567" });
  assert.equal(a.status, 302);
  all = await stored({ cleanup: true });
  cleaned = true;
  site = all.estrategia_web_site;
  assert.equal(site.matomo_site_id, 9);
  assert.equal(all.estrategia_web_google_tags.ga4, "G-ABC1234567", "Google tags saved in the same form");
  assert.equal(site.phone, "971 11 11 11", "the company details survived");
  assert.equal(site.company_name, COMPANY.company_name);
  assert.equal(site.allow_svg, true, "and SVG too");
});

test("each tab iff its module: consent off, no Cookies, and an unknown tab opens the first one left (Analytics); google-tags off, Analytics with only Matomo; both analytics modules off, no Analytics; uploads off, Media with only SVG", async () => {
  const [page, mediaOnly] = await Promise.all(["noConsent", "noAnalyticsNoUploads"].map((k) => screens[k]));
  assert.deepEqual(tabsOf(page.text), ["analytics", "media"], "consent off: no Cookies");
  assert.match(page.text, /nav-tab-active" data-tab="analytics"/, "an unknown tab (or none) opens the first one");
  assert.ok(!page.text.includes("estrategia_web_google_tags[") && page.text.includes("estrategia_web_site[matomo_url]"), "google-tags off: only Matomo");
  assert.deepEqual(tabsOf(mediaOnly.text), ["cookies", "media"], "both analytics modules off: no Analytics");
  assert.ok(mediaOnly.text.includes("estrategia_web_site[allow_svg]") && !mediaOnly.text.includes("estrategia_web_uploads["), "uploads off: Media has only SVG (site is core)");
});
