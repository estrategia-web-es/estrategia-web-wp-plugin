// NIVEL: 2 — Playground WordPress, because it WRITES: enables and disables types in the option, creates clients, sectors and images via REST and checks the raw row in the database.
import { test, before } from "node:test";
import assert from "node:assert/strict";
import { reset, wp, saveCompany, seedPage, html, rawPost, upload, png, BASE, type } from "../../../tests/lib.mjs";

before(reset);

let before_id;

test("without `client` in enabled_types the type does not exist; once enabled it exists with its Spanish labels", async () => {
  await saveCompany({ enabled_types: [] });
  assert.equal((await wp(`/types/${type("client")}`)).status, 404);
  assert.equal((await wp(`/${type("client")}`)).status, 404, "nor its REST route");

  await saveCompany({ enabled_types: ["client"] });
  const registered = await wp(`/types/${type("client")}`);
  assert.equal(registered.status, 200);
  assert.equal(registered.json.name, "Clientes");
  assert.deepEqual(registered.json.taxonomies, [type("client_sector")]);
  assert.equal((await wp(`/types/${type("testimonial")}`)).status, 404, "only the enabled one");
});

test("disabling the type does NOT delete its content, and re-enabling it finds it still there", async () => {
  await saveCompany({ enabled_types: ["client"] });
  const created = await wp(`/${type("client")}`, { method: "POST", body: { title: "Cliente de antes", status: "publish", meta: { estrategia_web_client_url: "https://antes.test" } } });
  assert.equal(created.status, 201, created.text);
  before_id = created.json.id;

  await saveCompany({ enabled_types: [] });
  assert.equal((await wp(`/${type("client")}/${before_id}`)).status, 404, "disabled, WordPress does not know it");
  const raw = await rawPost(before_id);
  assert.equal(raw.post_type, type("client"), "but the row is still in wp_posts");
  assert.equal(raw.post_status, "publish");

  await saveCompany({ enabled_types: ["client"] });
  const back = await wp(`/${type("client")}/${before_id}`);
  assert.equal(back.status, 200);
  assert.equal(back.json.title.rendered, "Cliente de antes");
  assert.equal(back.json.meta.estrategia_web_client_url, "https://antes.test", "with its fields");
});

test("the public slug is the Spanish one (/clientes/) and the single page responds after enabling the type", async () => {
  const back = await wp(`/${type("client")}/${before_id}`);
  assert.match(back.json.link, /\/clientes\/cliente-de-antes\/$/);
  const page = await html(back.json.link);
  assert.equal(page.status, 200, "the URL rules were rebuilt on their own when enabling");
  assert.match(page.text, /Cliente de antes/);
});

test("fields (meta) via REST: type and sanitizing (rating 1-5, email, URL)", async () => {
  await saveCompany({ enabled_types: ["client", "testimonial", "location"] });
  const t = await wp(`/${type("testimonial")}`, { method: "POST", body: { title: "Testimonial", content: "Very good", status: "publish", meta: { estrategia_web_testimonial_author: "Ana <b>", estrategia_web_testimonial_rating: 9, estrategia_web_testimonial_client: before_id } } });
  assert.equal(t.status, 201, t.text);
  assert.equal(t.json.meta.estrategia_web_testimonial_author, "Ana");
  assert.equal(t.json.meta.estrategia_web_testimonial_rating, 5, "out of range is clamped to 5");
  assert.equal(t.json.meta.estrategia_web_testimonial_client, before_id);

  const t2 = await wp(`/${type("testimonial")}`, { method: "POST", body: { title: "Another", status: "publish", meta: { estrategia_web_testimonial_client: t.json.id } } });
  assert.equal(t2.json.meta.estrategia_web_testimonial_client, 0, "an id that is not a client is not saved");

  // 1.8.0: the street keeps its two lines (\r\n from a browser becomes \n); the old free texts are gone.
  const l = await wp(`/${type("location")}`, { method: "POST", body: { title: "Palma location", status: "publish", meta: { estrategia_web_location_email: "bad", estrategia_web_location_street: "Edificio Sol\r\nCalle 1", estrategia_web_location_map_query: "  Palma <b>Centro</b> " } } });
  assert.equal(l.status, 201, l.text);
  assert.equal(l.json.meta.estrategia_web_location_email, "");
  assert.equal(l.json.meta.estrategia_web_location_street, "Edificio Sol\nCalle 1");
  assert.equal(l.json.meta.estrategia_web_location_map_query, "Palma Centro");
  assert.equal("estrategia_web_location_address" in l.json.meta, false, "retired in 1.8.0");
});

