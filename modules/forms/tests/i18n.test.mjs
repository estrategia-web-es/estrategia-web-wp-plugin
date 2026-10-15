// NIVEL: 3 — browser. NEEDS A BROWSER BECAUSE: the editor translations (the ones core passes inline to wp.i18n from the .l10n.php; up to 1.0.0, a JSON) are only loaded by wp.i18n inside the rendered editor; the rest of the test is server-side and lives here so the language switch is not split in two.
//
// Puts the site in es_ES (WPLANG) and checks that the plugin's .l10n.php
// (estrategia-web domain, one for everything) is loaded on the server and in the editor: form default texts, server errors, the recorded
// language, the email's «Sí» and the editor texts. No WPML: the language
// is the WordPress locale.
import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { BASE, playwright, reset, testApi, mails, submissions, seedPage, loadForm, submit, GOOD, waitMinTime } from "./lib.mjs";

let page;
before(async () => {
  await reset();
  await testApi("/locale", { method: "POST", body: { locale: "es_ES" } });
  page = await seedPage(
    `<!-- wp:estrategia-web-forms/form {"formId":"es"} --><!-- wp:estrategia-web-forms/text {"label":"Nombre","name":"nombre","required":true} /--><!-- wp:estrategia-web-forms/checkbox {"name":"privacidad","consent":true} /--><!-- wp:estrategia-web-forms/submit /--><!-- wp:estrategia-web-forms/notification {"to":"equipo@ejemplo.test"} /--><!-- /wp:estrategia-web-forms/form -->`,
    "es"
  );
});
after(() => testApi("/locale", { method: "POST", body: { locale: "" } }));

test("server in es_ES: default texts, errors, recorded language and email", async () => {
  const { html, hidden } = await loadForm(page.url);
  // assert.ok and not assert.match: if it fails, do not dump 60 KB of HTML.
  assert.ok(/>Enviar<\/button>/.test(html), "button without its own text = «Enviar» from the .l10n.php");
  assert.ok(/He leído y acepto la política de privacidad\./.test(html), "default consent in Spanish");

  await waitMinTime();
  const bad = await submit(hidden, { nombre: "" });
  assert.equal(bad.json.errors.nombre, "Este campo es obligatorio.");
  assert.equal(bad.json.message, "No se ha podido enviar el mensaje. Revisa el formulario y vuelve a intentarlo.");

  const ok = await submit(hidden, { nombre: "Ana", privacidad: "1" });
  assert.equal(ok.json.message, "Gracias. Tu mensaje se ha enviado.");
  const [row] = await submissions();
  assert.equal(row.lang, "es_ES", "the submission's language is recorded");
  const [mail] = await mails();
  assert.match(mail.text, /He leído y acepto la política de privacidad\.: Sí/, "{{todos_los_campos}} in the submission's language");
});

test("editor in es_ES: wp.i18n has the translations of the estrategia-web domain", async () => {
  const browser = await playwright().chromium.launch();
  try {
    const p = await (await browser.newContext()).newPage();
    await p.goto(BASE + "/wp-admin/");
    if (p.url().includes("wp-login.php")) {
      await p.fill("#user_login", "admin");
      await p.fill("#user_pass", "password");
      await Promise.all([p.waitForURL(/wp-admin/), p.click("#wp-submit")]);
    }
    await p.goto(BASE + "/wp-admin/post-new.php?post_type=page");
    await p.waitForFunction(() => window.wp?.blocks?.getBlockType("estrategia-web-forms/form"));
    const t = await p.evaluate(() => [wp.i18n.__("Email notification", "estrategia-web"), wp.blocks.getBlockType("estrategia-web-forms/form").title]);
    assert.deepEqual(t, ["Aviso por correo", "Formulario"]);
  } finally {
    await browser.close();
  }
});
