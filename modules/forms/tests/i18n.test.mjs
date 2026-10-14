// NIVEL: 3 — navegador. NECESITA NAVEGADOR PORQUE: las traducciones del editor (las que el núcleo pasa en línea a wp.i18n desde el .l10n.php; hasta la 1.0.0, un JSON) solo las carga wp.i18n dentro del editor pintado; el resto de la prueba es de servidor y va aquí para no partir el cambio de idioma en dos.
//
// Pone la web en es_ES (WPLANG) y comprueba que el .l10n.php del plugin
// (dominio estrategia-web, uno para todo) se carga en el servidor y en el editor: textos por defecto del formulario, errores del servidor, el idioma
// registrado, el «Sí» del correo y los textos del editor. Sin WPML: el idioma
// es el locale de WordPress.
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

test("servidor en es_ES: textos por defecto, errores, idioma registrado y correo", async () => {
  const { html, hidden } = await loadForm(page.url);
  // assert.ok y no assert.match: si falla, que no vuelque 60 KB de HTML.
  assert.ok(/>Enviar<\/button>/.test(html), "botón sin texto propio = «Enviar» del .l10n.php");
  assert.ok(/He leído y acepto la política de privacidad\./.test(html), "consentimiento por defecto en castellano");

  await waitMinTime();
  const bad = await submit(hidden, { nombre: "" });
  assert.equal(bad.json.errors.nombre, "Este campo es obligatorio.");
  assert.equal(bad.json.message, "No se ha podido enviar el mensaje. Revisa el formulario y vuelve a intentarlo.");

  const ok = await submit(hidden, { nombre: "Ana", privacidad: "1" });
  assert.equal(ok.json.message, "Gracias. Tu mensaje se ha enviado.");
  const [row] = await submissions();
  assert.equal(row.lang, "es_ES", "el idioma del envío queda registrado");
  const [mail] = await mails();
  assert.match(mail.text, /He leído y acepto la política de privacidad\.: Sí/, "{{todos_los_campos}} en el idioma del envío");
});

test("editor en es_ES: wp.i18n tiene las traducciones del dominio estrategia-web", async () => {
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
