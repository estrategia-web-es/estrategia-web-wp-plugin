// NIVEL: 1 — no browser or emulator. That wpml-config.xml says the same as Meta_Panel (copy / translate) about each location, client and testimonial meta, and that no meta is left undecided.
//
// Reads the files as text (no WordPress): the metas come from the
// «'key' => array( 'type' => …» rows of Content_Types::definitions().
//
// Seen red (14-10-2026): with estrategia_web_location_phone switched to
// «translate» in the XML, «the XML says the same as Meta_Panel» fails
// naming that field.
import { test } from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";

const ROOT = path.resolve(import.meta.dirname, "../../..");
const read = (rel) => fs.readFileSync(path.join(ROOT, rel), "utf8");

const types = read("modules/site/includes/class-content-types.php");
const panel = read("modules/site/includes/class-meta-panel.php");
const xml = read("wpml-config.xml");

const metaKeys = [...types.matchAll(/'([a-z_]+)'\s*=>\s*array\(\s*'type'\s*=>/g)].map((m) => m[1]);
const constant = (name) => {
  const body = panel.match(new RegExp(`const ${name} = array\\(([\\s\\S]*?)\\);`))?.[1] ?? "";
  return [...body.matchAll(/'([a-z_]+)'/g)].map((m) => m[1]);
};
const php = Object.fromEntries([...constant("WPML_COPY").map((k) => [k, "copy"]), ...constant("WPML_TRANSLATE").map((k) => [k, "translate"])]);
const fromXml = Object.fromEntries([...xml.matchAll(/<custom-field action="(copy|translate)">([a-z_]+)<\/custom-field>/g)].map((m) => [m[2], m[1]]));

test("the metas are read (if this fails, the test is not looking at anything)", () => {
  assert.ok(metaKeys.includes("estrategia_web_location_phone") && metaKeys.includes("estrategia_web_client_business_type") && metaKeys.includes("testimonial_role"), metaKeys.join(", "));
});

test("every location, client and testimonial meta is decided (copy or translate), and only once", () => {
  const copy = constant("WPML_COPY");
  const translate = constant("WPML_TRANSLATE");
  for (const key of metaKeys) assert.ok(key in php, `${key}: undecided in Meta_Panel (WPML_COPY or WPML_TRANSLATE)`);
  assert.deepEqual(copy.filter((k) => translate.includes(k)), [], "in both lists");
});

test("the XML says the same as Meta_Panel", () => {
  assert.deepEqual(fromXml, php);
});

test("address and schedule are translated; phone, links and map are copied (David's request, 07-10-2026)", () => {
  assert.equal(php.estrategia_web_location_address, "translate");
  assert.equal(php.estrategia_web_location_schedule, "translate");
  for (const k of ["phone", "email", "map_url", "map_embed", "static_map", "static_map_id"]) assert.equal(php["estrategia_web_location_" + k], "copy", k);
});
