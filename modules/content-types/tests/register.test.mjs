// NIVEL: 2 — Playground WordPress. NOT LEVEL 1 BECAUSE: it turns the module's theme files on and reads back what WordPress registered from them (types, taxonomies, meta with their JSON types), what REST stores through our sanitizers and our «required» check (some look up other posts and attachments), and the URLs WordPress serves from the rules our hash flushes. The validator and the errors of the broken files are level 1 (schema.test.mjs); here only that the loader lists them.
//
// The site under test speaks es_ES (tests/wp/estrategia-web-site-test.php),
// so the labels are the "es" ones of the JSON.
//
// 28-10-2026, from ~59 s: one /state after switching the files on (the flush
// runs at init 99 of that same request, Registrar::maybe_flush()), the
// independent requests batched, and «Block Bindings: core/post-meta» gone —
// bindings.test.mjs renders core/post-meta next to our source on the same
// restaurant («restaurant», the «raw» paragraph). Round 2: the REST calls go
// in batches (batch.mjs: several calls in one request; the types are
// registered before the first one, so nothing here needs a boot between
// calls), the two sanitizer cases of the wine are two wines made at once,
// and the REST tests and the URL test run as two tracks at once, the URL one
// on a restaurant of its own created with its cuisine (the create answer
// carries its permalink).
//
// Seen red (15-10-2026): with check_required() returning $prepared always,
// «a required field empty on publish» failed (201 instead of 400); with
// maybe_flush() not flushing, «the URLs» (no rewrite rules for restaurants)
// and «changing the definitions» failed. And for real, the first run: a
// required NUMBER left empty was published, because its default (0) counted
// as a value — now «not given» is what is empty for a number.
// Seen red (28-10-2026, round 2, batched), each changed test with one
// expected value changed (the 21-character type, the blank address, the
// label that is not an image, the permalink under /restaurants/, the rules
// switched off): all five red; restored, green.
import { test, describe, before, after } from "node:test";
import assert from "node:assert/strict";
import { BASE, credentials, html, testApi, upload, png } from "../../../tests/lib.mjs";
import { batch } from "./batch.mjs";

const ct = async (path, { method = "GET", body } = {}) => {
  const res = await fetch(BASE + "/wp-json/estrategia-web-ct-test/v1" + path, { method, headers: body ? { "Content-Type": "application/json" } : {}, body: body ? JSON.stringify(body) : undefined });
  if (!res.ok) throw new Error(`${method} ${path} -> ${res.status} ${await res.text()}`);
  return res.json();
};
const post = (path, body) => ({ method: "POST", path: "/wp/v2" + path, body });

let state;
let restaurant;

before(async () => {
  // reset() and the theme files on (the option the ct-test setup route sets) in one request; content-types is on by default.
  await Promise.all([testApi("/setup", { method: "POST", body: { options: { estrategia_web_ct_test: 1 } } }), credentials()]);
  state = await ct("/state"); // This request registers the new definitions and flushes (hash changed) before answering.
});
// What reset() does not undo: the theme files switched on (off also deletes the gment_ posts).
after(() => ct("/setup", { method: "POST", body: { on: false } }));

test("the valid files are registered; the broken ones are not, and their errors are listed", () => {
  assert.equal(state.module, true, "module loaded");
  assert.deepEqual(state.registered.sort(), ["gment_cuisine", "gment_restaurant", "gment_wine", "gment_winery"]);
  assert.deepEqual(state.taxonomy.sort(), ["gment_restaurant", "gment_wine"], "the cuisine taxonomy is shared by reference");
  const long = state.errors["gment_too_long.json"].join(" | ");
  // In Spanish: the site under test speaks es_ES, and the errors are translated (languages/).
  assert.match(long, /name «gment_restaurant_menu» pasa de 20 caracteres \(21\)/);
  assert.match(long, /Clave desconocida «fields\[1\]\.requird»/);
  assert.match(state.errors["gment_not_json.json"].join(" "), /No es un JSON válido/);
  assert.equal(state.errors["gment_restaurant.json"], undefined);
});

