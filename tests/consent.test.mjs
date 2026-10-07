// NIVEL: 2 — WordPress de Playground, porque lo probado es el HTML que IMPRIME la web (el aviso, el enlace y la línea «Powered by») según los filtros y la opción guardada por REST.
//
// Aviso de cookies (0.5.0, vino del tema padre). Qué es y por qué, en la
// cabecera de includes/class-consent.php. Lo que pasa en el navegador (botones,
// cookie, API JS, evento, Matomo con el aviso real, contraste) está en
// tests/consent-browser.test.mjs (nivel 3).
//
// La «web» que declara categorías es la ruta de pruebas consent-setup
// (tests/wp/estrategia-web-site-test.php), que hace de functions.php del tema.
//
// Vista fallar el 05-10-2026 con cuatro mutantes, cada uno pillado: rel con
// nofollow, la revisión con '2', el filtro del «Powered by» sin aplicar y el
// alias armazon/consent y armazon-site/consent sin registrar.
//
// Cómo se mantiene: si cambia el formato de la cookie o la fórmula de la
// revisión, la prueba «la revisión es la del padre» TIENE que ponerse roja:
// cambiarla hace que todos los visitantes vuelvan a ver el aviso. No se
// «arregla» la prueba sin decidir eso.
import { test, before } from "node:test";
import assert from "node:assert/strict";
import crypto from "node:crypto";
import { BASE, html, reset, saveCompany, seedPage, testApi } from "./lib.mjs";

const setup = (body) => testApi("/consent-setup", { method: "POST", body });
const front = async (path = "/") => (await html(BASE + path + (path.includes("?") ? "&" : "?") + "nocache=" + Date.now())).text;
const NOTICE = 'id="estrategia-web-consent"';

/** La línea «Powered by» del aviso: { html, anchor: { href, target, rel, text } | null }. */
function powered(page) {
  const m = page.match(/<p class="estrategia-web-consent__powered">([\s\S]*?)<\/p>/);
  if (!m) return null;
  const a = m[1].match(/<a ([^>]*)>([\s\S]*?)<\/a>/);
  const attr = (name) => (a?.[1].match(new RegExp(`${name}="([^"]*)"`)) || [])[1];
  return {
    html: m[1],
    text: m[1].replace(/<span class="estrategia-web-consent__sr">[\s\S]*?<\/span>/, "").replace(/<[^>]+>/g, "").trim(),
    anchor: a ? { href: attr("href"), target: attr("target"), rel: attr("rel") } : null,
  };
}

before(reset);

test("consent (0.5.0): el plugin lleva el aviso; sin categorías declaradas NO sale (ni su módulo)", async () => {
  await reset();
  const r = await setup({});
  assert.equal(r.active, true, "con un tema que no trae aviso, el aviso es del plugin");
  assert.deepEqual(r.categories, []);
  const page = await front();
  assert.ok(!page.includes(NOTICE), "sin categorías no hay aviso");
  assert.ok(!page.includes("estrategia-web-site-consent-view-script-module"), "sin categorías no se carga el módulo");
});

test("consent (0.5.0): con categorías sale el aviso, con las categorías en orden estable y los tres botones", async () => {
  await reset();
  await setup({ categories: ["maps", "statistics"] });
  const page = await front();
  assert.ok(page.includes(NOTICE), "el aviso no está");
  const offered = [...page.matchAll(/name="estrategia-web-consent-category"\s+value="([^"]+)"/g)].map((m) => m[1]);
  assert.deepEqual(offered, ["statistics", "maps"], "orden de las categorías conocidas, no el del filtro");
  for (const action of ["accept", "reject", "configure"]) {
    assert.ok(page.includes(`data-estrategia-web-consent-action="${action}"`), `falta el botón ${action}`);
  }
  assert.ok(page.includes("estrategia-web-site-consent-view-script-module"), "el módulo del aviso");
  assert.ok(page.includes("Aceptar todas") && page.includes("Rechazar todas"), "textos traducidos del dominio estrategia-web-site");
});

test("consent (0.5.0): la revisión y la cookie son LAS DEL PADRE (quien ya eligió no vuelve a ver el aviso)", async () => {
  await reset();
  const md5 = (s) => crypto.createHash("md5").update(s).digest("hex").slice(0, 8);
  // La fórmula de armazon-wp-padre 0.6.0, inc/consent.php: md5( implode( ',', categorías ) . '|' . '1' ).
  const r = await setup({ categories: ["statistics", "maps"] });
  assert.equal(r.revision, md5("statistics,maps|1"));
  const page = await front();
  assert.match(page, new RegExp(`"revision":"${r.revision}"`), "el estado de Interactivity lleva la misma revisión");
  assert.match(page, /"cookieName":"estrategia_web_consent"/, "la misma cookie");
});

