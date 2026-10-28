// NIVEL: 2 — Playground WordPress. NOT LEVEL 1 BECAUSE: what is tested is the migration on a real request's `init`, what /wp/v2/settings stores (sanitizing, the author's 403, SVGs that must be in the media library) and the block and shortcode as a visitor gets them.
//
// social module: the list of networks (estrategia_web_social_links).
// - migration: with no saved list, the fixed fields of “Company details” move
//   to the list ONCE (on the next request's init), and are not deleted;
// - the saved list rules, in its order: sanitized, rendered by the block,
//   and read by the social.<network> alias of the shortcode;
// - custom SVG icons only from the media library;
// - an empty saved list renders nothing and is not migrated again;
// - an author cannot change it.
//
// Requests (28-10-2026): ONE page holds every block variant and the
// shortcode, seeded once and only re-read after each change of the list;
// the list of the sanitizing, the alias and the SVG cases is ONE saved list
// (they were three saves and three seeded pages); «a new fixed field does not
// get in once migrated» was the same rule as «an empty list is not migrated
// again» (Links::saved()): only the second is kept. reset() deletes the list
// and the company details, so there is no after().
// Seen red (28-10-2026) with migrate() returning early (first test: the
// stored list null), with any media file accepted as an icon (second: the
// PNG kept its icon_id), and with saved() false for an empty list plus
// update_option() in migrate() (third: the empty list was migrated again).
// saved() alone does NOT do it: add_option() refuses to overwrite the empty
// list, so that mutation is not a bug.
// Round 2 (28-10-2026): reset, company details and page in ONE request (core /setup). Seen red
// again with migrate() returning early (the migration test).
import { test, before } from "node:test";
import assert from "node:assert/strict";
import { testApi, wp, html, upload, png, credentials, adminSession } from "../../../tests/lib.mjs";

const OPTION = "estrategia_web_social_links";
const saveLinks = async (value) => {
  const r = await wp("/settings", { method: "POST", body: { [OPTION]: value } });
  assert.equal(r.status, 200, r.text);
  return r.json[OPTION];
};
const block = (cls, attrs = {}) => `<!-- wp:group {"className":"${cls}"} --><div class="wp-block-group ${cls}"><!-- wp:estrategia-web/social-links ${JSON.stringify(attrs)} /--></div><!-- /wp:group -->`;
const CONTENT =
  block("t-default") +
  block("t-names", { display: "icons-names", size: "large" }) +
  '<!-- wp:paragraph --><p class="t-alias">[estrategia_web_company key="social.instagram"]|[estrategia_web_company key="social.x"]</p><!-- /wp:paragraph -->';

