// NIVEL: 2 — Playground WordPress with REAL WPML. NOT LEVEL 1 BECAUSE: what is tested is that WPML itself reads the plugin's wpml-config.xml (<custom-types>, <taxonomies>, <admin-texts>) and that a company detail translated in String Translation reaches /en/; that only exists in a WordPress running WPML. That the XML declares what the code registers is wpml-config.test.mjs (level 1). NOT LEVEL 3: everything checked is the HTML the server sends and what WPML stored.
// WPML: REAL — runs against the WordPress with WPML (tests/run.mjs, «WPML»).
//
// THE COMPANY DETAILS AND THE TYPES, TRANSLATABLE FROM THE PLUGIN (1.4.3).
// Until 1.4.2 the plugin declared none of this: the types' modes were set by
// hand by Lavandería's loader and WPML did not offer to translate the
// address, the hours or the WhatsApp message of «Company details».
//  1. After re-reading the wpml-config.xml files (as WPML does when one of
//     its screens opens), the plugin's types and taxonomy are in mode 2
//     (display as translated) and LOCKED on WPML's screen (a file declares
//     them).
//  2. String Translation has the strings of estrategia_web_site (address,
//     registry, hours, WhatsApp message) and NOT those that are not
//     translated (phone, company name, tax ID).
//  3. With the address and the message translated, the English page shows
//     them — the address through [estrategia_web_company], the message in the
//     WhatsApp button bound to the company data (Block Bindings, as
//     Lavandería's footer) — and the Spanish one keeps the originals.
//  4. /wp/v2/settings without ?lang returns the ORIGINAL (what a content
//     loader reads and writes: if it returned the translation, the next load
//     would store it as the original).
//  5. With the translation removed, /en/ shows the original again.
//
// Moved from Lavandería (tests/company-data-wpml.test.mjs) on 25-10-2026: the
// same steps on a legal page of its own («Nave 14» → «Unit 14») instead of
// Lavandería's /aviso-legal/ and footer. NEEDS the local WPML copy (with
// String Translation): without it, it FAILS (tests/run.mjs, «WPML»).
//
// How to maintain: a new detail in <admin-texts> needs no change here
// (wpml-config.test.mjs compares the XML with the code); this changes only if
// HOW the detail reaches the page changes.
// The traps (16-10-2026, in Lavandería):
// - With translate="2" in the plugin's XML, WPML set the types to 2 but its
//   XSD rejects it and String Translation dropped the file whole: ZERO
//   strings, no error. Step 2 catches it.
// - In a fresh WPML, String Translation builds no .mo until someone opens
//   its screen: the translation is in the database and the site does not
//   show it. That is why step 3 opens the screen first, as a translator would.
// - WPML registers the strings of an OPTION only if it exists when it
//   re-reads the files: the test saves every key first.
//
// Seen red (25-10-2026): expecting «Unit 15» in /en/, step 3 fails («the
// address is not translated»); restored, green.
import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { reset, wp, saveCompany, html, wpml, requireWpml, openWpmlScreen } from "../../../tests/lib.mjs";

