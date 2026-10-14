// NIVEL: 1 — sin navegador ni emulador. Lo puro del módulo redirects (includes/class-rules.php) con el PHP de la máquina: normalizar, casar, leer el CSV REAL de Lavandería (83 reglas) y validar bucles, duplicados y destinos.
//
// Si no hay `php` en el PATH, la prueba FALLA (no se salta).
//
// Vista en rojo (14-10-2026): sin volver a partir por «?» después de
// decodificar (paso 3 de class-rules.php), «el %3F de las URL viejas y el ?
// literal son la misma clave» falla con /en/%3Fp=30671 frente a /en/?p=30671.
// Ojo: con /index.html%3Fp=6981.html NO falla, porque la clave sin partir
// coincide letra a letra con la partida; por eso los casos con barra final
// y con page_id, que son los que la delatan.
import { test } from "node:test";
import assert from "node:assert/strict";
import { spawnSync } from "node:child_process";
import path from "node:path";

const r = spawnSync("php", [path.join(import.meta.dirname, "php", "pure.php")], { encoding: "utf8" });
assert.equal(r.status, 0, "php no corrió: " + (r.error?.message || r.stderr || r.stdout));
const out = JSON.parse(r.stdout);

test("normalizar: sin dominio, sin barra final, minúsculas, %xx decodificado", () => {
  const k = out.key;
  assert.equal(k["/Contacto/"], "/contacto");
  assert.equal(k["https://www.ejemplo.com/contacto/"], "/contacto");
  assert.equal(k["/contacto/#form"], "/contacto");
  assert.equal(k["/a//b/"], "/a/b");
  assert.equal(k["/caf%C3%A9/"], "/café");
  assert.equal(k[""], "", "un origen vacío no es una clave");
  assert.equal(out.key_base, "/viejo", "WordPress en subcarpeta: se quita la base");
});

test("el %3F de las URL viejas y el ? literal son la misma clave; page_id es p", () => {
  assert.equal(out.key["/index.html%3Fp=6981.html"], out.key["/index.html?p=6981.html"]);
  assert.equal(out.key["/?page_id=5"], out.key["/?p=5"]);
  assert.equal(out.key["/en/%3Fp=30671"], out.key["/en/?p=30671"], "la barra antes del %3F, como antes del ?");
  assert.equal(out.key["/index.html%3Fpage_id=6981"], out.key["/index.html?p=6981"], "page_id = p también detrás de un %3F");
  assert.equal(out.cand_3f[0], out.key["/index.html%3Fp=6981.html"], "la petición con %3F busca primero su clave exacta");
});

test("la query que no es del origen se ignora al casar", () => {
  assert.ok(out.cand_utm.includes("/contacto"), "/contacto/?utm_source=… casa con /contacto/");
  assert.ok(out.cand_p_lang.includes("/?p=6981"), "/?p=6981&lang=es casa con /?p=6981");
  assert.equal(out.cand_p_lang.indexOf("/?p=6981"), 1, "p va justo después de la query entera");
  assert.equal(out.cand_p_lang.at(-1), "/", "la ruta sola, la última");
  assert.ok(out.cand_long <= 13, "una query de 200 parámetros no genera 200 claves: " + out.cand_long);
});

test("el CSV real de Lavandería se lee entero y valida sin un error", () => {
  const c = out.csv;
  assert.equal(c.rows, 83);
  assert.deepEqual(c.errors, []);
  assert.equal(c.valid, 83);
  assert.deepEqual(c.types, { 301: 69, 410: 14 });
  assert.equal(c.self_match, 83, "cada origen, pedido tal cual, casa con su regla");
  assert.equal(c.inactive, 0, "ninguna lleva «revisar»: todas activas");
  assert.deepEqual(c.first, { line: 2, source: "/index.html", target: "/", type: "301", note: "Portada (variante de /)", active: true });
  assert.match(c.quoted, /hoteles, barcos, restaurantes/, "las comillas con comas dentro");
  assert.equal(c.empty_410, "");
});

test("validar: bucles, destino igual al origen, duplicados, destinos y tipos", () => {
  const v = out.validate;
  assert.equal(v.loop2, "loop", "A→B y B→A");
  assert.equal(v.loop3, "loop", "A→B→C y C→A");
  assert.equal(v.loop_abs, "loop", "el bucle con URL absoluta de la propia web");
  assert.equal(v.loop_inactive, "loop", "una regla apagada también cuenta: encenderla en lote no pasa por aquí");
  assert.equal(v.chain_ok, "", "una cadena sin bucle se deja");
  assert.equal(v.same, "same_target");
  assert.equal(v.same_abs, "same_target");
  assert.equal(v.external_ok, "");
  assert.equal(v.duplicate, "duplicate", "/A/ y /a son el mismo origen");
  assert.equal(v.dup_3f, "duplicate", "%3F y ? son el mismo origen");
  assert.equal(v.empty_target, "empty_target");
  assert.equal(v.bad_target, "bad_target");
  assert.equal(v.bad_target_js, "bad_target");
  assert.equal(v.bad_type, "bad_type");
  assert.equal(v.default_type, 301, "sin tipo, 301");
  assert.equal(v.home, "home_source");
  assert.equal(v.home_p_ok, "", "/?p=ID sí se puede");
  assert.equal(v.foreign_source, "foreign_source");
  assert.equal(v.gone_target, "", "un 410 no guarda destino");
});

test("CSV con punto y coma, BOM, fila «revisar» (entra apagada) y sin cabecera", () => {
  assert.equal(out.semi.length, 2, "la línea vacía no cuenta");
  assert.deepEqual(out.semi[0], { line: 2, source: "/a/", target: "/b/", type: "302", note: "=SUMA(1) [revisar: dudoso]", active: false });
  assert.equal(out.semi[1].type, "410");
  assert.deepEqual(out.noheader[0], { line: 1, source: "/a/", target: "/b/", type: "301", note: "nota", active: true });
  assert.deepEqual(out.cell, ["'=1+1", "/a/", "'-x"], "el CSV exportado no lleva fórmulas para Excel");
});
