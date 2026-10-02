// NIVEL: 2 — WordPress de Playground, porque ESCRIBE: activa y desactiva tipos en la opción, crea clientes, sectores e imágenes por REST y mira la fila cruda en la base de datos.
import { test, before } from "node:test";
import assert from "node:assert/strict";
import { reset, wp, saveCompany, seedPage, html, rawPost, upload, png, BASE } from "./lib.mjs";

before(reset);

let before_id;

test("sin `client` en enabled_types el tipo no existe; al activarlo existe con sus etiquetas en castellano", async () => {
  await saveCompany({ enabled_types: [] });
  assert.equal((await wp("/types/client")).status, 404);
  assert.equal((await wp("/client")).status, 404, "ni su ruta REST");

  await saveCompany({ enabled_types: ["client"] });
  const type = await wp("/types/client");
  assert.equal(type.status, 200);
  assert.equal(type.json.name, "Clientes");
  assert.deepEqual(type.json.taxonomies, ["client_sector"]);
  assert.equal((await wp("/types/testimonial")).status, 404, "solo el activado");
});

test("al desactivar el tipo su contenido NO se borra, y al reactivarlo sigue ahí", async () => {
  await saveCompany({ enabled_types: ["client"] });
  const created = await wp("/client", { method: "POST", body: { title: "Cliente de antes", status: "publish", meta: { client_url: "https://antes.test" } } });
  assert.equal(created.status, 201, created.text);
  before_id = created.json.id;

  await saveCompany({ enabled_types: [] });
  assert.equal((await wp(`/client/${before_id}`)).status, 404, "desactivado, WordPress no lo conoce");
  const raw = await rawPost(before_id);
  assert.equal(raw.post_type, "client", "pero la fila sigue en wp_posts");
  assert.equal(raw.post_status, "publish");

  await saveCompany({ enabled_types: ["client"] });
  const back = await wp(`/client/${before_id}`);
  assert.equal(back.status, 200);
  assert.equal(back.json.title.rendered, "Cliente de antes");
  assert.equal(back.json.meta.client_url, "https://antes.test", "con sus campos");
});

test("el slug público es el castellano (/clientes/) y la ficha responde tras activar el tipo", async () => {
  const back = await wp(`/client/${before_id}`);
  assert.match(back.json.link, /\/clientes\/cliente-de-antes\/$/);
  const page = await html(back.json.link);
  assert.equal(page.status, 200, "las reglas de URL se rehicieron solas al activar");
  assert.match(page.text, /Cliente de antes/);
});

test("campos (meta) por REST: tipo y saneado (valoración 1-5, correo, URL)", async () => {
  await saveCompany({ enabled_types: ["client", "testimonial", "location"] });
  const t = await wp("/testimonial", { method: "POST", body: { title: "Opinión", content: "Muy bien", status: "publish", meta: { testimonial_author: "Ana <b>", testimonial_rating: 9, testimonial_client: before_id } } });
  assert.equal(t.status, 201, t.text);
  assert.equal(t.json.meta.testimonial_author, "Ana");
  assert.equal(t.json.meta.testimonial_rating, 5, "fuera de rango se lleva a 5");
  assert.equal(t.json.meta.testimonial_client, before_id);

  const t2 = await wp("/testimonial", { method: "POST", body: { title: "Otra", status: "publish", meta: { testimonial_client: t.json.id } } });
  assert.equal(t2.json.meta.testimonial_client, 0, "un id que no es un cliente no se guarda");

  const l = await wp("/location", { method: "POST", body: { title: "Sede Palma", status: "publish", meta: { location_email: "mal", location_map_url: "javascript:alert(1)", location_map_embed: "https://www.google.com/maps/embed?pb=x", location_address: "Calle 1\nPalma" } } });
  assert.equal(l.status, 201, l.text);
  assert.equal(l.json.meta.location_email, "");
  assert.equal(l.json.meta.location_map_url, "");
  assert.equal(l.json.meta.location_map_embed, "https://www.google.com/maps/embed?pb=x");
  assert.equal(l.json.meta.location_address, "Calle 1\nPalma");
});

