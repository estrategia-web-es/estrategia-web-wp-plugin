// NIVEL: 2 — Playground WordPress, no browser. NOT LEVEL 1 BECAUSE: what is tested is WordPress's own entries list (edit.php?post_type=<type>) of the types this module registers, with the taxonomy dropdowns that List_Tools declares through \EstrategiaWeb\Admin\Admin_List (2.8.0) — the dropdown, its counts and the query it filters are WordPress's, with real terms and posts.
//
// Three types: gment_restaurant and gment_wine, the theme files of the tests (examples/ and
// fixtures/valid/), which SHARE the taxonomy gment_cuisine — the edge case by name: its dropdown is on
// both lists and shows no counts, because a term's count is of every type —, and gment_shop, made in
// the panel (as Juan's types are) with TWO taxonomies, whose dropdowns add up.
//
// Seen red (02-11-2026), one break each: List_Tools::boot() not called (no dropdown on any list: the
// three tests red); show_count back to true (the wines list said «prueba-italiana (2)», counting a
// restaurant).
// What this test CANNOT see: taxonomy_filter() setting tax_query instead of appending (the other trap of
// class-admin-list.php). These taxonomies are public, so WordPress filters by their query var too and
// both dropdowns apply either way; the append matters for a taxonomy with show_ui and no query var
// (banners' zone). The «add up» case stays as the guard of what a person sees.
//
// How to maintain: a new list tool for the types gets its case here. The trap: a type saved in the panel
// is registered from the NEXT request (module.php), so the terms and posts go in later batches.
import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { adminSession } from "../../../tests/lib.mjs";
import { batch } from "./batch.mjs";

const shop = {
  prefix: "gment_",
  name: "gment_shop",
  slug: "shops",
  labels: { singular: { es: "Tienda" }, plural: { es: "Tiendas" } },
  supports: ["title"],
  taxonomies: [
    { name: "gment_shop_zone", labels: { singular: "Zona", plural: "Zonas" } },
    { name: "gment_shop_kind", labels: { singular: "Clase", plural: "Clases" } },
  ],
  fields: [],
};

const ids = {};
const rows = (html) => [...html.matchAll(/<tr id="post-(\d+)"/g)].map((m) => Number(m[1]));
const name = (id) => Object.keys(ids).find((k) => ids[k] === id);
const select = (html, n) => html.match(new RegExp(`<select[^>]*name=['"]${n}['"][\\s\\S]*?</select>`))?.[0] ?? null;
const list = async (s, query) => {
  const { status, text } = await s.get("/wp-admin/edit.php?" + query);
  assert.equal(status, 200, query);
  return text;
};

let s;
before(async () => {
  // The site's reset deletes the theme-files switch, so it goes first; the panel type is saved last.
  const [, , , saved] = await batch(
    { method: "POST", path: "/estrategia-web-site-test/v1/reset" },
    { method: "POST", path: "/estrategia-web-ct-test/v1/constructor-reset" },
    { method: "POST", path: "/estrategia-web-ct-test/v1/setup", body: { on: true } },
    { method: "POST", path: "/estrategia-web/v1/content-types", body: { definition: shop } }
  );
  assert.equal(saved.status, 201, saved.text);
  const terms = {
    italiana: "gment_cuisine",
    china: "gment_cuisine",
    palma: "gment_shop_zone",
    inca: "gment_shop_zone",
    forn: "gment_shop_kind",
    bar: "gment_shop_kind",
  };
  const made = await batch(...Object.entries(terms).map(([t, tax]) => ({ method: "POST", path: `/wp/v2/${tax}`, body: { name: "prueba-" + t, slug: "prueba-" + t } })));
  const term = Object.fromEntries(Object.keys(terms).map((t, i) => [t, made[i].json.id]));
  for (const [i, m] of made.entries()) assert.equal(m.status, 201, Object.keys(terms)[i] + ": " + m.text);
  const posts = {
    rest_it: ["gment_restaurant", { gment_cuisine: [term.italiana], meta: { gment_address: "Carrer 1" } }],
    rest_cn: ["gment_restaurant", { gment_cuisine: [term.china], meta: { gment_address: "Carrer 2" } }],
    wine_it: ["gment_wine", { gment_cuisine: [term.italiana], meta: { gment_vintage: 2020 } }],
    shop_a: ["gment_shop", { gment_shop_zone: [term.palma], gment_shop_kind: [term.forn] }],
    shop_b: ["gment_shop", { gment_shop_zone: [term.palma], gment_shop_kind: [term.bar] }],
    shop_c: ["gment_shop", { gment_shop_zone: [term.inca], gment_shop_kind: [term.forn] }],
  };
  const [created] = await Promise.all([
    batch(...Object.entries(posts).map(([k, [type, body]]) => ({ method: "POST", path: `/wp/v2/${type}`, body: { title: "prueba-listado " + k, status: "publish", ...body } }))),
    adminSession().then((x) => (s = x)),
  ]);
  for (const [i, k] of Object.keys(posts).entries()) {
    assert.equal(created[i].status, 201, k + ": " + created[i].text);
    ids[k] = created[i].json.id;
  }
});

// The panel type and every gment_ post and term out, so the other tests never see them.
after(() => batch({ method: "POST", path: "/estrategia-web-ct-test/v1/constructor-reset" }, { method: "POST", path: "/estrategia-web-ct-test/v1/setup", body: { on: false } }));

test("a type with two taxonomies: one dropdown each, with counts (the taxonomy is that type's alone)", async () => {
  const html = await list(s, "post_type=gment_shop");
  const zone = select(html, "gment_shop_zone");
  assert.ok(zone, "no zone dropdown");
  assert.ok(select(html, "gment_shop_kind"), "no kind dropdown");
  assert.match(zone, /Zonas/, "the first option is the taxonomy's «all items» label");
  assert.match(zone, /prueba-palma(&nbsp;|\s)*\(2\)/, zone);
});

test("the two dropdowns add up: Palma and forn is only one shop", async () => {
  const html = await list(s, "post_type=gment_shop&gment_shop_zone=prueba-palma&gment_shop_kind=prueba-forn");
  assert.deepEqual(rows(html).map(name), ["shop_a"]);
  assert.match(select(html, "gment_shop_zone"), /<option[^>]*value="prueba-palma"[^>]*selected/, "the dropdown keeps the choice");
});

test("a shared taxonomy (gment_cuisine): on both lists, filtering each, and without counts", async () => {
  const [restaurants, wines] = await Promise.all([list(s, "post_type=gment_restaurant&gment_cuisine=prueba-italiana"), list(s, "post_type=gment_wine")]);
  assert.deepEqual(rows(restaurants).map(name), ["rest_it"]);
  const cuisine = select(wines, "gment_cuisine");
  assert.ok(cuisine, "no cuisine dropdown on the wines list");
  assert.match(cuisine, /prueba-italiana/);
  assert.doesNotMatch(cuisine, /prueba-italiana(&nbsp;|\s)*\(\d+\)/, "a count of every type would mislead: " + cuisine);
});
