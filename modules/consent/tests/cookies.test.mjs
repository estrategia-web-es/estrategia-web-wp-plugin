// NIVEL: 2 — WordPress de Playground, porque lo probado es la ruta REST del detector (límites, origen, lo que guarda), la ruta de administración y el HTML que pinta la web (aviso y tabla de la política) según lo guardado.
//
// Registro de cookies, detector y tabla de la política (1.1.0). Qué es y por
// qué: cabeceras de includes/class-cookie-registry.php, class-cookie-rest.php
// y class-cookie-table.php. Lo del navegador (que al aceptar se manden solo
// los nombres, la pantalla) está en cookies-browser.test.mjs (nivel 3).
//
// Rutas de apoyo: /consent-setup (tests/wp/, hace de tema que declara
// categorías) y las de modules/consent/tests/wp/estrategia-web-cookies-test.php
// (reset, límites del detector, la opción cruda).
//
// Cómo se mantiene: un límite nuevo del detector, su caso aquí con el filtro
// puesto por /setup; nunca bajar los límites de verdad para que pase.
import { test, beforeEach, after } from "node:test";
import assert from "node:assert/strict";
import { BASE, credentials, html, reset, seedPage, testApi, wp } from "../../../tests/lib.mjs";

const cookiesTest = async (path, body) => {
  const res = await fetch(BASE + "/wp-json/estrategia-web-cookies-test/v1" + path, {
    method: body === undefined ? "GET" : "POST",
    headers: { "Content-Type": "application/json" },
    body: body === undefined ? undefined : JSON.stringify(body),
  });
  if (!res.ok) throw new Error(`${path} -> ${res.status} ${await res.text()}`);
  return res.json();
};
const setupConsent = (body) => testApi("/consent-setup", { method: "POST", body });
const front = async (path = "/") => (await html(BASE + path + (path.includes("?") ? "&" : "?") + "nocache=" + Date.now())).text;

/** POST del detector como lo haría un navegador. */
const report = (names, headers = {}) =>
  fetch(BASE + "/wp-json/estrategia-web/v1/cookies/seen", {
    method: "POST",
    headers: { "Content-Type": "application/json", ...headers },
    body: JSON.stringify({ names }),
  });

/** La ruta de administración, como administrador (o sin credenciales con as = null). */
async function admin(method, body, as = "administrator", query = "") {
  const who = as ? (await credentials())[as] : null;
  const res = await fetch(BASE + "/wp-json/estrategia-web/v1/cookies" + query, {
    method,
    headers: {
      ...(who ? { Authorization: "Basic " + Buffer.from(`${who.user}:${who.password}`).toString("base64") } : {}),
      ...(body ? { "Content-Type": "application/json" } : {}),
    },
    body: body ? JSON.stringify(body) : undefined,
  });
  return { status: res.status, json: await res.json().catch(() => null) };
}

const offered = (page) => [...page.matchAll(/name="estrategia-web-consent-category"\s+value="([^"]+)"/g)].map((m) => m[1]);

const clean = async () => {
  await reset();
  await cookiesTest("/reset", {});
};
beforeEach(clean);
after(clean); // Las demás pruebas comparten este WordPress: nada de «ask» ni filas que se queden.

test("detector: guarda solo NOMBRES, contesta 202 y con la misma IP en el intervalo da 429", async () => {
  await setupConsent({ categories: ["maps"] });
  const r = await report(["_ga", "_ga_ABC123", "wp-wpml_current_language", "x=secreto", "<b>"]);
  assert.equal(r.status, 202, await r.text());
  const raw = (await cookiesTest("/raw")).raw;
  assert.deepEqual(Object.keys(raw).sort(), ["_ga", "_ga_*", "wp-wpml_current_language"]);
  assert.ok(!JSON.stringify(raw).includes("secreto") && !JSON.stringify(raw).includes("<b>"), "ni valores ni HTML");
  assert.equal(raw["wp-wpml_current_language"].category, "necessary");
  assert.equal(raw["_ga"].status, "pending");

  const again = await report(["otra"]);
  assert.equal(again.status, 429, "el mismo cliente dentro de los 10 minutos");
  assert.ok(!("otra" in (await cookiesTest("/raw")).raw));
});

test("detector: tope por hora para toda la web, más de 60 nombres = 400, origen ajeno = 403, apagado = 403", async () => {
  await setupConsent({ categories: ["maps"] });
  await cookiesTest("/setup", { interval: 0, cap: 2 });
  assert.equal((await report(["a1"])).status, 202);
  assert.equal((await report(["a2"])).status, 202);
  assert.equal((await report(["a3"])).status, 429, "tercera en la hora con tope 2");

  await cookiesTest("/reset", {});
  await setupConsent({ categories: ["maps"] });
  await cookiesTest("/setup", { interval: 0 });
  assert.equal((await report(Array.from({ length: 61 }, (_, i) => "n" + i))).status, 400);
  assert.equal((await report(["b1"], { Origin: "https://malo.example" })).status, 403);
  assert.equal((await report(["b2"], { Origin: BASE })).status, 202, "el propio origen sí");

  const off = await wp("/settings", { method: "POST", body: { estrategia_web_cookies_settings: { detector: false, ask: [] } } });
  assert.equal(off.status, 200, off.text);
  assert.equal((await report(["b3"])).status, 403);
  assert.ok(!(await front()).includes('"detector":{'), "apagado, la página no lleva la configuración del detector");
});

