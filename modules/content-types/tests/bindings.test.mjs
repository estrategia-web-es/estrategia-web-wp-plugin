// NIVEL: 2 — Playground WordPress. NOT LEVEL 1 BECAUSE: what is tested is the HTML WordPress renders for blocks bound to estrategia-web/content-field inside Queries of restaurants and wines (examples/gment_restaurant.json and tests/fixtures/valid/gment_wine.json), next to core/post-meta for contrast: WordPress's block bindings, the Query's context and get_the_title() around our source. What Field_Format does with a value is level 1 (format.test.mjs).
//
// The site under test speaks es_ES without WordPress's own es_ES language
// pack: option labels and «Sí»/«No» come from this plugin's translations;
// the month names and the decimal separator are core's, so they are
// English here (the real thing is measured in Lavandería with WPML: the
// report of 16-10-2026).
//
// 28-10-2026, from ~38 s: the reset, the theme files and the three pages in
// ONE request (estrategia-web-site-test/v1/setup), the posts in two batches,
// and the three pages read in the tests only.
//
// Seen red (16-10-2026): with get_value() returning the raw meta, the three
// failed («€€€» instead of «€€€ — alto», the restaurant's ID instead of its
// title, the draft's ID instead of nothing). And for real, the first run:
// the image's alt came out as «&amp;quot;» — escaped by us AND by core
// (Field_Format's header, «The trap»).
// Seen red (28-10-2026) after the batching, one expected value changed in
// each test (the price label, «Ecológico», the empty key): all three red;
// restored, green.
import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { BASE, credentials, html, testApi, wp, upload, png } from "../../../tests/lib.mjs";

const SOURCE = "estrategia-web/content-field";
const binding = (args) => JSON.stringify({ source: SOURCE, args });
// A paragraph with class ct-<name>, its content bound to a field.
const para = (name, args) => `<!-- wp:paragraph {"className":"ct-${name}","metadata":{"bindings":{"content":${binding(args)}}}} -->\n<p class="ct-${name}">x</p>\n<!-- /wp:paragraph -->`;
const query = (postType, inner) =>
  `<!-- wp:query ${JSON.stringify({ queryId: 1, query: { perPage: 10, pages: 0, offset: 0, postType, order: "asc", orderBy: "title", inherit: false } })} -->\n<div class="wp-block-query"><!-- wp:post-template -->\n${inner}\n<!-- /wp:post-template --></div>\n<!-- /wp:query -->`;
// The rendered paragraph with that class: its inner HTML (only that, not 60 KB of page).
const inner = (text, name) => {
  const m = text.match(new RegExp(`<p[^>]*class="[^"]*ct-${name}\\b[^"]*"[^>]*>([\\s\\S]*?)</p>`));
  return m ? m[1] : null;
};

const PAGES = [
  {
    slug: "ct-restaurants",
    content: query(
      "gment_restaurant",
      [
        para("price", { key: "gment_price_range" }),
        `<!-- wp:paragraph {"className":"ct-raw","metadata":{"bindings":{"content":{"source":"core/post-meta","args":{"key":"gment_price_range"}}}}} -->\n<p class="ct-raw">x</p>\n<!-- /wp:paragraph -->`,
        para("phone", { key: "gment_phone" }),
        para("phone-link", { key: "gment_phone", link: true }),
        para("web-link", { key: "gment_website", link: true }),
        para("address", { key: "gment_address" }),
        `<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button {"metadata":{"bindings":{"url":${binding({ key: "gment_phone" })},"text":${binding({ key: "gment_phone" })}}}} --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button">x</a></div><!-- /wp:button --></div><!-- /wp:buttons -->`,
      ].join("\n")
    ),
  },
  {
    slug: "ct-wines",
    content: query(
      "gment_wine",
      [
        para("winery", { key: "gment_winery" }),
        para("winery-link", { key: "gment_winery", link: true }),
        `<!-- wp:image {"className":"ct-image","metadata":{"bindings":{"url":${binding({ key: "gment_label", size: "thumbnail" })},"alt":${binding({ key: "gment_label" })}}}} -->\n<figure class="wp-block-image ct-image"><img alt=""/></figure>\n<!-- /wp:image -->`,
        para("organic", { key: "gment_organic" }),
        para("featured", { key: "gment_featured" }),
        para("released", { key: "gment_released" }),
        para("released-custom", { key: "gment_released", format: "d/m/Y" }),
        para("alcohol", { key: "gment_alcohol" }),
        para("vintage", { key: "gment_vintage" }),
        para("contact", { key: "gment_contact", link: true }),
      ].join("\n")
    ),
  },
  { slug: "ct-empty", content: [query("gment_wine", para("winery", { key: "gment_winery" })), para("not-here", { key: "gment_price_range" }), para("nothing", { key: "gment_nothing" })].join("\n") },
];

let restaurant, draft, pic, wine, url;

