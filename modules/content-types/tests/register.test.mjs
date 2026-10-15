// NIVEL: 2 — Playground WordPress, because it WRITES: turns the module on, points it at the example and the fixtures, creates restaurants and wines through REST, and reads back what WordPress stored, the URLs it serves and the HTML a Query with core/post-meta renders.
//
// The site under test speaks es_ES (tests/wp/estrategia-web-site-test.php),
// so the labels are the "es" ones of the JSON.
//
// Seen red (15-10-2026): with check_required() returning $prepared always,
// «a required field empty on publish» failed (201 instead of 400); with
// maybe_flush() not flushing, «the URLs» (no rewrite rules for restaurants)
// and «changing the definitions» failed. And for real, the first run: a
// required NUMBER left empty was published, because its default (0) counted
// as a value — now «not given» is what is empty for a number.
import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { BASE, reset, seedPage, html, testApi, wp, upload, png } from "../../../tests/lib.mjs";

const ct = async (path, { method = "GET", body } = {}) => {
  const res = await fetch(BASE + "/wp-json/estrategia-web-ct-test/v1" + path, { method, headers: body ? { "Content-Type": "application/json" } : {}, body: body ? JSON.stringify(body) : undefined });
  if (!res.ok) throw new Error(`${method} ${path} -> ${res.status} ${await res.text()}`);
  return res.json();
};

let state;

before(async () => {
  await reset();
  const { loaded } = await testApi("/modules");
  await testApi("/modules", { method: "POST", body: { active: [...new Set([...loaded, "content-types"])] } });
  await ct("/setup", { method: "POST", body: { on: true } });
  await ct("/state"); // This request registers the new definitions and flushes (hash changed).
  state = await ct("/state");
});
after(async () => {
  await ct("/setup", { method: "POST", body: { on: false } });
  await reset();
});

test("the valid files are registered; the broken ones are not, and their errors are listed", () => {
  assert.equal(state.module, true, "module loaded");
  assert.deepEqual(state.registered.sort(), ["gment_cuisine", "gment_restaurant", "gment_wine"]);
  assert.deepEqual(state.taxonomy.sort(), ["gment_restaurant", "gment_wine"], "the cuisine taxonomy is shared by reference");
  const long = state.errors["gment_too_long.json"].join(" | ");
  // In Spanish: the site under test speaks es_ES, and the errors are translated (languages/).
  assert.match(long, /name «gment_restaurant_menu» pasa de 20 caracteres \(21\)/);
  assert.match(long, /Clave desconocida «fields\[1\]\.requird»/);
  assert.match(state.errors["gment_not_json.json"].join(" "), /No es un JSON válido/);
  assert.equal(state.errors["gment_restaurant.json"], undefined);
});

test("the type in REST, with the labels of the site language and its fields labelled for Block Bindings", async () => {
  const t = await wp("/types/gment_restaurant");
  assert.equal(t.status, 200, t.text);
  assert.equal(t.json.name, "Restaurantes");
  assert.equal(t.json.rest_base, "gment_restaurant");
  assert.ok(t.json.taxonomies.includes("gment_cuisine"));
  assert.equal((await wp("/types/gment_restaurant_menu")).status, 404, "the 21-character type does not exist, not even cut");
  const meta = state.meta.gment_restaurant;
  assert.deepEqual(meta.gment_phone, { type: "string", label: "Teléfono", default: "", rest: true });
  assert.deepEqual(meta.gment_price_range, { type: "string", label: "Precio", default: "€€", rest: true });
  assert.deepEqual(state.meta.gment_wine.gment_vintage, { type: "integer", label: "Vintage", default: 0, rest: true });
  assert.equal(state.meta.gment_wine.gment_alcohol.type, "number");
  assert.equal(state.meta.gment_wine.gment_organic.type, "boolean");
});

let restaurant;

test("a required field empty on publish: 400 with the field's name; a draft may be incomplete", async () => {
  const refused = await wp("/gment_restaurant", { method: "POST", body: { title: "Ca n'Andreu", status: "publish", meta: { gment_phone: "971 00 00 00" } } });
  assert.equal(refused.status, 400, refused.text);
  assert.equal(refused.json.code, "estrategia_web_required_fields");
  assert.match(refused.json.message, /Dirección/);
  const draft = await wp("/gment_restaurant", { method: "POST", body: { title: "Ca n'Andreu", status: "draft" } });
  assert.equal(draft.status, 201, draft.text);
  const blank = await wp(`/gment_restaurant/${draft.json.id}`, { method: "POST", body: { status: "publish", meta: { gment_address: "   " } } });
  assert.equal(blank.status, 400, "only spaces is empty once sanitized");
  const ok = await wp(`/gment_restaurant/${draft.json.id}`, { method: "POST", body: { status: "publish", slug: "ca-nandreu", meta: { gment_address: "Carrer Major, 1\nPalma" } } });
  assert.equal(ok.status, 200, ok.text);
  restaurant = ok.json;
});

