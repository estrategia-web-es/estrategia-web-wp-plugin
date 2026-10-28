// NIVEL: 2 — Playground WordPress. NOT LEVEL 1 BECAUSE: what is tested is what our binding sources and our shortcode put in a page WordPress renders — estrategia-web-site/company applied by Block Bindings (a paragraph's content, a button's url, the escaping on the way out), [estrategia_web_company] run by do_shortcode inside a sentence, the `registry` key through the settings REST API's sanitize and merge — and what the estrategia-web-site/location binding source puts there — the location found by slug among real posts (published only), the Query's postId context, the derived texts and the static map's attachment URL reaching the block markup — and the location fields' REST sanitizing (the static map keeps only a real image attachment: a lookup in the database). The derivations themselves (address, hours text, Maps link and embed) are level 1 (content-types/tests/location-data.test.mjs, site/tests/pure.test.mjs); the old static map path rules, local-image.test.mjs (level 1). No browser.
//
// 28-10-2026: one page with every case on it, read once, and the locations
// created in parallel. Round 2 (same day): static-map.test.mjs and the
// location half of meta-fields.test.mjs merged in here — they set up the same
// thing (the location type, locations, one page of bindings) in a file each.
// The static map: up to 1.7.0 a path field and an id field; since 2.0.0 only
// the media library image, and the URL is DERIVED under the old key
// (estrategia_web_location_static_map), which is what estrategia-web/map's
// staticMapUrl binds. The theme is not here: a paragraph and an image bound to it.
// Also round 2: bindings.test.mjs (the company source, and legal.test.mjs that
// had gone into it) merged in — one company save, one page, one read for all.
// Its cases' classes are «c-…»; the links (tel:, mailto:, wa.me) are
// pure.test.mjs (level 1). Legal data (0.4.0): the [estrategia_web_company]
// shortcode is what puts a field INSIDE a sentence of the legal notice (a
// WP 7.1 binding replaces the whole paragraph: see includes/class-shortcode.php).
// Seen red (28-10-2026) with the draft location published (the «t-draft»
// case shows its phone), with the expected hours text changed, with the
// expected id of the «not an image» case changed to the page's id, and with
// Maioris's map expected in Inca's <li>; and for the company cases, with the
// expected phone changed, the second phone's tel: expected for the first,
// the expected tax ID changed in the legal sentence, and the registry
// expected to keep its <i>.
import { test, before } from "node:test";
import assert from "node:assert/strict";
import { reset, wp, saveCompany, seedPage, html, upload, png, type } from "../../../tests/lib.mjs";

const SRC = "estrategia-web-site/location";
const meta = (args) => ({ metadata: { bindings: { content: { source: SRC, args } } } });
const attrs = (cls, bindings) => JSON.stringify({ className: cls, metadata: { bindings } });
const para = (cls, args) => `<!-- wp:paragraph ${JSON.stringify({ className: cls, ...meta(args) })} -->\n<p class="${cls}">placeholder-${cls}</p>\n<!-- /wp:paragraph -->`;
const query = JSON.stringify({ queryId: 3, query: { perPage: 10, pages: 0, offset: 0, postType: type("location"), order: "asc", orderBy: "title", inherit: false } });
const MAP_QUERY = "Lavandería Ecológica Mallorca - Maioris (Autoservicio)";
const STATIC = "estrategia_web_location_static_map";

const PAGE = `<!-- wp:buttons -->
<div class="wp-block-buttons"><!-- wp:button ${attrs("t-tel", { text: { source: SRC, args: { slug: "maioris", key: "estrategia_web_location_phone" } }, url: { source: SRC, args: { slug: "maioris", key: "estrategia_web_location_phone_href" } } })} -->
<div class="wp-block-button t-tel"><a class="wp-block-button__link wp-element-button" href="#">placeholder</a></div>
<!-- /wp:button -->
<!-- wp:button ${attrs("t-mail", { url: { source: SRC, args: { slug: "maioris", key: "estrategia_web_location_email_href" } } })} -->
<div class="wp-block-button t-mail"><a class="wp-block-button__link wp-element-button" href="#">Email</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons -->
<!-- wp:heading ${JSON.stringify({ className: "t-title", ...meta({ slug: "maioris", key: "title" }) })} -->
<h2 class="wp-block-heading t-title">x</h2>
<!-- /wp:heading -->
${para("t-address", { slug: "maioris", key: "estrategia_web_location_address" })}
${para("t-hours", { slug: "maioris", key: "estrategia_web_location_schedule" })}
${para("t-url", { slug: "maioris", key: "estrategia_web_location_map_url" })}
${para("t-embed", { slug: "maioris", key: "estrategia_web_location_map_embed" })}
${para("t-src", { slug: "maioris", key: STATIC })}
${para("t-none", { slug: "no-existe", key: "estrategia_web_location_phone" })}
${para("t-draft", { slug: "borrador", key: "estrategia_web_location_phone" })}
${para("t-nokey", { slug: "maioris", key: "phone" })}
${para("t-ctx", { key: "estrategia_web_location_phone" })}
<!-- wp:query ${query} -->
<div class="wp-block-query"><!-- wp:post-template -->
<!-- wp:post-title /-->
${para("t-addr", { key: "estrategia_web_location_address" })}
${para("t-phone", { key: "estrategia_web_location_phone_href" })}
${para("t-static", { key: STATIC })}
<!-- wp:image ${JSON.stringify({ className: "t-img", metadata: { bindings: { url: { source: SRC, args: { key: STATIC } } } } })} -->
<figure class="wp-block-image t-img"><img src="" alt=""/></figure>
<!-- /wp:image -->
<!-- /wp:post-template --></div>
<!-- /wp:query -->`;

