// NIVEL: 2 — Playground WordPress. NOT LEVEL 1 BECAUSE: what is tested is that WordPress, in es_ES, loads the plugin's .l10n.php for the domain estrategia-web: in the page it renders, in the submission endpoint's answers, in the log and the email, and in what it hands the block editor (the inline wp.i18n locale data and the block definitions). Only exists with WordPress choosing and loading the translation files.
//
// Puts the site in es_ES (the forms test locale; WPLANG needs core's language
// pack, which the test WordPress does not have) and checks that the plugin's
// .l10n.php (estrategia-web domain, one for everything) is loaded on the
// server and for the editor: form default texts, server errors, the recorded
// language, the email's «Sí» and the editor texts. No WPML: the language is
// the WordPress locale.
//
// Level 3 until 28-10-2026: it booted the editor to ask wp.i18n. What OUR
// code decides is that the editor gets the domain's translations
// (wp_set_script_translations) and block.json's texts translated; both are
// in the editor page the server sends, read here as HTML. That wp.i18n then
// uses them is WordPress's.
//
// The locale it forces is removed by any reset, the core one included
// (since 28-10-2026): no after(). Round 2: the bad and the good submission
// go at once, and the good one answers the emails and the log itself.
//
// Seen red (28-10-2026), both tests: the submit button's default text not
// passed through __() (server) and the Form block.json with another
// textdomain (the block's title); and, alone, «Email notification»
// translated otherwise in the es_ES .l10n.php (the editor script's data).
// Seen red again after round 2 (28-10-2026), every test, with the mutants above (one by one where a test reads what the one before left).
import { test, before } from "node:test";
import assert from "node:assert/strict";
import { adminSession } from "../../../tests/lib.mjs";
import { seed, loadForm, submit, waitMinTime } from "./lib.mjs";

let page, form, editor;
before(async () => {
  const [{ pages }, s] = await Promise.all([
    seed({
      locale: "es_ES",
      pages: [{ key: "es", slug: "es", content: `<!-- wp:estrategia-web-forms/form {"formId":"es"} --><!-- wp:estrategia-web-forms/text {"label":"Nombre","name":"nombre","required":true} /--><!-- wp:estrategia-web-forms/checkbox {"name":"privacidad","consent":true} /--><!-- wp:estrategia-web-forms/submit /--><!-- wp:estrategia-web-forms/notification {"to":"equipo@ejemplo.test"} /--><!-- /wp:estrategia-web-forms/form -->` }],
    }),
    adminSession(),
  ]);
  page = pages.es;
  [form, { text: editor }] = await Promise.all([loadForm(page.url), s.get("/wp-admin/post-new.php?post_type=page")]);
});

test("server in es_ES: default texts, errors, recorded language and email", async () => {
  const { html, hidden } = form;
  // assert.ok and not assert.match: if it fails, do not dump 60 KB of HTML.
  assert.ok(/>Enviar<\/button>/.test(html), "button without its own text = «Enviar» from the .l10n.php");
  assert.ok(/He leído y acepto la política de privacidad\./.test(html), "default consent in Spanish");

  await waitMinTime();
  // Both at once; the good one answers the emails and the log itself.
  const [bad, ok] = await Promise.all([submit(hidden, { nombre: "" }), submit(hidden, { nombre: "Ana", privacidad: "1" }, { state: true })]);
  assert.equal(bad.json.errors.nombre, "Este campo es obligatorio.");
  assert.equal(bad.json.message, "No se ha podido enviar el mensaje. Revisa el formulario y vuelve a intentarlo.");
  assert.equal(ok.json.message, "Gracias. Tu mensaje se ha enviado.");
  const { submissions: [row], mails: [mail] } = ok.state;
  assert.equal(row.lang, "es_ES", "the submission's language is recorded");
  assert.match(mail.text, /He leído y acepto la política de privacidad\.: Sí/, "{{all_fields}} in the submission's language");
});

test("editor in es_ES: it gets the translations of the estrategia-web domain, and the blocks' texts translated", () => {
  // What WordPress writes before the forms editor script (assets/editor.js) from the block's textdomain.
  const inline = editor.match(/<script id="estrategia-web-forms-editor-js-before">\s*wp\.i18n\.setLocaleData\( (\{[\s\S]*?\})\s*,\s*["']estrategia-web["']\s*\);/);
  assert.ok(inline, "no wp.i18n locale data for the forms editor script");
  const data = JSON.parse(inline[1]);
  assert.equal(data[""]?.domain, "estrategia-web");
  assert.equal(data[""]?.lang, "es_ES");
  assert.equal(data["Email notification"]?.[0], "Aviso por correo", "the editor script's translations: «Email notification»");
  assert.ok(/"estrategia-web-forms\\?\/form":\{[^{}]*?"title":"Formulario"/.test(editor),"the Form block's title, from block.json, in Spanish");
});
