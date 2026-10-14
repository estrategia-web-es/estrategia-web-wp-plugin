// NIVEL: 3 — navegador. NECESITA NAVEGADOR PORQUE: el aviso se decide en el navegador (cookie, módulo ES de Interactivity), la API y el evento son JS, y el peso visual y el contraste son estilo calculado.
//
// Aviso de cookies (0.5.0, vino del tema padre con su contrato). Qué es, en la
// cabecera de includes/class-consent.php. Aquí:
// - sale; Aceptar y Rechazar pesan igual (AEPD); rechazar guarda la cookie
//   con el formato del padre y persiste; no ejecuta lo rechazado;
// - el enlace «Configurar cookies» (nombre nuevo y alias viejo) lo reabre;
// - contrato JS: window.estrategiaWebConsent (categories, has, get, open, grant) y
//   el evento «estrategia-web:consent» con detail.accepted; aceptar activa los
//   <script type="text/plain" data-estrategia-web-consent>;
// - «estrategia-web:consent-ready» (0.5.0) sale una vez al arrancar, también sin
//   elección: Matomo lo necesita (ver la trampa 1 de class-matomo.php);
// - una cookie guardada por el aviso del PADRE (mismo formato y revisión)
//   vale: el aviso no vuelve a salir;
// - la línea «Powered by»: se ve, es más pequeña que los botones, y su
//   contraste con el fondo del aviso es AA (≥ 4,5:1), MEDIDO;
// - y Matomo híbrido contra este aviso REAL está en tests/matomo-browser.test.mjs.
//
// El tema es el de por defecto de Playground: el aviso no depende del tema.
//
// Vista fallar el 05-10-2026 con seis mutantes, cada uno pillado: rel con
// nofollow, revisión distinta (la cookie del padre deja de valer), alias sin
// registrar, sin el evento estrategia-web:consent, sin estrategia-web:consent-ready y el
// «Powered by» en #aaa (contraste 2,3:1).
import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import crypto from "node:crypto";
import { BASE, playwright, reset, seedPage, testApi } from "../../../tests/lib.mjs";

const setup = (body) => testApi("/consent-setup", { method: "POST", body });
const md5 = (s) => crypto.createHash("md5").update(s).digest("hex").slice(0, 8);

let browser;
let pageUrl;
before(async () => {
  browser = await playwright().chromium.launch();
  await reset();
  // theme_css: lo que emite el theme.json de Lavandería (ver la prueba del hueco).
  await setup({ categories: ["statistics", "maps"], script: true, powered_by: { show: true }, theme_css: ":root :where(p){margin-top:0;margin-bottom:0}" });
  ({ url: pageUrl } = await seedPage('<!-- wp:paragraph --><p>Pie</p><!-- /wp:paragraph --><!-- wp:estrategia-web-site/consent /--><!-- wp:armazon/consent {"label":"Alias viejo"} /-->', "prueba-aviso"));
});
after(() => browser?.close());

async function open(cookies = []) {
  const ctx = await browser.newContext({ viewport: { width: 1200, height: 900 } });
  if (cookies.length) await ctx.addCookies(cookies);
  const page = await ctx.newPage();
  page.errors = [];
  page.on("pageerror", (e) => page.errors.push(e.message));
  await page.goto(pageUrl, { waitUntil: "networkidle" });
  return { ctx, page };
}

const notice = (page) => page.locator("#estrategia-web-consent");