test("consent (0.5.0): Matomo híbrido declara «statistics» él solo y el aviso sale sin que el tema declare nada", async () => {
  await reset();
  await saveCompany({ matomo_url: "https://stats.ejemplo.test/", matomo_site_id: 3, matomo_mode: "hybrid" });
  const page = await front();
  const offered = [...page.matchAll(/name="estrategia-web-consent-category"\s+value="([^"]+)"/g)].map((m) => m[1]);
  assert.deepEqual(offered, ["statistics"]);
});

test("consent (1.0.0): «Powered by» APAGADO por defecto (WordPress.org, guideline 10)", async () => {
  await reset();
  await setup({ categories: ["maps"] });
  const page = await front();
  assert.ok(page.includes(NOTICE), "control: el aviso sale");
  assert.equal(powered(page), null, "sin el filtro no hay línea «Powered by»");
});

test("consent (0.5.0): «Powered by Estrategia Web» encendido: al pie, enlazado, pestaña nueva, rel=noopener y SIN nofollow", async () => {
  await reset();
  await setup({ categories: ["maps"], powered_by: { show: true } });
  const p = powered(await front());
  assert.ok(p, "no está la línea «Powered by»");
  assert.equal(p.text, "Powered by Estrategia Web");
  assert.equal(p.anchor?.href, "https://estrategiaweb.es/");
  assert.equal(p.anchor.target, "_blank");
  assert.equal(p.anchor.rel, "noopener", "rel tiene que ser noopener y nada más (sin nofollow: cuenta como enlace)");
  assert.ok(!/nofollow|sponsored|ugc/.test(p.html), "ni nofollow ni sponsored ni ugc");
  assert.match(p.html, /estrategia-web-consent__sr">\s*\(se abre en una pestaña nueva\)/, "aviso de pestaña nueva para lectores de pantalla");
  const page = await front();
  assert.ok(page.indexOf("estrategia-web-consent__actions") < page.indexOf("estrategia-web-consent__powered"), "va DESPUÉS de los botones");
});

test("consent (0.5.0): el «Powered by» se cambia y se quita con el filtro estrategia_web_site_consent_powered_by", async () => {
  await reset();
  await setup({ categories: ["maps"], powered_by: { show: true, text: "Hecha por Otra", url: "https://otra.example/" } });
  let p = powered(await front());
  assert.equal(p.text, "Hecha por Otra");
  assert.equal(p.anchor.href, "https://otra.example/");

  await setup({ categories: ["maps"], powered_by: { show: true, url: "" } });
  p = powered(await front());
  assert.equal(p.text, "Powered by Estrategia Web", "lo que no se pasa se queda");
  assert.equal(p.anchor, null, "url vacía: texto sin enlace");

  await setup({ categories: ["maps"], powered_by: { show: true, url: "javascript:alert(1)" } });
  p = powered(await front());
  assert.equal(p.anchor, null, "una url que no es http(s) se descarta");

  await setup({ categories: ["maps"], powered_by: false });
  const page = await front();
  assert.ok(page.includes(NOTICE), "control: el aviso sigue");
  assert.equal(powered(page), null, "__return_false la quita");
});

test("consent (0.5.0): el enlace «Configurar cookies» con el nombre nuevo, con el VIEJO (alias) y por shortcode", async () => {
  await reset();
  await setup({ categories: ["maps"] });
  const { url } = await seedPage(
    '<!-- wp:estrategia-web-site/consent {"label":"Mis cookies"} /-->' +
      "<!-- wp:armazon/consent /-->" +
      "<!-- wp:armazon-site/consent /-->" +
      '<!-- wp:shortcode -->[estrategia_web_consent_settings label="Por shortcode"]<!-- /wp:shortcode -->',
    "prueba-enlace-cookies"
  );
  const page = await front(new URL(url).pathname);
  const links = [...page.matchAll(/<p ([^>]*estrategia-web-consent-link[^>]*)><button type="button" class="estrategia-web-consent-link__button" aria-controls="estrategia-web-consent"[^>]*>([^<]*)<\/button><\/p>/g)].map((m) => ({ cls: m[1], label: m[2] }));
  assert.equal(links.length, 4, `tendría que haber cuatro enlaces y hay ${links.length}`);
  assert.match(links[0].cls, /wp-block-estrategia-web-site-consent/);
  assert.equal(links[0].label, "Mis cookies");
  // Los dos nombres viejos (armazon/consent ≤ padre 0.6.0, armazon-site/consent ≤ 0.5.4) pintan lo MISMO que el nuevo.
  assert.match(links[1].cls, /wp-block-estrategia-web-site-consent\b/, "el alias armazon/consent pinta como el bloque nuevo");
  assert.equal(links[1].label, "Configurar cookies");
  assert.match(links[2].cls, /wp-block-estrategia-web-site-consent\b/, "el alias armazon-site/consent pinta como el bloque nuevo");
  assert.equal(links[3].label, "Por shortcode");

  await setup({});
  const none = await front(new URL(url).pathname);
  assert.ok(!none.includes("estrategia-web-consent-link"), "sin categorías, el enlace no se pinta (no hay aviso que reabrir)");
});
