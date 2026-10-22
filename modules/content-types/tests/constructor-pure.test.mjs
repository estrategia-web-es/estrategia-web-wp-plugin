// NIVEL: 1 — no browser, no emulator. The pure parts of the constructor with the machine's PHP: every template validates and keeps the old names (the contract), the template rules of the validator, the three new field types, and the protections when a definition changes (includes/class-changes.php).
//
// If there is no `php` on the PATH the test FAILS (it is not skipped).
//
// Seen red (17-10-2026): with the shipped-names exemption off in
// Schema::check_name(), «every template validates» failed (client_url — the
// name then — and estrategia_web_* «must start with the prefix eweb_»); with Changes::check()
// not comparing slugs, «a URL that moves» failed; with redirect_paths()
// matching the base anywhere in the path, «/sedes-viejas/» was redirected.
import { test } from "node:test";
import assert from "node:assert/strict";
import { spawnSync } from "node:child_process";
import fs from "node:fs";
import path from "node:path";

const HERE = import.meta.dirname;
const php = (mode, input) => {
  const r = spawnSync("php", [path.join(HERE, "php", "constructor.php"), mode], { encoding: "utf8", input: input === undefined ? "" : JSON.stringify(input) });
  assert.equal(r.status, 0, "php did not run: " + (r.error?.message || r.stderr || r.stdout));
  return JSON.parse(r.stdout);
};
const template = (key) => JSON.parse(fs.readFileSync(path.join(HERE, "..", "templates", key + ".json"), "utf8"));
const errors = (def) => php("validate", { def });
const hasError = (def, re) => {
  const found = errors(def);
  assert.ok(found.some((e) => re.test(e)), `expected an error matching ${re}, got: ${JSON.stringify(found)}`);
};
const resolved = (key) => {
  const t = template(key);
  delete t.$schema;
  t.slug = t.slug.es;
  for (const tax of t.taxonomies || []) tax.slug = tax.slug.es;
  return t;
};

// THE CONTRACT: the names modules/site registered (Type_Names, Content_Types), which the themes, the content loads and the stored data use.
const CONTRACT = {
  client: { name: "eweb_client", taxonomies: ["eweb_client_sector"], fields: ["estrategia_web_client_url", "estrategia_web_client_image_fit", "estrategia_web_client_business_type"] },
  testimonial: { name: "eweb_testimonial", taxonomies: [], fields: ["estrategia_web_testimonial_author", "estrategia_web_testimonial_role", "estrategia_web_testimonial_rating", "estrategia_web_testimonial_client"] },
  location: {
    name: "eweb_location",
    taxonomies: [],
    fields: ["estrategia_web_location_address", "estrategia_web_location_phone", "estrategia_web_location_email", "estrategia_web_location_schedule", "estrategia_web_location_map_url", "estrategia_web_location_map_embed", "estrategia_web_location_static_map", "estrategia_web_location_static_map_id",
      // 22-10-2026, for modules/local-business (schema.org): the address by parts, the structured hours, the coordinates and the type.
      "estrategia_web_location_street", "estrategia_web_location_postal_code", "estrategia_web_location_locality", "estrategia_web_location_region", "estrategia_web_location_country", "estrategia_web_location_hours", "estrategia_web_location_latitude", "estrategia_web_location_longitude", "estrategia_web_location_business_type"],
  },
  project: { name: "eweb_project", taxonomies: ["eweb_project_category"], fields: [] },
  portfolio: { name: "eweb_portfolio", taxonomies: ["eweb_portfolio_category"], fields: [] },
};

test("every template validates, in every language of the plugin, with its URL in the site language", () => {
  const es = php("templates", { locale: "es_ES" });
  const ca = php("templates", { locale: "ca" });
  const en = php("templates", { locale: "en_US" });
  assert.deepEqual(Object.keys(es).sort(), Object.keys(CONTRACT).sort(), "the five templates");
  for (const key of Object.keys(CONTRACT)) {
    for (const [lang, out] of [["es", es], ["ca", ca], ["en", en]]) assert.deepEqual(out[key].errors, [], `${key} (${lang})`);
  }
  assert.equal(es.location.slug, "sedes");
  assert.equal(ca.location.slug, "seus");
  assert.equal(en.location.slug, "locations");
  assert.deepEqual(es.client.taxonomy_slugs, ["sector"], "the same slug the gettext translation gave (Lavandería: /sector/…)");
  assert.equal(es.testimonial.slug, "opiniones");
  assert.equal(es.client.slug, "clientes");
  for (const key of Object.keys(CONTRACT)) assert.deepEqual(Object.keys(es[key].slugs).sort(), ["ca", "en", "es"], key + ": one slug per language of the plugin, stored as such");
});