test("consent (0.5.0): sale, aceptar y rechazar pesan igual, rechazar guarda la cookie del padre y persiste", async () => {
  const { ctx, page } = await open();
  await notice(page).waitFor({ state: "visible" });
  const look = (name) =>
    page.getByRole("button", { name, exact: true }).evaluate((el) => {
      const s = getComputedStyle(el);
      return [s.backgroundColor, s.color, s.fontSize, s.fontWeight, s.paddingTop, s.paddingLeft, s.borderTopWidth, el.offsetHeight].join("|");
    });
  assert.equal(await look("Aceptar todas"), await look("Rechazar todas"), "aceptar y rechazar no pesan igual");

  await page.getByRole("button", { name: "Rechazar todas" }).click();
  await notice(page).waitFor({ state: "hidden" });
  const cookie = (await ctx.cookies()).find((c) => c.name === "estrategia_web_consent");
  assert.ok(cookie, "no se guardó la cookie estrategia_web_consent");
  assert.match(decodeURIComponent(cookie.value), /^[0-9a-f]{8}\.\.\d+$/, `formato de cookie inesperado: ${cookie.value}`);
  await page.reload({ waitUntil: "networkidle" });
  await page.waitForFunction(() => window.estrategiaWebConsent);
  assert.ok(await notice(page).isHidden(), "el aviso vuelve a salir tras rechazar");
  assert.deepEqual(await page.evaluate(() => window.estrategiaWebConsent.get()), []);
  assert.equal(await page.evaluate(() => window.estrategiaWebTestStatistics), undefined, "se ejecutó un script de estadísticas rechazado");
  assert.deepEqual(page.errors, []);
  await ctx.close();
});

test("consent (0.5.0): el enlace del pie lo reabre (nombre nuevo y alias armazon/consent), con el foco dentro, y Escape lo cierra", async () => {
  const { ctx, page } = await open();
  await page.getByRole("button", { name: "Rechazar todas" }).click();
  await notice(page).waitFor({ state: "hidden" });
  for (const name of ["Configurar cookies", "Alias viejo"]) {
    await page.getByRole("button", { name, exact: true }).click();
    await notice(page).waitFor({ state: "visible" });
    await page.waitForFunction(() => document.activeElement?.id === "estrategia-web-consent-title");
    await page.keyboard.press("Escape");
    await notice(page).waitFor({ state: "hidden" });
  }
  await ctx.close();
});

test("consent (0.5.0): contrato JS — evento estrategia-web:consent con detail.accepted, has/get/categories, scripts diferidos, grant y open", async () => {
  const { ctx, page } = await open();
  await notice(page).waitFor({ state: "visible" });
  assert.deepEqual(await page.evaluate(() => window.estrategiaWebConsent.categories), ["statistics", "maps"]);
  assert.equal(await page.evaluate(() => window.estrategiaWebConsent.get()), null, "sin elegir, get() es null");
  assert.equal(await page.evaluate(() => window.estrategiaWebConsent.has("necessary")), true);

  // grant: añade UNA categoría, guarda y lanza el evento; el aviso sigue abierto.
  await page.evaluate(() => {
    window.seen = [];
    document.addEventListener("estrategia-web:consent", (e) => window.seen.push(e.detail.accepted));
  });
  await page.evaluate(() => window.estrategiaWebConsent.grant("maps"));
  assert.deepEqual(await page.evaluate(() => window.seen), [["maps"]]);
  assert.deepEqual(await page.evaluate(() => window.estrategiaWebConsent.get()), ["maps"]);
  assert.ok(await notice(page).isVisible(), "grant no cierra el aviso");
  await page.evaluate(() => window.estrategiaWebConsent.grant("chat"));
  assert.deepEqual(await page.evaluate(() => window.estrategiaWebConsent.get()), ["maps"], "una categoría no declarada no se concede");

  // Aceptar todas: evento con las dos, script diferido activado.
  await page.getByRole("button", { name: "Aceptar todas" }).click();
  await page.waitForFunction(() => window.estrategiaWebTestStatistics === true);
  assert.deepEqual(await page.evaluate(() => window.seen.at(-1)), ["statistics", "maps"]);
  assert.equal(await page.evaluate(() => window.estrategiaWebConsent.has("statistics")), true);

  // Al recargar: sin aviso, evento al arrancar con la elección guardada.
  await page.addInitScript(() => {
    window.seen = [];
    document.addEventListener("estrategia-web:consent", (e) => window.seen.push(e.detail.accepted));
  });
  await page.reload({ waitUntil: "networkidle" });
  await page.waitForFunction(() => window.estrategiaWebTestStatistics === true);
  assert.ok(await notice(page).isHidden(), "el aviso vuelve a salir tras aceptar");
  assert.deepEqual(await page.evaluate(() => window.seen), [["statistics", "maps"]], "el evento se lanza al arrancar si ya había elección");

  // open() lo reabre con lo elegido marcado.
  await page.evaluate(() => window.estrategiaWebConsent.open());
  await notice(page).waitFor({ state: "visible" });
  assert.equal(await page.getByRole("checkbox", { name: /Analítica/ }).isChecked(), true, "1.1.0: «statistics» se rotula «Analítica»");
  assert.deepEqual(page.errors, []);
  await ctx.close();
});

