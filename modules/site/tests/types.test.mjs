// NIVEL: 2 — Playground WordPress. NOT LEVEL 1 BECAUSE: what is tested is what WordPress does with the site's types as the option switches them — registered or not for REST and the router, the rows kept in the database while off, the rewrite rules rebuilt for /clientes/ or a custom slug, the meta sanitized by its REST registration — and the Query rendering a sector's clients with their images the image-fit class and each client's business type through core/post-meta; the names are type-names.test.mjs (level 1). No browser.
//
// 28-10-2026, from ~70 s: the independent requests of each step in parallel;
// the client Query by sector and the image fit one are ONE Query (the fit
// cases are the sector's clients); the REST route and the post 404 once the
// type is off (core's consequence of the 404 of the type) are gone;
// and gone what content-types/tests/constructor-templates.test.mjs already
// proves through REST (the rating clamped to 5, a related «client» that is
// not a client saved as 0).
// Round 2 (same day): the client's business type (from meta-fields.test.mjs)
// rides on the same Query; «fields (meta) via REST» is gone — the testimonial's
// related client and its text fields are content-types/tests/constructor-templates.test.mjs,
// the location's street lines content-types/tests/location-box.test.mjs, its map
// search location.test.mjs (all level 2), and what a text or an email field
// keeps is the field type's sanitizer, the same for every type.
// Seen red (28-10-2026) with the expected Spanish label changed («Clientes»),
// with the restaurant expected in the hotels' Query, and with Hotel Mar's
// business type expected as «Agencia».

import { test, before } from "node:test";
import assert from "node:assert/strict";
import { reset, wp, saveCompany, seedPage, html, rawPost, upload, png, BASE, type } from "../../../tests/lib.mjs";

before(reset);

let before_id, link;

test("enabling `client`: the type exists with its Spanish labels and only the enabled one; disabled, it does not exist but its content stays in the database", async () => {
  await saveCompany({ enabled_types: ["client"] });
  // One GET of every type (round 2): the client with its labels, and the testimonial absent.
  const [all, created] = await Promise.all([
    wp("/types"),
    wp(`/${type("client")}`, { method: "POST", body: { title: "Cliente de antes", status: "publish", meta: { estrategia_web_client_url: "https://antes.test" } } }),
  ]);
  const registered = all.json[type("client")];
  assert.ok(registered, "the client type is registered: " + Object.keys(all.json ?? {}).join(", "));
  assert.equal(registered.name, "Clientes");
  assert.deepEqual(registered.taxonomies, [type("client_sector")]);
  assert.equal(type("testimonial") in all.json, false, "only the enabled one");
  assert.equal(created.status, 201, created.text);
  before_id = created.json.id;
  link = created.json.link;

  await saveCompany({ enabled_types: [] });
  // Its REST route and its post going 404 too are core's consequence of the type not being registered.
  const [gone, raw] = await Promise.all([wp(`/types/${type("client")}`), rawPost(before_id)]);
  assert.equal(gone.status, 404, "disabled, WordPress does not know it");
  assert.equal(raw.post_type, type("client"), "but the row is still in wp_posts");
  assert.equal(raw.post_status, "publish");
});

test("re-enabled, it finds its content with its fields, at the Spanish slug (/clientes/), and the single page responds", async () => {
  await saveCompany({ enabled_types: ["client"] });
  const [back, page] = await Promise.all([wp(`/${type("client")}/${before_id}`), html(link)]);
  assert.equal(back.status, 200);
  assert.equal(back.json.title.rendered, "Cliente de antes");
  assert.equal(back.json.meta.estrategia_web_client_url, "https://antes.test", "with its fields");
  assert.match(back.json.link, /\/clientes\/cliente-de-antes\/$/);
  assert.equal(page.status, 200, "the URL rules were rebuilt on their own when enabling");
  assert.match(page.text, /Cliente de antes/);
});

// ONE Query of clients filtered by sector, for both tests: the sector's clients with their images, and
// the image fit (Hotel Sol «contain», Hotel Mar by default; Restaurante Luna, out of the sector, sends
// a value that is not one of the two).
let queries;
const clientQuery = () =>
  (queries ??= (async () => {
    const [hotel, restaurants, ...imgs] = await Promise.all([
      wp(`/${type("client_sector")}`, { method: "POST", body: { name: "Hoteles" } }),
      wp(`/${type("client_sector")}`, { method: "POST", body: { name: "Restaurantes" } }),
      upload("logo-hotel-sol.png", "image/png", png(4, 2, [200, 0, 0])),
      upload("logo-hotel-mar.png", "image/png", png(4, 2, [0, 200, 0])),
    ]);
    assert.equal(hotel.status, 201, hotel.text);
    for (const img of imgs) assert.equal(img.status, 201, img.text);
    const client = async (title, body) => {
      const c = await wp(`/${type("client")}`, { method: "POST", body: { title, status: "publish", ...body } });
      assert.equal(c.status, 201, c.text);
      return c.json;
    };
    const sector = { queryId: 7, query: { perPage: 10, pages: 0, offset: 0, postType: type("client"), order: "asc", orderBy: "title", inherit: false, taxQuery: { [type("client_sector")]: [hotel.json.id] } } };
    const [sol, mar, luna, page] = await Promise.all([
      client("Hotel Sol", { [type("client_sector")]: [hotel.json.id], featured_media: imgs[0].json.id, meta: { estrategia_web_client_image_fit: "contain", estrategia_web_client_business_type: "  Agencia de <b>alquiler</b> vacacional " } }),
      client("Hotel Mar", { [type("client_sector")]: [hotel.json.id], featured_media: imgs[1].json.id, meta: { estrategia_web_client_business_type: "Hotel" } }),
      client("Restaurante Luna", { [type("client_sector")]: [restaurants.json.id], meta: { estrategia_web_client_image_fit: "stretch" } }),
      seedPage(
        `<!-- wp:query ${JSON.stringify(sector)} -->
<div class="wp-block-query"><!-- wp:post-template -->
<!-- wp:post-featured-image /-->
<!-- wp:post-title /-->
<!-- wp:paragraph ${JSON.stringify({ className: "t-tipo", metadata: { bindings: { content: { source: "core/post-meta", args: { key: "estrategia_web_client_business_type" } } } } })} -->
<p class="t-tipo">x</p>
<!-- /wp:paragraph -->
<!-- /wp:post-template --></div>
<!-- /wp:query -->`,
        "clientes-consultas"
      ),
    ]);
    const { text } = await html(page.url);
    const list = text.match(/<ul[^>]*wp-block-post-template[\s\S]*?<\/ul>/)?.[0];
    assert.ok(list, "the query renders its list");
    return { imgs: imgs.map((i) => i.json.source_url), list, sol, mar, luna };
  })());