test("detector: la página con aviso lleva la URL del detector, el periodo y el retraso", async () => {
  await setupConsent({ categories: ["maps"] });
  const config = (await front()).match(/"detector":\{"url":"([^"]+)","days":(\d+),"delay":(\d+)\}/);
  assert.ok(config, "falta la configuración del detector en el estado del aviso");
  assert.match(config[1].replace(/\\\//g, "/"), /\/wp-json\/estrategia-web\/v1\/cookies\/seen$/);
  assert.equal(config[2], "7");
});

test("administración: solo manage_options; crea, confirma y borra", async () => {
  assert.equal((await admin("GET", null, null)).status, 401);
  assert.equal((await admin("GET", null, "author")).status, 403);
  const made = await admin("POST", { name: "NID", category: "maps", purpose: "Google Maps la pone al cargar el mapa." });
  assert.equal(made.status, 200, JSON.stringify(made.json));
  assert.equal(made.json.entry.provider, "Google", "proveedor de la propuesta");
  assert.equal(made.json.entry.source, "manual");
  assert.equal((await admin("POST", { name: "mal nombre" })).status, 400);
  const list = await admin("GET");
  assert.ok(list.json.cookies.NID);
  assert.equal(list.json.attribution.license, "Apache-2.0");
  assert.equal((await admin("DELETE", null, "administrator", "?name=NID")).json.deleted, true);
});

test("tabla de la política: solo lo CONFIRMADO, por categoría, en castellano; bloque y shortcode", async () => {
  await admin("POST", { name: "estrategia_web_consent", status: "confirmed" });
  await admin("POST", { name: "_ga", status: "confirmed" });
  await admin("POST", { name: "_ga_*", status: "confirmed", purpose: "Distingue visitantes para las estadísticas." });
  await admin("POST", { name: "_gcl_au", status: "pending" });
  await admin("POST", { name: "NID", category: "maps", duration: "Hasta cerrar el navegador", status: "confirmed" });
  const { url } = await seedPage(
    "<!-- wp:estrategia-web/cookie-table /-->" + '<!-- wp:shortcode -->[estrategia_web_cookie_table category="statistics" heading_level="4"]<!-- /wp:shortcode -->',
    "prueba-tabla-cookies"
  );
  const page = await front(new URL(url).pathname);
  const block = page.match(/<div [^>]*wp-block-estrategia-web-cookie-table[\s\S]*?<\/figure><\/div>/)?.[0] || "";
  assert.ok(block, "el bloque no pinta la tabla");
  const titles = [...block.matchAll(/<h3 class="estrategia-web-cookie-table__title">([^<]+)<\/h3>/g)].map((m) => m[1]);
  assert.deepEqual(titles, ["Necesarias", "Analítica", "Mapas y contenido de terceros"], "categorías en orden y traducidas");
  assert.match(block, /<code>_ga_\*<\/code><\/td><td>Google Analytics<\/td><td>Distingue visitantes para las estadísticas\.<\/td><td>2 años<\/td>/);
  assert.match(block, /<code>estrategia_web_consent<\/code><\/td><td>Este sitio web<\/td>/);
  assert.match(block, /<td>Hasta cerrar el navegador<\/td>/, "duración libre tal cual");
  assert.ok(!block.includes("_gcl_au"), "lo pendiente no sale en la política");
  assert.match(page, /<h4 class="estrategia-web-cookie-table__title">Analítica<\/h4>/, "el shortcode con su categoría y su nivel");
});

test("tabla de la política: sin nada confirmado no pinta NADA en la web", async () => {
  await report(["_ga"]);
  const { url } = await seedPage("<!-- wp:estrategia-web/cookie-table /-->", "prueba-tabla-vacia");
  assert.ok(!(await front(new URL(url).pathname)).includes("estrategia-web-cookie-table"));
});

test("aviso 1.1.0: el administrador añade «Preferencias»; los alias valen al declarar; la revisión de siempre no cambia", async () => {
  const base = await setupConsent({ categories: ["maps"] });
  assert.deepEqual(offered(await front()), ["maps"]);
  const r = await wp("/settings", { method: "POST", body: { estrategia_web_cookies_settings: { detector: true, ask: ["preferences"] } } });
  assert.equal(r.status, 200, r.text);
  const page = await front();
  assert.deepEqual(offered(page), ["preferences", "maps"], "preferencias primero al enseñar");
  assert.match(page, /Preferencias<\/strong>/);
  const after = await setupConsent({ categories: ["maps"] });
  assert.notEqual(after.revision, base.revision, "preguntar algo nuevo cambia la revisión");

  await wp("/settings", { method: "POST", body: { estrategia_web_cookies_settings: { detector: true, ask: [] } } });
  const aliased = await setupConsent({ categories: ["analytics", "advertising"] });
  assert.deepEqual(aliased.categories, ["statistics", "marketing"]);
  assert.deepEqual(offered(await front()), ["statistics", "marketing"]);
  assert.match(await front(), /Analítica<\/strong>[\s\S]*Publicidad<\/strong>/);
  assert.equal((await setupConsent({ categories: ["maps"] })).revision, base.revision, "sin «ask», la revisión es la de antes de 1.1.0");
});
