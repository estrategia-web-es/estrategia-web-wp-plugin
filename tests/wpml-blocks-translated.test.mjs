// NIVEL: 2 — Playground WordPress with REAL WPML. NOT LEVEL 1 BECAUSE: what is tested is that WPML itself (its XSD, its block config, its string packages, its rebuild of the translation) understands the plugin's wpml-config.xml, and that only exists in a WordPress running WPML; reading the XML is what tests/wpml-blocks.test.mjs (level 1) already does. NOT LEVEL 3: everything checked is the HTML the server sends and the stored rows.
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
//  3. Translated, /en/<slug>/ shows the English texts and no Spanish one;
//     what is not declared arrives copied: the phone the same, and the select
//     with the SAME option preselected (defaultOption is a position).
//  4. The English page's form, sent: the acknowledgement has the default
//     subject («Site: title») with the title TRANSLATED and the field table
//     with the English labels, and the log stores lang=en.
// At the end, whatever happens, both pages and their package are deleted.
//
// Moved from Lavandería (tests/wpml-blocks.test.mjs) on 25-10-2026, when the
// plugin's test WordPress got WPML: same steps and assertions, on a page of
// its own instead of the site's. NEEDS the local WPML copy: without it, it
// FAILS (tests/run.mjs, «WPML»).
//
// How to maintain: a new block of the plugin with texts goes into CONTENT and
// EN; WHICH attributes are declared is watched by tests/wpml-blocks.test.mjs
// (level 1), this one checks that WPML understands them.
// The traps: WPML rebuilds the translation FROM THE ORIGINAL on every job, so
// a hand change to the English page lasts until the next update; and String
// Translation creates its packages table on the first wp-admin request, so
// the test opens wp-admin first (openWpmlScreen).
//
// Seen red (25-10-2026, here): expecting «Option three EWWB» preselected,
// step 3 fails («the select does not arrive with the second option
// preselected»); restored, green. And for real the same day, while porting:
// the test route ran as nobody, WPML's rebuild went through kses and the
// map's translated URLs were stored with «&amp;» (step 3, «the English page
// WPML stored lacks these translations»): fixed in the route, not here.
import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { reset, wp, testApi, BASE, wpml, requireWpml, openWpmlScreen } from "./lib.mjs";
import { loadForm, submit, mails, submissions, settings } from "../modules/forms/tests/lib.mjs";

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

const decode = (s) => s.replace(/&amp;/g, "&").replace(/&#0?38;/g, "&").replace(/&#0?39;|&#8217;/g, "'").replace(/&quot;/g, '"');
const blocks = (body) => wpml("/blocks", body);
let page;

before(async () => {
  await requireWpml();
  await reset();
  await openWpmlScreen("/wp-admin/");
  await wpml("/purge", { slugs: [SLUG, SLUG_EN] });
  const r = await wp("/pages", { method: "POST", body: { title: "Prueba bloques WPML", slug: SLUG, status: "publish", content: CONTENT } });
  assert.equal(r.status, 201, r.text);
  page = r.json;
  await settings({ min_seconds: 1, rate_limit: 0 });
  // A theme that declares «maps» (as Lavandería's): without a category the notice is off and the
  // «Cookie settings» link renders nothing.
  await testApi("/consent-setup", { method: "POST", body: { categories: ["maps"] } });
});

after(async () => {
  if (page) await blocks({ action: "delete", post_id: page.id });
});

let pkg;
test("1. the plugin's XML passes WPML's XSD and WPML has read its blocks", async () => {
  pkg = await blocks({ action: "package", post_id: page.id });
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

let path;
test("3. translated by WPML, /en/ shows it in English; the phone and the preselected option arrive copied", async () => {
  const tr = await blocks({ action: "translate", post_id: page.id, translations: EN, slug: SLUG_EN, title: "Blocks WPML test" });
  assert.ok(tr.translated >= Object.keys(EN).length, `only ${tr.translated} translations were saved`);
  // WPML rebuilt the English page with ALL the translations (also those not seen on the page).
  const content = tr.content.replace(/\\u0026/g, "&").replace(/\\u003c/g, "<").replace(/\\u003e/g, ">");
  const notStored = Object.values(EN).filter((en) => !content.includes(JSON.stringify(en).slice(1, -1)));
  assert.deepEqual(notStored, [], `the English page WPML stored lacks these translations: ${content.match(/<!-- wp:estrategia-web\/map [^>]*>/)?.[0]}`);
  path = new URL(tr.url).pathname;
  assert.match(path, /^\/en\//, `the translation is not in /en/: ${tr.url}`);
  const res = await fetch(BASE + path);
  assert.equal(res.status, 200, `${path} -> ${res.status}`);
  const text = decode(await res.text());
  const shown = (s) => text.includes(s) || text.includes(encodeURIComponent(s));
  // Not in the HTML: the success message and the title (server side; step 4) and the alt (the map has no image).
  const OFF_PAGE = [OPTIONS_ES, "Gracias, recibido EWWB", "Solicitud de prueba EWWB", "Fachada de la sede EWWB"];
  const notShown = Object.entries(EN).filter(([es]) => !OFF_PAGE.includes(es) && !es.startsWith("https://")).filter(([, en]) => !shown(en)).map(([, en]) => en);
  assert.deepEqual(notShown, [], `${path} does not show these translated texts`);
  const leftovers = Object.keys(EN).filter((es) => !es.startsWith("https://") && es.split("\n").some((line) => shown(line)));
  assert.deepEqual(leftovers, [], `${path} still shows Spanish texts`);
  assert.ok(text.includes("hl=en") && !text.includes("hl=es"), `${path}: the map does not carry the translated URL`);
  assert.ok(shown(PHONE) || text.includes(PHONE.replace(/\D/g, "")), `${path}: the phone did not arrive copied`);
  assert.match(text, /<option value="Option two EWWB" selected>/, `${path}: the select does not arrive with the second option preselected`);
});

test("4. the English page's form: acknowledgement with the translated title and labels, log with lang=en", async () => {
  const { hidden } = await loadForm(BASE + path);
  await new Promise((r) => setTimeout(r, 1100)); // min_seconds = 1: the fill time IS what the form measures.
  const sent = await submit(hidden, { nombre_ewwb: "Test Person", correo_ewwb: "ewwb@example.com", servicio_ewwb: "Option three EWWB", acepto_ewwb: "1" });
  assert.equal(sent.status, 200, JSON.stringify(sent.json));
  assert.equal(sent.json.message, "Thanks, received EWWB");
  const ack = (await mails()).find((m) => m.to.includes("ewwb@example.com"));
  assert.ok(ack, "no acknowledgement went out");
  assert.match(ack.subject, /: Test request EWWB$/, `the default subject does not carry the translated title: ${ack.subject}`);
  for (const s of ["Name EWWB", "Service EWWB", "Option three EWWB", "I accept the policy EWWB", ">Yes<"]) {
    assert.ok(ack.html.includes(s), `the default body of the acknowledgement lacks «${s}»`);
  }
  const log = (await submissions()).find((row) => row.form_id === "prueba-ewwb");
  assert.ok(log, "the submission is not in the log");
  assert.equal(log.lang, "en");
});
