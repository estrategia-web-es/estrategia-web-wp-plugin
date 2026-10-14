// NIVEL: 2 — WordPress de Playground, porque el enlace sale de la página de privacidad del sitio (opción wp_page_for_privacy_policy) y de render_callback.
//
// La etiqueta del consentimiento admite VARIOS enlaces: {texto} enlaza a la
// política de privacidad del sitio (como siempre) y {texto|url} a esa url.
// Nació el 06-10-2026 en Lavandería Mallorca: la frase nombraba dos páginas
// («protección de datos» y «Sus datos seguros») y salía un solo enlace con las
// dos, apuntando además a la página que el sitio tenía como privacidad.
import { test, before } from "node:test";
import assert from "node:assert/strict";
import { reset, seedPage, loadForm, TEAM } from "./lib.mjs";

const form = (label) => `<!-- wp:estrategia-web-forms/form {"formId":"consent","title":"Consent"} -->
<!-- wp:estrategia-web-forms/text {"label":"Nombre","name":"nombre","required":true} /-->
<!-- wp:estrategia-web-forms/checkbox {"label":${JSON.stringify(label)},"name":"privacidad","consent":true} /-->
<!-- wp:estrategia-web-forms/submit {"text":"Enviar"} /-->
<!-- wp:estrategia-web-forms/notification {"label":"Aviso","to":"${TEAM}","subject":"x"} /-->
<!-- /wp:estrategia-web-forms/form -->`;

const links = (html) => [...html.matchAll(/<a href="([^"]*)"[^>]*>([^<]*)<\/a>/g)].map((m) => [m[2], m[1]]);
// La etiqueta va aparte del checkbox (<label for="id">), no lo envuelve.
const consentHtml = (html) => {
  const input = html.match(/<input[^>]*name="fields\[privacidad\]"[^>]*>/)?.[0] || "";
  const id = input.match(/id="([^"]+)"/)?.[1];
  if (!id) return "";
  return html.match(new RegExp(`<label for="${id}"[^>]*>[\\s\\S]*?</label>`))?.[0] || "";
};

before(async () => {
  await reset();
});

test("{texto|url} enlaza a esa url, y puede haber varios en la misma frase", async () => {
  const page = await seedPage(form("Acepto la {protección de datos|/proteccion/} y {Sus datos seguros|/sus-datos/}, y he leído la {política de cookies|https://ejemplo.test/cookies/}."), "consent-varios");
  const { html } = await loadForm(page.url);
  const got = links(consentHtml(html));
  assert.deepEqual(got, [
    ["protección de datos", "/proteccion/"],
    ["Sus datos seguros", "/sus-datos/"],
    ["política de cookies", "https://ejemplo.test/cookies/"],
  ]);
  assert.ok(!/[{}|]/.test(consentHtml(html).replace(/<[^>]+>/g, "")), "no quedan llaves ni barras en el texto");
});

test("una url peligrosa no se enlaza: queda el texto solo", async () => {
  const page = await seedPage(form("Acepto la {política|javascript:alert(1)}."), "consent-js");
  const { html } = await loadForm(page.url);
  const label = consentHtml(html);
  assert.ok(!/javascript:/i.test(label), "sin javascript:");
  assert.match(label.replace(/<[^>]+>/g, ""), /Acepto la política\./);
});