const bind = (attr, key) => JSON.stringify({ metadata: { bindings: { [attr]: { source: "estrategia-web-site/company", args: { key } } } } });

const COMPANY = `<!-- wp:paragraph ${bind("content", "phone")} -->
<p class="c-phone">Placeholder phone</p>
<!-- /wp:paragraph -->
<!-- wp:paragraph ${bind("content", "address")} -->
<p class="c-address">x</p>
<!-- /wp:paragraph -->
<!-- wp:heading ${bind("content", "company_name")} -->
<h2 class="wp-block-heading c-name">x</h2>
<!-- /wp:heading -->
<!-- wp:paragraph ${bind("content", "social.instagram")} -->
<p class="c-social">x</p>
<!-- /wp:paragraph -->
<!-- wp:buttons -->
<div class="wp-block-buttons"><!-- wp:button {"className":"c-wa",${bind("url", "whatsapp_href").slice(1, -1)}} -->
<div class="wp-block-button c-wa"><a class="wp-block-button__link wp-element-button" href="#">WhatsApp</a></div>
<!-- /wp:button -->
<!-- wp:button {"className":"c-tel",${bind("url", "phone_href").slice(1, -1)}} -->
<div class="wp-block-button c-tel"><a class="wp-block-button__link wp-element-button" href="#">Call</a></div>
<!-- /wp:button -->
<!-- wp:button {"className":"c-mail",${bind("url", "email_href").slice(1, -1)}} -->
<div class="wp-block-button c-mail"><a class="wp-block-button__link wp-element-button" href="#">Email</a></div>
<!-- /wp:button -->
<!-- wp:button {"className":"c-tel2",${bind("url", "phone_secondary_href").slice(1, -1)}} -->
<div class="wp-block-button c-tel2"><a class="wp-block-button__link wp-element-button" href="#">Call the other one</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons -->`;

const REGISTRY = "Registro Mercantil de Mallorca, tomo 1234, folio 56, hoja PM-7890, inscripción 1.ª";
const sc = (key) => `[estrategia_web_company key="${key}"]`;
const LEGAL = `<!-- wp:paragraph {"className":"c-legal"} -->
<p class="c-legal">Titular: ${sc("legal_name")}, NIF ${sc("tax_id")}, domicilio en ${sc("address")}. Teléfono ${sc("phone")}, correo ${sc("email")}. Inscrita en el ${sc("registry")}.</p>
<!-- /wp:paragraph -->
<!-- wp:paragraph {"className":"c-unknown"} -->
<p class="c-unknown">(${sc("phone_mobile")})</p>
<!-- /wp:paragraph -->
<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"estrategia-web-site/company","args":{"key":"registry"}}}}} -->
<p class="c-bound">x</p>
<!-- /wp:paragraph -->`;

