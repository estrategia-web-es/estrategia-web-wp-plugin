// NIVEL: 2 — Playground WordPress with REAL WPML. NOT LEVEL 1 BECAUSE: what is tested is a submission of the form on the ENGLISH page of a WordPress running WPML — the language WPML gives the request, the settings' texts registered and translated in String Translation, the .mo of the plugin — and that only exists there (class-language.php had never run against real WPML until 17-10-2026). NOT LEVEL 3: the form is sent as its JavaScript sends it (the JSON route), and what is checked is the answer, the emails and the log.
// WPML: REAL — runs against the WordPress with WPML (tests/run.mjs, «WPML»).
//
// THE FORM WORKS IN ENGLISH (David, 17-10-2026: «the form also has to work in
// another language»). A contact page and its English translation (made with
// WPML, with the English attributes a translator would write), and:
//  1. the global texts of the settings (Forms › Settings: the success
//     message and the email footer), once written, appear in String
//     Translation (domain estrategia-web-forms) as SPANISH originals, and the
//     empty one (error message) does not;
//  2. a submission with errors in /en/: 422, the general message and each
//     field's in English (from the .po);
//  3. a good one in /en/: the success message is the String Translation
//     TRANSLATION; the acknowledgement goes out with its English subject and
//     body (block attributes, translated with the page), the field labels in
//     English, «Yes» for the checkbox and the translated footer; the log
//     stores lang=en and every notification as sent; and the same on the
//     Spanish page, as a control (Spanish, lang=es and the original footer):
//     without it, a green English case could mean everything comes out in
//     English.
// Nothing is removed at the end since round 2 (28-10-2026): the next run's
// seed unregisters the two strings (their translations go with them) and
// purges the pages; no other test reads the forms' GLOBAL texts in English
// (tests/wpml-blocks-translated.test.mjs gives its form its own message).
//
// A request to the WPML WordPress costs 3-6 s, so (28-10-2026, from ~25
// requests to ~9, in 5 rounds of two at once): the reset, the settings,
// the purge, the page and its translation are ONE request (seed() in
// lib.mjs: the settings are saved there, which is what registers their
// strings), at once with requireWpml(); the String
// Translation reads and writes go in one (/strings), at once with loading
// the English form; the Spanish one loads at once with the bad submission;
// the two good submissions (English and the Spanish
// control) are one test, sent at once, each answering the emails and the
// log itself (round 2: 4 rounds).
//
// Moved from Lavandería (tests/forms-wpml.test.mjs) on 25-10-2026, where it
// sent the form of its /en/contact/ (the loader's translation); here the two
// pages are the test's own. NEEDS the local WPML copy (with String
// Translation): without it, it FAILS (tests/run.mjs, «WPML»).
//
// What it found on its first day (17-10-2026): the translated success
// message never showed in /en/. The plugin registered the settings' texts
// without a language and WPML took them as ENGLISH (it does with any domain
// that is not admin_texts_…): nothing to translate into English. Fixed in
// Language::register_string; step 1 watches it through the string's
// original language.
// The traps: this test does NOT put the forms en_US marker (seed({marker:
// false})) — here WPML says the language; and String Translation builds no
// .mo until its screen is opened once: tests/run.mjs opens it when it boots
// the WPML WordPress (warmWpml(), 28-10-2026), so this file no longer logs in
// to open it (two rounds less, and its «TIEMPO: 60 s» gone). Against a WPML
// WordPress not started by the runner, open it once by hand
// (openWpmlScreen() in tests/lib.mjs).
//
// Seen red (25-10-2026): expecting «Hello Test Person!» in the
// acknowledgement, step 3 fails («the acknowledgement's body is not the
// English one»); restored, green. Seen red (28-10-2026), every test:
// Language::register_string() registering as «en» (steps 1 and 3; step 1
// sees it because the seed unregisters its strings first: the row a previous
// run left kept it green before), and Language::switch_to() switching to
// «es» and es_ES whatever the language (steps 2 and 3).
// Seen red again after round 2 (28-10-2026), every test, with the mutants above (one by one where a test reads what the one before left).
import { test, before } from "node:test";
import assert from "node:assert/strict";
import { requireWpml } from "../../../tests/lib.mjs";
import { seed, loadForm, submit, testApi, TEAM } from "./lib.mjs";