let url, svg, pic, admin, uploads;
/** The page as a visitor gets it: each block's <ul> (by its wrapper) and the shortcode's text. */
const read = async () => {
  const { status, text } = await html(url);
  assert.equal(status, 200);
  const ul = (cls) => text.slice(text.indexOf(cls)).match(/<ul[^>]*estrategia-web-social-links[\s\S]*?<\/ul>|<\/div>/)?.[0] ?? "";
  return { text, plain: ul("t-default"), names: ul("t-names"), alias: text.match(/class="t-alias[^"]*">([^<]*)<\/p>/)?.[1] };
};

before(async () => {
  // ONE request (round 2, the core /setup route): reset (the list not saved, like a site that has
  // just switched the module on), the company details saved as through /wp/v2/settings, and the
  // page. That request's init ran before the save: the migration is on the NEXT request.
  let setup;
  [setup, , admin] = await Promise.all([
    testApi("/setup", { method: "POST", body: { company: { company_name: "Lavandería Prueba", social: { instagram: "https://instagram.com/prueba", facebook: "" }, allow_svg: true }, pages: [{ slug: "redes", content: CONTENT }] } }),
    credentials(),
    adminSession(),
  ]);
  ({ url } = setup.pages[0]);
});
const DRAWING = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10" width="300" height="300"><script>alert(1)</script><path d="M0 0h10v10z" fill="#e1306c" onclick="alert(2)"/></svg>';

test("migration: with no saved list, the fixed fields of “Company details” move to the list ONCE, with their icon, and are not deleted; the block renders it, accessible and in a new tab; the alias reads it", async () => {
  // One after the other: this read's init migrates. (At once, the loser of the add_option race may answer from its cache of «no option».)
  const page = await read();
  // The icons of the next test, uploaded now (allow_svg is saved, and the migration is done: no
  // other request races this one's init) while this test reads the settings; awaited there.
  uploads = Promise.all([upload("red-propia.svg", "image/svg+xml", DRAWING), upload("no-svg.png", "image/png", png())]);
  uploads.catch(() => {}); // Its error is reported by the test that awaits it.
  const settings = (await wp("/settings")).json;
  assert.deepEqual(settings[OPTION], [{ name: "", url: "https://instagram.com/prueba", icon: "instagram", icon_id: 0 }]);
  assert.equal(settings.estrategia_web_site.social.instagram, "https://instagram.com/prueba", "the old value stays stored");

  const links = [...page.plain.matchAll(/<a\b[\s\S]*?<\/a>/g)].map((m) => m[0]);
  assert.equal(links.length, 1, page.plain);
  assert.match(links[0], /href="https:\/\/instagram\.com\/prueba"/);
  assert.match(links[0], /target="_blank" rel="noopener noreferrer"/);
  assert.match(links[0], /<svg[^>]*aria-hidden="true"[^>]*>/);
  assert.match(links[0], /<svg[^>]*fill="currentColor"/);
  assert.doesNotMatch(links[0], /<title>/, "no <title> in the drawing: the link carries the name");
  assert.match(links[0], /<span class="estrategia-web-social-links__sr">Instagram de Lavandería Prueba \(se abre en una pestaña nueva\)<\/span>/);
  assert.match(page.plain, /is-display-icons is-size-medium/);
  assert.match(page.plain, /is-network-instagram/);
  assert.equal(page.alias, "https://instagram.com/prueba|", "social.<network> alias until 2.0.0");
});

test("the saved list rules, in its order: icon guessed, email to mailto: without new tab, javascript: and the made-up icon dropped; the alias gives the FIRST of a network; only an SVG of the media library is an icon, sanitized and with its colours", async () => {
  [svg, pic] = await uploads;
  assert.equal(svg.status, 201, svg.text);
  assert.equal(pic.status, 201, pic.text);

  const saved = await saveLinks([
    { name: "", url: "https://www.tiktok.com/@prueba" },
    { name: "Correo", url: "hola@ejemplo.test" },
    { name: "Mala", url: "javascript:alert(1)" },
    { name: "Nuestra web <b>", url: "https://ejemplo.test/", icon: "inventado" },
    { name: "Mapa", url: "https://www.google.com/maps/place/x", icon: "" },
    { name: "", url: "https://twitter.com/prueba" },
    { name: "", url: "https://www.instagram.com/otra" },
    { name: "", url: "https://instagram.com/prueba", icon: "instagram" },
    { name: "Mi red", url: "https://mi-red.test/", icon_id: svg.json.id },
    { name: "Con PNG", url: "https://otra.test/", icon_id: pic.json.id },
  ]);
  assert.deepEqual(
    saved.map((i) => [i.name, i.url, i.icon, i.icon_id]),
    [
      ["", "https://www.tiktok.com/@prueba", "", 0],
      ["Correo", "mailto:hola@ejemplo.test", "", 0],
      ["Nuestra web", "https://ejemplo.test/", "", 0],
      ["Mapa", "https://www.google.com/maps/place/x", "", 0],
      ["", "https://twitter.com/prueba", "", 0],
      ["", "https://www.instagram.com/otra", "", 0],
      ["", "https://instagram.com/prueba", "instagram", 0],
      ["Mi red", "https://mi-red.test/", "", svg.json.id],
      ["Con PNG", "https://otra.test/", "", 0],
    ],
    "javascript: dropped, the made-up icon emptied, a PNG is not an icon"
  );

  const page = await read();
  assert.equal(page.alias, "https://www.instagram.com/otra|https://twitter.com/prueba", "alias: icon guessed, and the first one");
  const items = [...page.names.matchAll(/<li class="estrategia-web-social-links__item is-network-([a-z-]+)">([\s\S]*?)<\/li>/g)].map((m) => [m[1], m[2]]);
  assert.deepEqual(items.slice(0, 4).map((i) => i[0]), ["tiktok", "email", "website", "google-maps"], "in the saved order; the company ones no longer show");
  assert.equal(items.length, 9);
  assert.match(items[0][1], /<span class="estrategia-web-social-links__name" aria-hidden="true">TikTok<\/span>/, "without a name, the icon's");
  assert.match(items[1][1], /href="mailto:hola@ejemplo\.test"/);
  assert.doesNotMatch(items[1][1], /target=/, "a mailto: does not open a tab");
  assert.match(items[1][1], /Correo de Lavandería Prueba<\/span>/);
  assert.match(page.names, /is-display-icons-names is-size-large/);

  const own = items.find((i) => i[1].includes("https://mi-red.test/"))[1];
  assert.match(own, /<path d="M0 0h10v10z" fill="#e1306c"\/>/, "with its colour");
  assert.doesNotMatch(own, /script|onclick|width="300"/i);
  assert.match(own, /<svg[^>]*class="estrategia-web-social-links__icon"/);
});

test("empty saved list: the block renders nothing and is not migrated again (the fixed field still has a value); an author cannot change it; “Company details” has no fixed network fields, “Social networks” is in the menu", async () => {
  assert.deepEqual(await saveLinks([]), []);
  // Two later requests at once: whichever init would migrate, one of them shows it.
  const [settings, page, author, company] = await Promise.all([
    wp("/settings"),
    read(),
    wp("/settings", { as: "author", method: "POST", body: { [OPTION]: [{ url: "https://malo.test" }] } }),
    admin.get("/wp-admin/admin.php?page=estrategia-web-site"),
  ]);
  assert.deepEqual(settings.json[OPTION], []);
  assert.doesNotMatch(page.text, /estrategia-web-social-links__item/);
  assert.equal(author.status, 403, "the list belongs to /wp/v2/settings: an author cannot change it");
  // Plain HTML of wp-admin (it was the first case of the level 3 social-admin.test.mjs).
  assert.equal(company.status, 200);
  assert.doesNotMatch(company.text, /name="estrategia_web_site\[social\]/, "“Company details” no longer has the fixed network fields");
  assert.match(company.text.match(/<a[^>]*href=["'][^"']*page=estrategia-web-social["'][^>]*>[\s\S]*?<\/a>/)?.[0] ?? "", /Redes sociales/, "“Social networks” is in the Estrategia Web menu");
});
