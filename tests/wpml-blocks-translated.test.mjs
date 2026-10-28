// NIVEL: 2 — Playground WordPress with REAL WPML. NOT LEVEL 1 BECAUSE: what is tested is that WPML itself (its XSD, its block config, its string packages, its rebuild of the translation) understands the plugin's wpml-config.xml, and that only exists in a WordPress running WPML; reading the XML is what tests/wpml-blocks.test.mjs (level 1) already does. NOT LEVEL 3: everything checked is what WPML stores.
// WPML: REAL — runs against the WordPress with WPML (tests/run.mjs, «WPML»).
//
// THE TEXTS OF THE BLOCKS ARE TRANSLATED WITH THE PAGE (David, 17-10-2026:
// «the texts of blocks that the visitor reads have to be translated, no
// matter what»). Until 1.4.3 the XML only declared the forms: the WhatsApp
// message and label, the map's texts, the carousel's name, the cookie
// settings link and the form's title came out in Spanish in /en/.
//
// Creates a page with one block of each (texts tagged «EWWB» so they are not
// mistaken for anything else) and translates it THROUGH WPML'S FLOW, with its
// own hooks (route /blocks of tests/wp/estrategia-web-wpml-test.php):
// register the page's strings as when sending it to translation, save each
// translation, and say the job is done, which is when WPML rebuilds the
// English page from the original. Only the translation editor screen is
// skipped.
//  1. The plugin's XML passes WPML's XSD (otherwise WPML drops it whole,
//     silently) and WPML has read its blocks.
//  2. The translator sees every declared text and NOT what is not translated
//     (the phone, the form's keys, the recipient).
//  3. The English page WPML rebuilt carries every translation and no Spanish
//     text; what is not declared arrives copied: the phone the same, and the
//     select with the SAME option preselected (defaultOption is a position).
// The page, its translation and their package are deleted in the same
// request, whatever happens.
//
// 28-10-2026, from 115 s to one request. A request to the WPML WordPress
// costs 2,5-5 s and this test made ~17. Now /blocks-run does the reset, the
// purge, the page and the three /blocks steps inside ONE request (same
// routes, chained; it also creates String Translation's packages table, which
// made the wp-admin login of openWpmlScreen unnecessary). What was cut, and
// why it is not lost:
// - Rendering /en/ and reading its HTML: once WPML has stored the translated
//   attributes, showing them is our blocks' render.php, the same code in any
//   language (each block's own tests). WPML's routing of /en/ is WPML's.
//   What only WPML can break (the translations stored, nothing Spanish left,
//   the phone and defaultOption copied) is checked on the stored page.
// - Step 4, the English form sent (acknowledgement in English, log with
//   lang=en): modules/forms/tests/wpml.test.mjs, step 3, proves it with
//   String Translation and the same WPML.
// requireWpml() is only called when the route is missing (no WPML), for its
// message: the test still FAILS without WPML.
//
// How to maintain: a new block of the plugin with texts goes into CONTENT and
// EN; WHICH attributes are declared is watched by tests/wpml-blocks.test.mjs
// (level 1), this one checks that WPML understands them.
// The trap: WPML rebuilds the translation FROM THE ORIGINAL on every job, so
// a hand change to the English page lasts until the next update.
//
// Seen red (25-10-2026, here): expecting «Option three EWWB» preselected,
// step 3 fails («the select does not arrive with the second option
// preselected»); restored, green. And for real the same day, while porting:
// the test route ran as nobody, WPML's rebuild went through kses and the
// map's translated URLs were stored with «&amp;» (step 3, «the English page
// WPML stored lacks these translations»): fixed in the route, not here.
// Seen red (28-10-2026) with a config expected for a block that does not
// exist, with the WhatsApp message listed as not translated, and with
// defaultOption expected 3: each of the three steps fails.
import { test, before } from "node:test";
import assert from "node:assert/strict";
import { wpml, requireWpml } from "./lib.mjs";

const SLUG = "prueba-bloques-wpml";
const SLUG_EN = "blocks-wpml-test";
const OPTIONS_ES = "Opción uno EWWB\nOpción dos EWWB\nOpción tres EWWB";
const OPTIONS_EN = "Option one EWWB\nOption two EWWB\nOption three EWWB";

// Original (Spanish) → translation. The keys are exactly the attribute values.
const EN = {
  "Hola, quiero información EWWB": "Hello, I would like information EWWB",
  "Escríbenos por WhatsApp EWWB": "Write to us on WhatsApp EWWB",
  "Sede de prueba EWWB": "Test branch EWWB",
  "Calle de la Prueba 1, Palma EWWB": "1 Test Street, Palma EWWB",
  "Lunes a viernes, de 8 a 15 EWWB": "Monday to Friday, 8 am to 3 pm EWWB",
  "Fachada de la sede EWWB": "Front of the branch EWWB",
  "https://www.google.com/maps/embed?pb=ewwb&hl=es": "https://www.google.com/maps/embed?pb=ewwb&hl=en",
  "https://www.google.com/maps?q=ewwb&hl=es": "https://www.google.com/maps?q=ewwb&hl=en",
  "Carrusel de prueba EWWB": "Test carousel EWWB",
  "Diapositiva EWWB": "Slide EWWB",
  "Configurar cookies EWWB": "Cookie settings EWWB",
  "Solicitud de prueba EWWB": "Test request EWWB",
  "Gracias, recibido EWWB": "Thanks, received EWWB",
  "Nombre EWWB": "Name EWWB",
  "Tu nombre EWWB": "Your name EWWB",
  "Correo EWWB": "Email EWWB",
  "Servicio EWWB": "Service EWWB",
  "Elige uno EWWB": "Choose one EWWB",
  [OPTIONS_ES]: OPTIONS_EN,
  "Acepto la política EWWB": "I accept the policy EWWB",
  "Enviar solicitud EWWB": "Send request EWWB",
};
const PHONE = "+34 600 111 222";
const NOT_OFFERED = [PHONE, "prueba-ewwb", "nombre_ewwb", "{{correo_ewwb}}", "Aviso EWWB"];

