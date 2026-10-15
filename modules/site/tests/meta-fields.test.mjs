// NIVEL: 2 — Playground WordPress, because it WRITES: creates clients and locations via REST and renders a Query with core/post-meta.
//
// The unreleased field additions: the client's «Business type» and the
// location's map embed, which accepts the whole <iframe>.
import { test, before } from "node:test";
import assert from "node:assert/strict";
import { reset, wp, saveCompany, seedPage, html, type } from "../../../tests/lib.mjs";

before(async () => {
  await reset();
  await saveCompany({ enabled_types: ["client", "location"] });
});

test("client: estrategia_web_client_business_type is saved sanitized via REST", async () => {
  const c = await wp(`/${type("client")}`, { method: "POST", body: { title: "Portic Villas", status: "publish", meta: { estrategia_web_client_business_type: "  Agencia de <b>alquiler</b> vacacional " } } });
  assert.equal(c.status, 201, c.text);
  assert.equal(c.json.meta.estrategia_web_client_business_type, "Agencia de alquiler vacacional");
  assert.equal(c.json.title.raw ?? c.json.title.rendered, "Portic Villas", "the title has no parentheses");
});

test("client: in a Query of clients, core/post-meta renders each one's business type (the cards)", async () => {
  await wp(`/${type("client")}`, { method: "POST", body: { title: "Hotel Bahía", status: "publish", meta: { estrategia_web_client_business_type: "Hotel" } } });
  const bind = { content: { source: "core/post-meta", args: { key: "estrategia_web_client_business_type" } } };
  const query = JSON.stringify({ queryId: 9, query: { perPage: 10, pages: 0, offset: 0, postType: type("client"), order: "asc", orderBy: "title", inherit: false } });
  const page = await seedPage(
    `<!-- wp:query ${query} -->
<div class="wp-block-query"><!-- wp:post-template -->
<!-- wp:post-title /-->
<!-- wp:paragraph ${JSON.stringify({ className: "t-tipo", metadata: { bindings: bind } })} -->
<p class="t-tipo">x</p>
<!-- /wp:paragraph -->
<!-- /wp:post-template --></div>
<!-- /wp:query -->`,
    "clientes-tipo"
  );
  const { status, text } = await html(page.url);
  assert.equal(status, 200);
  const items = [...(text.match(/<ul[^>]*wp-block-post-template[\s\S]*?<\/ul>/)?.[0] ?? "").matchAll(/<li[\s\S]*?<\/li>/g)].map((m) => m[0]);
  assert.equal(items.length, 2);
  assert.match(items[0], /Hotel Bahía[\s\S]*<p class="t-tipo[^"]*">Hotel<\/p>/);
  assert.match(items[1], /Portic Villas[\s\S]*<p class="t-tipo[^"]*">Agencia de alquiler vacacional<\/p>/);
});

test("location: the map embed accepts Google's whole <iframe> and stores only its src; a bare URL passes too", async () => {
  const iframe = '<iframe src="https://www.google.com/maps/embed?pb=!1m18!1m12" width="600" height="450" style="border:0;" allowfullscreen="" loading="lazy"></iframe>';
  const s = await wp(`/${type("location")}`, { method: "POST", body: { title: "Maioris", slug: "maioris", status: "publish", meta: { estrategia_web_location_map_embed: iframe } } });
  assert.equal(s.status, 201, s.text);
  assert.equal(s.json.meta.estrategia_web_location_map_embed, "https://www.google.com/maps/embed?pb=!1m18!1m12");
  const u = await wp(`/${type("location")}/${s.json.id}`, { method: "POST", body: { meta: { estrategia_web_location_map_embed: "https://www.google.com/maps/embed?pb=x" } } });
  assert.equal(u.json.meta.estrategia_web_location_map_embed, "https://www.google.com/maps/embed?pb=x");
  const bad = await wp(`/${type("location")}/${s.json.id}`, { method: "POST", body: { meta: { estrategia_web_location_map_embed: '<iframe src="javascript:alert(1)"></iframe>' } } });
  assert.equal(bad.json.meta.estrategia_web_location_map_embed, "", "javascript: rejected");
});