test("a Query (core/query) of `client` filtered by client_sector renders those of that sector with title and image", async () => {
  await saveCompany({ enabled_types: ["client"] });
  const hotel = await wp(`/${type("client_sector")}`, { method: "POST", body: { name: "Hoteles" } });
  const restaurants = await wp(`/${type("client_sector")}`, { method: "POST", body: { name: "Restaurantes" } });
  assert.equal(hotel.status, 201, hotel.text);

  const make = async (title, sector, color) => {
    const img = await upload(`logo-${sector}-${title.length}.png`, "image/png", png(4, 2, color));
    assert.equal(img.status, 201, img.text);
    const c = await wp(`/${type("client")}`, { method: "POST", body: { title, status: "publish", [type("client_sector")]: [sector], featured_media: img.json.id } });
    assert.equal(c.status, 201, c.text);
    return { id: c.json.id, img: img.json.source_url };
  };
  const a = await make("Hotel Sol", hotel.json.id, [200, 0, 0]);
  const b = await make("Hotel Mar", hotel.json.id, [0, 200, 0]);
  const c = await make("Restaurante Luna", restaurants.json.id, [0, 0, 200]);

  const query = JSON.stringify({ queryId: 7, query: { perPage: 10, pages: 0, offset: 0, postType: type("client"), order: "asc", orderBy: "title", inherit: false, taxQuery: { [type("client_sector")]: [hotel.json.id] } } });
  const page = await seedPage(`<!-- wp:query ${query} -->
<div class="wp-block-query"><!-- wp:post-template -->
<!-- wp:post-featured-image /-->
<!-- wp:post-title /-->
<!-- /wp:post-template --></div>
<!-- /wp:query -->`, "clientes-hoteles");

  const { text } = await html(page.url);
  const list = text.match(/<ul[^>]*wp-block-post-template[\s\S]*?<\/ul>/)?.[0];
  assert.ok(list, "the query renders its list");
  const items = [...list.matchAll(/<li[\s\S]*?<\/li>/g)].map((m) => m[0]);
  assert.equal(items.length, 2, "only the two from the sector");
  assert.match(items[0], /Hotel Mar/, "ordered by title");
  assert.match(items[1], /Hotel Sol/);
  const file = (u) => u.split("/").pop().replace(/\.png$/, "");
  assert.ok(items[0].includes(file(b.img)) && /<img/.test(items[0]), "with its featured image");
  assert.ok(items[1].includes(file(a.img)));
  assert.ok(!list.includes("Restaurante Luna") && !list.includes(file(c.img)), "the one from another sector does not appear");
  assert.ok(BASE);
});

test("rewrite_slugs (0.2.0): moves the client archive to /cliente/ without a filter, the /clientes/ page is left free, and empty goes back to the usual one", async () => {
  await saveCompany({ enabled_types: ["client"], rewrite_slugs: { client: "cliente" } });
  const c = await wp(`/${type("client")}`, { method: "POST", body: { title: "Cliente movido", status: "publish" } });
  assert.equal(c.status, 201, c.text);
  assert.match(c.json.link, /\/cliente\/cliente-movido\/$/);
  const single = await html(c.json.link);
  assert.equal(single.status, 200, "the rules were rebuilt when the slug changed");
  assert.match(single.text, /Cliente movido/);
  assert.equal((await html(BASE + "/cliente/")).status, 200, "the archive, at /cliente/");

  await seedPage("<!-- wp:paragraph --><p>own-clients-page</p><!-- /wp:paragraph -->", "clientes");
  const own = await html(BASE + "/clientes/");
  assert.equal(own.status, 200);
  assert.match(own.text, /own-clients-page/, "/clientes/ is the PAGE, not the archive");

  await saveCompany({ rewrite_slugs: { client: "" } });
  const back = await wp(`/${type("client")}/${c.json.id}`);
  assert.match(back.json.link, /\/clientes\/cliente-movido\/$/, "empty = the usual slug");
  assert.equal((await html(back.json.link)).status, 200);
});

test("estrategia_web_client_image_fit (0.2.0): 'cover' by default, sanitized to cover|contain, and 'contain' puts has-image-fit-contain on the Query's <li>", async () => {
  await saveCompany({ enabled_types: ["client"] });
  const make = async (title, meta) => {
    const c = await wp(`/${type("client")}`, { method: "POST", body: { title, status: "publish", meta } });
    assert.equal(c.status, 201, c.text);
    return c.json;
  };
  const logo = await make("Fit Logo", { estrategia_web_client_image_fit: "contain" });
  const photo = await make("Fit Photo", {});
  const odd = await make("Fit Odd", { estrategia_web_client_image_fit: "stretch" });
  assert.equal(logo.meta.estrategia_web_client_image_fit, "contain");
  assert.equal(photo.meta.estrategia_web_client_image_fit, "cover", "by default");
  assert.equal(odd.meta.estrategia_web_client_image_fit, "cover", "another value is sanitized to cover");

  const query = JSON.stringify({ queryId: 8, query: { perPage: 10, pages: 0, offset: 0, postType: type("client"), order: "asc", orderBy: "title", inherit: false, search: "Fit " } });
  const page = await seedPage(`<!-- wp:query ${query} -->
<div class="wp-block-query"><!-- wp:post-template -->
<!-- wp:post-title /-->
<!-- /wp:post-template --></div>
<!-- /wp:query -->`, "clientes-fit");
  const { text } = await html(page.url);
  const li = (title) => text.match(new RegExp(`<li[^>]*>(?:(?!</li>)[\\s\\S])*${title}`))?.[0].match(/<li[^>]*>/)[0];
  assert.match(li("Fit Logo"), /\bhas-image-fit-contain\b/);
  assert.doesNotMatch(li("Fit Photo"), /has-image-fit/);
  assert.doesNotMatch(li("Fit Odd"), /has-image-fit/);
});
