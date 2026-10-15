// NIVEL: 1 — no browser, no emulator. The definition validator (includes/class-schema.php) with the machine's PHP: name limits, the prefix, field rules, cross-file checks, defaults, labels by locale, and that content-types.schema.json says the same as PHP.
//
// If there is no `php` on the PATH the test FAILS (it is not skipped). On
// this machine it is C:\wamp64\bin\php\php8.3.6.
//
// Seen red (15-10-2026): with MAX_TYPE at 21, «type name: … 21 are refused»
// AND the drift test failed (the JSON Schema still said 20); with the
// boolean-required rule switched off, «field rules» failed.
import { test } from "node:test";
import assert from "node:assert/strict";
import { spawnSync } from "node:child_process";
import fs from "node:fs";
import path from "node:path";

const HERE = import.meta.dirname;
const MODULE = path.join(HERE, "..");
const php = (mode, input) => {
  const r = spawnSync("php", [path.join(HERE, "php", "schema.php"), mode], { encoding: "utf8", input: input === undefined ? "" : JSON.stringify(input) });
  assert.equal(r.status, 0, "php did not run: " + (r.error?.message || r.stderr || r.stdout));
  return JSON.parse(r.stdout);
};
const example = JSON.parse(fs.readFileSync(path.join(MODULE, "examples", "gmct_restaurant.json"), "utf8"));
const schema = JSON.parse(fs.readFileSync(path.join(MODULE, "content-types.schema.json"), "utf8"));
const base = () => structuredClone(example);
const errors = (def) => php("validate", def);
const hasError = (def, re) => {
  const found = errors(def);
  assert.ok(found.some((e) => re.test(e)), `expected an error matching ${re}, got: ${JSON.stringify(found)}`);
};

test("the Gastronòmicament example (gmct_restaurant) is valid", () => {
  assert.deepEqual(errors(example), []);
});

test("type name: 20 characters pass, 21 are refused (WordPress would cut it)", () => {
  const ok = base();
  ok.name = "gmct_" + "r".repeat(15);
  assert.equal(ok.name.length, 20);
  assert.deepEqual(errors(ok), []);
  const long = base();
  long.name = "gmct_" + "r".repeat(16);
  hasError(long, /longer than 20 characters \(21\)/);
});

test("taxonomy name: 32 characters pass, 33 are refused", () => {
  const ok = base();
  ok.taxonomies[0].name = "gmct_" + "c".repeat(27);
  assert.deepEqual(errors(ok), []);
  const long = base();
  long.taxonomies[0].name = "gmct_" + "c".repeat(28);
  hasError(long, /longer than 32 characters \(33\)/);
});

test("prefix: Estrategia Web's eweb_ and WordPress's wp_ are refused; every name must start with the prefix", () => {
  for (const prefix of ["eweb_", "wp_", "estrategia_web_", "ew_"]) {
    const def = base();
    def.prefix = prefix;
    assert.ok(errors(def).length > 0, prefix + " should be refused");
  }
  const own = base();
  own.prefix = "eweb_";
  own.name = "eweb_restaurant";
  hasError(own, /reserved/);
  const noPrefix = base();
  noPrefix.name = "restaurant";
  hasError(noPrefix, /must start with the prefix “gmct_”/);
  const field = base();
  field.fields[0].name = "phone";
  hasError(field, /fields\[1\]\.name “phone” must start with the prefix/);
  const tax = base();
  tax.taxonomies[0].name = "cuisine";
  hasError(tax, /taxonomies\[1\]\.name “cuisine” must start with the prefix/);
  const bare = base();
  bare.name = "gmct_";
  hasError(bare, /must start with the prefix/);
});

test("a typo in a key is an error, not ignored", () => {
  const def = base();
  def.fields[1].requird = true;
  hasError(def, /Unknown key “fields\[2\]\.requird”/);
  const top = base();
  top.label = "x";
  hasError(top, /Unknown key “label”/);
});

