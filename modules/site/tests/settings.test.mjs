// NIVEL: 2 — Playground WordPress, because it WRITES: saves the estrategia_web_site option through the real REST API, with user and permissions.
import { test, before } from "node:test";
import assert from "node:assert/strict";
import { reset, wp, saveCompany } from "../../../tests/lib.mjs";

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

test("save via REST and read it back: all keys, WhatsApp digits only, line breaks preserved", async () => {
  const saved = await saveCompany(GOOD);
  assert.equal(saved.company_name, GOOD.company_name);
  assert.equal(saved.email, GOOD.email);
  assert.equal(saved.whatsapp, "34600111222", "wa.me needs digits only, with the country code");
  assert.equal(saved.address, GOOD.address);
  assert.equal(saved.social.instagram, GOOD.social.instagram);
  assert.equal(saved.social.facebook, "", "networks not sent exist, empty");
  assert.deepEqual(saved.enabled_types, ["client", "location"]);
  assert.equal(saved.allow_svg, true);

  const read = await wp("/settings");
  assert.equal(read.status, 200);
  assert.deepEqual(read.json.estrategia_web_site, saved, "what is read is what was saved");
});

test("partial save: a PUT with one key does not erase the others", async () => {
  const saved = await saveCompany({ phone: "971 99 99 99" });
  assert.equal(saved.phone, "971 99 99 99");
  assert.equal(saved.company_name, GOOD.company_name);
  assert.deepEqual(saved.enabled_types, ["client", "location"]);
});

test("sanitizing: an invalid email, URL and WhatsApp are NOT saved and the previous value stays", async () => {
  const saved = await saveCompany({ email: "not-an-email", map_url: "javascript:alert(1)", whatsapp: "12", company_name: "<b>Acme</b> SL" });
  assert.equal(saved.email, GOOD.email);
  assert.equal(saved.map_url, GOOD.map_url);
  assert.equal(saved.whatsapp, "34600111222");
  assert.equal(saved.company_name, "Acme SL", "no HTML tags");
});

test("schema: a content type that does not exist is rejected entirely (400)", async () => {
  const r = await wp("/settings", { method: "POST", body: { estrategia_web_site: { enabled_types: ["client", "services"] } } });
  assert.equal(r.status, 400);
});

test("rewrite_slugs (0.2.0): sanitize_title, empty by default, merge by key, unknown name → 400", async () => {
  const a = await saveCompany({ rewrite_slugs: { client: "My Client!" } });
  assert.equal(a.rewrite_slugs.client, "my-client");
  assert.equal(a.rewrite_slugs.client_sector, "", "the ones not sent exist, empty");
  assert.equal(a.rewrite_slugs.portfolio_category, "");

  const b = await saveCompany({ rewrite_slugs: { client_sector: "sector-x" } });
  assert.equal(b.rewrite_slugs.client, "my-client", "saving one does not erase the others");
  assert.equal(b.rewrite_slugs.client_sector, "sector-x");

  const c = await saveCompany({ phone: "971 55 55 55" });
  assert.deepEqual(c.rewrite_slugs, b.rewrite_slugs, "saving another key does not touch them");

  const bad = await wp("/settings", { method: "POST", body: { estrategia_web_site: { rewrite_slugs: { services: "services-slug" } } } });
  assert.equal(bad.status, 400, "only the plugin's types and taxonomies");

  const read = await wp("/settings");
  assert.deepEqual(read.json.estrategia_web_site.rewrite_slugs, b.rewrite_slugs);
  await saveCompany({ rewrite_slugs: { client: "", client_sector: "" } });
});

test("permissions: an author cannot read or change the data via /wp/v2/settings", async () => {
  const r = await wp("/settings", { as: "author", method: "POST", body: { estrategia_web_site: { phone: "000" } } });
  assert.equal(r.status, 403);
  const read = await wp("/settings");
  assert.notEqual(read.json.estrategia_web_site.phone, "000");
});
