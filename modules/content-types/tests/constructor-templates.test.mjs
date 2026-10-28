// NIVEL: 2 — Playground WordPress. NOT LEVEL 1 BECAUSE: what is tested is what WordPress holds after the plugin acts — the template copies that saving «enabled_types» stores and switches (Site_Types, on the request that saves the option), the copies added through the constructor's REST route and registered with their old names, the field-type sanitizers that look up OTHER posts (a client, a page) and this site's URL, a stored definition beating a theme file, and the 1.4.x migration that reads the stored option and counts the entries in the database. That every template validates, keeps its names and resolves its slug per language is level 1: constructor-pure.test.mjs.
//
// Split from constructor.test.mjs on 28-10-2026 (that file was 144 s on its
// own): the constructor's own protections and redirects stay there; the
// templates and what came with them (the three field types the templates
// brought, the theme files, the migration from 1.4.x) are here. The client
// template's taxonomy label and its registered/unregistered checks went: the
// label path is the testimonial's, and registration follows «active», which
// constructor.test.mjs's «deleting a type» proves. Its /sedes/ rules went
// too: the template's slug in the site language is level 1.
//
// HOW IT IS FAST: like constructor.test.mjs — the REST calls go in batches
// (batch.mjs: several calls in one request, split only where WordPress has
// to boot again to see what was written), and two tracks run at once: the
// templates, and a type of the site's own plus a theme file. The migration,
// which deletes every definition, goes after both. Round 2 (28-10-2026):
// the two sanitizer cases of each field are two posts made at once instead
// of a post and its update.
//
// The site under test speaks es_ES. It starts with reset() and
// constructor-reset; it leaves only eweb_ definitions and posts, which the
// next file's reset() deletes (the theme files are switched off by the
// migration test).
//
// Seen red (28-10-2026, round 2, batched), each test with one expected value
// changed (the testimonial's label, the clamped rating, the theme image
// path, the database label of the wine, the location's kept slug): all five
// red; restored, green.
import { test, describe, before } from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import { BASE, credentials, reset } from "../../../tests/lib.mjs";
import { batch } from "./batch.mjs";

const ct = async (route, { method = "GET", body } = {}) => {
  const res = await fetch(BASE + "/wp-json/estrategia-web-ct-test/v1" + route, { method, headers: body ? { "Content-Type": "application/json" } : {}, body: body ? JSON.stringify(body) : undefined });
  if (!res.ok) throw new Error(`${method} ${route} -> ${res.status} ${await res.text()}`);
  return res.json();
};
const CT = "/estrategia-web/v1/content-types";
const company = (enabled_types) => ({ method: "POST", path: "/wp/v2/settings", body: { estrategia_web_site: { enabled_types } } });

before(async () => {
  // Independent of each other: reset() deletes definitions and options, constructor-reset the gment_ posts.
  await Promise.all([reset(), credentials(), ct("/constructor-reset", { method: "POST" })]);
});

