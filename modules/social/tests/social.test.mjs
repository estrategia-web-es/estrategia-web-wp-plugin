// NIVEL: 2 — Playground WordPress, because it WRITES: saves the network list via REST, uploads SVGs to the media library and renders the block on a page.
import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { reset, wp, saveCompany, seedPage, html, upload, png } from "../../../tests/lib.mjs";

const OPTION = "estrategia_web_social_links";
const saveLinks = async (value) => {
  const r = await wp("/settings", { method: "POST", body: { [OPTION]: value } });
  assert.equal(r.status, 200, r.text);
  return r.json[OPTION];
};
const block = (attrs = {}) => `<!-- wp:estrategia-web/social-links ${JSON.stringify(attrs)} /-->`;
const render = async (attrs, slug) => {
  const page = await seedPage(block(attrs), slug);
  const { status, text } = await html(page.url);
  assert.equal(status, 200);
  return text.match(/<ul[^>]*estrategia-web-social-links[\s\S]*?<\/ul>/)?.[0] ?? "";
};

before(async () => {
  await reset();
  await saveLinks(null); // Not saved: like a site that has just switched the module on.
  await saveCompany({ company_name: "Lavandería Prueba", social: { instagram: "https://instagram.com/prueba", facebook: "" } });
});
after(() => saveLinks(null));

test("migration: with no saved list, the fixed fields of “Company details” move to the list ONCE, with their icon, and are not deleted", async () => {
  // The saveCompany in before() was already another request: its init saw the fields empty. This read is the first with a value.
  await wp("/settings");
  const first = (await wp("/settings")).json;
  assert.deepEqual(first[OPTION], [{ name: "", url: "https://instagram.com/prueba", icon: "instagram", icon_id: 0 }]);
  assert.equal(first.estrategia_web_site.social.instagram, "https://instagram.com/prueba", "the old value stays stored");
  await saveCompany({ social: { facebook: "https://facebook.com/prueba" } });
  await wp("/settings");
  assert.equal((await wp("/settings")).json[OPTION].length, 1, "already migrated: a new fixed field does not get in");
  await saveCompany({ social: { facebook: "" } });
});

test("social.<network> alias until 2.0.0: the shortcode returns the link of the first network in the list with that icon", async () => {
  const shortcode = async (slug) => {
    const page = await seedPage("<!-- wp:paragraph --><p class=\"t-alias\">[estrategia_web_company key=\"social.instagram\"]|[estrategia_web_company key=\"social.x\"]</p><!-- /wp:paragraph -->", slug);
    return (await html(page.url)).text.match(/class="t-alias[^"]*">([^<]*)<\/p>/)?.[1];
  };
  assert.equal(await shortcode("alias-1"), "https://instagram.com/prueba|");
  await saveLinks([
    { url: "https://ejemplo.test/" },
    { url: "https://twitter.com/prueba" },
    { url: "https://www.instagram.com/otra" },
    { url: "https://instagram.com/prueba", icon: "instagram" },
  ]);
  assert.equal(await shortcode("alias-2"), "https://www.instagram.com/otra|https://twitter.com/prueba", "icon guessed, and the first one");
  await saveLinks([{ url: "https://instagram.com/prueba", icon: "instagram" }]);
});

test("with the migrated list, the block renders the network, accessible and in a new tab", async () => {
  const ul = await render({}, "redes-empresa");
  const links = [...ul.matchAll(/<a\b[\s\S]*?<\/a>/g)].map((m) => m[0]);
  assert.equal(links.length, 1, ul);
  assert.match(links[0], /href="https:\/\/instagram\.com\/prueba"/);
  assert.match(links[0], /target="_blank" rel="noopener noreferrer"/);
  assert.match(links[0], /<svg[^>]*aria-hidden="true"[^>]*>/);
  assert.match(links[0], /<svg[^>]*fill="currentColor"/);
  assert.doesNotMatch(links[0], /<title>/, "no <title> in the drawing: the link carries the name");
  assert.match(links[0], /<span class="estrategia-web-social-links__sr">Instagram de Lavandería Prueba \(se abre en una pestaña nueva\)<\/span>/);
  assert.match(ul, /is-display-icons is-size-medium/);
  assert.match(ul, /is-network-instagram/);
});

