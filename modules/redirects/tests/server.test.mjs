// NIVEL: 2 — WordPress de Playground, porque lo probado es lo que contesta WordPress a una petición de verdad (404 → 301/410), con la tabla, el contador y el registro de 404 en su base de datos.
//
// Importa el CSV REAL de Lavandería (83 reglas) y pide sus orígenes tal cual.
// También mide lo que cuesta: las consultas a las tablas del módulo en una
// página que existe (cero) y en un 404, y el tiempo de una búsqueda.
//
// Vista en rojo (14-10-2026): con Runtime sin mirar is_404() (consultando
// en todas las peticiones), «una página que existe no toca la tabla» falla:
// la regla /nosotros/ del CSV se come la página viva /nosotros/ con un 301.
import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { BASE, CSV, api, coreApi, reset, get, seedPage } from "./lib.mjs";

before(() => reset());
after(async () => {
  await api("/reset", { method: "POST" });
  await api("/setup", { method: "POST", body: { on: false } });
});

test("apagado por defecto: una web sin la opción guardada no lo carga", async () => {
  await coreApi("/modules", { method: "POST", body: { active: null } });
  const m = await coreApi("/modules");
  assert.ok(m.available.includes("redirects"));
  assert.ok(!m.loaded.includes("redirects"));
  await api("/setup", { method: "POST", body: { on: true } });
  assert.ok((await coreApi("/modules")).loaded.includes("redirects"));
});

test("el CSV de Lavandería entra entero; dos veces no duplica; --dry-run no escribe", async () => {
  const dry = await api("/import", { method: "POST", body: { csv: CSV, dry_run: true } });
  assert.deepEqual([dry.created, dry.updated, dry.unchanged, dry.errors.length], [83, 0, 0, 0]);
  assert.equal((await api("/rules")).length, 0, "dry_run no escribe");

  const first = await api("/import", { method: "POST", body: { csv: CSV } });
  assert.deepEqual(first.errors, []);
  assert.deepEqual([first.created, first.updated, first.unchanged], [83, 0, 0]);
  const again = await api("/import", { method: "POST", body: { csv: CSV } });
  assert.deepEqual([again.created, again.updated, again.unchanged, again.errors.length], [0, 0, 83, 0]);
  assert.equal((await api("/rules")).length, 83);
});

test("los orígenes del CSV redirigen, con sus variantes (%3F, ?, page_id, utm, mayúsculas)", async () => {
  const cases = [
    ["/index.html", 301, "/"],
    ["/index.html%3Fp=6981.html", 301, "/quienes-somos/"],
    ["/index.html?p=6981.html", 301, "/quienes-somos/"],
    ["/?p=6981", 301, "/quienes-somos/"],
    ["/?page_id=6981", 301, "/quienes-somos/"],
    ["/?p=6981&utm_source=google", 301, "/quienes-somos/"],
    ["/NOSOTROS/", 301, "/quienes-somos/"],
    ["/nosotros", 301, "/quienes-somos/"],
    ["/en/?p=30671", 301, "/en/"],
    ["/en/index.html%3Fp=30694.html", 301, "/en/about-us/"],
    ["/feed/index.html", 301, "/feed/"],
  ];
  for (const [from, status, to] of cases) {
    const r = await get(from);
    assert.equal(r.status, status, from);
    assert.equal(r.location, BASE + to, from);
    assert.equal(r.by, "Estrategia Web", from);
  }
});

test("un 410 devuelve 410 y la página de «no encontrado» del tema, sin redirigir", async () => {
  for (const from of ["/lavanderia-santa-ponsa/", "/?p=30859", "/de/"]) {
    const r = await get(from);
    assert.equal(r.status, 410, from);
    assert.equal(r.location, null);
    assert.match(r.text, /<html/i, "se pinta la web");
    assert.ok(!/Fatal error|Warning:|Notice:/.test(r.text));
  }
});

test("contador y última visita", async () => {
  await get("/contact/");
  await get("/contact/?x=1");
  const rule = (await api("/rules")).find((r) => r.source === "/contact/");
  assert.equal(Number(rule.hits), 2);
  assert.match(rule.last_hit, /^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/);
});