test("the templates keep the old names: type, taxonomies and meta keys (nothing to migrate in the database)", () => {
  const es = php("templates", { locale: "es_ES" });
  for (const [key, c] of Object.entries(CONTRACT)) {
    const n = es[key].normalized;
    assert.equal(n.name, c.name, key);
    assert.deepEqual(n.taxonomies.map((t) => t.name), c.taxonomies, key + " taxonomies");
    assert.deepEqual(n.fields.map((f) => f.name), c.fields, key + " fields, in the panel's order");
    assert.equal(n.wpml, "display-as-translated", key);
  }
  const loc = Object.fromEntries(es.location.normalized.fields.map((f) => [f.name, f]));
  assert.deepEqual(
    Object.fromEntries(Object.entries(loc).map(([k, f]) => [k, f.wpml])),
    {
      estrategia_web_location_address: "translate",
      estrategia_web_location_phone: "copy",
      estrategia_web_location_email: "copy",
      estrategia_web_location_schedule: "translate",
      estrategia_web_location_map_url: "copy",
      estrategia_web_location_map_embed: "copy",
      estrategia_web_location_static_map: "copy",
      estrategia_web_location_static_map_id: "copy",
      // A postal address is not translated (the post office reads it in the local language): copied, like the hours and the coordinates.
      estrategia_web_location_street: "copy",
      estrategia_web_location_postal_code: "copy",
      estrategia_web_location_locality: "copy",
      estrategia_web_location_region: "copy",
      estrategia_web_location_country: "copy",
      estrategia_web_location_hours: "copy",
      estrategia_web_location_latitude: "copy",
      estrategia_web_location_longitude: "copy",
      estrategia_web_location_business_type: "copy",
    },
    "the same copy / translate as wpml-config.xml and Meta_Panel::WPML_*"
  );
  const t = Object.fromEntries(es.testimonial.normalized.fields.map((f) => [f.name, f]));
  assert.equal(t.estrategia_web_testimonial_rating.meta_type, "integer", "rating stored as an integer, as before");
  assert.equal(t.estrategia_web_testimonial_role.wpml, "translate");
  assert.deepEqual(t.estrategia_web_testimonial_client.post_type, ["eweb_client"]);
  const c = Object.fromEntries(es.client.normalized.fields.map((f) => [f.name, f]));
  assert.equal(c.estrategia_web_client_image_fit.default, "cover", "a client without the field saved counts as a photo");
  assert.deepEqual(es.client.normalized.supports, ["title", "thumbnail", "excerpt", "page-attributes", "custom-fields"], "no editor: the classic screen, as before");
  assert.deepEqual(es.location.normalized.labels.details, { en: "Location details", es: "Datos de la sede", ca: "Dades de la seu" }, "the panel title the old Meta_Panel had");
});

test("template rules: the shipped names keep their prefixes; the type name is fixed; additions follow the rules", () => {
  assert.deepEqual(errors(resolved("testimonial")), []);
  const noTemplate = resolved("testimonial");
  delete noTemplate.template;
  hasError(noTemplate, /prefix “eweb_” is reserved/);
  const renamed = resolved("testimonial");
  renamed.name = "eweb_opinion";
  hasError(renamed, /“name” must be “eweb_testimonial”/);
  const added = resolved("testimonial");
  added.fields.push({ name: "eweb_testimonial_source", type: "url", label: "Source" });
  assert.deepEqual(errors(added), [], "a new field with the definition's prefix");
  const bare = resolved("testimonial");
  bare.fields.push({ name: "source", type: "url", label: "Source" });
  hasError(bare, /fields\[5\]\.name “source” must start with the prefix “eweb_”/);
  const other = resolved("testimonial");
  other.fields.push({ name: "gment_source", type: "url", label: "Source" });
  hasError(other, /must start with the prefix “eweb_”/);
  const unknown = resolved("testimonial");
  unknown.template = "review";
  hasError(unknown, /there is no template “review”/);
  // Outside a template, a template's meta key gets no free pass.
  hasError({ prefix: "gment_", name: "gment_client", labels: { singular: "C", plural: "Cs" }, fields: [{ name: "estrategia_web_client_url", type: "url", label: "Web" }] }, /“estrategia_web_client_url” must start with the prefix “gment_”/);
});

