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
  assert.deepEqual(state.registered.sort(), ["gmct_cuisine", "gmct_restaurant", "gmct_wine"]);
  assert.deepEqual(state.taxonomy.sort(), ["gmct_restaurant", "gmct_wine"], "the cuisine taxonomy is shared by reference");
  const long = state.errors["gmct_too_long.json"].join(" | ");
  assert.match(long, /name “gmct_restaurant_menus” is longer than 20 characters \(21\)/);
  assert.match(long, /Unknown key “fields\[1\]\.requird”/);
  assert.match(state.errors["gmct_not_json.json"].join(" "), /Not valid JSON/);
  assert.equal(state.errors["gmct_restaurant.json"], undefined);
});

test("the type in REST, with the labels of the site language and its fields labelled for Block Bindings", async () => {
  const t = await wp("/types/gmct_restaurant");
  assert.equal(t.status, 200, t.text);
  assert.equal(t.json.name, "Restaurantes");
  assert.equal(t.json.rest_base, "gmct_restaurant");
  assert.ok(t.json.taxonomies.includes("gmct_cuisine"));
  assert.equal((await wp("/types/gmct_restaurant_menus")).status, 404, "the 21-character type does not exist, not even cut");
  const meta = state.meta.gmct_restaurant;
  assert.deepEqual(meta.gmct_phone, { type: "string", label: "Teléfono", default: "", rest: true });
  assert.deepEqual(meta.gmct_price_range, { type: "string", label: "Precio", default: "€€", rest: true });
  assert.deepEqual(state.meta.gmct_wine.gmct_vintage, { type: "integer", label: "Vintage", default: 0, rest: true });
  assert.equal(state.meta.gmct_wine.gmct_alcohol.type, "number");
  assert.equal(state.meta.gmct_wine.gmct_organic.type, "boolean");
});

let restaurant;

test("a required field empty on publish: 400 with the field's name; a draft may be incomplete", async () => {
  const refused = await wp("/gmct_restaurant", { method: "POST", body: { title: "Ca n'Andreu", status: "publish", meta: { gmct_phone: "971 00 00 00" } } });
  assert.equal(refused.status, 400, refused.text);
  assert.equal(refused.json.code, "estrategia_web_required_fields");
  assert.match(refused.json.message, /Dirección/);
  const draft = await wp("/gmct_restaurant", { method: "POST", body: { title: "Ca n'Andreu", status: "draft" } });
  assert.equal(draft.status, 201, draft.text);
  const blank = await wp(`/gmct_restaurant/${draft.json.id}`, { method: "POST", body: { status: "publish", meta: { gmct_address: "   " } } });
  assert.equal(blank.status, 400, "only spaces is empty once sanitized");
  const ok = await wp(`/gmct_restaurant/${draft.json.id}`, { method: "POST", body: { status: "publish", slug: "ca-nandreu", meta: { gmct_address: "Carrer Major, 1\nPalma" } } });
  assert.equal(ok.status, 200, ok.text);
  restaurant = ok.json;
});