test("una página que existe no toca la tabla; un 404 hace una consulta", async () => {
  const page = await seedPage("<!-- wp:paragraph --><p>Viva</p><!-- /wp:paragraph -->", "nosotros");
  const live = await get(new URL(page.url).pathname);
  assert.equal(live.status, 200, "la página viva gana a la regla /nosotros/ (solo actúan en los 404)");
  const l = await api("/last");
  assert.equal(l.queries, 0, "cero consultas a las tablas del módulo en una página que existe");
  console.log(`  · página que existe: ${l.total} consultas en total, 0 del módulo`);

  await get("/esto-no-existe/");
  const n = await api("/last");
  assert.equal(n.queries, 1, "un 404 sin regla: una consulta (registro de 404 apagado)");
  console.log(`  · 404 sin regla: ${n.total} consultas en total, ${n.queries} del módulo`);
});

test("validación por la misma vía que la pantalla: bucle, destino igual, duplicado", async () => {
  assert.equal((await api("/rule", { method: "POST", body: { source: "/quienes-somos/", target: "/nosotros/" } })).error, "loop", "/nosotros/ → /quienes-somos/ ya existe");
  assert.equal((await api("/rule", { method: "POST", body: { source: "/x/", target: "/X" } })).error, "same_target");
  assert.equal((await api("/rule", { method: "POST", body: { source: "/Index.html/", target: "/otra/" } })).error, "duplicate");
  const ok = await api("/rule", { method: "POST", body: { source: "/temporal/", target: "https://ejemplo.org/oferta", type: 302 } });
  assert.equal(ok.error, "");
  const r = await get("/temporal/?utm_campaign=x");
  assert.equal(r.status, 302);
  assert.equal(r.location, "https://ejemplo.org/oferta", "destino externo, sin la query de la petición");
});

test("registro de 404: apagado no apunta; encendido apunta la ruta sin la query ni datos personales; la purga lo vacía", async () => {
  await get("/ruta-perdida/?email=ana@ejemplo.test");
  assert.equal((await api("/log")).length, 0, "apagado de serie");

  await api("/settings", { method: "POST", body: { log_404: true, log_days: 30 } });
  await get("/ruta-perdida/?email=ana@ejemplo.test");
  await get("/Ruta-Perdida");
  // /index.html%3Fp=99999.html NO valdría: casa con la regla /index.html → / (la query que no es del origen se ignora).
  assert.equal((await get("/index.html%3Fp=99999.html")).location, BASE + "/");
  await get("/vieja.html%3Fp=99999.html");
  const log = await api("/log");
  assert.deepEqual(log.map((r) => [r.path, Number(r.hits)]), [["/ruta-perdida", 2], ["/vieja.html?p=99999.html", 1]]);
  assert.ok(!JSON.stringify(log).includes("ana@"), "ni rastro de la query");
  assert.ok(!Object.keys(log[0]).some((k) => /ip|agent|referer/i.test(k)), "sin columnas de IP ni navegador");

  await get("/quienes-somos-no/");
  assert.equal((await api("/last")).queries, 4, "404 NUEVO sin regla con registro: buscar regla + actualizar (0 filas) + contar el tope + insertar");
  await get("/quienes-somos-no/");
  assert.equal((await api("/last")).queries, 2, "el mismo 404 otra vez: buscar regla + actualizar");

  await api("/log-backdate", { method: "POST" });
  const p = await api("/purge", { method: "POST" });
  assert.equal(p.scheduled, true, "la purga diaria está programada");
  assert.equal((await api("/log")).length, 0);
});

test("apagar el módulo deja de redirigir y no borra las reglas", async () => {
  await api("/setup", { method: "POST", body: { on: false } });
  assert.equal((await get("/index.html%3Fp=6981.html")).status, 404);
  await api("/setup", { method: "POST", body: { on: true } });
  assert.equal((await api("/rules")).length, 84);
  assert.equal((await get("/index.html%3Fp=6981.html")).status, 301);
});

test("lo que cuesta una búsqueda (se apunta, no se exige)", async () => {
  const small = await api("/bench", { method: "POST", body: { n: 300, rules: 0 } });
  const big = await api("/bench", { method: "POST", body: { n: 300, rules: 5000 } });
  console.log(`  · ${small.rules} reglas: ${small.ms_each} ms/búsqueda · ${big.rules} reglas: ${big.ms_each} ms/búsqueda (${big.db_engine})`);
  assert.ok(big.ms_each < 50, "una búsqueda por índice no depende del número de reglas");
});