test("a Query (core/query) of `client` filtered by client_sector renders those of that sector with title and image", async () => {
  const { imgs, list } = await clientQuery();
  const items = [...list.matchAll(/<li[\s\S]*?<\/li>/g)].map((m) => m[0]);
  assert.equal(items.length, 2, "only the two from the sector");
  assert.match(items[0], /Hotel Mar/, "ordered by title");
  assert.match(items[1], /Hotel Sol/);
  const file = (u) => u.split("/").pop().replace(/\.png$/, "");
  assert.ok(items[0].includes(file(imgs[1])) && /<img/.test(items[0]), "with its featured image");
  assert.ok(items[1].includes(file(imgs[0])));
  assert.ok(!list.includes("Restaurante Luna"), "the one from another sector does not appear");
});

test("estrategia_web_client_image_fit (0.2.0): 'cover' by default, sanitized to cover|contain, and 'contain' puts has-image-fit-contain on the Query's <li>", async () => {
  const { list, sol, mar, luna } = await clientQuery();
  assert.equal(sol.meta.estrategia_web_client_image_fit, "contain");
  assert.equal(mar.meta.estrategia_web_client_image_fit, "cover", "by default");
  assert.equal(luna.meta.estrategia_web_client_image_fit, "cover", "another value is sanitized to cover");
  const li = (title) => list.match(new RegExp(`<li[^>]*>(?:(?!</li>)[\\s\\S])*${title}`))?.[0].match(/<li[^>]*>/)[0];
  assert.match(li("Hotel Sol"), /\bhas-image-fit-contain\b/);
  assert.doesNotMatch(li("Hotel Mar"), /has-image-fit/);
});

// Moved here from meta-fields.test.mjs (round 2, 28-10-2026): the same clients, the same Query.
test("the client's business type: sanitized via REST, and core/post-meta renders each one's in the Query (the cards)", async () => {
  const { list, sol } = await clientQuery();
  assert.equal(sol.meta.estrategia_web_client_business_type, "Agencia de alquiler vacacional");
  const li = (title) => list.match(new RegExp(`<li[^>]*>(?:(?!</li>)[\\s\\S])*${title}[\\s\\S]*?</li>`))?.[0] ?? "";
  assert.match(li("Hotel Mar"), /<p class="t-tipo[^"]*">Hotel<\/p>/);
  assert.match(li("Hotel Sol"), /<p class="t-tipo[^"]*">Agencia de alquiler vacacional<\/p>/);
});

test("rewrite_slugs (0.2.0): moves the client archive to /cliente/ without a filter, the /clientes/ page is left free, and empty goes back to the usual one", async () => {
  await saveCompany({ enabled_types: ["client"], rewrite_slugs: { client: "cliente" } });
  const [c] = await Promise.all([
    wp(`/${type("client")}`, { method: "POST", body: { title: "Cliente movido", status: "publish" } }),
    seedPage("<!-- wp:paragraph --><p>own-clients-page</p><!-- /wp:paragraph -->", "clientes"),
  ]);
  assert.equal(c.status, 201, c.text);
  assert.match(c.json.link, /\/cliente\/cliente-movido\/$/);
  const [single, archive, own] = await Promise.all([html(c.json.link), html(BASE + "/cliente/"), html(BASE + "/clientes/")]);
  assert.equal(single.status, 200, "the rules were rebuilt when the slug changed");
  assert.match(single.text, /Cliente movido/);
  assert.equal(archive.status, 200, "the archive, at /cliente/");
  assert.equal(own.status, 200);
  assert.match(own.text, /own-clients-page/, "/clientes/ is the PAGE, not the archive");

  await saveCompany({ rewrite_slugs: { client: "" } });
  const back = await html(BASE + "/clientes/cliente-movido/");
  assert.equal(back.status, 200, "empty = the usual slug, and its rules rebuilt");
  assert.match(back.text, /Cliente movido/);
});