test("the new field types: rating (0–5), embed, local_image; and the optional type labels", () => {
  const def = (field) => ({ prefix: "gment_", name: "gment_review", labels: { singular: "Review", plural: "Reviews" }, fields: [{ name: "gment_x", label: "X", ...field }] });
  assert.deepEqual(errors(def({ type: "rating", default: 3 })), []);
  hasError(def({ type: "rating", default: 6 }), /default must be a whole number from 0/);
  hasError(def({ type: "rating", default: "3" }), /default must be a whole number from 0/);
  assert.deepEqual(errors(def({ type: "embed" })), []);
  assert.deepEqual(errors(def({ type: "local_image", default: "assets/maps/x.webp" })), []);
  hasError(def({ type: "rating", options: ["1"] }), /options is only for fields of type “select”/);
  const labels = def({ type: "text" });
  labels.labels.details = { es: "Datos de la reseña" };
  labels.labels.featured_image = "Photo";
  assert.deepEqual(errors(labels), []);
  labels.labels.colour = "x";
  hasError(labels, /Unknown key “labels\.colour”/);
  const tax = def({ type: "text" });
  tax.taxonomies = [{ name: "gment_kind", labels: { singular: "Kind", plural: "Kinds", details: "x" } }];
  hasError(tax, /Unknown key “taxonomies\[1\]\.labels\.details”/);
});

// 22-10-2026. Seen red: with the hours branch out of Schema::check_type_rules(), the invalid default passed.
test("the hours field type: a default must be hours as stored", () => {
  const def = (field) => ({ prefix: "gment_", name: "gment_shop", labels: { singular: "Shop", plural: "Shops" }, fields: [{ name: "gment_h", label: "Hours", type: "hours", ...field }] });
  assert.deepEqual(errors(def({})), []);
  assert.deepEqual(errors(def({ default: '{"mo":["08:00-14:00"]}' })), []);
  assert.deepEqual(errors(def({ default: "" })), []);
  hasError(def({ default: "Mo-Fr 8-14" }), /default must be opening hours as stored/);
  hasError(def({ default: '{"mo":["14:00-08:00","08:00-09:00"],"tu":[]}' }), /default must be opening hours as stored/, "not canonical (unsorted, an empty day)");
});

// 22-10-2026. Seen red: with add_missing() appending at the end, the new fields went after the site's own «Parking».
test("new fields of a template: offered by name, put in after their template neighbour, the site's own fields untouched", () => {
  const stored = resolved("location");
  // A site that copied the template on 17-10-2026: the first eight fields, one of them changed, plus one of its own.
  stored.fields = stored.fields.slice(0, 8);
  stored.fields[0].label = { es: "Dirección (como se ve)" };
  const own = { name: "eweb_location_parking", type: "boolean", label: "Parking" };
  stored.fields.push(own);
  const { missing, added } = php("missing", { def: stored });
  const NEW = ["estrategia_web_location_street", "estrategia_web_location_postal_code", "estrategia_web_location_locality", "estrategia_web_location_region", "estrategia_web_location_country", "estrategia_web_location_hours", "estrategia_web_location_latitude", "estrategia_web_location_longitude", "estrategia_web_location_business_type"];
  assert.deepEqual(missing.map((m) => m.field.name), NEW);
  assert.equal(missing[0].after, "estrategia_web_location_static_map_id");
  assert.deepEqual(added.fields.map((f) => f.name), [...stored.fields.slice(0, 8).map((f) => f.name), ...NEW, own.name], "after the template's last old field, before the site's own one");
  assert.deepEqual(added.fields[0], stored.fields[0], "the site's version of a template field is kept");
  assert.deepEqual(added.fields.at(-1), own, "and so is its own field");
  assert.deepEqual(errors(added), [], "what a deploy would store validates");
  assert.deepEqual(php("missing", { def: added }).missing, [], "idempotent: nothing more to offer");
  const plain = { prefix: "gment_", name: "gment_shop", labels: { singular: "Shop", plural: "Shops" }, fields: [] };
  assert.deepEqual(php("missing", { def: plain }).missing, [], "a definition without a template is offered nothing");
});