// Two tracks at once (the test WordPress answers two requests at a time): they use different restaurants.
describe("two tracks at once", { concurrency: true }, () => {
  describe("a restaurant and a wine through REST", { concurrency: 1 }, () => {
    test("the type in REST, with the labels of the site language and its fields labelled for Block Bindings", async () => {
      const [t, menu] = await batch({ path: "/wp/v2/types/gment_restaurant" }, { path: "/wp/v2/types/gment_restaurant_menu" });
      assert.equal(t.status, 200, t.text);
      assert.equal(t.json.name, "Restaurantes");
      assert.equal(t.json.rest_base, "gment_restaurant");
      assert.ok(t.json.taxonomies.includes("gment_cuisine"));
      assert.equal(menu.status, 404, "the 21-character type does not exist, not even cut");
      const meta = state.meta.gment_restaurant;
      assert.deepEqual(meta.gment_phone, { type: "string", label: "Teléfono", default: "", rest: true });
      assert.deepEqual(meta.gment_price_range, { type: "string", label: "Precio", default: "€€", rest: true });
      assert.deepEqual(state.meta.gment_wine.gment_vintage, { type: "integer", label: "Vintage", default: 0, rest: true });
      assert.equal(state.meta.gment_wine.gment_alcohol.type, "number");
      assert.equal(state.meta.gment_wine.gment_organic.type, "boolean");
    });

    test("a required field empty on publish: 400 with the field's name; a draft may be incomplete", async () => {
      const [refused, draft] = await batch(
        post("/gment_restaurant", { title: "Ca n'Andreu", status: "publish", meta: { gment_phone: "971 00 00 00" } }),
        post("/gment_restaurant", { title: "Ca n'Andreu", status: "draft" })
      );
      assert.equal(refused.status, 400, refused.text);
      assert.equal(refused.json.code, "estrategia_web_required_fields");
      assert.match(refused.json.message, /Dirección/);
      assert.equal(draft.status, 201, draft.text);
      const [blank, ok] = await batch(
        post(`/gment_restaurant/${draft.json.id}`, { status: "publish", meta: { gment_address: "   " } }),
        post(`/gment_restaurant/${draft.json.id}`, { status: "publish", slug: "ca-nandreu", meta: { gment_address: "Carrer Major, 1\nPalma" } })
      );
      assert.equal(blank.status, 400, "only spaces is empty once sanitized");
      assert.equal(ok.status, 200, ok.text);
      restaurant = ok.json;
    });

    test("sanitizers: what cannot be stored becomes empty (or the default), never an error", async () => {
      // The uploads are real multipart requests; the rest, one batch.
      const [[r, page, missing], pic, text] = await Promise.all([
        batch(
          post(`/gment_restaurant/${restaurant.id}`, { meta: { gment_phone: "+34 971 (00) 00-00 <b>ext</b>", gment_price_range: "€€€€", gment_website: "javascript:alert(1)" } }),
          post("/pages", { title: "Not a restaurant", status: "publish" }),
          post("/gment_wine", { title: "No vintage", status: "publish" })
        ),
        upload("label.png", "image/png", png()),
        upload("notes.txt", "text/plain", "hello"),
      ]);
      assert.equal(r.status, 200, r.text);
      assert.equal(r.json.meta.gment_phone, "+34 971 (00) 00-00");
      assert.equal(r.json.meta.gment_price_range, "€€", "not an option: the default");
      assert.equal(r.json.meta.gment_website, "");
      assert.equal(r.json.meta.gment_address, "Carrer Major, 1\nPalma", "textarea keeps the line break");
      assert.equal(missing.status, 400, "the integer required field at 0 is empty");

      const [w, wrong] = await batch(
        post("/gment_wine", {
          title: "Anima Negra",
          status: "publish",
          meta: { gment_vintage: 1850, gment_alcohol: 13.5, gment_contact: "not an email", gment_label: pic.json.id, gment_released: "2026-02-30", gment_organic: true, gment_winery: restaurant.id, gment_tasting: "<script>x</script>Fruity" },
        }),
        post("/gment_wine", { title: "Wrong ones", status: "publish", meta: { gment_vintage: 2000, gment_winery: page.json.id, gment_label: text.status === 201 ? text.json.id : 999999, gment_released: "2026-10-15" } })
      );
      assert.equal(w.status, 201, w.text);
      assert.equal(w.json.meta.gment_vintage, 1900, "clamped to min");
      assert.equal(w.json.meta.gment_alcohol, 13.5);
      assert.equal(w.json.meta.gment_contact, "");
      assert.equal(w.json.meta.gment_label, pic.json.id);
      assert.equal(w.json.meta.gment_released, "", "30 February is not a date");
      assert.equal(w.json.meta.gment_organic, true);
      assert.equal(w.json.meta.gment_winery, restaurant.id);
      assert.equal(w.json.meta.gment_tasting, "Fruity");
      assert.equal(wrong.status, 201, wrong.text);
      assert.equal(wrong.json.meta.gment_winery, 0, "a page is not a restaurant");
      assert.equal(wrong.json.meta.gment_label, 0, "not an image");
      assert.equal(wrong.json.meta.gment_released, "2026-10-15");
    });
  });

  describe("the URLs", { concurrency: 1 }, () => {
    test("the URLs: single, archive and taxonomy, from the slugs of the JSON in the SITE language (rules flushed by the hash)", async () => {
      // The example's slugs are per language ({"ca": "restaurants", "es": "restaurantes", …}); without WPML the
      // site language's is registered — es_ES here — and the others are not URLs at all.
      assert.ok(state.rules.some((r) => r.startsWith("restaurantes/")), "rewrite rules for restaurantes: " + JSON.stringify(state.rules));
      assert.ok(!state.rules.some((r) => r.startsWith("restaurants/") || r.startsWith("cuina/")), "no rules for the other languages' slugs");
      assert.ok(state.rules.some((r) => r.startsWith("bodegas/")), "the fixture without a plain string either: bodegas");
      // A restaurant of its own (the other track has «Ca n'Andreu»), with its cuisine from the start.
      const [term] = await batch(post("/gment_cuisine", { name: "Mallorquina", slug: "mallorquina" }));
      assert.equal(term.status, 201, term.text);
      const [r] = await batch(post("/gment_restaurant", { title: "Es Racó", slug: "es-raco", status: "publish", gment_cuisine: [term.json.id], meta: { gment_address: "Plaça Major, 3" } }));
      assert.equal(r.status, 201, r.text);
      assert.equal(r.json.link, BASE + "/restaurantes/es-raco/", "the permalink agrees with the rules");
      const [single, archive, catalan, taxonomy] = await Promise.all([
        html(BASE + "/restaurantes/es-raco/"),
        html(BASE + "/restaurantes/"),
        // Not followed: WordPress may 301 a 404 to the post it guesses, which is fine — what matters is that it is not served there.
        fetch(BASE + "/restaurants/es-raco/", { redirect: "manual" }),
        html(BASE + "/cocina/mallorquina/"),
      ]);
      assert.equal(single.status, 200);
      assert.match(single.text, /Es Rac(ó|&oacute;)/);
      assert.equal(archive.status, 200, "archive");
      assert.notEqual(catalan.status, 200, "the Catalan slug is not a URL without WPML");
      assert.equal(taxonomy.status, 200, "taxonomy");
    });
  });
});

test("changing the definitions flushes the rules once: off, the restaurant URLs go; on, they come back", async () => {
  await ct("/setup", { method: "POST", body: { on: false } });
  const off = await ct("/state");
  assert.deepEqual(off.rules, [], "no rules without the definitions");
  assert.notEqual(off.hash, state.hash);
  await ct("/setup", { method: "POST", body: { on: true } });
  const on = await ct("/state");
  assert.equal(on.hash, state.hash, "same definitions, same hash");
  assert.ok(on.rules.some((r) => r.startsWith("restaurantes/")));
});