const CONTEXT = "admin_texts_estrategia_web_site";
const name = (key) => `[estrategia_web_site]${key}`;
const string = (body) => wpml("/string", { context: CONTEXT, ...body });
const ADDRESS = "Calle Camp Franc, 11, Nave 14\n07199 Palma";
const ADDRESS_EN = "Calle Camp Franc, 11, Unit 14\n07199 Palma";
const MESSAGE_ES = "Hola, os escribo desde la web";
const MESSAGE_EN = "Hi, I am writing from the website";
const bind = (key) => ({ source: "estrategia-web-site/company", args: { key } });
const CONTENT = `<!-- wp:paragraph --><p class="ew-address">[estrategia_web_company key="address"]</p><!-- /wp:paragraph -->
<!-- wp:estrategia-web/whatsapp ${JSON.stringify({ metadata: { bindings: { phone: bind("whatsapp"), message: bind("whatsapp_message") } } })} /-->`;
const decode = (s) => s.replace(/&amp;/g, "&").replace(/&#0?39;|&#8217;/g, "'");
let es;
let en;

before(async () => {
  await requireWpml();
  await reset();
  await wpml("/purge", { slugs: ["aviso-legal-prueba", "legal-notice-test"] });
  // Every key, also the empty ones (schedule): WPML only registers the strings of an option that exists.
  // The three types registered (as on a site that uses them), so WPML has them to apply its config to.
  await saveCompany({ enabled_types: ["client", "testimonial", "location"], company_name: "Lavandería de pruebas", legal_name: "Pruebas S.L.", tax_id: "B00000000", phone: "971 00 00 00", whatsapp: "+34 600 111 222", address: ADDRESS, registry: "Registro Mercantil de Palma, tomo 1", schedule: "", whatsapp_message: "" });
  const r = await wp("/pages", { method: "POST", body: { title: "Aviso legal", slug: "aviso-legal-prueba", status: "publish", content: CONTENT } });
  assert.equal(r.status, 201, r.text);
  const tr = await wpml("/translate", { kind: "post", type: "page", original: r.json.id, title: "Legal notice", slug: "legal-notice-test", content: CONTENT });
  es = r.json.link;
  en = tr.url;
});

after(async () => {
  await string({ name: name("address"), delete: true }).catch(() => {});
  await string({ name: name("whatsapp_message"), delete: true }).catch(() => {});
});

const page = async (url) => {
  const r = await html(url);
  assert.equal(r.status, 200, `${url} -> ${r.status}`);
  return decode(r.text);
};

let info;
test("1. the plugin's types and taxonomy: in mode 2 and locked (WPML read the plugin's wpml-config.xml)", async () => {
  info = await string({ name: name("address") });
  for (const t of ["eweb_client", "eweb_testimonial", "eweb_location"]) {
    assert.equal(Number(info.types[t]), 2, `${t}: mode ${info.types[t]} (2 expected, display as translated)`);
  }
  assert.equal(Number(info.taxonomies.eweb_client_sector), 2, `eweb_client_sector: mode ${info.taxonomies.eweb_client_sector}`);
  const locked = JSON.stringify(info.locked);
  for (const t of ["eweb_client", "eweb_location", "eweb_client_sector"]) {
    assert.ok(locked.includes(t), `${t} is not locked in WPML (did it not read the plugin's wpml-config.xml?): ${locked}`);
  }
});

test("2. String Translation: the translatable company details are there, the others are not", async () => {
  assert.ok(info.id, `no string ${name("address")} in String Translation; there are: ${info.strings.join(", ")}`);
  for (const k of ["registry", "schedule", "whatsapp_message"]) {
    assert.ok(info.strings.includes(name(k)), `${name(k)} is missing: ${info.strings.join(", ")}`);
  }
  for (const k of ["phone", "company_name", "tax_id"]) {
    assert.ok(!info.strings.includes(name(k)), `${k} is NOT translated and it is in String Translation`);
  }
});

test("3. translated, the address (shortcode) and the WhatsApp message (binding) show in English, and only there", async () => {
  await saveCompany({ whatsapp_message: MESSAGE_ES });
  // As a translator would: until String Translation's screen is opened once, a fresh WPML builds no .mo (see the header).
  await openWpmlScreen();
  await string({ name: name("address"), value: ADDRESS_EN });
  const msg = await string({ name: name("whatsapp_message"), value: MESSAGE_EN });
  assert.ok(msg.id, `${name("whatsapp_message")} was not registered when it got a value: ${msg.strings.join(", ")}`);
  const english = await page(en);
  const spanish = await page(es);
  assert.match(english, /Unit 14/, `${en}: the address is not translated`);
  assert.doesNotMatch(english, /Nave 14/, `${en}: the Spanish address is still there`);
  assert.match(spanish, /Nave 14/, `${es}: the original address is gone`);
  assert.ok(english.includes(`?text=${encodeURIComponent(MESSAGE_EN)}`), `${en}: the WhatsApp button does not carry the translated message`);
  assert.ok(spanish.includes(`?text=${encodeURIComponent(MESSAGE_ES)}`), `${es}: the WhatsApp button does not carry the original message`);
});

test("4. /wp/v2/settings without ?lang returns the original", async () => {
  const now = (await wp("/settings")).json.estrategia_web_site;
  assert.equal(now.address, ADDRESS, "/wp/v2/settings returns the translated address: a loader would store it as the original");
  assert.equal(now.whatsapp_message, MESSAGE_ES);
});

test("5. with the translation removed, /en/ shows the original again", async () => {
  await string({ name: name("address"), delete: true });
  assert.match(await page(en), /Nave 14/, `${en}: with the translation removed, the English one still shows`);
});