test("field rules: select, relation, number, date, boolean, image, duplicates, wpml", () => {
  const cases = [
    [(d) => delete d.fields[2].options, /a select needs “options”/],
    [(d) => (d.fields[2].default = "€€€€"), /default must be the value of one of the options/],
    [(d) => d.fields[2].options.push("€"), /two options have the same value/],
    [(d) => d.fields.push({ name: "gmct_chef", type: "relation", label: "Chef" }), /a relation needs “post_type”/],
    [(d) => d.fields.push({ name: "gmct_chef", type: "relation", label: "Chef", post_type: "gmct_chef", default: 3 }), /a relation cannot have a default/],
    [(d) => d.fields.push({ name: "gmct_seats", type: "number", label: "Seats", min: 10, max: 5 }), /min is greater than max/],
    [(d) => d.fields.push({ name: "gmct_seats", type: "number", label: "Seats", integer: true, default: 2.5 }), /default must be a number/],
    [(d) => d.fields.push({ name: "gmct_seats", type: "number", label: "Seats", max: 100, default: 101 }), /default must be a number within min and max/],
    [(d) => d.fields.push({ name: "gmct_opened", type: "date", label: "Opened", default: "2026-02-30" }), /YYYY-MM-DD/],
    [(d) => d.fields.push({ name: "gmct_terrace", type: "boolean", label: "Terrace", required: true }), /a boolean cannot be required/],
    [(d) => d.fields.push({ name: "gmct_logo", type: "image", label: "Logo", default: 5 }), /an image cannot have a default/],
    [(d) => d.fields.push({ name: "gmct_phone", type: "text", label: "Again" }), /declared twice/],
    [(d) => (d.fields[0].wpml = "duplicate"), /wpml must be one of: copy, translate, ignore/],
    [(d) => (d.fields[0].type = "repeater"), /type must be one of/],
    [(d) => (d.fields[0].options = ["a"]), /options is only for fields of type “select”/],
    [(d) => (d.fields[0].label = { catalan: "Telèfon" }), /label is required/],
    [(d) => (d.labels = { singular: "Restaurant" }), /labels\.plural is required/],
    [(d) => (d.slug = "/restaurants/"), /slug must be lowercase/],
    [(d) => (d.supports = ["title", "editr"]), /unknown value “editr”/],
    [(d) => (d.icon = "food"), /Dashicons/],
  ];
  for (const [change, re] of cases) {
    const def = base();
    change(def);
    hasError(def, re);
  }
  // And what IS allowed: a nested slug, a number within range, a relation to two types.
  const ok = base();
  ok.slug = "carta/restaurants";
  ok.fields.push({ name: "gmct_seats", type: "number", label: "Seats", integer: true, min: 0, max: 500, default: 40 });
  ok.fields.push({ name: "gmct_related", type: "relation", label: "Related", post_type: ["gmct_restaurant", "post"] });
  ok.fields.push({ name: "gmct_terrace", type: "boolean", label: "Terrace", default: true });
  assert.deepEqual(errors(ok), []);
});

test("not a JSON object: one clear error", () => {
  assert.deepEqual(errors([1, 2]), ["The file is not a JSON object."]);
});

test("across files: the same type twice, the same taxonomy twice, a reference to an undeclared taxonomy", () => {
  const recipe = { prefix: "gmct_", name: "gmct_recipe", labels: { singular: "Recipe", plural: "Recipes" }, taxonomies: ["gmct_cuisine", "category"] };
  assert.deepEqual(php("check-all", { "a.json": example, "b.json": recipe }), {}, "a reference to a taxonomy declared in another file, and category, are fine");
  const out = php("check-all", { "a.json": example, "b.json": example, "c.json": { ...recipe, taxonomies: ["gmct_wine_region"] } });
  assert.match(out["b.json"].join(" "), /type “gmct_restaurant” is already declared in a\.json/);
  assert.match(out["b.json"].join(" "), /name “gmct_cuisine” is already declared in a\.json/);
  assert.match(out["c.json"].join(" "), /taxonomy “gmct_wine_region” is not declared/);
  assert.equal(out["a.json"], undefined, "the first one keeps its name");
  const clash = php("check-all", { "a.json": example, "b.json": { ...recipe, fields: [{ name: "gmct_phone", type: "tel", label: "Phone", wpml: "translate" }] } });
  assert.match(clash["b.json"].join(" "), /“gmct_phone” has wpml “translate” here and “copy” in a\.json/);
});

