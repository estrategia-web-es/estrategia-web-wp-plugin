// NIVEL: 2 — Playground WordPress, because it WRITES: enables the `location` type, creates locations and uploads an image via REST; what is checked is the sanitizing REST returns and the HTML the server renders, no browser.
//
// A location's static map (0.4.0): the fields `estrategia_web_location_static_map` (URL of
// the image, served by the site itself) and `estrategia_web_location_static_map_id` (the
// same image in the media library). The parent theme's estrategia-web/map block reads them
// (staticMapUrl attribute) with core/post-meta inside a Query of locations;
// the theme is not here, so it is checked with a paragraph and an image bound
// to that same field, which is what the block receives.
import { test, before } from "node:test";
import assert from "node:assert/strict";
import { BASE, reset, wp, saveCompany, seedPage, html, upload, png, type } from "../../../tests/lib.mjs";

let loc, imageId;
before(async () => {
  await reset();
  await saveCompany({ enabled_types: ["location"] });
  const r = await wp(`/${type("location")}`, { method: "POST", body: { title: "Palma location", slug: "palma", status: "publish" } });
  assert.equal(r.status, 201, r.text);
  loc = r.json;
  const img = await upload("mapa-palma.png", "image/png", png(8, 4, [40, 120, 200]));
  assert.equal(img.status, 201, img.text);
  imageId = img.json.id;
});

const saveMeta = async (meta) => {
  const r = await wp(`/${type("location")}/${loc.id}`, { method: "POST", body: { meta } });
  assert.equal(r.status, 200, r.text);
  return r.json.meta;
};

test("estrategia_web_location_static_map (0.4.0): the theme path, the site path and a same-domain URL are saved", async () => {
  for (const [value, expected] of [
    ["assets/maps/sede.webp", "assets/maps/sede.webp"],
    ["  ./assets/maps/sede.png ", "assets/maps/sede.png"],
    ["/wp-content/uploads/mapa.webp", "/wp-content/uploads/mapa.webp"],
    [BASE + "/wp-content/uploads/mapa.jpg", BASE + "/wp-content/uploads/mapa.jpg"],
    ["", ""],
  ]) {
    assert.equal((await saveMeta({ estrategia_web_location_static_map: value })).estrategia_web_location_static_map, expected, JSON.stringify(value));
  }
});

test("estrategia_web_location_static_map (0.4.0): rejects another domain, //domain, javascript:, «..» and what is not an image", async () => {
  for (const value of [
    "https://tile.openstreetmap.org/16/33248/24911.png",
    "//otro.test/mapa.png",
    "javascript:alert(1)",
    "assets/../../wp-config.php",
    "../mapa.png",
    "assets/maps/sede.php",
    "/wp-content/uploads/mapa.webp?x=<script>",
    "assets/maps/se de.webp",
  ]) {
    await saveMeta({ estrategia_web_location_static_map: "assets/maps/buena.webp" });
    assert.equal((await saveMeta({ estrategia_web_location_static_map: value })).estrategia_web_location_static_map, "", JSON.stringify(value));
  }
});

test("estrategia_web_location_static_map_id (0.4.0): an image attachment is saved; an id that is not an image attachment, 0", async () => {
  assert.equal((await saveMeta({ estrategia_web_location_static_map_id: imageId })).estrategia_web_location_static_map_id, imageId);
  assert.equal((await saveMeta({ estrategia_web_location_static_map_id: loc.id })).estrategia_web_location_static_map_id, 0, "the location itself is not an image");
  assert.equal((await saveMeta({ estrategia_web_location_static_map_id: 999999 })).estrategia_web_location_static_map_id, 0);
  assert.equal((await saveMeta({ estrategia_web_location_static_map_id: -3 })).estrategia_web_location_static_map_id, 0);
});

test("in a Query of locations, core/post-meta gives each location ITS static map (what staticMapUrl of estrategia-web/map receives)", async () => {
  await saveMeta({ estrategia_web_location_static_map: "/wp-content/uploads/mapa-palma.webp" });
  const other = await wp(`/${type("location")}`, { method: "POST", body: { title: "Inca location", slug: "inca", status: "publish", meta: { estrategia_web_location_static_map: "/wp-content/uploads/mapa-inca.webp" } } });
  assert.equal(other.status, 201, other.text);

  const pm = (attr) => ({ [attr]: { source: "core/post-meta", args: { key: "estrategia_web_location_static_map" } } });
  const query = JSON.stringify({ queryId: 7, query: { perPage: 10, pages: 0, offset: 0, postType: type("location"), order: "asc", orderBy: "title", inherit: false } });
  const page = await seedPage(
    `<!-- wp:query ${query} -->
<div class="wp-block-query"><!-- wp:post-template -->
<!-- wp:paragraph ${JSON.stringify({ className: "t-static", metadata: { bindings: pm("content") } })} -->
<p class="t-static">x</p>
<!-- /wp:paragraph -->
<!-- wp:image ${JSON.stringify({ className: "t-img", metadata: { bindings: pm("url") } })} -->
<figure class="wp-block-image t-img"><img src="" alt=""/></figure>
<!-- /wp:image -->
<!-- /wp:post-template --></div>
<!-- /wp:query -->`,
    "sedes-mapa-estatico"
  );
  const { status, text } = await html(page.url);
  assert.equal(status, 200);
  const items = [...(text.match(/<ul[^>]*wp-block-post-template[\s\S]*?<\/ul>/)?.[0] ?? "").matchAll(/<li[\s\S]*?<\/li>/g)].map((m) => m[0]);
  assert.equal(items.length, 2);
  assert.match(items[0], /<p class="t-static[^"]*">\/wp-content\/uploads\/mapa-inca\.webp<\/p>/, "Inca comes first by title");
  assert.match(items[0], /<img[^>]*src="\/wp-content\/uploads\/mapa-inca\.webp"/);
  assert.match(items[1], /<p class="t-static[^"]*">\/wp-content\/uploads\/mapa-palma\.webp<\/p>/);
});

test("the field also comes out through the estrategia-web-site/location source, by slug", async () => {
  await saveMeta({ estrategia_web_location_static_map: "/wp-content/uploads/mapa-palma.webp" });
  const page = await seedPage(
    `<!-- wp:paragraph ${JSON.stringify({ className: "t-src", metadata: { bindings: { content: { source: "estrategia-web-site/location", args: { slug: "palma", key: "estrategia_web_location_static_map" } } } } })} -->
<p class="t-src">x</p>
<!-- /wp:paragraph -->`,
    "sede-mapa-por-slug"
  );
  const { text } = await html(page.url);
  assert.match(text, /<p class="t-src[^"]*">\/wp-content\/uploads\/mapa-palma\.webp<\/p>/);
});