describe("two tracks at once", { concurrency: true }, () => {
  describe("the templates", { concurrency: 1 }, () => {
    test("templates: «enabled_types» still switches them (Site_Types); added from the panel, once, with the same type, names and labels as before", async () => {
      // All of it is stored definitions: one request. The client goes back on at the end, for the next test.
      const [, client, , off, again, testimonial, location, on] = await batch(
        company(["client"]),
        { path: CT + "/eweb_client" },
        company([]),
        { path: CT + "/eweb_client" },
        ...["client", "testimonial", "location"].map((key) => ({ method: "POST", path: CT + "/templates/" + key })),
        { method: "POST", path: CT + "/eweb_client", body: { active: true } }
      );
      assert.equal(client.status, 200, "enabled_types added the client template: " + client.text);
      assert.equal(client.json.active, true);
      assert.deepEqual(client.json.definition.slug, { en: "clients", es: "clientes", ca: "clients" }, "a NEW type (no entries): the template's slugs per language");
      assert.equal(off.json.active, false, "taken out of enabled_types: switched off, not deleted");
      assert.equal(again.status, 409, "only once (a switched-off copy counts)");
      for (const [key, added] of [["testimonial", testimonial], ["location", location]]) {
        assert.equal(added.status, 201, key + ": " + added.text);
        assert.equal(added.json.template, key);
      }
      assert.equal(on.json.active, true);
      const [t] = await batch({ path: "/wp/v2/types/eweb_testimonial" }); // Registered from this request.
      assert.equal(t.status, 200);
      assert.equal(t.json.name, "Opiniones");
      assert.equal(t.json.rest_base, "eweb_testimonial", "the same REST route as before");
    });

    test("the rating field type and the related client: clamped, and only a client is a client", async () => {
      const [client, page] = await batch({ method: "POST", path: "/wp/v2/eweb_client", body: { title: "Cliente prueba", status: "publish" } }, { method: "POST", path: "/wp/v2/pages", body: { title: "Una página", status: "publish" } });
      assert.equal(client.status, 201, client.text);
      const opinion = (rating, related) => ({ method: "POST", path: "/wp/v2/eweb_testimonial", body: { title: "Opinión", status: "publish", meta: { estrategia_web_testimonial_rating: rating, estrategia_web_testimonial_client: related } } });
      const [high, low] = await batch(opinion(9, page.json.id), opinion(-2, client.json.id));
      assert.equal(high.status, 201, high.text);
      assert.equal(high.json.meta.estrategia_web_testimonial_rating, 5, "clamped to 5 (and an integer: the rating type)");
      assert.equal(high.json.meta.estrategia_web_testimonial_client, 0, "a page is not a client");
      assert.equal(low.json.meta.estrategia_web_testimonial_rating, 0);
      assert.equal(low.json.meta.estrategia_web_testimonial_client, client.json.id);
    });
  });

  describe("a type of the site's own, and a theme file", { concurrency: 1 }, () => {
    test("the embed and local_image field types: the URL out of an iframe; an image of this site, never another domain's", async () => {
      // The location template used them up to 1.7.0; since 2.0.0 they are derived there, so they are measured on a type of the site's own.
      const place = { prefix: "gment_", name: "gment_place", labels: { singular: "Lugar", plural: "Lugares" }, fields: [{ name: "gment_embed", type: "embed", label: "Mapa" }, { name: "gment_picture", type: "local_image", label: "Imagen" }] };
      const [saved] = await batch({ method: "POST", path: CT, body: { definition: place } });
      assert.equal(saved.status, 201, saved.text);
      const iframe = '<iframe src="https://www.google.com/maps/embed?pb=!1m18&amp;x=1" width="600"></iframe>';
      const sede = (meta) => ({ method: "POST", path: "/wp/v2/gment_place", body: { title: "Sede", status: "publish", meta } });
      const [loc, theme] = await batch(sede({ gment_embed: iframe, gment_picture: "https://otro.test/mapa.webp" }), sede({ gment_picture: "./assets/maps/sede.webp" })); // Registered on this request.
      assert.equal(loc.status, 201, loc.text);
      assert.equal(loc.json.meta.gment_embed, "https://www.google.com/maps/embed?pb=!1m18&x=1");
      assert.equal(loc.json.meta.gment_picture, "", "another domain: refused");
      assert.equal(theme.json.meta.gment_picture, "assets/maps/sede.webp");
    });

    test("a type defined in a file and in the database: the database wins and the file is listed as ignored", async () => {
      await ct("/setup", { method: "POST", body: { on: true } });
      const def = JSON.parse(fs.readFileSync(path.join(import.meta.dirname, "fixtures", "valid", "gment_wine.json"), "utf8"));
      def.labels.plural = "Vinos de la casa";
      const [r] = await batch({ method: "POST", path: CT, body: { definition: def } });
      assert.equal(r.status, 201, r.text);
      const [type, state] = await batch({ path: "/wp/v2/types/gment_wine" }, { path: "/estrategia-web-ct-test/v1/state" });
      assert.equal(type.json.name, "Vinos de la casa", "registered from the database");
      assert.match((state.json.errors["gment_wine.json"] || []).join(" "), /base de datos|database/);
    });
  });
});

test("the migration of a 1.4.x site: its enabled types and the ones with entries, with the URLs it serves today; once", async () => {
  // The legacy route deletes every definition first; switching the theme files off deletes the gment_ posts (and leaves nothing of them to the next file).
  const [legacy] = await Promise.all([
    ct("/constructor-legacy", { method: "POST", body: { option: { enabled_types: ["client", "location"], rewrite_slugs: { location: "centros" } }, testimonials: 2 } }),
    ct("/setup", { method: "POST", body: { on: false } }),
  ]);
  assert.equal(legacy.testimonials.length, 2);
  // The next request migrates (init 2) and registers in the same load: everything read in it.
  const [client, list, testimonials, unregistered] = await batch({ path: "/wp/v2/types/eweb_client" }, { path: CT }, { path: "/estrategia-web-ct-test/v1/constructor-raw?name=eweb_testimonial" }, { path: "/wp/v2/types/eweb_testimonial" });
  assert.equal(client.status, 200, "this request migrates and registers in the same load");
  const by = Object.fromEntries(list.json.types.map((t) => [t.name, t]));
  assert.deepEqual(Object.keys(by).sort(), ["eweb_client", "eweb_location", "eweb_testimonial"], "project and portfolio: neither enabled nor with entries");
  assert.equal(by.eweb_client.active, true);
  assert.equal(by.eweb_location.active, true);
  assert.equal(by.eweb_testimonial.active, false, "entries but not enabled: stored switched off, as before (not registered)");
  assert.deepEqual(by.eweb_client.definition.slug, { es: "clientes" }, "the URL it served (the es gettext slug), not the template's per-language set");
  assert.deepEqual(by.eweb_client.definition.taxonomies[0].slug, { es: "sector" });
  assert.deepEqual(by.eweb_location.definition.slug, { es: "centros" }, "a slug fixed in rewrite_slugs is kept");
  assert.ok(testimonials.json.entries >= 2, "entries untouched (2 here, plus the ones the rating test left)");
  assert.equal(unregistered.status, 404);
  // Once: a definition deleted afterwards is not brought back — by the next request, which would migrate again if it were to.
  const [del] = await batch({ method: "DELETE", path: CT + "/eweb_location" });
  assert.equal(del.status, 200, del.text);
  const [gone] = await batch({ path: CT + "/eweb_location" });
  assert.equal(gone.status, 404, "not migrated again");
});