test("sanitizers: what cannot be stored becomes empty (or the default), never an error", async () => {
  const r = await wp(`/gment_restaurant/${restaurant.id}`, {
    method: "POST",
    body: { meta: { gment_phone: "+34 971 (00) 00-00 <b>ext</b>", gment_price_range: "€€€€", gment_website: "javascript:alert(1)" } },
  });
  assert.equal(r.status, 200, r.text);
  assert.equal(r.json.meta.gment_phone, "+34 971 (00) 00-00");
  assert.equal(r.json.meta.gment_price_range, "€€", "not an option: the default");
  assert.equal(r.json.meta.gment_website, "");
  assert.equal(r.json.meta.gment_address, "Carrer Major, 1\nPalma", "textarea keeps the line break");

  const page = await wp("/pages", { method: "POST", body: { title: "Not a restaurant", status: "publish" } });
  const pic = await upload("label.png", "image/png", png());
  const text = await upload("notes.txt", "text/plain", "hello");
  const w = await wp("/gment_wine", {
    method: "POST",
    body: {
      title: "Anima Negra",
      status: "publish",
      meta: { gment_vintage: 1850, gment_alcohol: 13.5, gment_contact: "not an email", gment_label: pic.json.id, gment_released: "2026-02-30", gment_organic: true, gment_winery: restaurant.id, gment_tasting: "<script>x</script>Fruity" },
    },
  });
  assert.equal(w.status, 201, w.text);
  assert.equal(w.json.meta.gment_vintage, 1900, "clamped to min");
  assert.equal(w.json.meta.gment_alcohol, 13.5);
  assert.equal(w.json.meta.gment_contact, "");
  assert.equal(w.json.meta.gment_label, pic.json.id);
  assert.equal(w.json.meta.gment_released, "", "30 February is not a date");
  assert.equal(w.json.meta.gment_organic, true);
  assert.equal(w.json.meta.gment_winery, restaurant.id);
  assert.equal(w.json.meta.gment_tasting, "Fruity");
  const wrong = await wp(`/gment_wine/${w.json.id}`, { method: "POST", body: { meta: { gment_winery: page.json.id, gment_label: text.status === 201 ? text.json.id : 999999, gment_released: "2026-10-15" } } });
  assert.equal(wrong.status, 200, wrong.text);
  assert.equal(wrong.json.meta.gment_winery, 0, "a page is not a restaurant");
  assert.equal(wrong.json.meta.gment_label, 0, "not an image");
  assert.equal(wrong.json.meta.gment_released, "2026-10-15");
  const missing = await wp("/gment_wine", { method: "POST", body: { title: "No vintage", status: "publish" } });
  assert.equal(missing.status, 400, "the integer required field at 0 is empty");
});

test("the URLs: single, archive and taxonomy, from the slugs of the JSON (rules flushed by the hash)", async () => {
  assert.ok(state.rules.some((r) => r.startsWith("restaurants/")), "rewrite rules for restaurants: " + JSON.stringify(state.rules));
  const term = await wp("/gment_cuisine", { method: "POST", body: { name: "Mallorquina", slug: "mallorquina" } });
  assert.equal(term.status, 201, term.text);
  await wp(`/gment_restaurant/${restaurant.id}`, { method: "POST", body: { gment_cuisine: [term.json.id] } });
  const single = await html(BASE + "/restaurants/ca-nandreu/");
  assert.equal(single.status, 200);
  assert.match(single.text, /Ca n.(#8217;|’|')?Andreu/);
  assert.equal((await html(BASE + "/restaurants/")).status, 200, "archive");
  assert.equal((await html(BASE + "/cuisine/mallorquina/")).status, 200, "taxonomy");
});

test("Block Bindings: a Query of restaurants with core/post-meta renders the phone and the price", async () => {
  const query = JSON.stringify({ queryId: 1, query: { perPage: 10, pages: 0, offset: 0, postType: "gment_restaurant", order: "asc", orderBy: "title", inherit: false } });
  const bound = (key) => `<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"core/post-meta","args":{"key":"${key}"}}}}} -->\n<p class="ct-${key}">x</p>\n<!-- /wp:paragraph -->`;
  const page = await seedPage(
    `<!-- wp:query ${query} -->\n<div class="wp-block-query"><!-- wp:post-template -->\n${bound("gment_phone")}\n${bound("gment_price_range")}\n<!-- /wp:post-template --></div>\n<!-- /wp:query -->`,
    "ct-bindings"
  );
  const { status, text } = await html(page.url);
  assert.equal(status, 200);
  // Only the paragraphs in the message, not 60 KB of page.
  const bound_html = (text.match(/<p[^>]*class="ct-[^"]*"[^>]*>[^<]*<\/p>/g) || []).join("\n");
  assert.match(bound_html, /class="ct-gment_phone[^"]*">\+34 971 \(00\) 00-00</);
  assert.match(bound_html, /class="ct-gment_price_range[^"]*">€€</);
});

test("changing the definitions flushes the rules once: off, the restaurant URLs go; on, they come back", async () => {
  await ct("/setup", { method: "POST", body: { on: false } });
  await ct("/state");
  const off = await ct("/state");
  assert.deepEqual(off.rules, [], "no rules without the definitions");
  assert.notEqual(off.hash, state.hash);
  await ct("/setup", { method: "POST", body: { on: true } });
  await ct("/state");
  const on = await ct("/state");
  assert.equal(on.hash, state.hash, "same definitions, same hash");
  assert.ok(on.rules.some((r) => r.startsWith("restaurants/")));
});
