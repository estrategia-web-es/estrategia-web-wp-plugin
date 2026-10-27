// NIVEL: 2 — Playground WordPress, because it WRITES: enables the `location` type, creates locations via REST and a page; what is checked is the HTML the server renders, no browser.
import { test, before } from "node:test";
import assert from "node:assert/strict";
import { reset, wp, saveCompany, seedPage, html, type } from "../../../tests/lib.mjs";

const SRC = "estrategia-web-site/location";
const meta = (args) => ({ metadata: { bindings: { content: { source: SRC, args } } } });
const attrs = (cls, bindings) => JSON.stringify({ className: cls, metadata: { bindings } });

let maioris;
before(async () => {
  await reset();
  await saveCompany({ enabled_types: ["location"], phone: "971 00 00 00" });
  await wp("/types"); // The definition is stored on the save; registered from the next request.
  const make = async (title, slug, status, m) => {
    const r = await wp(`/${type("location")}`, { method: "POST", body: { title, slug, status, meta: m } });
    assert.equal(r.status, 201, r.text);
    return r.json;
  };
  // 1.8.0: the address by parts (the text is derived), the map as a search query (the link is derived).
  maioris = await make("Maioris & Llucmajor", "maioris", "publish", {
    estrategia_web_location_street: "Carrer de Maioris 3",
    estrategia_web_location_postal_code: "07620",
    estrategia_web_location_locality: "Llucmajor",
    estrategia_web_location_phone: "971 11 22 33",
    estrategia_web_location_email: "maioris@ejemplo.test",
    estrategia_web_location_hours: JSON.stringify({ mo: ["08:00-20:00"], tu: ["08:00-20:00"], we: ["08:00-20:00"], th: ["08:00-20:00"], fr: ["08:00-20:00"] }),
    estrategia_web_location_map_query: "Maioris",
  });
  await make("Palma location", "palma", "publish", { estrategia_web_location_street: "Carrer de Palma 1", estrategia_web_location_postal_code: "07001", estrategia_web_location_locality: "Palma", estrategia_web_location_phone: "971 44 55 66" });
  await make("Draft location", "borrador", "draft", { estrategia_web_location_phone: "971 99 99 99", estrategia_web_location_street: "Must not appear" });
});

const between = (text, cls, tag) => text.match(new RegExp(`<${tag}[^>]*${cls}[^>]*>([\\s\\S]*?)</${tag}>`))?.[1];
const href = (text, cls) => text.match(new RegExp(`${cls}[^"]*"><a[^>]*href="([^"]*)"`))?.[1];