let text, image, other, page, made;
before(async () => {
  await reset();
  await saveCompany({
      enabled_types: ["location"],
      legal_name: "Lavandería <b>Tom</b> & Cía SL",
      tax_id: "B07000000",
      registry: REGISTRY,
      company_name: "Tom & Jerry <SL>",
      phone: "971 12 34 56",
      phone_secondary: "+34 600 99 88 77",
      email: "hola@ejemplo.test",
      whatsapp: "34600111222",
      whatsapp_message: "Hola, quería información",
      address: "Calle Mayor 1\n07001 Palma",
      social: { instagram: "https://instagram.com/prueba" },
    });
  const [img, img2, p] = await Promise.all([upload("mapa-maioris.png", "image/png", png(8, 4, [40, 120, 200])), upload("mapa-inca.png", "image/png", png(8, 4, [200, 120, 40])), seedPage(PAGE + "\n" + COMPANY + "\n" + LEGAL, "sedes-prueba")]);
  assert.equal(img.status, 201, img.text);
  assert.equal(img2.status, 201, img2.text);
  [image, other, page] = [img.json, img2.json, p];
  const make = async (title, slug, status, m) => {
    const r = await wp(`/${type("location")}`, { method: "POST", body: { title, slug, status, meta: m } });
    assert.equal(r.status, 201, r.text);
    return r.json;
  };
  // 2.0.0: the address by parts (the text is derived), the map as a search query (the link is derived).
  // Drafts for the refused static map ids: they stay out of the Query.
  made = await Promise.all([
    make("Maioris & Llucmajor", "maioris", "publish", {
      estrategia_web_location_street: "Carrer de Maioris 3",
      estrategia_web_location_postal_code: "07620",
      estrategia_web_location_locality: "Llucmajor",
      estrategia_web_location_phone: "971 11 22 33",
      estrategia_web_location_email: "maioris@ejemplo.test",
      estrategia_web_location_hours: JSON.stringify({ mo: ["08:00-20:00"], tu: ["08:00-20:00"], we: ["08:00-20:00"], th: ["08:00-20:00"], fr: ["08:00-20:00"] }),
      estrategia_web_location_map_query: MAP_QUERY,
      estrategia_web_location_static_map_id: image.id,
    }),
    make("Palma location", "palma", "publish", { estrategia_web_location_street: "Carrer de Palma 1", estrategia_web_location_postal_code: "07001", estrategia_web_location_locality: "Palma", estrategia_web_location_phone: "971 44 55 66" }),
    make("Inca location", "inca", "publish", { estrategia_web_location_static_map_id: other.id }),
    make("Draft location", "borrador", "draft", { estrategia_web_location_phone: "971 99 99 99", estrategia_web_location_street: "Must not appear" }),
    make("Not an image", "no-imagen", "draft", { estrategia_web_location_static_map_id: page.id }),
    make("No such post", "no-existe-id", "draft", { estrategia_web_location_static_map_id: 999999 }),
    make("Negative", "negativo", "draft", { estrategia_web_location_static_map_id: -3 }),
  ]);
  const r = await html(page.url);
  assert.equal(r.status, 200);
  text = r.text;
});

const between = (t, cls, tag) => t.match(new RegExp(`<${tag}[^>]*${cls}[^>]*>([\\s\\S]*?)</${tag}>`))?.[1];
const href = (t, cls) => t.match(new RegExp(`${cls}[^"]*"><a[^>]*href="([^"]*)"`))?.[1];
const chref = (cls) => text.match(new RegExp(`${cls}[^"]*"><a[^>]*href="([^"]*)"`))?.[1];
const esc = (s) => s.replace(/[.?]/g, "\\$&");
const items = () => [...(text.match(/<ul[^>]*wp-block-post-template[\s\S]*?<\/ul>/)?.[0] ?? "").matchAll(/<li[\s\S]*?<\/li>/g)].map((m) => m[0]);