test("normalize: the defaults, WPML per field type, and custom-fields added when there are fields", () => {
  const n = php("normalize", { prefix: "gmct_", name: "gmct_wine_cellar", labels: { singular: "Winery", plural: "Wineries" }, supports: ["title"], fields: [{ name: "gmct_notes", type: "textarea", label: "Notes" }, { name: "gmct_founded", type: "number", label: "Founded", integer: true }, { name: "gmct_logo", type: "image", label: "Logo" }] });
  assert.equal(n.slug, "wine-cellar");
  assert.deepEqual(n.supports, ["title", "custom-fields"]);
  assert.equal(n.wpml, "translate");
  assert.equal(n.has_archive, true);
  assert.deepEqual(n.fields.map((f) => [f.name, f.meta_type, f.wpml, f.default]), [
    ["gmct_notes", "string", "translate", ""],
    ["gmct_founded", "integer", "copy", 0],
    ["gmct_logo", "integer", "copy", 0],
  ]);
  const ex = php("normalize", example);
  assert.equal(ex.taxonomies[0].hierarchical, true);
  assert.deepEqual(ex.fields[2].options[0], { value: "€", label: { ca: "€ — econòmic", es: "€ — económico", en: "€ — budget" } });
});

test("labels by locale: exact, then language, then en, then the first one", () => {
  const text = { ca: "Restaurant (ca)", es_ES: "Restaurante", en: "Restaurant" };
  assert.equal(php("text", { text, locale: "es_ES" }), "Restaurante");
  assert.equal(php("text", { text, locale: "ca_ES" }), "Restaurant (ca)");
  assert.equal(php("text", { text, locale: "de_DE" }), "Restaurant");
  assert.equal(php("text", { text: { fr: "Restaurant (fr)", it: "Ristorante" }, locale: "de_DE" }), "Restaurant (fr)");
  assert.equal(php("text", { text: "Plain", locale: "ca" }), "Plain");
});

test("content-types.schema.json says the same as the PHP validator (no drift)", () => {
  const lists = php("lists");
  const keys = (o) => Object.keys(o).sort();
  assert.deepEqual(keys(schema.properties), [...lists.type_keys].sort(), "type keys");
  assert.deepEqual(keys(schema.$defs.taxonomy.properties), [...lists.taxonomy_keys].sort(), "taxonomy keys");
  assert.deepEqual(keys(schema.$defs.field.properties), [...lists.field_keys].sort(), "field keys");
  assert.deepEqual(keys(schema.$defs.labels.properties), [...lists.label_keys].sort(), "label keys");
  assert.deepEqual(schema.$defs.field.properties.type.enum, lists.field_types, "field types");
  assert.deepEqual(schema.properties.supports.items.enum, lists.supports, "supports");
  assert.deepEqual(schema.$defs.field.properties.wpml.enum, lists.field_wpml, "field wpml");
  assert.deepEqual(schema.$defs.typeWpml.enum, lists.type_wpml, "type wpml");
  assert.deepEqual([...schema.properties.prefix.not.enum].sort(), [...lists.reserved].sort(), "reserved prefixes");
  assert.equal(schema.properties.name.maxLength, lists.max_type);
  assert.equal(schema.$defs.taxonomy.properties.name.maxLength, lists.max_taxonomy);
  assert.equal(schema.$defs.field.properties.name.maxLength, lists.max_field);
  assert.equal(schema.additionalProperties, false);
  assert.equal(schema.$defs.field.additionalProperties, false);
});
