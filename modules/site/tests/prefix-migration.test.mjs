// NIVEL: 2 — Playground WordPress, because it WRITES: seeds via SQL a site with the types WITHOUT prefix (like 1.1.0), checks the alias, and runs the prefix migrator dry and for real.
//
// Why it exists (15-10-2026): 1.2.0 registers the types with a prefix
// (Type_Names). A site published with 1.1.0 has its clients, locations and
// Queries under the old names; without the migrator, upgrading the plugin leaves
// the site without clients. The real test, with WPML and Lavandería's content,
// is the lavanderia-mallorca rehearsal (despliegue/prefijo-tipos.php).
//
// Seen red (15-10-2026): without the migrator's taxonomies() step, «for
// real» fails: the page renders the Query empty (the sector is still old).
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

test("before migrating: nobody sees the old rows (plugin 1.2.0 registers other names)", async () => {
  assert.equal((await rawPost(seed.client)).post_type, "client");
  assert.doesNotMatch(await page(), /Hotel de antes/);
});

test("dry run: counts what it would do and writes nothing", async () => {
  const r = await testApi("/prefix-migration", { method: "POST", body: { dry: true } });
  assert.equal(r.seco, true);
  assert.deepEqual(r.tipos, [`client → ${type("client")}: 1 entradas`, `location → ${type("location")}: 1 entradas`]);
  assert.deepEqual(r.taxonomias, [`client_sector → ${type("client_sector")}: 1 términos`]);
  const page_entry = r.contenido.find((e) => e.id === seed.page);
  assert.equal(page_entry?.cambios, 3, "postType, the taxQuery key and the post-terms term");
  assert.equal((await rawPost(seed.client)).post_type, "client", "a dry run does not write");
});

test("for real: the row, the sector and the Query switch to the new names and the page renders the client", async () => {
  const r = await testApi("/prefix-migration", { method: "POST", body: { dry: false } });
  assert.equal(r.seco, false);
  assert.deepEqual(r.avisos, []);
  assert.equal((await rawPost(seed.client)).post_type, type("client"));
  assert.equal((await rawPost(seed.location)).post_type, type("location"));
  const text = await page();
  assert.match(text, /Hotel de antes/, "the Query finds the client of its sector");
  assert.match(text, /Hoteles de antes/, "post-terms renders the sector");
  const loc = await wp(`/${type("location")}/${seed.location}?context=edit`);
  assert.equal(loc.json.meta.estrategia_web_location_phone, "971 11 22 33", "the metas do not change");
  assert.ok(r.wpml.includes(`icl_sitepress_settings#custom_posts_sync_option[v]: client → ${type("client")}`), JSON.stringify(r.wpml));
  assert.ok(r.wpml.includes(`WPML(setup/translate-everything-taxonomies)[v]: client_sector → ${type("client_sector")}`), JSON.stringify(r.wpml));
  assert.ok(r.rankmath.includes(`rank_math_known_post_types: client → ${type("client")}`), JSON.stringify(r.rankmath));
  const opts = await testApi("/options", { method: "POST", body: { names: ["icl_sitepress_settings#custom_posts_sync_option", "rank_math_known_post_types"] } });
  assert.deepEqual(opts["icl_sitepress_settings#custom_posts_sync_option"], { v: { page: 1, [type("client")]: 2, [type("location")]: 2 } }, "order and values are preserved");
  assert.deepEqual(opts.rank_math_known_post_types, { post: "post", [type("client")]: type("client") });
});

test("idempotent: the second pass finds nothing", async () => {
  const r = await testApi("/prefix-migration", { method: "POST", body: { dry: false } });
  for (const k of ["tipos", "taxonomias", "contenido", "plantillas", "menus", "wpml", "rankmath", "pantallas", "avisos"]) {
    assert.deepEqual(r[k], [], k);
  }
});

test("alias until 2.0.0: a Query with the OLD names renders the new ones, and /wp/v2/client answers like the new one", async () => {
  const old = `<!-- wp:query {"queryId":6,"query":{"perPage":10,"pages":0,"offset":0,"postType":"client","order":"asc","orderBy":"title","inherit":false,"taxQuery":{"include":{"client_sector":[${seed.term}]}}}} --><div class="wp-block-query"><!-- wp:post-template --><!-- wp:post-title /--><!-- /wp:post-template --></div><!-- /wp:query -->`;
  const p = await wp("/pages", { method: "POST", body: { title: "Old query", status: "publish", content: old } });
  assert.equal(p.status, 201, p.text);
  assert.match((await html(p.json.link)).text, /Hotel de antes/);
  const alias = await wp(`/client/${seed.client}`);
  assert.equal(alias.status, 200, alias.text);
  assert.equal(alias.json.type, type("client"));
});