test("by slug, from any page: button with text and url to the phone of the «maioris» location; title, address with <br>, email and map", async () => {
  const page = await seedPage(
    `<!-- wp:buttons -->
<div class="wp-block-buttons"><!-- wp:button ${attrs("t-tel", { text: { source: SRC, args: { slug: "maioris", key: "estrategia_web_location_phone" } }, url: { source: SRC, args: { slug: "maioris", key: "estrategia_web_location_phone_href" } } })} -->
<div class="wp-block-button t-tel"><a class="wp-block-button__link wp-element-button" href="#">placeholder</a></div>
<!-- /wp:button -->
<!-- wp:button ${attrs("t-mail", { url: { source: SRC, args: { slug: "maioris", key: "estrategia_web_location_email_href" } } })} -->
<div class="wp-block-button t-mail"><a class="wp-block-button__link wp-element-button" href="#">Email</a></div>
<!-- /wp:button -->
<!-- wp:button ${attrs("t-map", { url: { source: SRC, args: { slug: "maioris", key: "estrategia_web_location_map_url" } } })} -->
<div class="wp-block-button t-map"><a class="wp-block-button__link wp-element-button" href="#">Map</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons -->
<!-- wp:heading ${JSON.stringify({ className: "t-title", ...meta({ slug: "maioris", key: "title" }) })} -->
<h2 class="wp-block-heading t-title">x</h2>
<!-- /wp:heading -->
<!-- wp:paragraph ${JSON.stringify({ className: "t-address", ...meta({ slug: "maioris", key: "estrategia_web_location_address" }) })} -->
<p class="t-address">x</p>
<!-- /wp:paragraph -->`,
    "autoservicio"
  );
  const { status, text } = await html(page.url);
  assert.equal(status, 200);
  assert.equal(href(text, "t-tel"), "tel:+34971112233");
  assert.match(text, /t-tel[^"]*"><a[^>]*>971 11 22 33<\/a>/, "the button text is the phone");
  assert.equal(href(text, "t-mail"), "mailto:maioris@ejemplo.test");
  assert.equal(href(text, "t-map"), "https://www.google.com/maps/search/?api=1&#038;query=Maioris", "derived from the query (1.8.0)");
  assert.equal(between(text, "t-title", "h2"), "Maioris &amp; Llucmajor", "the title, escaped");
  assert.equal(between(text, "t-address", "p").replace(/\n/g, ""), "Carrer de Maioris 3<br>07620 Llucmajor", "derived from the parts (1.8.0)");
});

test("the derived hours text (1.8.0): from the «hours» field, in the site's language (es)", async () => {
  const page = await seedPage(
    `<!-- wp:paragraph ${JSON.stringify({ className: "t-hours", ...meta({ slug: "maioris", key: "estrategia_web_location_schedule" }) })} -->
<p class="t-hours">x</p>
<!-- /wp:paragraph -->`,
    "sede-horario"
  );
  const { text } = await html(page.url);
  assert.equal(between(text, "t-hours", "p").replace(/\n/g, ""), "Abierto de lunes a viernes<br>De 8:00 a 20:00");
});

test("a non-existent, draft or keyless location comes out empty; outside a location, a key without slug too", async () => {
  const page = await seedPage(
    `<!-- wp:paragraph ${JSON.stringify({ className: "t-none", ...meta({ slug: "no-existe", key: "estrategia_web_location_phone" }) })} -->
<p class="t-none">placeholder-none</p>
<!-- /wp:paragraph -->
<!-- wp:paragraph ${JSON.stringify({ className: "t-draft", ...meta({ slug: "borrador", key: "estrategia_web_location_phone" }) })} -->
<p class="t-draft">placeholder-draft</p>
<!-- /wp:paragraph -->
<!-- wp:paragraph ${JSON.stringify({ className: "t-nokey", ...meta({ slug: "maioris", key: "phone" }) })} -->
<p class="t-nokey">placeholder-nokey</p>
<!-- /wp:paragraph -->
<!-- wp:paragraph ${JSON.stringify({ className: "t-ctx", ...meta({ key: "estrategia_web_location_phone" }) })} -->
<p class="t-ctx">placeholder-ctx</p>
<!-- /wp:paragraph -->`,
    "sedes-vacias"
  );
  const { text } = await html(page.url);
  for (const cls of ["t-none", "t-draft", "t-nokey", "t-ctx"]) {
    assert.equal(between(text, cls, "p"), "", `${cls} empty`);
  }
  assert.ok(!text.includes("971 99 99 99") && !text.includes("Must not appear"), "nothing from the draft");
});

test("without slug, inside a Query of locations: each <li> shows the address of ITS location (postId context)", async () => {
  const query = JSON.stringify({ queryId: 3, query: { perPage: 10, pages: 0, offset: 0, postType: type("location"), order: "asc", orderBy: "title", inherit: false } });
  const page = await seedPage(
    `<!-- wp:query ${query} -->
<div class="wp-block-query"><!-- wp:post-template -->
<!-- wp:post-title /-->
<!-- wp:paragraph ${JSON.stringify({ className: "t-addr", ...meta({ key: "estrategia_web_location_address" }) })} -->
<p class="t-addr">x</p>
<!-- /wp:paragraph -->
<!-- wp:paragraph ${JSON.stringify({ className: "t-phone", ...meta({ key: "estrategia_web_location_phone_href" }) })} -->
<p class="t-phone">x</p>
<!-- /wp:paragraph -->
<!-- /wp:post-template --></div>
<!-- /wp:query -->`,
    "sedes-consulta"
  );
  const { text } = await html(page.url);
  const items = [...(text.match(/<ul[^>]*wp-block-post-template[\s\S]*?<\/ul>/)?.[0] ?? "").matchAll(/<li[\s\S]*?<\/li>/g)].map((m) => m[0]);
  assert.equal(items.length, 2, "the two published ones");
  assert.match(items[0], /Maioris/);
  assert.equal(between(items[0], "t-addr", "p").replace(/\n/g, ""), "Carrer de Maioris 3<br>07620 Llucmajor");
  assert.equal(between(items[0], "t-phone", "p"), "tel:+34971112233");
  assert.equal(between(items[1], "t-addr", "p").replace(/\n/g, ""), "Carrer de Palma 1<br>07001 Palma");
  assert.ok(maioris.id);
});