test("the saved list rules, in its order: icon guessed from the link, email to mailto: without new tab, javascript: and the made-up icon dropped", async () => {
  const saved = await saveLinks([
    { name: "", url: "https://www.tiktok.com/@prueba" },
    { name: "Correo", url: "hola@ejemplo.test" },
    { name: "Mala", url: "javascript:alert(1)" },
    { name: "Nuestra web <b>", url: "https://ejemplo.test/", icon: "inventado" },
    { name: "Mapa", url: "https://www.google.com/maps/place/x", icon: "" },
  ]);
  assert.deepEqual(
    saved.map((i) => [i.name, i.url, i.icon, i.icon_id]),
    [
      ["", "https://www.tiktok.com/@prueba", "", 0],
      ["Correo", "mailto:hola@ejemplo.test", "", 0],
      ["Nuestra web", "https://ejemplo.test/", "", 0],
      ["Mapa", "https://www.google.com/maps/place/x", "", 0],
    ]
  );
  const ul = await render({ display: "icons-names", size: "large" }, "redes-lista");
  const items = [...ul.matchAll(/<li class="estrategia-web-social-links__item is-network-([a-z-]+)">([\s\S]*?)<\/li>/g)].map((m) => [m[1], m[2]]);
  assert.deepEqual(items.map((i) => i[0]), ["tiktok", "email", "website", "google-maps"], "the company ones no longer show");
  assert.match(items[0][1], /<span class="estrategia-web-social-links__name" aria-hidden="true">TikTok<\/span>/, "without a name, the icon's");
  assert.match(items[1][1], /href="mailto:hola@ejemplo\.test"/);
  assert.doesNotMatch(items[1][1], /target=/, "a mailto: does not open a tab");
  assert.match(items[1][1], /Correo de Lavandería Prueba<\/span>/);
  assert.match(ul, /is-display-icons-names is-size-large/);
});

test("custom SVG: only an SVG from the media library is valid as an icon, and it renders sanitized and with its colours", async () => {
  await saveCompany({ allow_svg: true });
  const svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10" width="300" height="300"><script>alert(1)</script><path d="M0 0h10v10z" fill="#e1306c" onclick="alert(2)"/></svg>';
  const up = await upload("red-propia.svg", "image/svg+xml", svg);
  assert.equal(up.status, 201, up.text);
  const pic = await upload("no-svg.png", "image/png", png());
  assert.equal(pic.status, 201, pic.text);

  const saved = await saveLinks([
    { name: "Mi red", url: "https://mi-red.test/", icon_id: up.json.id },
    { name: "Con PNG", url: "https://otra.test/", icon_id: pic.json.id },
  ]);
  assert.equal(saved[0].icon_id, up.json.id);
  assert.equal(saved[1].icon_id, 0, "a PNG is not an icon");

  const ul = await render({}, "redes-svg");
  const first = ul.match(/<li[\s\S]*?<\/li>/)[0];
  assert.match(first, /<path d="M0 0h10v10z" fill="#e1306c"\/>/, "with its colour");
  assert.doesNotMatch(first, /script|onclick|width="300"/i);
  assert.match(first, /<svg[^>]*class="estrategia-web-social-links__icon"/);
  await saveCompany({ allow_svg: false });
});

test("empty saved list: the block renders nothing and is not migrated again (the fixed field still has a value)", async () => {
  assert.deepEqual(await saveLinks([]), []);
  await wp("/settings");
  assert.deepEqual((await wp("/settings")).json[OPTION], []);
  const page = await seedPage(block(), "redes-vacia");
  const { text } = await html(page.url);
  assert.doesNotMatch(text, /estrategia-web-social-links__item/);
});

test("an author cannot read or change the list via REST (it belongs to /wp/v2/settings)", async () => {
  const r = await wp("/settings", { as: "author", method: "POST", body: { [OPTION]: [{ url: "https://malo.test" }] } });
  assert.equal(r.status, 403);
});
