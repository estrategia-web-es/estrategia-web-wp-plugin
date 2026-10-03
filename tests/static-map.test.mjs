// NIVEL: 2 — WordPress de Playground, porque ESCRIBE: activa el tipo `location`, crea sedes y sube una imagen por REST; lo que se mira es el saneado que devuelve REST y el HTML que pinta el servidor, sin navegador.
//
// Mapa estático de una sede (0.4.0): los campos `location_static_map` (URL de
// la imagen, servida por el propio sitio) y `location_static_map_id` (la
// misma imagen en la mediateca). Los lee el bloque armazon/map del tema padre
// (atributo staticMapUrl) con core/post-meta dentro de una Consulta de sedes;
// aquí no está el tema, así que se mira con un párrafo y una imagen enlazados
// a ese mismo campo, que es lo que recibe el bloque.
import { test, before } from "node:test";
import assert from "node:assert/strict";
import { BASE, reset, wp, saveCompany, seedPage, html, upload, png } from "./lib.mjs";

let sede, imageId;
before(async () => {
  await reset();
  await saveCompany({ enabled_types: ["location"] });
  const r = await wp("/location", { method: "POST", body: { title: "Sede Palma", slug: "palma", status: "publish" } });
  assert.equal(r.status, 201, r.text);
  sede = r.json;
  const img = await upload("mapa-palma.png", "image/png", png(8, 4, [40, 120, 200]));
  assert.equal(img.status, 201, img.text);
  imageId = img.json.id;
});

const saveMeta = async (meta) => {
  const r = await wp(`/location/${sede.id}`, { method: "POST", body: { meta } });
  assert.equal(r.status, 200, r.text);
  return r.json.meta;
};

test("location_static_map (0.4.0): se guardan la ruta del tema, la del sitio y una URL del mismo dominio", async () => {
  for (const [value, expected] of [
    ["assets/maps/sede.webp", "assets/maps/sede.webp"],
    ["  ./assets/maps/sede.png ", "assets/maps/sede.png"],
    ["/wp-content/uploads/mapa.webp", "/wp-content/uploads/mapa.webp"],
    [BASE + "/wp-content/uploads/mapa.jpg", BASE + "/wp-content/uploads/mapa.jpg"],
    ["", ""],
  ]) {
    assert.equal((await saveMeta({ location_static_map: value })).location_static_map, expected, JSON.stringify(value));
  }
});

test("location_static_map (0.4.0): fuera otro dominio, //dominio, javascript:, «..» y lo que no es una imagen", async () => {
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
    await saveMeta({ location_static_map: "assets/maps/buena.webp" });
    assert.equal((await saveMeta({ location_static_map: value })).location_static_map, "", JSON.stringify(value));
  }
});

test("location_static_map_id (0.4.0): un adjunto de imagen se guarda; un id que no es un adjunto de imagen, 0", async () => {
  assert.equal((await saveMeta({ location_static_map_id: imageId })).location_static_map_id, imageId);
  assert.equal((await saveMeta({ location_static_map_id: sede.id })).location_static_map_id, 0, "la propia sede no es una imagen");
  assert.equal((await saveMeta({ location_static_map_id: 999999 })).location_static_map_id, 0);
  assert.equal((await saveMeta({ location_static_map_id: -3 })).location_static_map_id, 0);
});

test("en una Consulta de sedes, core/post-meta da a cada sede SU mapa estático (lo que recibe staticMapUrl de armazon/map)", async () => {
  await saveMeta({ location_static_map: "/wp-content/uploads/mapa-palma.webp" });
  const otra = await wp("/location", { method: "POST", body: { title: "Sede Inca", slug: "inca", status: "publish", meta: { location_static_map: "/wp-content/uploads/mapa-inca.webp" } } });
  assert.equal(otra.status, 201, otra.text);

  const pm = (attr) => ({ [attr]: { source: "core/post-meta", args: { key: "location_static_map" } } });
  const query = JSON.stringify({ queryId: 7, query: { perPage: 10, pages: 0, offset: 0, postType: "location", order: "asc", orderBy: "title", inherit: false } });
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
  assert.match(items[0], /<p class="t-static[^"]*">\/wp-content\/uploads\/mapa-inca\.webp<\/p>/, "Inca va primero por título");
  assert.match(items[0], /<img[^>]*src="\/wp-content\/uploads\/mapa-inca\.webp"/);
  assert.match(items[1], /<p class="t-static[^"]*">\/wp-content\/uploads\/mapa-palma\.webp<\/p>/);
});

test("el campo también sale por la fuente armazon-site/location, por slug", async () => {
  await saveMeta({ location_static_map: "/wp-content/uploads/mapa-palma.webp" });
  const page = await seedPage(
    `<!-- wp:paragraph ${JSON.stringify({ className: "t-src", metadata: { bindings: { content: { source: "armazon-site/location", args: { slug: "palma", key: "location_static_map" } } } } })} -->
<p class="t-src">x</p>
<!-- /wp:paragraph -->`,
    "sede-mapa-por-slug"
  );
  const { text } = await html(page.url);
  assert.match(text, /<p class="t-src[^"]*">\/wp-content\/uploads\/mapa-palma\.webp<\/p>/);
});
