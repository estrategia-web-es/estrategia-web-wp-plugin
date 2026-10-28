// NIVEL: 2 — Playground WordPress, because it WRITES: enables the `location` type, creates locations and uploads images via REST; what is checked is the sanitizing REST returns and the HTML the server renders, no browser.
//
// A location's static map. Up to 1.7.0 two fields: `estrategia_web_location_static_map`
// (a path or URL of the site) and `estrategia_web_location_static_map_id` (the same image
// in the media library). Since 1.8.0 (each datum once) only the media library image;
// the URL is DERIVED and comes out through the estrategia-web-site/location source
// under the old key, which is what estrategia-web/map's staticMapUrl binds. The
// theme is not here, so it is checked with a paragraph and an image bound to it.
// The path rules of the «local_image» field type (what the old field was) are
// still a type any definition can use: checked here on a type of the site's own.
//
// Seen red (27-10-2026) with 1.7.0's tests against 1.8.0: the location no longer
// had the path field (each assertion on it failed), which is what moved them here.
import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { BASE, reset, wp, saveCompany, seedPage, html, upload, png, type, credentials } from "../../../tests/lib.mjs";

let loc, imageId, image, other;
const ct = async (path, body) => {
  const who = (await credentials()).administrator;
  const res = await fetch(BASE + "/wp-json/estrategia-web/v1/content-types" + path, {
    method: "POST",
    headers: { Authorization: "Basic " + Buffer.from(`${who.user}:${who.password}`).toString("base64"), "Content-Type": "application/json" },
    body: JSON.stringify(body),
  });
  return { status: res.status, text: await res.text() };
};

before(async () => {
  await reset();
  await saveCompany({ enabled_types: ["location"] });
  const own = await ct("", { definition: { prefix: "gment_", name: "gment_spot", labels: { singular: "Sitio", plural: "Sitios" }, fields: [{ name: "gment_spot_picture", type: "local_image", label: "Imagen" }] } });
  assert.equal(own.status, 201, own.text);
  await wp("/types"); // Both registered from the next request.
  const r = await wp(`/${type("location")}`, { method: "POST", body: { title: "Palma location", slug: "palma", status: "publish" } });
  assert.equal(r.status, 201, r.text);
  loc = r.json;
  const img = await upload("mapa-palma.png", "image/png", png(8, 4, [40, 120, 200]));
  assert.equal(img.status, 201, img.text);
  imageId = img.json.id;
  image = img.json.source_url;
  const img2 = await upload("mapa-inca.png", "image/png", png(8, 4, [200, 120, 40]));
  other = img2.json;
});

after(async () => {
  for (const p of (await wp("/gment_spot?status=any&context=edit&per_page=100")).json || []) await wp(`/gment_spot/${p.id}?force=true`, { method: "DELETE" });
  await reset(); // Also deletes the stored definitions.
});

const saveMeta = async (meta) => {
  const r = await wp(`/${type("location")}/${loc.id}`, { method: "POST", body: { meta } });
  assert.equal(r.status, 200, r.text);
  return r.json.meta;
};

test("local_image (the old static map path, a field type of its own): the theme path, the site path and a same-domain URL are saved", async () => {
  const spot = await wp("/gment_spot", { method: "POST", body: { title: "Sitio", status: "publish" } });
  assert.equal(spot.status, 201, spot.text);
  const save = async (v) => (await wp(`/gment_spot/${spot.json.id}`, { method: "POST", body: { meta: { gment_spot_picture: v } } })).json.meta.gment_spot_picture;
  for (const [value, expected] of [
    ["assets/maps/sede.webp", "assets/maps/sede.webp"],
    ["  ./assets/maps/sede.png ", "assets/maps/sede.png"],
    ["/wp-content/uploads/mapa.webp", "/wp-content/uploads/mapa.webp"],
    [BASE + "/wp-content/uploads/mapa.jpg", BASE + "/wp-content/uploads/mapa.jpg"],
    ["", ""],
  ]) {
    assert.equal(await save(value), expected, JSON.stringify(value));
  }
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
    await save("assets/maps/buena.webp");
    assert.equal(await save(value), "", JSON.stringify(value));
  }
});

test("estrategia_web_location_static_map_id: an image attachment is saved; an id that is not an image attachment, 0", async () => {
  assert.equal((await saveMeta({ estrategia_web_location_static_map_id: imageId })).estrategia_web_location_static_map_id, imageId);
  assert.equal((await saveMeta({ estrategia_web_location_static_map_id: loc.id })).estrategia_web_location_static_map_id, 0, "the location itself is not an image");
  assert.equal((await saveMeta({ estrategia_web_location_static_map_id: 999999 })).estrategia_web_location_static_map_id, 0);
  assert.equal((await saveMeta({ estrategia_web_location_static_map_id: -3 })).estrategia_web_location_static_map_id, 0);
});

test("in a Query of locations, the location source gives each location ITS static map URL (what staticMapUrl of estrategia-web/map receives)", async () => {
  await saveMeta({ estrategia_web_location_static_map_id: imageId });
  const inca = await wp(`/${type("location")}`, { method: "POST", body: { title: "Inca location", slug: "inca", status: "publish", meta: { estrategia_web_location_static_map_id: other.id } } });
  assert.equal(inca.status, 201, inca.text);

  const src = (attr) => ({ [attr]: { source: "estrategia-web-site/location", args: { key: "estrategia_web_location_static_map" } } });
  const query = JSON.stringify({ queryId: 7, query: { perPage: 10, pages: 0, offset: 0, postType: type("location"), order: "asc", orderBy: "title", inherit: false } });
  const page = await seedPage(
    `<!-- wp:query ${query} -->
<div class="wp-block-query"><!-- wp:post-template -->
<!-- wp:paragraph ${JSON.stringify({ className: "t-static", metadata: { bindings: src("content") } })} -->
<p class="t-static">x</p>
<!-- /wp:paragraph -->
<!-- wp:image ${JSON.stringify({ className: "t-img", metadata: { bindings: src("url") } })} -->
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
  assert.match(items[0], new RegExp(`<p class="t-static[^"]*">${other.source_url.replace(/[.?]/g, "\\$&")}</p>`), "Inca comes first by title");
  assert.match(items[0], new RegExp(`<img[^>]*src="${other.source_url.replace(/[.?]/g, "\\$&")}"`));
  assert.match(items[1], new RegExp(`<p class="t-static[^"]*">${image.replace(/[.?]/g, "\\$&")}</p>`));
});

test("by slug, through the estrategia-web-site/location source", async () => {
  await saveMeta({ estrategia_web_location_static_map_id: imageId });
  const page = await seedPage(
    `<!-- wp:paragraph ${JSON.stringify({ className: "t-src", metadata: { bindings: { content: { source: "estrategia-web-site/location", args: { slug: "palma", key: "estrategia_web_location_static_map" } } } } })} -->
<p class="t-src">x</p>
<!-- /wp:paragraph -->`,
    "sede-mapa-por-slug"
  );
  const { text } = await html(page.url);
  assert.match(text, new RegExp(`<p class="t-src[^"]*">${image.replace(/[.?]/g, "\\$&")}</p>`));
});
