// NIVEL: 2 — WordPress de Playground, porque ESCRIBE: siembra por SQL una web con los tipos SIN prefijo (como la 1.1.0), mira el alias, y pasa el migrador del prefijo en seco y de verdad.
//
// Por qué existe (15-10-2026): la 1.2.0 registra los tipos con prefijo
// (Type_Names). Una web publicada con la 1.1.0 tiene sus clientes, sedes y
// Consultas con los nombres viejos; sin el migrador, al subir el plugin la web
// se queda sin clientes. La prueba de verdad, con WPML y el contenido de
// Lavandería, es el ensayo de lavanderia-mallorca (despliegue/prefijo-tipos.php).
//
// Vista en rojo (15-10-2026): sin el paso taxonomies() del migrador, «de
// verdad» falla: la página pinta la Consulta vacía (el sector sigue viejo).
import { test, before } from "node:test";
import assert from "node:assert/strict";
import { reset, wp, saveCompany, html, rawPost, testApi, BASE, type } from "../../../tests/lib.mjs";

let seed;
before(async () => {
  await reset();
  await saveCompany({ enabled_types: ["client", "location"] });
  seed = await testApi("/legacy-types", { method: "POST" });
});

const page = async () => (await html(`${BASE}/?page_id=${seed.page}`)).text;

test("antes de migrar: las filas viejas no las ve nadie (el plugin 1.2.0 registra otros nombres)", async () => {
  assert.equal((await rawPost(seed.client)).post_type, "client");
  assert.doesNotMatch(await page(), /Hotel de antes/);
});

test("seco: cuenta lo que haría y no escribe nada", async () => {
  const r = await testApi("/prefix-migration", { method: "POST", body: { dry: true } });
  assert.equal(r.seco, true);
  assert.deepEqual(r.tipos, [`client → ${type("client")}: 1 entradas`, `location → ${type("location")}: 1 entradas`]);
  assert.deepEqual(r.taxonomias, [`client_sector → ${type("client_sector")}: 1 términos`]);
  const page_entry = r.contenido.find((e) => e.id === seed.page);
  assert.equal(page_entry?.cambios, 3, "postType, la clave del taxQuery y el term de post-terms");
  assert.equal((await rawPost(seed.client)).post_type, "client", "en seco no se escribe");
});

test("de verdad: la fila, el sector y la Consulta pasan a los nombres nuevos y la página pinta el cliente", async () => {
  const r = await testApi("/prefix-migration", { method: "POST", body: { dry: false } });
  assert.equal(r.seco, false);
  assert.deepEqual(r.avisos, []);
  assert.equal((await rawPost(seed.client)).post_type, type("client"));
  assert.equal((await rawPost(seed.location)).post_type, type("location"));
  const text = await page();
  assert.match(text, /Hotel de antes/, "la Consulta encuentra el cliente de su sector");
  assert.match(text, /Hoteles de antes/, "post-terms pinta el sector");
  const sede = await wp(`/${type("location")}/${seed.location}?context=edit`);
  assert.equal(sede.json.meta.estrategia_web_location_phone, "971 11 22 33", "las metas no cambian");
});

test("idempotente: la segunda pasada no encuentra nada", async () => {
  const r = await testApi("/prefix-migration", { method: "POST", body: { dry: false } });
  for (const k of ["tipos", "taxonomias", "contenido", "plantillas", "menus", "wpml", "rankmath", "pantallas", "avisos"]) {
    assert.deepEqual(r[k], [], k);
  }
});

test("alias hasta 2.0.0: una Consulta con los nombres VIEJOS pinta lo nuevo, y /wp/v2/client contesta como el nuevo", async () => {
  const old = `<!-- wp:query {"queryId":6,"query":{"perPage":10,"pages":0,"offset":0,"postType":"client","order":"asc","orderBy":"title","inherit":false,"taxQuery":{"include":{"client_sector":[${seed.term}]}}}} --><div class="wp-block-query"><!-- wp:post-template --><!-- wp:post-title /--><!-- /wp:post-template --></div><!-- /wp:query -->`;
  const p = await wp("/pages", { method: "POST", body: { title: "Consulta vieja", status: "publish", content: old } });
  assert.equal(p.status, 201, p.text);
  assert.match((await html(p.json.link)).text, /Hotel de antes/);
  const alias = await wp(`/client/${seed.client}`);
  assert.equal(alias.status, 200, alias.text);
  assert.equal(alias.json.type, type("client"));
});
