// NIVEL: 1 — sin navegador ni emulador. Que wpml-config.xml diga de cada meta de sede, cliente y opinión lo mismo que Meta_Panel (copy / translate), y que no se quede ninguna meta sin decidir.
//
// Lee los ficheros como texto (sin WordPress): las metas salen de las filas
// «'clave' => array( 'type' => …» de Content_Types::definitions().
//
// Vista en rojo (14-10-2026): con estrategia_web_location_phone pasado a
// «translate» en el XML, «el XML dice lo mismo que Meta_Panel» falla
// nombrando ese campo.
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

test("las metas se leen (si esto falla, la prueba no está mirando nada)", () => {
  assert.ok(metaKeys.includes("estrategia_web_location_phone") && metaKeys.includes("estrategia_web_client_business_type") && metaKeys.includes("testimonial_role"), metaKeys.join(", "));
});

test("toda meta de sede, cliente y opinión está decidida (copy o translate), y solo una vez", () => {
  const copy = constant("WPML_COPY");
  const translate = constant("WPML_TRANSLATE");
  for (const key of metaKeys) assert.ok(key in php, `${key}: sin decidir en Meta_Panel (WPML_COPY o WPML_TRANSLATE)`);
  assert.deepEqual(copy.filter((k) => translate.includes(k)), [], "en las dos listas");
});

test("el XML dice lo mismo que Meta_Panel", () => {
  assert.deepEqual(fromXml, php);
});

test("dirección y horario se traducen; teléfono, enlaces y mapa se copian (la petición de David, 07-10-2026)", () => {
  assert.equal(php.estrategia_web_location_address, "translate");
  assert.equal(php.estrategia_web_location_schedule, "translate");
  for (const k of ["phone", "email", "map_url", "map_embed", "static_map", "static_map_id"]) assert.equal(php["estrategia_web_location_" + k], "copy", k);
});