const CONTEXT = "estrategia-web-forms";
const strings = (list) => testApi("/strings", { method: "POST", body: list.map((s) => ({ context: CONTEXT, ...s })) });
const SUCCESS_ES = "Gracias, hemos recibido tu mensaje (prueba forms-wpml)";
const SUCCESS_EN = "Thank you, we have your message (forms-wpml test)";
const FOOTER_ES = "Pie de prueba forms-wpml";
const FOOTER_EN = "forms-wpml test footer";
const ACK_SUBJECT = "We have received your request";

const form = (t) => `<!-- wp:estrategia-web-forms/form {"formId":"contacto-wpml","title":"${t.title}"} -->
<!-- wp:estrategia-web-forms/text {"label":"${t.name}","name":"nombre","required":true} /-->
<!-- wp:estrategia-web-forms/email {"label":"${t.email}","name":"email","required":true} /-->
<!-- wp:estrategia-web-forms/tel {"label":"${t.phone}","name":"telefono","required":true} /-->
<!-- wp:estrategia-web-forms/textarea {"label":"${t.detail}","name":"detalle"} /-->
<!-- wp:estrategia-web-forms/checkbox {"label":"${t.consent}","name":"privacidad","required":true} /-->
<!-- wp:estrategia-web-forms/submit {"text":"${t.send}"} /-->
<!-- wp:estrategia-web-forms/notification {"label":"Equipo","to":"${TEAM}","subject":"Nuevo contacto"} /-->
<!-- wp:estrategia-web-forms/notification {"label":"Acuse","to":"{{email}}","subject":"${t.ackSubject}","body":"${t.ackBody}\\n\\n{{all_fields}}"} /-->
<!-- /wp:estrategia-web-forms/form -->`;
const ES = { title: "Contacto", name: "Nombre completo", email: "Correo", phone: "Teléfono", detail: "Detalle", consent: "Acepto la política de privacidad", send: "Enviar", ackSubject: "Hemos recibido tu solicitud", ackBody: "Hola {{nombre}}, gracias por escribir." };
const EN = { title: "Contact", name: "Full name", email: "Email", phone: "Phone", detail: "Details", consent: "I accept the privacy policy", send: "Send", ackSubject: ACK_SUBJECT, ackBody: "Hello {{nombre}}, thank you for writing." };
const good = (email) => ({ nombre: "Test Person", email, telefono: "+34 600 000 000", detalle: "Line one", privacidad: "1" });
let es, en, registered;
const forms = {};

