// NIVEL: 2 — Playground WordPress, because it WRITES: creates clients and locations via REST and renders a Query with core/post-meta.
//
// The unreleased field additions: the client's «Business type» and (1.8.0)
// the location's single map field, the search, with the link and the embed derived.
import { test, before } from "node:test";
import assert from "node:assert/strict";
import { reset, wp, saveCompany, seedPage, html, type } from "../../../tests/lib.mjs";

before(async () => {
  await reset();
  await saveCompany({ enabled_types: ["client", "location"] });
  await wp("/types"); // Stored on the save; registered from the next request.
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

// 1.8.0: the location keeps ONE map field, the search; the link and the embed are derived (the embed
// field and its whole-<iframe> sanitizer: content-types/tests/constructor.test.mjs, on a type of its own).
test("location: «What to search on Google Maps» is stored as typed, and the Maps link and the embed come out of it", async () => {
  const s = await wp(`/${type("location")}`, { method: "POST", body: { title: "Maioris", slug: "maioris", status: "publish", meta: { estrategia_web_location_map_query: "Lavandería Ecológica Mallorca - Maioris (Autoservicio)" } } });
  assert.equal(s.status, 201, s.text);
  assert.equal(s.json.meta.estrategia_web_location_map_query, "Lavandería Ecológica Mallorca - Maioris (Autoservicio)");
  const p = (cls, key) => `<!-- wp:paragraph ${JSON.stringify({ className: cls, metadata: { bindings: { content: { source: "estrategia-web-site/location", args: { slug: "maioris", key } } } } })} -->\n<p class="${cls}">x</p>\n<!-- /wp:paragraph -->`;
  const page = await seedPage(p("t-url", "estrategia_web_location_map_url") + "\n" + p("t-embed", "estrategia_web_location_map_embed"), "sede-mapa");
  const { text } = await html(page.url);
  assert.match(text, /<p class="t-url[^"]*">https:\/\/www\.google\.com\/maps\/search\/\?api=1&amp;query=Lavander%C3%ADa\+Ecol%C3%B3gica\+Mallorca\+-\+Maioris\+%28Autoservicio%29<\/p>/);
  assert.match(text, /<p class="t-embed[^"]*">https:\/\/www\.google\.com\/maps\/embed\?origin=mfe&amp;pb=!1m3!2m1!1sLavander%C3%ADa\+Ecol%C3%B3gica\+Mallorca\+-\+Maioris\+%28Autoservicio%29!6i15<\/p>/);
});
