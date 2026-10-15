// NIVEL: 2 — Playground WordPress, because the link comes from the site's privacy page (wp_page_for_privacy_policy option) and from render_callback.
//
// The consent label accepts SEVERAL links: {text} links to the site's
// privacy policy (as always) and {text|url} to that url.
// Born on 06-10-2026 at Lavandería Mallorca: the sentence named two pages
// («protección de datos» and «Sus datos seguros») and a single link came out with
// both, pointing moreover to the page the site had set as privacy.
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
// The label sits apart from the checkbox (<label for="id">), it does not wrap it.
const consentHtml = (html) => {
  const input = html.match(/<input[^>]*name="fields\[privacidad\]"[^>]*>/)?.[0] || "";
  const id = input.match(/id="([^"]+)"/)?.[1];
  if (!id) return "";
  return html.match(new RegExp(`<label for="${id}"[^>]*>[\\s\\S]*?</label>`))?.[0] || "";
};

before(async () => {
  await reset();
});

test("{text|url} links to that url, and there can be several in the same sentence", async () => {
  const page = await seedPage(form("I accept the {data protection|/proteccion/} and {Your data is safe|/sus-datos/}, and I have read the {cookie policy|https://ejemplo.test/cookies/}."), "consent-varios");
  const { html } = await loadForm(page.url);
  const got = links(consentHtml(html));
  assert.deepEqual(got, [
    ["data protection", "/proteccion/"],
    ["Your data is safe", "/sus-datos/"],
    ["cookie policy", "https://ejemplo.test/cookies/"],
  ]);
  assert.ok(!/[{}|]/.test(consentHtml(html).replace(/<[^>]+>/g, "")), "no braces or bars left in the text");
});

test("a dangerous url is not linked: only the text remains", async () => {
  const page = await seedPage(form("I accept the {policy|javascript:alert(1)}."), "consent-js");
  const { html } = await loadForm(page.url);
  const label = consentHtml(html);
  assert.ok(!/javascript:/i.test(label), "no javascript:");
  assert.match(label.replace(/<[^>]+>/g, ""), /I accept the policy\./);
});
