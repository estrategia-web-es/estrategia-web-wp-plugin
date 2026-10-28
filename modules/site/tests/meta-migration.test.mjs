// NIVEL: 2 — Playground WordPress. NOT LEVEL 1 BECAUSE: what is tested is the meta prefix migrator on a real database (rows renamed with SQL, someone else's rows left alone, the bindings inside stored pages rewritten) and the read alias as WordPress resolves it (REST, get_post_meta, a core/post-meta binding rendered); the key map and the content renaming as functions are meta-prefix.test.mjs (level 1). No browser.
//
// Why it exists (17-10-2026): the metas got their prefix
// (estrategia_web_client_url…, Unprefixed_Meta). A site published with 1.4.3
// keeps its rows and its core/post-meta bindings under the old keys; the
// alias makes them look the same until the migrator renames them. The real
// rehearsal, with WPML and Lavandería's content, is lavanderia-mallorca
// (despliegue/prefijo-metas.php).
//
// 28-10-2026: the independent reads of each step in parallel; the page with
// the pasted binding is seeded after the second pass, as before. Round 2
// (same day): the second pass and the alias reads go with the reads after
// the first pass (none of them writes).
// Seen red (17-10-2026): without Unprefixed_Meta::block_data() (the bindings
// alias), «before migrating» fails: the page shows «sin web» instead of the
// client's website, because core only resolves registered keys.
// Seen red (28-10-2026) with the expected row of someone else's page changed
// to the prefixed key («a page's client_url belongs to someone else»); round 2,
// with the second pass expected to find a meta, and with someone else's key
// expected to read another value through the alias.
import { test, before } from "node:test";
import assert from "node:assert/strict";
import { reset, wp, saveCompany, html, testApi, BASE, type } from "../../../tests/lib.mjs";

let seed;
before(async () => {
  // The seed deletes an earlier run's «fichas-de-antes» page before inserting its own (tests/wp).
  await reset();
  [, seed] = await Promise.all([saveCompany({ enabled_types: ["client", "testimonial"] }), testApi("/legacy-meta", { method: "POST" })]);
});

// By its slug, not ?page_id= (a 301 to the slug: two requests per read).
const page = async () => (await html(`${BASE}/fichas-de-antes/`)).text;
const raw = (id) => testApi(`/raw-meta/${id}`);
const read = async (id, key) => (await testApi("/get-meta", { method: "POST", body: { id, key } })).value;

test("before migrating: the page and REST show the old rows through the new keys", async () => {
  const [text, c, value] = await Promise.all([page(), wp(`/${type("client")}/${seed.client}?context=edit`), read(seed.client, "client_url")]);
  assert.match(text, /https:\/\/antes\.test\//, "the binding with the old key shows the website");
  assert.match(text, /Gerente del Hotel Antes/, "and the role (binding written args-first)");
  assert.equal(c.json.meta.estrategia_web_client_url, "https://antes.test/", "REST reads the old row under the new key");
  assert.equal(value, "https://antes.test/");
});

test("dry run: counts what it would do and writes nothing", async () => {
  const r = await testApi("/meta-prefix-migration", { method: "POST", body: { dry: true } });
  assert.equal(r.seco, true);
  assert.deepEqual(r.metas, ["client_url → estrategia_web_client_url: 1 rows", "testimonial_role → estrategia_web_testimonial_role: 1 rows", "testimonial_client → estrategia_web_testimonial_client: 1 rows"]);
  assert.ok(r.contenido.some((l) => l.startsWith(`#${seed.page} page fichas-de-antes: 2 bindings`)), JSON.stringify(r.contenido));
  assert.ok(r.avisos.some((l) => l.startsWith(`#${seed.both} `)), "the client with both rows is warned about");
  assert.deepEqual(r.entradas.map((e) => e.id).filter((id) => [seed.client, seed.testimonial, seed.page].includes(id)).sort(), [seed.client, seed.testimonial, seed.page].sort());
  assert.equal((await raw(seed.client)).client_url, "https://antes.test/", "a dry run does not write");
});

let second, aliasReads;
test("for real: the rows and the bindings take the new keys; someone else's page is not touched", async () => {
  const r = await testApi("/meta-prefix-migration", { method: "POST", body: { dry: false } });
  assert.equal(r.seco, false);
  // The second pass (idempotent) and the alias reads go with these reads: none of them writes.
  const [client, testimonial, foreign, both, stored, text, again, ...aliases] = await Promise.all([
    raw(seed.client),
    raw(seed.testimonial),
    raw(seed.foreign),
    raw(seed.both),
    testApi(`/raw-content/${seed.page}`),
    page(),
    testApi("/meta-prefix-migration", { method: "POST", body: { dry: false } }),
    read(seed.client, "client_url"),
    read(seed.testimonial, "testimonial_client"),
    read(seed.foreign, "client_url"),
  ]);
  [second, aliasReads] = [again, aliases];
  assert.deepEqual(client, { estrategia_web_client_url: "https://antes.test/" });
  assert.deepEqual(testimonial, { estrategia_web_testimonial_client: String(seed.client), estrategia_web_testimonial_role: "Gerente del Hotel Antes" });
  assert.deepEqual(foreign, { client_url: "https://ajeno.test/" }, "a page's client_url belongs to someone else");
  assert.deepEqual(both, { client_url: "https://vieja.test/", estrategia_web_client_url: "https://nueva.test/" }, "both: left as is, with a warning");
  assert.doesNotMatch(stored.content, /"key":"(client_url|testimonial_role)"/);
  assert.match(stored.content, /"key":"estrategia_web_client_url"/);
  assert.match(text, /https:\/\/antes\.test\//, "the page looks the same");
  assert.match(text, /Gerente del Hotel Antes/);
  assert.match(text, /https:\/\/nueva\.test\//, "the client with both rows shows the new one");
});

test("idempotent: the second pass finds nothing (but the same warning)", () => {
  const r = second;
  assert.deepEqual(r.metas, []);
  assert.deepEqual(r.contenido, []);
  assert.deepEqual(r.entradas, []);
});

test("alias until 2.0.0: a theme reading the old key and a pasted binding with it still work", async () => {
  const pasted = `<!-- wp:query {"queryId":8,"query":{"perPage":10,"pages":0,"offset":0,"postType":"${type("client")}","order":"asc","orderBy":"title","inherit":false}} --><div class="wp-block-query"><!-- wp:post-template --><!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"core/post-meta","args":{"key":"client_url"}}}}} --><p>sin web</p><!-- /wp:paragraph --><!-- /wp:post-template --></div><!-- /wp:query -->`;
  const [client, testimonial, foreign] = aliasReads;
  const p = await testApi("/page", { method: "POST", body: { slug: "binding-pegado", title: "Binding pegado", content: pasted } });
  assert.equal(client, "https://antes.test/");
  assert.equal(testimonial, String(seed.client));
  assert.equal(foreign, "https://ajeno.test/", "someone else's key, unchanged");
  assert.match((await html(p.url)).text, /https:\/\/antes\.test\//, "a binding pasted after migrating, with the old key");
});
