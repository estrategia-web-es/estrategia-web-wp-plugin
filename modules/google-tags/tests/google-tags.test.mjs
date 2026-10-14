// NIVEL: 2 — WordPress de Playground, porque lo probado es lo que IMPRIME la web (el <head> y las categorías del aviso) según la opción guardada por REST y los módulos encendidos.
//
// Etiquetas de Google (1.1.0). Qué hace y por qué: cabecera de
// modules/google-tags/includes/class-google-tags.php. Lo del navegador (qué
// se pide a Google antes y después de aceptar, Consent Mode) está en
// google-tags-browser.test.mjs (nivel 3).
//
// Cómo se mantiene: un campo nuevo, su caso de «vacío = nada» y su caso de
// validación aquí.
import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { BASE, html, reset, testApi, wp } from "../../../tests/lib.mjs";

const front = async () => (await html(BASE + "/?nocache=" + Date.now())).text;
const TAG = 'id="estrategia-web-google-tags"';
const offered = (page) => [...page.matchAll(/name="estrategia-web-consent-category"\s+value="([^"]+)"/g)].map((m) => m[1]);
const cleanAll = async () => {
  await reset();
  await fetch(BASE + "/wp-json/estrategia-web-cookies-test/v1/reset", { method: "POST" });
};

/** Enciende google-tags además de lo que ya carga la web por defecto. */
async function enable(extra = ["google-tags"], without = []) {
  await testApi("/modules", { method: "POST", body: { active: null } });
  const { loaded } = await testApi("/modules");
  await testApi("/modules", { method: "POST", body: { active: [...loaded.filter((m) => !without.includes(m)), ...extra] } });
}
const save = (value) => wp("/settings", { method: "POST", body: { estrategia_web_google_tags: value } });

before(cleanAll);
after(cleanAll);

test("google-tags: APAGADO por defecto; encendido y con los campos vacíos no imprime nada", async () => {
  const { loaded } = await testApi("/modules");
  assert.ok(!loaded.includes("google-tags"), "Default: off");
  await enable();
  assert.ok((await testApi("/modules")).loaded.includes("google-tags"));
  const page = await front();
  assert.ok(!page.includes(TAG) && !page.includes("googletagmanager"), "sin ids no hay nada de Google");
  assert.deepEqual(offered(page), [], "ni categorías en el aviso");
});

test("google-tags: con GA4, consent «default» denied ANTES que nada y ningún <script src> de Google en el HTML", async () => {
  await enable();
  const r = await save({ ga4: "G-TEST12345" });
  assert.equal(r.status, 200, r.text);
  const page = await front();
  assert.ok(page.includes(TAG));
  const head = page.slice(0, page.indexOf("</head>"));
  const ours = head.indexOf(TAG);
  const firstScript = head.search(/<script\b/);
  assert.ok(ours >= 0 && head.lastIndexOf("<script", ours) === firstScript, "es el PRIMER script del <head>");
  const defaults = head.match(/gtag\('consent', 'default', \{([\s\S]*?)\}\);/)?.[1] || "";
  for (const key of ["ad_storage", "ad_user_data", "ad_personalization", "analytics_storage"]) {
    assert.match(defaults, new RegExp(`${key}: 'denied'`), `${key} no empieza denied`);
  }
  assert.ok(!/<script[^>]+src="[^"]*googletagmanager/.test(page), "el script de Google no va en el HTML: lo pide el navegador tras aceptar");
  assert.match(page, /"ga4":"G-TEST12345"/);
  assert.deepEqual(offered(page), ["statistics"], "GA4 pide Analítica");
});

test("google-tags: Ads pide Publicidad; Tag Manager, las dos; la etiqueta de conversión solo con su cuenta de Ads", async () => {
  await enable();
  await save({ ga4: "", ads: "AW-123456789", ads_form_label: "AW-123456789/AbCd_ef-12", gtm: "" });
  let page = await front();
  assert.deepEqual(offered(page), ["marketing"]);
  assert.match(page, /"form":"AW-123456789\\?\/AbCd_ef-12"/);

  await save({ ads: "", gtm: "GTM-ABCD123" });
  page = await front();
  assert.deepEqual(offered(page), ["statistics", "marketing"]);
  assert.match(page, /"form":""/, "sin cuenta de Ads, la conversión no se manda");
});

test("google-tags: ids mal escritos → 400 por REST; minúsculas por la pantalla se arreglan solas (clean)", async () => {
  await enable();
  assert.equal((await save({ ga4: "UA-1234-1" })).status, 400);
  assert.equal((await save({ gtm: "GTM-<script>" })).status, 400);
  assert.equal((await save({ mode: "loco" })).status, 400);
  const ok = await save({ ga4: "G-ABCDE12345", mode: "advanced" });
  assert.equal(ok.status, 200);
  assert.equal(ok.json.estrategia_web_google_tags.mode, "advanced");
  assert.match(await front(), /"advanced":true/);
});

test("google-tags: Requires consent — con el aviso apagado no se carga (y la pantalla de módulos dice por qué)", async () => {
  await enable(["google-tags"], ["consent"]);
  await save({ ga4: "G-TEST12345" });
  const mods = await testApi("/modules");
  assert.ok(!mods.loaded.includes("google-tags"));
  assert.deepEqual(mods.blocked["google-tags"], ["consent"]);
  assert.ok(!(await front()).includes(TAG));
});