test("sanitizers: what cannot be stored becomes empty (or the default), never an error", async () => {
  const r = await wp(`/gmct_restaurant/${restaurant.id}`, {
    method: "POST",
    body: { meta: { gmct_phone: "+34 971 (00) 00-00 <b>ext</b>", gmct_price_range: "€€€€", gmct_website: "javascript:alert(1)" } },
  });
  assert.equal(r.status, 200, r.text);
  assert.equal(r.json.meta.gmct_phone, "+34 971 (00) 00-00");
  assert.equal(r.json.meta.gmct_price_range, "€€", "not an option: the default");
  assert.equal(r.json.meta.gmct_website, "");
  assert.equal(r.json.meta.gmct_address, "Carrer Major, 1\nPalma", "textarea keeps the line break");

  const page = await wp("/pages", { method: "POST", body: { title: "Not a restaurant", status: "publish" } });
  const pic = await upload("label.png", "image/png", png());
  const text = await upload("notes.txt", "text/plain", "hello");
  const w = await wp("/gmct_wine", {
    method: "POST",
    body: {
      title: "Anima Negra",
      status: "publish",
      meta: { gmct_vintage: 1850, gmct_alcohol: 13.5, gmct_contact: "not an email", gmct_label: pic.json.id, gmct_released: "2026-02-30", gmct_organic: true, gmct_winery: restaurant.id, gmct_tasting: "<script>x</script>Fruity" },
    },
  });
  assert.equal(w.status, 201, w.text);
  assert.equal(w.json.meta.gmct_vintage, 1900, "clamped to min");
  assert.equal(w.json.meta.gmct_alcohol, 13.5);
  assert.equal(w.json.meta.gmct_contact, "");
  assert.equal(w.json.meta.gmct_label, pic.json.id);
  assert.equal(w.json.meta.gmct_released, "", "30 February is not a date");
  assert.equal(w.json.meta.gmct_organic, true);
  assert.equal(w.json.meta.gmct_winery, restaurant.id);
  assert.equal(w.json.meta.gmct_tasting, "Fruity");
  const wrong = await wp(`/gmct_wine/${w.json.id}`, { method: "POST", body: { meta: { gmct_winery: page.json.id, gmct_label: text.status === 201 ? text.json.id : 999999, gmct_released: "2026-10-15" } } });
  assert.equal(wrong.status, 200, wrong.text);
  assert.equal(wrong.json.meta.gmct_winery, 0, "a page is not a restaurant");
  assert.equal(wrong.json.meta.gmct_label, 0, "not an image");
  assert.equal(wrong.json.meta.gmct_released, "2026-10-15");
  const missing = await wp("/gmct_wine", { method: "POST", body: { title: "No vintage", status: "publish" } });
  assert.equal(missing.status, 400, "the integer required field at 0 is empty");
});

test("the URLs: single, archive and taxonomy, from the slugs of the JSON (rules flushed by the hash)", async () => {
  assert.ok(state.rules.some((r) => r.startsWith("restaurants/")), "rewrite rules for restaurants: " + JSON.stringify(state.rules));
  const term = await wp("/gmct_cuisine", { method: "POST", body: { name: "Mallorquina", slug: "mallorquina" } });
  assert.equal(term.status, 201, term.text);
  await wp(`/gmct_restaurant/${restaurant.id}`, { method: "POST", body: { gmct_cuisine: [term.json.id] } });
  const single = await html(BASE + "/restaurants/ca-nandreu/");
  assert.equal(single.status, 200);
  assert.match(single.text, /Ca n.(#8217;|’|')?Andreu/);
  assert.equal((await html(BASE + "/restaurants/")).status, 200, "archive");
  assert.equal((await html(BASE + "/cuisine/mallorquina/")).status, 200, "taxonomy");
});

test("Block Bindings: a Query of restaurants with core/post-meta renders the phone and the price", async () => {
  const query = JSON.stringify({ queryId: 1, query: { perPage: 10, pages: 0, offset: 0, postType: "gmct_restaurant", order: "asc", orderBy: "title", inherit: false } });
  const bound = (key) => `<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"core/post-meta","args":{"key":"${key}"}}}}} -->\n<p class="ct-${key}">x</p>\n<!-- /wp:paragraph -->`;
  const page = await seedPage(
    `<!-- wp:query ${query} -->\n<div class="wp-block-query"><!-- wp:post-template -->\n${bound("gmct_phone")}\n${bound("gmct_price_range")}\n<!-- /wp:post-template --></div>\n<!-- /wp:query -->`,
    "ct-bindings"
  );
  const { status, text } = await html(page.url);
  assert.equal(status, 200);
  // Only the paragraphs in the message, not 60 KB of page.
  const bound_html = (text.match(/<p[^>]*class="ct-[^"]*"[^>]*>[^<]*<\/p>/g) || []).join("\n");
  assert.match(bound_html, /class="ct-gmct_phone[^"]*">\+34 971 \(00\) 00-00</);
  assert.match(bound_html, /class="ct-gmct_price_range[^"]*">€€</);
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
