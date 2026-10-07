// NIVEL: 2 — WordPress de Playground, porque lo probado es lo que IMPRIME la web (el <head> de la portada) según la opción guardada por REST, y el saneado de esa opción.
//
// Matomo (0.3.0). Qué hace cada modo: cabecera de includes/class-matomo.php.
// Lo que pasa en el navegador (cookies, consentimiento) está en
// tests/matomo-browser.test.mjs (nivel 3).
import { test, before } from "node:test";
import assert from "node:assert/strict";
import { BASE, html, reset, saveCompany, seedPage, testApi, wp } from "./lib.mjs";

const MATOMO = "https://stats.ejemplo.test/";
const TAG = 'id="estrategia-web-site-matomo"';

before(reset);

const front = async () => (await html(BASE + "/?nocache=" + Date.now())).text;

test("matomo (0.3.0): sin URL o sin id NO se imprime nada; con los dos, sí", async () => {
  assert.ok(!(await front()).includes(TAG), "recién instalado no hay código de Matomo");
  assert.ok(!(await front()).includes("_paq"), "ni rastro de _paq");

  await saveCompany({ matomo_url: MATOMO, matomo_site_id: 0 });
  assert.ok(!(await front()).includes(TAG), "con URL pero sin id, nada");

  await saveCompany({ matomo_url: "", matomo_site_id: 3 });
  assert.ok(!(await front()).includes(TAG), "con id pero sin URL, nada");

  await saveCompany({ matomo_url: MATOMO, matomo_site_id: 3 });
  const page = await front();
  assert.ok(page.includes(TAG), "con URL e id, el código está");
  assert.match(page, /setSiteId', "3"/);
  assert.ok(page.includes('"https:\\/\\/stats.ejemplo.test\\/"') || page.includes('"https://stats.ejemplo.test/"'), "la URL de la opción");
});

test("matomo (0.3.0): por defecto es SIN cookies (disableCookies) y no pide la categoría al aviso", async () => {
  await reset();
  const saved = await saveCompany({ matomo_url: MATOMO, matomo_site_id: 3 });
  assert.equal(saved.matomo_mode, "cookieless");
  const page = await front();
  assert.ok(page.includes("'disableCookies'"), "disableCookies");
  assert.ok(!page.includes("requireCookieConsent"), "nada de consentimiento en el modo sin cookies");
  assert.ok(!page.includes("rememberCookieConsentGiven"));
  assert.deepEqual(await testApi("/consent-categories"), ["maps"], "sin cookies no hay nada que preguntar");
});

test("matomo (0.3.0): híbrido = requireCookieConsent + escucha del aviso, y declara «statistics» en el aviso del padre", async () => {
  await reset();
  await saveCompany({ matomo_url: MATOMO, matomo_site_id: 3, matomo_mode: "hybrid" });
  const page = await front();
  assert.ok(page.includes("'requireCookieConsent'"));
  assert.ok(!page.includes("'disableCookies'"));
  assert.ok(page.includes("estrategia-web:consent") && page.includes("rememberCookieConsentGiven") && page.includes("forgetCookieConsentGiven"));
  assert.deepEqual(await testApi("/consent-categories"), ["maps", "statistics"], "se añade sin pisar la del hijo");
});

test("matomo (0.3.0): saneado — URL pegada con matomo.php, URL inválida, id entero, modo fuera del enum", async () => {
  await reset();
  let saved = await saveCompany({ matomo_url: "https://stats.ejemplo.test/matomo.php", matomo_site_id: "7" });
  assert.equal(saved.matomo_url, MATOMO, "se quita matomo.php y queda acabada en /");
  assert.equal(saved.matomo_site_id, 7, "entero, no texto");

  saved = await saveCompany({ matomo_url: "https://stats.ejemplo.test/sub" });
  assert.equal(saved.matomo_url, "https://stats.ejemplo.test/sub/", "siempre acabada en /");

  saved = await saveCompany({ matomo_url: "javascript:alert(1)" });
  assert.equal(saved.matomo_url, "https://stats.ejemplo.test/sub/", "una URL inválida no borra la buena");

  const badMode = await wp("/settings", { method: "POST", body: { estrategia_web_site: { matomo_mode: "always" } } });
  assert.equal(badMode.status, 400, "modo fuera del enum → 400");
  const badId = await wp("/settings", { method: "POST", body: { estrategia_web_site: { matomo_site_id: -1 } } });
  assert.equal(badId.status, 400, "id negativo → 400");

  const read = await wp("/settings");
  assert.equal(read.json.estrategia_web_site.matomo_mode, "cookieless");
  assert.equal(read.json.estrategia_web_site.matomo_site_id, 7);
});

test("matomo (0.3.0): las claves de Matomo NO son datos enlazables del editor", async () => {
  await reset();
  await saveCompany({ matomo_url: MATOMO, matomo_site_id: 3 });
  const p = '<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"estrategia-web-site/company","args":{"key":"matomo_url"}}}}} --><p>x</p><!-- /wp:paragraph -->';
  const { url } = await seedPage(p, "matomo-binding");
  const page = await html(url);
  assert.equal(page.status, 200);
  assert.ok(!page.text.includes(">https://stats.ejemplo.test/<"), "la URL de Matomo no sale por la fuente de la empresa");
});
