// NIVEL: 1 — no browser or emulator. That wpml-config.xml declares what the code registers: in <admin-texts>, the company details of Settings::WPML_TRANSLATE, every company detail decided (translate or same), and valid attributes; and that the types of Type_Names are NOT in it any more (since 17-10-2026 the content-types templates declare them, display-as-translated).
//
// Reads the files as text (no WordPress). The check with real WPML (String Translation offering the
// strings, /en/ showing the translation) is company-data-wpml.test.mjs
// (level 2, the plugin's WordPress with WPML; in Lavandería until 25-10-2026).
//
// Seen red (16-10-2026): with eweb_location switched to translate="1" in the
// XML, «the XML declares every type…» fails naming it; with «schedule»
// removed from Settings::WPML_TRANSLATE, «every company detail is decided»
// fails naming it; with translate="2" on eweb_location (what the first
// version of this XML had, valid-looking and dropped by WPML's XSD), the
// attribute test fails.
import { test } from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";

const ROOT = path.resolve(import.meta.dirname, "../../..");
const read = (rel) => fs.readFileSync(path.join(ROOT, rel), "utf8");

// The XML without its comments: they quote entries as examples.
const xml = read("wpml-config.xml").replace(/<!--[\s\S]*?-->/g, "");
const names = read("modules/site/includes/class-type-names.php");
const settings = read("modules/site/includes/class-settings.php");
const company = read("modules/site/includes/class-company.php");

const constBody = (src, name) => src.match(new RegExp(`const ${name} = array\\(([\\s\\S]*?)\\n?\\t?\\);`))?.[1] ?? "";
const list = (src, name) => [...constBody(src, name).matchAll(/'([a-z_]+)'/g)].map((m) => m[1]);

const PREFIX = names.match(/const PREFIX = '([a-z_]+)';/)?.[1];
const KEYS = list(names, "KEYS");
const TAXONOMIES = list(names, "TAXONOMIES");
// Since 17-10-2026 the modes are the content-types templates' (they declare WPML through its filter, not this XML).
const TEMPLATES = path.join(ROOT, "modules/content-types/templates");
const MODES = {};
for (const f of fs.readdirSync(TEMPLATES).filter((x) => x.endsWith(".json"))) {
  const t = JSON.parse(fs.readFileSync(path.join(TEMPLATES, f), "utf8"));
  MODES[t.name.slice(PREFIX.length)] = t.wpml;
  for (const tax of t.taxonomies || []) if (typeof tax === "object") MODES[tax.name.slice(PREFIX.length)] = tax.wpml;
}

const declared = (tag) => Object.fromEntries([...xml.matchAll(new RegExp(`<${tag} ([^>]*)>([a-z_]+)</${tag}>`, "g"))].map((m) => [m[2], m[1].trim()]));

// Top-level keys of Settings::DEFAULTS (two tabs of indentation), minus Company::NOT_DATA.
const defaultsBody = settings.match(/const DEFAULTS = array\(([\s\S]*?)\n\t\);/)?.[1] ?? "";
const DEFAULT_KEYS = [...defaultsBody.matchAll(/^\t\t'([a-z_]+)'\s*=>/gm)].map((m) => m[1]);
const NOT_DATA = list(company, "NOT_DATA");
const DATA_KEYS = DEFAULT_KEYS.filter((k) => !NOT_DATA.includes(k));
const TRANSLATE = list(settings, "WPML_TRANSLATE");
const SAME = list(settings, "WPML_SAME");
const siteTexts = [...(xml.match(/<key name="estrategia_web_site">([\s\S]*?)<\/key>\s*(?=<key name=|<\/admin-texts>)/)?.[1] ?? "").matchAll(/<key name="([a-z_]+)"/g)].map((m) => m[1]);

test("the lists are read (if this fails, the test is not looking at anything)", () => {
  assert.equal(PREFIX, "eweb_");
  assert.ok(KEYS.includes("client") && KEYS.includes("portfolio_category"), KEYS.join(", "));
  assert.ok(TAXONOMIES.includes("client_sector"), TAXONOMIES.join(", "));
  assert.ok(DATA_KEYS.includes("schedule") && DATA_KEYS.includes("company_name") && !DATA_KEYS.includes("enabled_types"), DATA_KEYS.join(", "));
  assert.ok(siteTexts.length > 0, "no <key name=\"estrategia_web_site\"> in <admin-texts>");
});

test("every type and taxonomy of Type_Names has its template, display-as-translated (the 1.4.3 criterion, kept)", () => {
  assert.deepEqual(Object.keys(MODES).sort(), [...KEYS].sort());
  for (const [key, mode] of Object.entries(MODES)) assert.equal(mode, "display-as-translated", key);
});

test("the XML no longer declares those types, taxonomies or metas: the content-types module does, from each definition (two sources would fight)", () => {
  assert.deepEqual(Object.keys(declared("custom-type")).filter((n) => n.startsWith(PREFIX)), [], "<custom-types>");
  assert.deepEqual(Object.keys(declared("taxonomy")).filter((n) => n.startsWith(PREFIX)), [], "<taxonomies>");
  assert.doesNotMatch(xml, /<custom-field[^>]*>estrategia_web_(location|client|testimonial)_/, "<custom-fields>");
});

test("every translate attribute is one WPML's XSD admits (0 or 1): an invalid one drops the whole file's <admin-texts> silently", () => {
  const bad = [...xml.matchAll(/\btranslate="([^"]*)"/g)].map((m) => m[1]).filter((v) => !["0", "1"].includes(v));
  assert.deepEqual(bad, []);
  const dat = [...xml.matchAll(/\bdisplay-as-translated="([^"]*)"/g)].map((m) => m[1]).filter((v) => v !== "1");
  assert.deepEqual(dat, []);
});

test("every company detail is decided (Settings::WPML_TRANSLATE or WPML_SAME), and only once", () => {
  for (const key of DATA_KEYS) assert.ok(TRANSLATE.includes(key) || SAME.includes(key), `${key}: undecided in Settings (WPML_TRANSLATE or WPML_SAME)`);
  for (const key of [...TRANSLATE, ...SAME]) assert.ok(DATA_KEYS.includes(key), `${key}: not a company detail of Settings::DEFAULTS`);
  assert.deepEqual(TRANSLATE.filter((k) => SAME.includes(k)), [], "in both lists");
});

test("<admin-texts> offers exactly Settings::WPML_TRANSLATE of estrategia_web_site", () => {
  assert.deepEqual([...siteTexts].sort(), [...TRANSLATE].sort());
});

test("schedule, address and the WhatsApp message are translated; the phone and the brand are not (David's request, 16-10-2026)", () => {
  for (const k of ["schedule", "address", "whatsapp_message"]) assert.ok(TRANSLATE.includes(k), k);
  for (const k of ["company_name", "phone", "whatsapp", "email"]) assert.ok(SAME.includes(k), k);
});

test("the WhatsApp module's own message option is offered too", () => {
  assert.match(xml, /<key name="estrategia_web_whatsapp_message" \/>/);
  assert.doesNotMatch(xml, /<key name="estrategia_web_whatsapp_phone"/);
});