// Two requests at a time is what the WordPress serves (two workers): each round below is one.
before(async () => {
  // The check and the seed; requireWpml()'s verdict first (without WPML, its message is the one that matters).
  const [wpmlState, seeded] = await Promise.allSettled([
    requireWpml(),
    seed({
      marker: false,
      // Unregistered first: step 1 must see how the save registers them, not what a previous run left.
      unregister: ["Default success message", "Email footer", "Default error message"],
      settings: { success_message: SUCCESS_ES, email_footer: FOOTER_ES },
      purge: ["contacto-wpml", "contact-wpml"],
      pages: [{ key: "es", slug: "contacto-wpml", title: "Contacto", content: form(ES), translation: { title: "Contact", slug: "contact-wpml", content: form(EN) } }],
    }),
  ]);
  for (const r of [wpmlState, seeded]) if (r.status === "rejected") throw r.reason;
  const { pages } = seeded.value;
  es = pages.es.url;
  en = pages.es.translation.url;
  assert.match(new URL(en).pathname, /^\/en\//);
  // What String Translation holds BEFORE translating (step 1 asserts it) and the two translations, at once
  // with loading the English form (its HTML does not depend on String Translation; the submissions do).
  [[registered], forms.en] = await Promise.all([
    strings([{ name: "Default success message" }, { name: "Default success message", value: SUCCESS_EN }, { name: "Email footer", value: FOOTER_EN }]),
    loadForm(en),
  ]);
});


test("1. the written settings texts are in String Translation, as Spanish originals; the empty one is not", () => {
  const info = registered;
  assert.ok(info.id, `no «Default success message» in String Translation (${CONTEXT}); there are: ${info.strings.join(", ")}`);
  assert.equal(info.language, "es", "the text was registered as another language: WPML would not translate it into English");
  assert.ok(info.strings.includes("Email footer"), `«Email footer» is missing: ${info.strings.join(", ")}`);
  assert.ok(!info.strings.includes("Default error message"), "the error message is empty and was registered anyway");
});

test("2. with errors, in English", async () => {
  const { html, hidden } = forms.en;
  // At once, the Spanish form for the control of step 3.
  let bad;
  [bad, forms.es] = await Promise.all([submit(hidden, { nombre: "Test Person", email: "not-an-email" }), loadForm(es)]);
  assert.match(html, /Full name/, `${en}: the form is not in English`);
  assert.equal(bad.status, 422, JSON.stringify(bad.json));
  assert.equal(bad.json.message, "The message could not be sent. Please check the form and try again.");
  assert.equal(bad.json.errors.email, "Please enter a valid email address.");
  assert.equal(bad.json.errors.telefono, "This field is required.");
  assert.equal(bad.json.errors.privacidad, "You must check this box to continue.");
});

test("3. a good one, in English: translated success message, English acknowledgement, log with lang=en; and the Spanish page as control", async () => {
  // Each answers the emails and the log as they are when it ends (submit(…, { state: true })): no read after them.
  const [sentEn, sentEs] = await Promise.all([submit(forms.en.hidden, good("visitor-en@example.com"), { state: true }), submit(forms.es.hidden, good("visitor-es@example.com"), { state: true })]);
  const mails = [...sentEn.state.mails, ...sentEs.state.mails];
  const submissions = [...sentEn.state.submissions, ...sentEs.state.submissions];
  const ackTo = (email) => mails.find((m) => m.to.includes(email));
  const logOf = (email) => submissions.find((row) => row.form_id === "contacto-wpml" && JSON.stringify(row).includes(email));

  assert.equal(sentEn.status, 200, JSON.stringify(sentEn.json));
  assert.equal(sentEn.json.message, SUCCESS_EN, "the success message is not the String Translation translation");
  const ack = ackTo("visitor-en@example.com");
  assert.ok(ack, "no acknowledgement to the sender");
  assert.equal(ack.subject, ACK_SUBJECT);
  assert.match(ack.html, /Hello Test Person/, "the acknowledgement's body is not the English one");
  assert.match(ack.html, /Full name/, "the field table does not carry the English labels");
  assert.match(ack.html, />Yes</, "the checked box does not come out as «Yes»");
  assert.ok(ack.html.includes(FOOTER_EN), "the email footer is not the translation");
  assert.ok(!ack.html.includes(FOOTER_ES) && !/>Sí</.test(ack.html), "the English acknowledgement carries Spanish texts");
  const log = logOf("visitor-en@example.com");
  assert.ok(log, "the submission is not in the log");
  assert.equal(log.lang, "en", `the log stores lang=${log.lang}`);
  assert.ok(log.notifications.every((n) => n.status === "sent"), JSON.stringify(log.notifications));

  // The control, in Spanish.
  assert.equal(sentEs.status, 200, JSON.stringify(sentEs.json));
  assert.equal(sentEs.json.message, SUCCESS_ES);
  const ackEs = ackTo("visitor-es@example.com");
  assert.ok(ackEs && ackEs.html.includes(FOOTER_ES) && />Sí</.test(ackEs.html), "the Spanish acknowledgement lacks the original footer or the «Sí»");
  assert.equal(logOf("visitor-es@example.com")?.lang, "es");
});
