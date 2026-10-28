// NIVEL: 2 — Playground WordPress. NOT LEVEL 1 BECAUSE: what is tested is the type prefix migrator on a real database (rows, terms, the Query inside a stored page, WPML's and Rank Math's options renamed) and the alias as WordPress resolves it (a Query with the old names rendered, /wp/v2/client); the names and the content renaming as functions are type-names.test.mjs (level 1). No browser.
//
// Why it exists (15-10-2026): 1.2.0 registers the types with a prefix
// (Type_Names). A site published with 1.1.0 has its clients, locations and
// Queries under the old names; without the migrator, upgrading the plugin leaves
// the site without clients. The real test, with WPML and Lavandería's content,
// is the lavanderia-mallorca rehearsal (despliegue/prefijo-tipos.php).
//
// 28-10-2026: the independent reads of each step in parallel; round 2 (same
// day): the second pass and the /wp/v2/client alias read go with the reads
// after the first pass (none of them writes).
// Seen red (15-10-2026): without the migrator's taxonomies() step, «for
// real» fails: the page renders the Query empty (the sector is still old).
// Seen red (28-10-2026) with the expected WPML sync value of the location
// changed from 2 to 1 («order and values are preserved»); round 2, with the
// second pass expected to find something, and with /wp/v2/client expected to
// answer as the location type.
import { test, before } from "node:test";
import assert from "node:assert/strict";
import { reset, wp, saveCompany, html, rawPost, testApi, BASE, type } from "../../../tests/lib.mjs";

let seed;
before(async () => {
  // The seed deletes an earlier run's «clientes-de-antes» page before inserting its own (tests/wp).
  await reset();
  // One after the other: the save builds the types' definitions, and the content-types migration
  // also looks at which old types have entries; the seed's rows must not race it.
  await saveCompany({ enabled_types: ["client", "location"] });
  seed = await testApi("/legacy-types", { method: "POST" });
});

// By its slug, not ?page_id= (a 301 to the slug: two requests per read).
const page = async () => (await html(`${BASE}/clientes-de-antes/`)).text;

test("before migrating: nobody sees the old rows (plugin 1.2.0 registers other names)", async () => {
  const [row, text] = await Promise.all([rawPost(seed.client), page()]);
  assert.equal(row.post_type, "client");
  assert.doesNotMatch(text, /Hotel de antes/);
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

let client, location, text, loc, opts, second, aliasRead;
test("for real: the row, the sector and the Query switch to the new names and the page renders the client", async () => {
  const r = await testApi("/prefix-migration", { method: "POST", body: { dry: false } });
  assert.equal(r.seco, false);
  assert.deepEqual(r.avisos, []);
  assert.ok(r.wpml.includes(`icl_sitepress_settings#custom_posts_sync_option[v]: client → ${type("client")}`), JSON.stringify(r.wpml));
  assert.ok(r.wpml.includes(`WPML(setup/translate-everything-taxonomies)[v]: client_sector → ${type("client_sector")}`), JSON.stringify(r.wpml));
  assert.ok(r.rankmath.includes(`rank_math_known_post_types: client → ${type("client")}`), JSON.stringify(r.rankmath));
  // The second pass (idempotent) and the alias read go with these reads: none of them writes.
  let alias;
  [client, location, text, loc, opts, second, alias] = await Promise.all([
    rawPost(seed.client),
    rawPost(seed.location),
    page(),
    wp(`/${type("location")}/${seed.location}?context=edit`),
    testApi("/options", { method: "POST", body: { names: ["icl_sitepress_settings#custom_posts_sync_option", "rank_math_known_post_types"] } }),
    testApi("/prefix-migration", { method: "POST", body: { dry: false } }),
    wp(`/client/${seed.client}`),
  ]);
  aliasRead = alias;
  assert.equal(client.post_type, type("client"));
  assert.equal(location.post_type, type("location"));
  assert.match(text, /Hotel de antes/, "the Query finds the client of its sector");
  assert.match(text, /Hoteles de antes/, "post-terms renders the sector");
  assert.equal(loc.json.meta.estrategia_web_location_phone, "971 11 22 33", "the metas do not change");
  assert.deepEqual(opts["icl_sitepress_settings#custom_posts_sync_option"], { v: { page: 1, [type("client")]: 2, [type("location")]: 2 } }, "order and values are preserved");
  assert.deepEqual(opts.rank_math_known_post_types, { post: "post", [type("client")]: type("client") });
});

test("idempotent: the second pass finds nothing", () => {
  const r = second;
  for (const k of ["tipos", "taxonomias", "contenido", "plantillas", "menus", "wpml", "rankmath", "pantallas", "avisos"]) {
    assert.deepEqual(r[k], [], k);
  }
});

test("alias until 2.0.0: a Query with the OLD names renders the new ones, and /wp/v2/client answers like the new one", async () => {
  const old = `<!-- wp:query {"queryId":6,"query":{"perPage":10,"pages":0,"offset":0,"postType":"client","order":"asc","orderBy":"title","inherit":false,"taxQuery":{"include":{"client_sector":[${seed.term}]}}}} --><div class="wp-block-query"><!-- wp:post-template --><!-- wp:post-title /--><!-- /wp:post-template --></div><!-- /wp:query -->`;
  const p = await testApi("/page", { method: "POST", body: { slug: "old-query", title: "Old query", content: old } });
  assert.match((await html(p.url)).text, /Hotel de antes/);
  assert.equal(aliasRead.status, 200, aliasRead.text);
  assert.equal(aliasRead.json.type, type("client"));
});
