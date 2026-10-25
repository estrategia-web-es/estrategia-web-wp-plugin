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
//     stores lang=en and every notification as sent;
//  4. the same on the Spanish page, as a control: Spanish, lang=es and the
//     original footer. Without it, a green step 3 could mean everything comes
//     out in English.
// At the end, whatever happens, the translations are removed (the pages
// stay until the next run purges them).
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
// The traps: this test does NOT use this module's reset() of lib.mjs, which
// switches the site to en_US (the «forms» marker) — here WPML says the
// language; and String Translation builds no .mo until its screen is opened
// once (openWpmlScreen).
//
// Seen red (25-10-2026): expecting «Hello Test Person!» in the
// acknowledgement, step 3 fails («the acknowledgement's body is not the
// English one»); restored, green.
import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { reset as coreReset, wp, wpml, requireWpml, openWpmlScreen } from "../../../tests/lib.mjs";
import { testApi, settings, mails, submissions, loadForm, submit, TEAM } from "./lib.mjs";

const CONTEXT = "estrategia-web-forms";
const string = (body) => wpml("/string", { context: CONTEXT, ...body });
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
const send = async (url, fields) => {
  const { html, hidden } = await loadForm(url);
  await new Promise((r) => setTimeout(r, 1100)); // min_seconds = 1: the fill time IS what the form measures.
  return { html, ...(await submit(hidden, fields)) };
};
let es;
let en;

before(async () => {
  await requireWpml();
  // This module's own reset (mails, log, settings), then the core one: the core one removes the «forms» marker (en_US).
  await testApi("/reset", { method: "POST" });
  await coreReset();
  await wpml("/purge", { slugs: ["contacto-wpml", "contact-wpml"] });
  const r = await wp("/pages", { method: "POST", body: { title: "Contacto", slug: "contacto-wpml", status: "publish", content: form(ES) } });
  assert.equal(r.status, 201, r.text);
  const tr = await wpml("/translate", { kind: "post", type: "page", original: r.json.id, title: "Contact", slug: "contact-wpml", content: form(EN) });
  es = r.json.link;
  en = tr.url;
  assert.match(new URL(en).pathname, /^\/en\//);
});

after(async () => {
  await string({ name: "Default success message", delete: true }).catch(() => {});
  await string({ name: "Email footer", delete: true }).catch(() => {});
});

test("1. the written settings texts are in String Translation, as Spanish originals; the empty one is not", async () => {
  await settings({ min_seconds: 1, rate_limit: 0, success_message: SUCCESS_ES, email_footer: FOOTER_ES });
  const info = await string({ name: "Default success message" });
  assert.ok(info.id, `no «Default success message» in String Translation (${CONTEXT}); there are: ${info.strings.join(", ")}`);
  assert.equal(info.language, "es", "the text was registered as another language: WPML would not translate it into English");
  assert.ok(info.strings.includes("Email footer"), `«Email footer» is missing: ${info.strings.join(", ")}`);
  assert.ok(!info.strings.includes("Default error message"), "the error message is empty and was registered anyway");
  await openWpmlScreen();
  await string({ name: "Default success message", value: SUCCESS_EN });
  await string({ name: "Email footer", value: FOOTER_EN });
});

test("2. with errors, in English", async () => {
  const bad = await send(en, { nombre: "Test Person", email: "not-an-email" });
  assert.match(bad.html, /Full name/, `${en}: the form is not in English`);
  assert.equal(bad.status, 422, JSON.stringify(bad.json));
  assert.equal(bad.json.message, "The message could not be sent. Please check the form and try again.");
  assert.equal(bad.json.errors.email, "Please enter a valid email address.");
  assert.equal(bad.json.errors.telefono, "This field is required.");
  assert.equal(bad.json.errors.privacidad, "You must check this box to continue.");
});

test("3. a good one, in English: translated success message, English acknowledgement, log with lang=en", async () => {
  const sent = await send(en, good("visitor-en@example.com"));
  assert.equal(sent.status, 200, JSON.stringify(sent.json));
  assert.equal(sent.json.message, SUCCESS_EN, "the success message is not the String Translation translation");
  const ack = (await mails()).find((m) => m.to.includes("visitor-en@example.com"));
  assert.ok(ack, "no acknowledgement to the sender");
  assert.equal(ack.subject, ACK_SUBJECT);
  assert.match(ack.html, /Hello Test Person/, "the acknowledgement's body is not the English one");
  assert.match(ack.html, /Full name/, "the field table does not carry the English labels");
  assert.match(ack.html, />Yes</, "the checked box does not come out as «Yes»");
  assert.ok(ack.html.includes(FOOTER_EN), "the email footer is not the translation");
  assert.ok(!ack.html.includes(FOOTER_ES) && !/>Sí</.test(ack.html), "the English acknowledgement carries Spanish texts");
  const log = (await submissions()).find((row) => row.form_id === "contacto-wpml" && JSON.stringify(row).includes("visitor-en@example.com"));
  assert.ok(log, "the submission is not in the log");
  assert.equal(log.lang, "en", `the log stores lang=${log.lang}`);
  assert.ok(log.notifications.every((n) => n.status === "sent"), JSON.stringify(log.notifications));
});

test("4. control, in Spanish: Spanish texts, lang=es and the original footer", async () => {
  const sent = await send(es, good("visitor-es@example.com"));
  assert.equal(sent.status, 200, JSON.stringify(sent.json));
  assert.equal(sent.json.message, SUCCESS_ES);
  const ack = (await mails()).find((m) => m.to.includes("visitor-es@example.com"));
  assert.ok(ack && ack.html.includes(FOOTER_ES) && />Sí</.test(ack.html), "the Spanish acknowledgement lacks the original footer or the «Sí»");
  const log = (await submissions()).find((row) => row.form_id === "contacto-wpml" && JSON.stringify(row).includes("visitor-es@example.com"));
  assert.equal(log?.lang, "es");
});