before(async () => {
  // reset() + the theme files on (the option the ct-test setup route sets) + the pages, in one request.
  const [{ pages }] = await Promise.all([testApi("/setup", { method: "POST", body: { options: { estrategia_web_ct_test: 1 }, pages: PAGES } }), credentials()]);
  url = Object.fromEntries(PAGES.map((p, i) => [p.slug, pages[i].url]));
  const [r, d, up] = await Promise.all([
    wp("/gment_restaurant", { method: "POST", body: { title: "Ca n'Andreu", slug: "ca-nandreu", status: "publish", meta: { gment_address: "Carrer Major, 1\nPalma", gment_price_range: "€€€", gment_phone: "+34 971 (00) 00-00", gment_website: "https://canandreu.test/" } } }),
    wp("/gment_restaurant", { method: "POST", body: { title: "Not open yet", status: "draft" } }),
    upload("bottle.png", "image/png", png(40, 20)),
  ]);
  assert.equal(r.status, 201, r.text);
  assert.equal(up.status, 201, up.text);
  [restaurant, draft] = [r.json, d.json];
  const [alt, w] = await Promise.all([
    wp(`/media/${up.json.id}`, { method: "POST", body: { alt_text: 'Botella "Anima"' } }),
    wp("/gment_wine", {
      method: "POST",
      body: { title: "Anima Negra", status: "publish", meta: { gment_winery: restaurant.id, gment_label: up.json.id, gment_vintage: 2019, gment_alcohol: 13.5, gment_released: "2026-10-16", gment_organic: true, gment_featured: false, gment_contact: "hola@animanegra.test" } },
    }),
  ]);
  assert.equal(w.status, 201, w.text);
  [pic, wine] = [alt.json, w.json];
});
// What reset() does not undo: the theme files switched on (off also deletes the gment_ posts).
after(() => fetch(BASE + "/wp-json/estrategia-web-ct-test/v1/setup", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ on: false }) }));

test("restaurant: the select shows its label (core/post-meta, its value); tel and url as text, as a link and in a button", async () => {
  const { status, text } = await html(url["ct-restaurants"]);
  assert.equal(status, 200);
  assert.equal(inner(text, "price"), "€€€ — alto", "the es label of the option");
  assert.equal(inner(text, "raw"), "€€€", "core/post-meta, for contrast: the stored value");
  assert.equal(inner(text, "phone"), "+34 971 (00) 00-00");
  assert.equal(inner(text, "phone-link"), '<a href="tel:+34971000000">+34 971 (00) 00-00</a>');
  // The external-links module adds target/rel/aria-label to it: only the link itself is checked.
  assert.match(inner(text, "web-link"), /^<a [^>]*href="https:\/\/canandreu\.test\/"[^>]*>https:\/\/canandreu\.test\/<\/a>$/);
  assert.equal(inner(text, "address"), "Carrer Major, 1<br>\nPalma");
  const button = text.match(/<div class="wp-block-button[^"]*">[\s\S]*?<\/div>/)?.[0] ?? "";
  assert.match(button, /<a [^>]*href="tel:\+34971000000"[^>]*>\+34 971 \(00\) 00-00<\/a>/, "a button: url and text bound to the phone: " + button);
});

test("wine: relation → the restaurant's title and link, image → URL (in the size asked for) and alt in core/image, boolean, date, number, email", async () => {
  const { status, text } = await html(url["ct-wines"]);
  assert.equal(status, 200);
  assert.equal(inner(text, "winery"), "Ca n&#8217;Andreu", "the title, as WordPress renders it (get_the_title)");
  assert.equal(inner(text, "winery-link"), `<a href="${BASE}/restaurantes/ca-nandreu/">Ca n&#8217;Andreu</a>`);
  const thumb = pic.media_details.sizes.thumbnail?.source_url ?? pic.source_url;
  const img = text.match(/<figure[^>]*ct-image[^>]*>[\s\S]*?<\/figure>/)?.[0] ?? "";
  assert.ok(img.includes(`src="${thumb}"`), "the image URL, in the size asked for: " + img);
  assert.ok(img.includes('alt="Botella &quot;Anima&quot;"'), "the alt text: " + img);
  assert.equal(inner(text, "organic"), "Ecológico", "true_label of the definition, in es");
  assert.equal(inner(text, "featured"), "No", "no texts in the definition: the translated No");
  assert.match(inner(text, "released"), /^(octubre|October) 16, 2026$/, "the site's date format (F j, Y)");
  assert.equal(inner(text, "released-custom"), "16/10/2026");
  assert.match(inner(text, "alcohol"), /^13[.,]5$/, "one decimal, as stored");
  assert.match(inner(text, "vintage"), /^2[.,  ]?019$/, "an integer, without decimals");
  assert.equal(inner(text, "contact"), '<a href="mailto:hola@animanegra.test">hola@animanegra.test</a>');
});

test("empty: a related post that is not published, a key that is not a field of the post's type, a key of no type", async () => {
  const stored = (await wp(`/gment_wine/${wine.id}`, { method: "POST", body: { meta: { gment_winery: draft.id } } })).json.meta.gment_winery;
  const { text } = await html(url["ct-empty"]);
  assert.equal(inner(text, "winery"), "", `a draft restaurant (stored: ${stored}) is not shown, nor its ID`);
  assert.equal(inner(text, "not-here"), "", "outside a Query the context is the page: a page has no price");
  assert.equal(inner(text, "nothing"), "");
});