test("consent (0.5.0): una elección guardada por el aviso del PADRE (≤ 0.6.0) sigue valiendo: no vuelve a salir", async () => {
  // La cookie tal como la escribía armazon-wp-padre/blocks/consent/view.js.
  const value = `${md5("statistics,maps|1")}.maps.${Math.floor(Date.now() / 1000)}`;
  const { ctx, page } = await open([{ name: "estrategia_web_consent", value: encodeURIComponent(value), url: BASE + "/" }]);
  await page.waitForFunction(() => window.estrategiaWebConsent);
  assert.ok(await notice(page).isHidden(), "el aviso vuelve a salir a quien ya eligió con el padre");
  assert.deepEqual(await page.evaluate(() => window.estrategiaWebConsent.get()), ["maps"]);
  await ctx.close();
});

test("consent (0.5.0): «estrategia-web:consent-ready» se lanza UNA vez al arrancar, también sin elección (null), con la API ya puesta", async () => {
  const ctx = await browser.newContext();
  await ctx.addInitScript(() => {
    window.ready = [];
    document.addEventListener("estrategia-web:consent-ready", (e) => window.ready.push([e.detail.accepted, typeof window.estrategiaWebConsent?.get]));
  });
  const page = await ctx.newPage();
  await page.goto(pageUrl, { waitUntil: "networkidle" });
  await page.waitForFunction(() => window.ready.length > 0);
  assert.deepEqual(await page.evaluate(() => window.ready), [[null, "function"]]);
  await page.getByRole("button", { name: "Rechazar todas" }).click();
  await page.reload({ waitUntil: "networkidle" });
  await page.waitForFunction(() => window.ready.length > 0);
  assert.deepEqual(await page.evaluate(() => window.ready), [[[], "function"]]);
  await ctx.close();
});

test("consent (0.5.1): abierto, es lo PRIMERO al tabular; se sale con Tab (sin trampa) y Escape no lo cierra sin elegir", async () => {
  const { ctx, page } = await open();
  await notice(page).waitFor({ state: "visible" });
  await page.keyboard.press("Tab");
  const inside = () => page.evaluate(() => !!document.activeElement?.closest("#estrategia-web-consent"));
  assert.ok(await inside(), `el primer Tab va a ${await page.evaluate(() => document.activeElement?.outerHTML.slice(0, 80))}, no al aviso`);
  // Sin trampa: se sale con Tab en, como mucho, tantos pasos como enfocables tiene.
  const stops = await page.evaluate(() => document.querySelectorAll("#estrategia-web-consent a[href], #estrategia-web-consent button:not([disabled])").length);
  let left = false;
  for (let i = 0; i <= stops && !left; i++) {
    await page.keyboard.press("Tab");
    left = !(await inside());
  }
  assert.ok(left, `tras ${stops + 1} tabuladores el foco sigue dentro del aviso: trampa de foco`);
  assert.ok(await notice(page).isVisible(), "salir con Tab no debe cerrar el aviso");
  await page.keyboard.press("Shift+Tab");
  assert.ok(await inside(), "Mayús+Tab no vuelve al aviso");
  await page.keyboard.press("Escape");
  assert.ok(await notice(page).isVisible(), "la primera vez, Escape no lo cierra: hay que elegir");
  assert.deepEqual(page.errors, []);
  await ctx.close();
});

