// NIVEL: 2 — Playground WordPress, because what is tested is the HTML WordPress renders: Queries of restaurants and wines (examples/gment_restaurant.json and tests/fixtures/valid/gment_wine.json) with blocks bound to estrategia-web/content-field, next to core/post-meta for contrast.
//
// The site under test speaks es_ES without WordPress's own es_ES language
// pack: option labels and «Sí»/«No» come from this plugin's translations;
// the month names and the decimal separator are core's, so they are
// English here (the real thing is measured in Lavandería with WPML: the
// report of 16-10-2026).
//
// Seen red (16-10-2026): with get_value() returning the raw meta, the three
// failed («€€€» instead of «€€€ — alto», the restaurant's ID instead of its
// title, the draft's ID instead of nothing). And for real, the first run:
// the image's alt came out as «&amp;quot;» — escaped by us AND by core
// (Field_Format's header, «The trap»).
import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { BASE, reset, seedPage, html, testApi, wp, upload, png } from "../../../tests/lib.mjs";

const ct = async (path, { method = "GET", body } = {}) => {
  const res = await fetch(BASE + "/wp-json/estrategia-web-ct-test/v1" + path, { method, headers: body ? { "Content-Type": "application/json" } : {}, body: body ? JSON.stringify(body) : undefined });
  if (!res.ok) throw new Error(`${method} ${path} -> ${res.status} ${await res.text()}`);
  return res.json();
};

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

let restaurant, draft, pic, wine;

before(async () => {
  await reset();
  const { loaded } = await testApi("/modules");
  await testApi("/modules", { method: "POST", body: { active: [...new Set([...loaded, "content-types"])] } });
  await ct("/setup", { method: "POST", body: { on: true } });
  await ct("/state");
  const r = await wp("/gment_restaurant", { method: "POST", body: { title: "Ca n'Andreu", slug: "ca-nandreu", status: "publish", meta: { gment_address: "Carrer Major, 1\nPalma", gment_price_range: "€€€", gment_phone: "+34 971 (00) 00-00", gment_website: "https://canandreu.test/" } } });
  assert.equal(r.status, 201, r.text);
  restaurant = r.json;
  const d = await wp("/gment_restaurant", { method: "POST", body: { title: "Not open yet", status: "draft" } });
  draft = d.json;
  const up = await upload("bottle.png", "image/png", png(40, 20));
  assert.equal(up.status, 201, up.text);
  pic = (await wp(`/media/${up.json.id}`, { method: "POST", body: { alt_text: 'Botella "Anima"' } })).json;
  const w = await wp("/gment_wine", {
    method: "POST",
    body: { title: "Anima Negra", status: "publish", meta: { gment_winery: restaurant.id, gment_label: pic.id, gment_vintage: 2019, gment_alcohol: 13.5, gment_released: "2026-10-16", gment_organic: true, gment_featured: false, gment_contact: "hola@animanegra.test" } },
  });
  assert.equal(w.status, 201, w.text);
  wine = w.json;
});
after(async () => {
  await ct("/setup", { method: "POST", body: { on: false } });
  await reset();
});

test("restaurant: the select shows its label (core/post-meta, its value); tel and url as text, as a link and in a button", async () => {
  const page = await seedPage(
    query(
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
    "ct-restaurants"
  );
  const { status, text } = await html(page.url);
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
  const image = `<!-- wp:image {"className":"ct-image","metadata":{"bindings":{"url":${binding({ key: "gment_label", size: "thumbnail" })},"alt":${binding({ key: "gment_label" })}}}} -->\n<figure class="wp-block-image ct-image"><img alt=""/></figure>\n<!-- /wp:image -->`;
  const page = await seedPage(
    query(
      "gment_wine",
      [
        para("winery", { key: "gment_winery" }),
        para("winery-link", { key: "gment_winery", link: true }),
        image,
        para("organic", { key: "gment_organic" }),
        para("featured", { key: "gment_featured" }),
        para("released", { key: "gment_released" }),
        para("released-custom", { key: "gment_released", format: "d/m/Y" }),
        para("alcohol", { key: "gment_alcohol" }),
        para("vintage", { key: "gment_vintage" }),
        para("contact", { key: "gment_contact", link: true }),
      ].join("\n")
    ),
    "ct-wines"
  );
  const { status, text } = await html(page.url);
  assert.equal(status, 200);
  assert.equal(inner(text, "winery"), "Ca n&#8217;Andreu", "the title, as WordPress renders it (get_the_title)");
  assert.equal(inner(text, "winery-link"), `<a href="${BASE}/restaurantes/ca-nandreu/">Ca n&#8217;Andreu</a>`);
  const thumb = (await wp(`/media/${pic.id}`)).json.media_details.sizes.thumbnail?.source_url ?? pic.source_url;
  const img = text.match(/<figure[^>]*ct-image[^>]*>[\s\S]*?<\/figure>/)?.[0] ?? "";
  assert.ok(img.includes(`src="${thumb}"`), "the image URL, in the size asked for: " + img);
  assert.ok(img.includes('alt="Botella &quot;Anima&quot;"'), "the alt text: " + img);
  assert.equal(inner(text, "organic"), "Ecológico", "true_label of the definition, in es");
  assert.equal(inner(text, "featured"), "No", "no texts in the definition: the translated No");
  assert.match(inner(text, "released"), /^(octubre|October) 16, 2026$/, "the site's date format (F j, Y)");
  assert.equal(inner(text, "released-custom"), "16/10/2026");
  assert.match(inner(text, "alcohol"), /^13[.,]5$/, "one decimal, as stored");
  assert.match(inner(text, "vintage"), /^2[.,  ]?019$/, "an integer, without decimals");
  assert.equal(inner(text, "contact"), '<a href="mailto:hola@animanegra.test">hola@animanegra.test</a>');
});

test("empty: a related post that is not published, a key that is not a field of the post's type, a key of no type", async () => {
  await wp(`/gment_wine/${wine.id}`, { method: "POST", body: { meta: { gment_winery: draft.id } } });
  const stored = (await wp(`/gment_wine/${wine.id}`)).json.meta.gment_winery;
  const page = await seedPage(
    [query("gment_wine", para("winery", { key: "gment_winery" })), para("not-here", { key: "gment_price_range" }), para("nothing", { key: "gment_nothing" })].join("\n"),
    "ct-empty"
  );
  const { text } = await html(page.url);
  assert.equal(inner(text, "winery"), "", `a draft restaurant (stored: ${stored}) is not shown, nor its ID`);
  assert.equal(inner(text, "not-here"), "", "outside a Query the context is the page: a page has no price");
  assert.equal(inner(text, "nothing"), "");
  await wp(`/gment_wine/${wine.id}`, { method: "POST", body: { meta: { gment_winery: restaurant.id } } });
});