const block = (name, attrs, inner) =>
  inner === undefined ? `<!-- wp:${name} ${JSON.stringify(attrs)} /-->` : `<!-- wp:${name} ${JSON.stringify(attrs)} -->${inner}<!-- /wp:${name} -->`;
const CONTENT = [
  block("estrategia-web/whatsapp", { phone: PHONE, message: "Hola, quiero información EWWB", label: "Escríbenos por WhatsApp EWWB" }),
  block("estrategia-web/map", {
    placeName: "Sede de prueba EWWB",
    address: "Calle de la Prueba 1, Palma EWWB",
    phone: PHONE,
    hours: "Lunes a viernes, de 8 a 15 EWWB",
    imageAlt: "Fachada de la sede EWWB",
    embedUrl: "https://www.google.com/maps/embed?pb=ewwb&hl=es",
    mapsUrl: "https://www.google.com/maps?q=ewwb&hl=es",
  }),
  block("estrategia-web/carousel", { label: "Carrusel de prueba EWWB" }, "<!-- wp:paragraph --><p>Diapositiva EWWB</p><!-- /wp:paragraph -->"),
  block("estrategia-web-site/consent", { label: "Configurar cookies EWWB" }),
  block(
    "estrategia-web-forms/form",
    { formId: "prueba-ewwb", title: "Solicitud de prueba EWWB", successMessage: "Gracias, recibido EWWB" },
    [
      block("estrategia-web-forms/text", { label: "Nombre EWWB", name: "nombre_ewwb", required: true, placeholder: "Tu nombre EWWB" }),
      block("estrategia-web-forms/email", { label: "Correo EWWB", name: "correo_ewwb", required: true }),
      block("estrategia-web-forms/select", { label: "Servicio EWWB", name: "servicio_ewwb", placeholder: "Elige uno EWWB", options: OPTIONS_ES, defaultOption: 2 }),
      block("estrategia-web-forms/checkbox", { label: "Acepto la política EWWB", name: "acepto_ewwb", required: true }),
      block("estrategia-web-forms/submit", { text: "Enviar solicitud EWWB" }),
      block("estrategia-web-forms/notification", { label: "Aviso EWWB", to: "{{correo_ewwb}}" }),
    ].join("\n")
  ),
].join("\n\n");

// A string as it sits inside the stored block markup (JSON-escaped: «\n», quotes).
const inMarkup = (s) => JSON.stringify(s).slice(1, -1);
let pkg, tr, content;

before(async () => {
  ({ package: pkg, translation: tr } = await wpml("/blocks-run", { slug: SLUG, title: "Prueba bloques WPML", slug_en: SLUG_EN, title_en: "Blocks WPML test", content: CONTENT, translations: EN }).catch(async (e) => {
    await requireWpml();
    throw e;
  }));
  content = tr.content.replace(/\\u0026/g, "&").replace(/\\u003c/g, "<").replace(/\\u003e/g, ">");
});

test("1. the plugin's XML passes WPML's XSD and WPML has read its blocks", () => {
  assert.ok(pkg.xsd.valid, `the plugin's wpml-config.xml does NOT pass WPML's XSD (WPML would drop it whole): ${pkg.xsd.errors.join(" | ")}`);
  for (const b of ["estrategia-web/whatsapp", "estrategia-web/map", "estrategia-web/carousel", "estrategia-web-site/consent", "estrategia-web-forms/form"]) {
    assert.ok(pkg.config[b], `WPML has no config for ${b}: ${Object.keys(pkg.config).join(", ")}`);
  }
});

test("2. what the translator would see: every declared text, and nothing that is not translated", () => {
  const offered = pkg.strings.map((s) => s.value);
  const missing = Object.keys(EN).filter((v) => !offered.includes(v));
  assert.deepEqual(missing, [], `WPML does not offer these texts for translation; it offers: ${JSON.stringify(offered)}`);
  for (const v of NOT_OFFERED) assert.ok(!offered.includes(v), `«${v}» is NOT translated and WPML offers it`);
});

test("3. translated by WPML, the English page carries every translation and nothing Spanish; the phone and the preselected option arrive copied", () => {
  assert.ok(tr.translated >= Object.keys(EN).length, `only ${tr.translated} translations were saved`);
  assert.match(new URL(tr.url).pathname, /^\/en\//, `the translation is not in /en/: ${tr.url}`);
  // WPML rebuilt the English page with ALL the translations (also those not seen on the page).
  const notStored = Object.values(EN).filter((en) => !content.includes(inMarkup(en)));
  assert.deepEqual(notStored, [], `the English page WPML stored lacks these translations: ${content.match(/<!-- wp:estrategia-web\/map [^>]*>/)?.[0]}`);
  const leftovers = Object.keys(EN).filter((es) => content.includes(inMarkup(es)));
  assert.deepEqual(leftovers, [], "the English page WPML stored still has Spanish texts");
  assert.ok(content.includes(inMarkup(PHONE)), "the phone did not arrive copied");
  assert.match(content, /<!-- wp:estrategia-web-forms\/select \{[^}]*"defaultOption":2[,}]/, "the select does not arrive with the second option preselected (defaultOption is a position)");
});
