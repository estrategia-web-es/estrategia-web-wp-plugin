// NIVEL: 2 — Playground WordPress. NOT LEVEL 1 BECAUSE: the links are written by the checkbox block's render_callback when WordPress serves the page, with WordPress's URL escaping (esc_url, which is what drops javascript:) and the site's privacy page (wp_page_for_privacy_policy).
//
// Both pages are seeded in one request with the reset, and read two at a
// time (28-10-2026). Seen red that day, both tests, with the {text|url} url
// replaced by the privacy page's, and a «javascript:» one left unescaped.
// The consent label accepts SEVERAL links: {text} links to the site's
// privacy policy (as always) and {text|url} to that url.
// Born on 06-10-2026 at Lavandería Mallorca: the sentence named two pages
// («protección de datos» and «Sus datos seguros») and a single link came out with
// both, pointing moreover to the page the site had set as privacy.
import { test, before } from "node:test";
import assert from "node:assert/strict";
import { seed, loadForm, TEAM } from "./lib.mjs";

const form = (label) => `<!-- wp:estrategia-web-forms/form {"formId":"consent","title":"Consent"} -->
<!-- wp:estrategia-web-forms/text {"label":"Nombre","name":"nombre","required":true} /-->
<!-- wp:estrategia-web-forms/checkbox {"label":${JSON.stringify(label)},"name":"privacidad","consent":true} /-->
<!-- wp:estrategia-web-forms/submit {"text":"Enviar"} /-->
<!-- wp:estrategia-web-forms/notification {"label":"Aviso","to":"${TEAM}","subject":"x"} /-->
<!-- /wp:estrategia-web-forms/form -->`;

// href wherever it is in the tag: the external-links module adds attributes to external ones (aria-label before href, 28-10-2026).
const links = (html) => [...html.matchAll(/<a\b[^>]*?\shref="([^"]*)"[^>]*>([^<]*)<\/a>/g)].map((m) => [m[2], m[1]]);
// The label sits apart from the checkbox (<label for="id">), it does not wrap it.
const consentHtml = (html) => {
  const input = html.match(/<input[^>]*name="fields\[privacidad\]"[^>]*>/)?.[0] || "";
  const id = input.match(/id="([^"]+)"/)?.[1];
  if (!id) return "";
  return html.match(new RegExp(`<label for="${id}"[^>]*>[\\s\\S]*?</label>`))?.[0] || "";
};

let several, dangerous;
before(async () => {
  const { pages } = await seed({
    pages: [
      { key: "several", slug: "consent-varios", content: form("I accept the {data protection|/proteccion/} and {Your data is safe|/sus-datos/}, and I have read the {cookie policy|https://ejemplo.test/cookies/}.") },
      { key: "dangerous", slug: "consent-js", content: form("I accept the {policy|javascript:alert(1)}.") },
    ],
  });
  [several, dangerous] = await Promise.all([loadForm(pages.several.url), loadForm(pages.dangerous.url)]);
});

test("{text|url} links to that url, and there can be several in the same sentence", () => {
  const { html } = several;
  const got = links(consentHtml(html));
  assert.deepEqual(got, [
    ["data protection", "/proteccion/"],
    ["Your data is safe", "/sus-datos/"],
    ["cookie policy", "https://ejemplo.test/cookies/"],
  ]);
  assert.ok(!/[{}|]/.test(consentHtml(html).replace(/<[^>]+>/g, "")), "no braces or bars left in the text");
});

test("a dangerous url is not linked: only the text remains", () => {
  const { html } = dangerous;
  const label = consentHtml(html);
  assert.ok(!/javascript:/i.test(label), "no javascript:");
  assert.match(label.replace(/<[^>]+>/g, ""), /I accept the policy\./);
});
