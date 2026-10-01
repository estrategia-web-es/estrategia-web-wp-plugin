// NIVEL: 2 — WordPress de Playground, porque ESCRIBE: guarda la opción armazon_site por la API REST de verdad, con usuario y permisos.
import { test, before } from "node:test";
import assert from "node:assert/strict";
import { reset, wp, saveCompany } from "./lib.mjs";

before(reset);

const GOOD = {
  company_name: "Lavandería de Prueba",
  legal_name: "Prueba SL",
  tax_id: "B00000000",
  email: "hola@ejemplo.test",
  phone: "971 12 34 56",
  whatsapp: "+34 600 111 222",
  whatsapp_message: "Hola, quería información",
  address: "Calle Mayor 1\n07001 Palma",
  schedule: "L-V 9-14",
  map_url: "https://maps.google.com/?q=Palma",
  social: { instagram: "https://instagram.com/prueba" },
  enabled_types: ["client", "location"],
  allow_svg: true,
};

test("guardar por REST y leerlo: todas las claves, WhatsApp solo dígitos, saltos de línea conservados", async () => {
  const saved = await saveCompany(GOOD);
  assert.equal(saved.company_name, GOOD.company_name);
  assert.equal(saved.email, GOOD.email);
  assert.equal(saved.whatsapp, "34600111222", "wa.me necesita solo dígitos con el prefijo");
  assert.equal(saved.address, GOOD.address);
  assert.equal(saved.social.instagram, GOOD.social.instagram);
  assert.equal(saved.social.facebook, "", "las redes no enviadas existen, vacías");
  assert.deepEqual(saved.enabled_types, ["client", "location"]);
  assert.equal(saved.allow_svg, true);

  const read = await wp("/settings");
  assert.equal(read.status, 200);
  assert.deepEqual(read.json.armazon_site, saved, "lo leído es lo guardado");
});

test("guardar por partes: un PUT con una clave no borra las demás", async () => {
  const saved = await saveCompany({ phone: "971 99 99 99" });
  assert.equal(saved.phone, "971 99 99 99");
  assert.equal(saved.company_name, GOOD.company_name);
  assert.deepEqual(saved.enabled_types, ["client", "location"]);
});

test("saneado: un correo, una URL y un WhatsApp inválidos NO se guardan y se queda el valor anterior", async () => {
  const saved = await saveCompany({ email: "no-es-correo", map_url: "javascript:alert(1)", whatsapp: "12", company_name: "<b>Acme</b> SL" });
  assert.equal(saved.email, GOOD.email);
  assert.equal(saved.map_url, GOOD.map_url);
  assert.equal(saved.whatsapp, "34600111222");
  assert.equal(saved.company_name, "Acme SL", "sin etiquetas HTML");
});

test("esquema: un tipo de contenido que no existe se rechaza entero (400)", async () => {
  const r = await wp("/settings", { method: "POST", body: { armazon_site: { enabled_types: ["client", "services"] } } });
  assert.equal(r.status, 400);
});

test("permisos: un autor no puede leer ni cambiar los datos por /wp/v2/settings", async () => {
  const r = await wp("/settings", { as: "author", method: "POST", body: { armazon_site: { phone: "000" } } });
  assert.equal(r.status, 403);
  const read = await wp("/settings");
  assert.notEqual(read.json.armazon_site.phone, "000");
});