test("una Consulta (core/query) de `client` filtrada por client_sector pinta los de ese sector con título e imagen", async () => {
  await saveCompany({ enabled_types: ["client"] });
  const hotel = await wp("/client_sector", { method: "POST", body: { name: "Hoteles" } });
  const resto = await wp("/client_sector", { method: "POST", body: { name: "Restaurantes" } });
  assert.equal(hotel.status, 201, hotel.text);

  const make = async (title, sector, color) => {
    const img = await upload(`logo-${sector}-${title.length}.png`, "image/png", png(4, 2, color));
    assert.equal(img.status, 201, img.text);
    const c = await wp("/client", { method: "POST", body: { title, status: "publish", client_sector: [sector], featured_media: img.json.id } });
    assert.equal(c.status, 201, c.text);
    return { id: c.json.id, img: img.json.source_url };
  };
  const a = await make("Hotel Sol", hotel.json.id, [200, 0, 0]);
  const b = await make("Hotel Mar", hotel.json.id, [0, 200, 0]);
  const c = await make("Restaurante Luna", resto.json.id, [0, 0, 200]);

  const query = JSON.stringify({ queryId: 7, query: { perPage: 10, pages: 0, offset: 0, postType: "client", order: "asc", orderBy: "title", inherit: false, taxQuery: { client_sector: [hotel.json.id] } } });
  const page = await seedPage(`<!-- wp:query ${query} -->
<div class="wp-block-query"><!-- wp:post-template -->
<!-- wp:post-featured-image /-->
<!-- wp:post-title /-->
<!-- /wp:post-template --></div>
<!-- /wp:query -->`, "clientes-hoteles");

  const { text } = await html(page.url);
  const list = text.match(/<ul[^>]*wp-block-post-template[\s\S]*?<\/ul>/)?.[0];
  assert.ok(list, "la consulta pinta su lista");
  const items = [...list.matchAll(/<li[\s\S]*?<\/li>/g)].map((m) => m[0]);
  assert.equal(items.length, 2, "solo los dos del sector");
  assert.match(items[0], /Hotel Mar/, "orden por título");
  assert.match(items[1], /Hotel Sol/);
  const file = (u) => u.split("/").pop().replace(/\.png$/, "");
  assert.ok(items[0].includes(file(b.img)) && /<img/.test(items[0]), "con su imagen destacada");
  assert.ok(items[1].includes(file(a.img)));
  assert.ok(!list.includes("Restaurante Luna") && !list.includes(file(c.img)), "el de otro sector no sale");
  assert.ok(BASE);
});

test("rewrite_slugs (0.2.0): mueve el archivo de client a /cliente/ sin filtro, la página /clientes/ queda libre, y vacío vuelve al de siempre", async () => {
  await saveCompany({ enabled_types: ["client"], rewrite_slugs: { client: "cliente" } });
  const c = await wp("/client", { method: "POST", body: { title: "Cliente movido", status: "publish" } });
  assert.equal(c.status, 201, c.text);
  assert.match(c.json.link, /\/cliente\/cliente-movido\/$/);
  const single = await html(c.json.link);
  assert.equal(single.status, 200, "las reglas se rehicieron al cambiar el slug");
  assert.match(single.text, /Cliente movido/);
  assert.equal((await html(BASE + "/cliente/")).status, 200, "el archivo, en /cliente/");

  await seedPage("<!-- wp:paragraph --><p>pagina-clientes-propia</p><!-- /wp:paragraph -->", "clientes");
  const own = await html(BASE + "/clientes/");
  assert.equal(own.status, 200);
  assert.match(own.text, /pagina-clientes-propia/, "/clientes/ es la PÁGINA, no el archivo");

  await saveCompany({ rewrite_slugs: { client: "" } });
  const back = await wp(`/client/${c.json.id}`);
  assert.match(back.json.link, /\/clientes\/cliente-movido\/$/, "vacío = el slug de siempre");
  assert.equal((await html(back.json.link)).status, 200);
});

test("client_image_fit (0.2.0): 'cover' por defecto, saneado a cover|contain, y 'contain' pone has-image-fit-contain en el <li> de la Consulta", async () => {
  await saveCompany({ enabled_types: ["client"] });
  const make = async (title, meta) => {
    const c = await wp("/client", { method: "POST", body: { title, status: "publish", meta } });
    assert.equal(c.status, 201, c.text);
    return c.json;
  };
  const logo = await make("Fit Logo", { client_image_fit: "contain" });
  const foto = await make("Fit Foto", {});
  const raro = await make("Fit Raro", { client_image_fit: "stretch" });
  assert.equal(logo.meta.client_image_fit, "contain");
  assert.equal(foto.meta.client_image_fit, "cover", "por defecto");
  assert.equal(raro.meta.client_image_fit, "cover", "otro valor se sanea a cover");

  const query = JSON.stringify({ queryId: 8, query: { perPage: 10, pages: 0, offset: 0, postType: "client", order: "asc", orderBy: "title", inherit: false, search: "Fit " } });
  const page = await seedPage(`<!-- wp:query ${query} -->
<div class="wp-block-query"><!-- wp:post-template -->
<!-- wp:post-title /-->
<!-- /wp:post-template --></div>
<!-- /wp:query -->`, "clientes-fit");
  const { text } = await html(page.url);
  const li = (title) => text.match(new RegExp(`<li[^>]*>(?:(?!</li>)[\\s\\S])*${title}`))?.[0].match(/<li[^>]*>/)[0];
  assert.match(li("Fit Logo"), /\bhas-image-fit-contain\b/);
  assert.doesNotMatch(li("Fit Foto"), /has-image-fit/);
  assert.doesNotMatch(li("Fit Raro"), /has-image-fit/);
});
