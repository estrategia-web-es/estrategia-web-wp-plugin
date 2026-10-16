// NIVEL: 2 — Playground WordPress, because what is checked is the HTML a Catalan site PRINTS to the visitor and the slug a type is registered with, and both only happen in a real request with the plugin's languages/estrategia-web-ca.l10n.php loaded.
//
// Catalan (locale «ca», WPML's own locale for it). Gastronòmicament is
// Catalan by default with es/en/de: ?wpml-double=ca&wpml-default=ca imitates
// it (the WPML double in tests/wp/estrategia-web-site-test.php; what it does
// and does not imitate is explained there). WPLANG stays empty, as in
// Lavandería production (tests/wplang.test.mjs).
//
// What it checks: the texts the VISITOR reads (cookie notice, two-click map,
// WhatsApp button, carousel arrows, form defaults) come out in Catalan, and
// the URL slugs are the Catalan ones whatever the request's language
// (Plugin::url_slug()).
//
// Seen in red on 16-10-2026 before languages/estrategia-web-ca.po existed:
// every text in English («Accept all», «Show map»…) and the slug «locations».
//
// How to maintain: the wording lives in languages/estrategia-web-ca.po and
// David decides the doubtful forms (docs/translations/ca-review.md). If one
// is changed there, change it here too. The slugs are a promise: changing
// «seus» or «clients» changes the URLs of every Catalan site already
// published, so this test is not «fixed» without deciding that.
import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { BASE, html, reset, saveCompany, seedPage, testApi } from "./lib.mjs";

const CA = "wpml-double=ca&wpml-default=ca";
// What the visitor reads: entities and the curly apostrophe of wptexturize back to plain text.
const text = (s) =>
  s
    .replace(/&#0?39;|&#8217;|’/g, "'")
    .replace(/&quot;/g, '"')
    .replace(/&amp;/g, "&");

let page;
before(async () => {
  await reset();
  await saveCompany({ enabled_types: ["location", "client"] });
  await testApi("/consent-setup", { method: "POST", body: { categories: ["maps", "statistics"] } });
  page = await seedPage(
    '<!-- wp:estrategia-web/map {"placeName":"Seu de prova","address":"Plaça de Cort 1, 07001 Palma","embedUrl":"https://www.google.com/maps/embed?pb=!1m18!1m12"} /-->' +
      '<!-- wp:estrategia-web/whatsapp {"phone":"34600000000"} /-->' +
      '<!-- wp:estrategia-web/carousel {"label":"Fotos"} --><!-- wp:paragraph --><p>U</p><!-- /wp:paragraph --><!-- wp:paragraph --><p>Dos</p><!-- /wp:paragraph --><!-- /wp:estrategia-web/carousel -->' +
      '<!-- wp:estrategia-web-forms/form {"formId":"ca"} --><!-- wp:estrategia-web-forms/text {"label":"Nom","name":"nom","required":true} /--><!-- wp:estrategia-web-forms/checkbox {"name":"privadesa","consent":true} /--><!-- wp:estrategia-web-forms/submit /--><!-- /wp:estrategia-web-forms/form -->',
    "catala"
  );
});
after(reset);

test("Catalan site, Catalan request: a plugin text and the URL slug", async () => {
  const r = await testApi("/i18n?" + CA);
  assert.equal(r.now.text, "Accepta-les totes", `determine_locale() was ${r.determine_locale}`);
  assert.equal(r.now.slug, "seus");
  assert.equal(r.registered_slug, "seus");
  assert.equal(r.switched_en.text, "Accept all", "a WPML switch to English still works");
  assert.equal(r.switched_en.slug, "seus", "the slug is the site's, also while in English");
});

test("Catalan site, Spanish request (/es/): Spanish texts, Catalan slug", async () => {
  const r = await testApi("/i18n?wpml-double=es&wpml-default=ca");
  assert.equal(r.now.text, "Aceptar todas");
  assert.equal(r.registered_slug, "seus", "the stored rules are the default language's");
});

test("what the visitor reads on a Catalan page", async () => {
  const r = await html(page.url + (page.url.includes("?") ? "&" : "?") + CA + "&nocache=" + Date.now());
  assert.equal(r.status, 200);
  const t = text(r.text);
  const expected = [
    // Cookie notice.
    "Utilitzem galetes pròpies i de tercers per a les finalitats que s'indiquen.",
    "Accepta-les totes",
    "Rebutja-les totes",
    "Configura",
    "Desa la selecció",
    "Necessàries",
    "Mapes i contingut de tercers",
    // Two-click map.
    "Mostra el mapa",
    "Obre a Google Maps",
    // WhatsApp button (accessible name).
    "Escriu-nos per WhatsApp",
    // Carousel arrows.
    "Diapositiva anterior",
    "Diapositiva següent",
    // Form defaults.
    "He llegit i accepto la",
    "política de privadesa",
    ">Envia<",
  ];
  const missing = expected.filter((s) => !t.includes(s));
  // Not assert.match on the page: if it fails, do not dump 60 KB of HTML.
  assert.deepEqual(missing, [], "texts missing from the Catalan page");
  for (const english of ["Accept all", "Reject all", "Show map", "Chat on WhatsApp", "Next slide"]) {
    assert.ok(!t.includes(english), `«${english}» is still in English`);
  }
});

test("the other Catalan slugs, as the site language registers them", async () => {
  const r = await testApi("/i18n?" + CA);
  assert.equal(r.registered_slug, "seus");
  const types = await testApi("/i18n-slugs?" + CA);
  assert.deepEqual(types, {
    clients: "clients",
    "client-sector": "sector",
    testimonials: "opinions",
    locations: "seus",
    projects: "projectes",
    "project-category": "categoria-projecte",
    portfolio: "portafolis",
    "portfolio-category": "categoria-portafolis",
  });
});