test("by slug, from any page: button with text and url to the phone of the «maioris» location; title, address with <br>, email", () => {
  assert.equal(href(text, "t-tel"), "tel:+34971112233");
  assert.match(text, /t-tel[^"]*"><a[^>]*>971 11 22 33<\/a>/, "the button text is the phone");
  assert.equal(href(text, "t-mail"), "mailto:maioris@ejemplo.test");
  assert.equal(between(text, "t-title", "h2"), "Maioris &amp; Llucmajor", "the title, escaped");
  assert.equal(between(text, "t-address", "p").replace(/\n/g, ""), "Carrer de Maioris 3<br>07620 Llucmajor", "derived from the parts (2.0.0)");
});

test("the derived hours text (2.0.0): from the «hours» field, in the site's language (es)", () => {
  assert.equal(between(text, "t-hours", "p").replace(/\n/g, ""), "Abierto de lunes a viernes<br>De 8:00 a 20:00");
});

// 2.0.0: the location keeps ONE map field, the search; the link and the embed are derived.
test("«What to search on Google Maps» is stored as typed, and the Maps link and the embed come out of it", () => {
  assert.equal(made[0].meta.estrategia_web_location_map_query, MAP_QUERY);
  assert.match(text, /<p class="t-url[^"]*">https:\/\/www\.google\.com\/maps\/search\/\?api=1&amp;query=Lavander%C3%ADa\+Ecol%C3%B3gica\+Mallorca\+-\+Maioris\+%28Autoservicio%29<\/p>/);
  assert.match(text, /<p class="t-embed[^"]*">https:\/\/www\.google\.com\/maps\/embed\?origin=mfe&amp;pb=!1m3!2m1!1sLavander%C3%ADa\+Ecol%C3%B3gica\+Mallorca\+-\+Maioris\+%28Autoservicio%29!6i15<\/p>/);
});

test("a non-existent, draft or keyless location comes out empty; outside a location, a key without slug too", () => {
  for (const cls of ["t-none", "t-draft", "t-nokey", "t-ctx"]) {
    assert.equal(between(text, cls, "p"), "", `${cls} empty`);
  }
  assert.ok(!text.includes("971 99 99 99") && !text.includes("Must not appear"), "nothing from the draft");
});

test("without slug, inside a Query of locations: each <li> shows the address of ITS location (postId context)", () => {
  const li = items();
  assert.equal(li.length, 3, "the three published ones");
  assert.match(li[1], /Maioris/, "by title: Inca, Maioris, Palma");
  assert.equal(between(li[0], "t-addr", "p"), "", "Inca has no address");
  assert.equal(between(li[1], "t-addr", "p").replace(/\n/g, ""), "Carrer de Maioris 3<br>07620 Llucmajor");
  assert.equal(between(li[1], "t-phone", "p"), "tel:+34971112233");
  assert.equal(between(li[2], "t-addr", "p").replace(/\n/g, ""), "Carrer de Palma 1<br>07001 Palma");
});

test("estrategia_web_location_static_map_id: an image attachment is saved; an id that is not an image attachment, 0", () => {
  assert.deepEqual(made.slice(4).map((l) => l.meta.estrategia_web_location_static_map_id), [0, 0, 0], "a page, a missing id, a negative one");
  assert.deepEqual([made[0], made[2]].map((l) => l.meta.estrategia_web_location_static_map_id), [image.id, other.id]);
});

test("the static map URL through the location source: each location ITS map in a Query (what staticMapUrl of estrategia-web/map receives), and by slug", () => {
  const li = items();
  assert.match(li[0], new RegExp(`<p class="t-static[^"]*">${esc(other.source_url)}</p>`), "Inca comes first by title");
  assert.match(li[0], new RegExp(`<img[^>]*src="${esc(other.source_url)}"`));
  assert.match(li[1], new RegExp(`<p class="t-static[^"]*">${esc(image.source_url)}</p>`));
  assert.match(text, new RegExp(`<p class="t-src[^"]*">${esc(image.source_url)}</p>`));
});

// The company data (moved here from bindings.test.mjs, round 2, 28-10-2026: the same set-up).
test("a paragraph bound to estrategia-web-site/company:phone shows the phone, not the placeholder text", () => {
    assert.equal(between(text, "c-phone", "p"), "971 12 34 56");
  assert.ok(!text.includes("Placeholder phone"));
});

test("the button with whatsapp_href carries the correct wa.me; phone_href, email_href and phone_secondary_href too", () => {
  assert.equal(chref("c-wa"), "https://wa.me/34600111222?text=Hola%2C%20quer%C3%ADa%20informaci%C3%B3n");
  assert.equal(chref("c-tel"), "tel:+34971123456");
  assert.equal(chref("c-mail"), "mailto:hola@ejemplo.test");
  assert.equal(chref("c-tel2"), "tel:+34600998877", "the tel: of the second phone (0.2.0)");
});

test("text: multiple lines come out with <br>, the data HTML is escaped, social.<network> with a dot works", () => {
  assert.equal(between(text, "c-address", "p").replace(/\n/g, ""), "Calle Mayor 1<br>07001 Palma");
  // sanitize_text_field strips «<SL>»; whatever remains with & is escaped.
  assert.match(between(text, "c-name", "h2"), /^Tom &amp; Jerry/);
  assert.equal(between(text, "c-social", "p"), "https://instagram.com/prueba");
});

test("legal sentence with [estrategia_web_company]: legal name, tax ID, address on one line, phone, email and registry", () => {
  assert.equal(
    between(text, "c-legal", "p"),
    "Titular: Lavandería Tom &amp; Cía SL, NIF B07000000, domicilio en Calle Mayor 1, 07001 Palma. " +
      "Teléfono 971 12 34 56, correo hola@ejemplo.test. Inscrita en el " + REGISTRY + "."
  );
  assert.ok(!text.includes("[estrategia_web_company"), "no unrendered shortcode");
});

test("a key that does not exist comes out empty, not the shortcode nor an error", () => {
  // Parentheses and not brackets: «[[estrategia_web_company …]]» is how a shortcode is
  // ESCAPED in WordPress (it comes out literally), not a shortcode inside brackets.
  assert.equal(between(text, "c-unknown", "p"), "()");
});

test("registry is also a bindable field: a paragraph with a binding shows the registry", () => {
  assert.equal(between(text, "c-bound", "p"), REGISTRY);
});

test("registry (0.4.0): saved via REST without HTML, and the merge does not erase it", async () => {
  const a = await saveCompany({ registry: "Registro <i>Mercantil</i> de Palma" });
  assert.equal(a.registry, "Registro Mercantil de Palma", "no HTML tags");
  const b = await saveCompany({ phone: "971 12 34 56" });
  assert.equal(b.registry, "Registro Mercantil de Palma", "saving another key does not touch it");
});