test("Changes: what is refused, what is warned, and the URLs that move", () => {
  const base = { prefix: "gment_", name: "gment_restaurant", slug: "restaurants", labels: { singular: "R", plural: "Rs" }, taxonomies: [{ name: "gment_cuisine", labels: { singular: "C", plural: "Cs" } }], fields: [{ name: "gment_phone", type: "tel", label: "Phone" }, { name: "gment_seats", type: "number", label: "Seats" }] };
  const copy = () => structuredClone(base);
  const check = (change, counts = {}) => {
    const next = copy();
    change(next);
    return php("changes", { old: base, new: next, counts });
  };
  assert.deepEqual(php("changes", { old: null, new: base, counts: {} }), { errors: [], warnings: [], slugs: [] }, "a new type: nothing to compare");
  assert.deepEqual(check((d) => (d.public = true)), { errors: [], warnings: [], slugs: [] }, "an explicit default is not a change (normalized)");

  const renamed = check((d) => (d.name = "gment_eatery"), { entries: 3 });
  assert.match(renamed.errors.join(" "), /“gment_restaurant” cannot change: it has 3 entries/);
  assert.deepEqual(check((d) => (d.name = "gment_eatery"), { entries: 0 }).errors, [], "no entries: a typo being fixed");

  const fields = check((d) => {
    d.fields.shift();
    d.fields[0].type = "text";
  });
  assert.equal(fields.errors.length, 0);
  assert.match(fields.warnings.join(" "), /“gment_phone” is removed: what the entries have in it stays/);
  assert.match(fields.warnings.join(" "), /“gment_seats” changes from number to text/);

  const url = check((d) => {
    d.slug = "eat";
    d.taxonomies[0].slug = "kitchen";
  });
  assert.deepEqual(url.slugs, [["type", "gment_restaurant", "restaurants", "eat", ""], ["taxonomy", "gment_cuisine", "cuisine", "kitchen", ""]]);
  // Per language (content-types/url): only the languages whose slug really changes.
  assert.deepEqual(check((d) => (d.slug = { es: "restaurants", en: "restaurants" })).slugs, [], "a string to the same slug in every language: nothing moves (Lavandería's migration)");
  const perLang = php("changes", { old: { ...base, slug: { es: "restaurantes", en: "restaurants" } }, new: { ...base, slug: { es: "restaurantes", en: "eateries" } }, counts: {} });
  assert.deepEqual(perLang.slugs, [["type", "gment_restaurant", "restaurants", "eateries", "en"]]);
  assert.match(url.warnings.join(" "), /from \/restaurants\/ to \/eat\//);

  const taxRenamed = check((d) => (d.taxonomies[0].name = "gment_kitchen"), { terms: { gment_cuisine: 4 } });
  assert.match(taxRenamed.errors.join(" "), /“gment_cuisine” cannot be renamed to “gment_kitchen”/);
  const taxGone = check((d) => (d.taxonomies = []), { terms: { gment_cuisine: 4 } });
  assert.deepEqual(taxGone.errors, []);
  assert.match(taxGone.warnings.join(" "), /“gment_cuisine” is removed: its 4 terms stay/);
});

test("redirect paths: the old base as a whole segment, after the language prefix", () => {
  const out = php("redirects", { paths: ["/sedes/", "/sedes/maioris/", "/en/sedes/son-oms-en/", "/sedes-viejas/", "/en/locations/x/", "/otra/sedes/"], from: "sedes", to: "centros" });
  assert.deepEqual(out, {
    "/sedes/": "/centros/",
    "/sedes/maioris/": "/centros/maioris/",
    "/en/sedes/son-oms-en/": "/en/centros/son-oms-en/",
  });
  assert.deepEqual(php("redirects", { paths: ["/a/"], from: "a", to: "a" }), {}, "no change, no redirect");
});