test("consent (0.5.1): entre el texto y los botones hay al menos 16 px, a 402 y a 1512", async () => {
  for (const width of [402, 1512]) {
    const ctx = await browser.newContext({ viewport: { width, height: 900 } });
    const page = await ctx.newPage();
    await page.goto(pageUrl, { waitUntil: "networkidle" });
    await notice(page).waitFor({ state: "visible" });
    const m = await page.evaluate(() => {
      const text = document.querySelector(".estrategia-web-consent__text");
      const title = document.querySelector(".estrategia-web-consent__title");
      const range = document.createRange();
      range.selectNodeContents(text);
      const lastLine = Math.max(...[...range.getClientRects()].map((r) => r.bottom));
      const button = document.querySelector(".estrategia-web-consent__actions").getBoundingClientRect().top;
      return { gap: button - lastLine, titleGap: text.getBoundingClientRect().top - title.getBoundingClientRect().bottom };
    });
    assert.ok(m.gap >= 16, `${width}: entre la última línea del texto y los botones hay ${m.gap.toFixed(1)} px`);
    assert.ok(m.titleGap >= 4, `${width}: título y texto pegados (${m.titleGap.toFixed(1)} px)`);
    console.log(`# ${width}: texto → botones ${m.gap.toFixed(1)} px; título → texto ${m.titleGap.toFixed(1)} px`);
    await ctx.close();
  }
});

/** Contraste WCAG entre dos colores rgb()/rgba() (el de delante sin alfa). */
function contrast(fg, bg) {
  const parse = (s) => s.match(/[\d.]+/g).slice(0, 3).map(Number);
  const lum = ([r, g, b]) => {
    const c = [r, g, b].map((v) => {
      v /= 255;
      return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4;
    });
    return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2];
  };
  const [a, b] = [lum(parse(fg)), lum(parse(bg))].sort((x, y) => y - x);
  return (a + 0.05) / (b + 0.05);
}

test("consent (0.5.0): «Powered by» visible, pequeño (no compite con los botones) y con contraste AA MEDIDO", async () => {
  const { ctx, page } = await open();
  await notice(page).waitFor({ state: "visible" });
  const link = page.getByRole("link", { name: /Powered by Estrategia Web/ });
  assert.ok(await link.isVisible(), "no se ve el enlace «Powered by»");
  assert.equal(await link.getAttribute("rel"), "noopener");
  assert.equal(await link.getAttribute("target"), "_blank");
  const m = await link.evaluate((el) => {
    let bg = "rgba(0, 0, 0, 0)";
    for (let n = el; n; n = n.parentElement) {
      const c = getComputedStyle(n).backgroundColor;
      if (!/rgba\(.*,\s*0\)$/.test(c) && c !== "transparent") {
        bg = c;
        break;
      }
    }
    const s = getComputedStyle(el);
    const button = getComputedStyle(document.querySelector(".estrategia-web-consent__button"));
    return { color: s.color, bg, opacity: s.opacity, size: parseFloat(s.fontSize), buttonSize: parseFloat(button.fontSize) };
  });
  assert.equal(m.opacity, "1", "la línea no debe ir con opacidad (el contraste medido dejaría de valer)");
  assert.ok(!/rgba\(.*,\s*0?\.\d+\)$/.test(m.color), `color con transparencia: ${m.color}`);
  const ratio = contrast(m.color, m.bg);
  assert.ok(ratio >= 4.5, `contraste ${ratio.toFixed(2)}:1 (${m.color} sobre ${m.bg}); AA pide 4,5:1`);
  assert.ok(m.size < m.buttonSize, `la línea (${m.size}px) no es más pequeña que los botones (${m.buttonSize}px)`);
  console.log(`# Powered by: ${m.color} sobre ${m.bg} = ${ratio.toFixed(2)}:1, ${m.size}px (botones ${m.buttonSize}px)`);
  await ctx.close();
});
