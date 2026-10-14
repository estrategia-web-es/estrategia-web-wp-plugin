// NIVEL: 1 — sin navegador ni emulador. El registro de cookies (propuestas de la base propia y de la Open Cookie Database, comodines, límites contra la inundación) y los alias de las categorías, con el PHP de la máquina.
//
// Qué es el registro y por qué: cabecera de includes/class-cookie-registry.php.
// Si no hay `php` en el PATH, la prueba FALLA (no se salta), como la de site.
//
// Vista fallar el 14-10-2026 con tres mutantes: match_key con el prefijo más
// CORTO (gana _g* sobre _ga_*), _pk_id.* quitado de base() (sale «Piwik» de
// la base externa) y name_ok sin excluir «=» (se guardaría «x=secreto»).
//
// Cómo se mantiene: un caso nuevo, su clave en tests/php/pure.php y su assert aquí.
import { test } from "node:test";
import assert from "node:assert/strict";
import { spawnSync } from "node:child_process";
import path from "node:path";

const r = spawnSync("php", [path.join(import.meta.dirname, "php", "pure.php")], { encoding: "utf8" });
assert.equal(r.status, 0, "php no corrió: " + (r.error?.message || r.stderr));
const out = JSON.parse(r.stdout);

test("cookies: el nombre exacto gana a cualquier patrón; entre patrones, el prefijo más largo", () => {
  assert.equal(out.match.exact, "_ga");
  assert.equal(out.match.prefix, "_ga_*");
  assert.equal(out.match.longest, "wp-settings-time-*");
  assert.equal(out.match.short, "_g*");
  assert.equal(out.match.none, null);
});

test("cookies: wp-wpml_current_language (se pone ANTES del consentimiento) es NECESARIA, de WPML, 1 día", () => {
  const s = out.suggest["wp-wpml_current_language"];
  assert.equal(s.category, "necessary");
  assert.equal(s.provider, "WPML");
  assert.equal(s.duration, "1 day");
  assert.equal(s.suggested, "base", "la decide nuestra lista, no la base externa");
});

test("cookies: Google Analytics de la Open Cookie Database, con _ga_<id> como patrón _ga_*", () => {
  assert.equal(out.suggest._ga.key, "_ga");
  assert.equal(out.suggest._ga.category, "statistics");
  assert.equal(out.suggest._ga_ABC123.key, "_ga_*");
  assert.equal(out.suggest._ga_ABC123.provider, "Google Analytics");
  assert.equal(out.suggest._gcl_au.category, "marketing");
  assert.equal(out.suggest.inventada_xyz, null);
});

test("cookies: _pk_id.<site>.<id> es de MATOMO (base propia), no de Piwik PRO por prefijo más largo", () => {
  const s = out.suggest["_pk_id.87.a1b2"];
  assert.equal(s.key, "_pk_id.*");
  assert.equal(s.provider, "Matomo");
  assert.equal(s.category, "statistics");
});

test("cookies: la del propio aviso es necesaria", () => {
  assert.equal(out.suggest.estrategia_web_consent.category, "necessary");
});

test("cookies: solo nombres con caracteres de cookie (ni valores, ni espacios, ni HTML, ≤ 128)", () => {
  assert.deepEqual(out.name_ok, { normal: true, pattern: true, value: false, space: false, long: false, html: false });
});

test("cookies: el detector agrupa _ga_ABC123 y _ga_ZZZ999 en _ga_*, actualiza «visto» y NO guarda valores", () => {
  assert.deepEqual(Object.keys(out.recorded).sort(), ["_ga", "_ga_*", "wp-wpml_current_language"]);
  assert.equal(out.recorded["_ga_*"].example, "_ga_ABC123");
  assert.equal(out.recorded["_ga_*"].first_seen, 1000);
  assert.equal(out.recorded["_ga_*"].last_seen, 2000);
  assert.equal(out.recorded["wp-wpml_current_language"].last_seen, 1000);
  assert.ok(!JSON.stringify(out.recorded).includes("secreto"), "un «nombre=valor» no entra");
  for (const entry of Object.values(out.recorded)) assert.equal(entry.status, "pending", "lo detectado espera a que lo confirme el administrador");
});

test("cookies: el registro no pasa de MAX_ENTRIES aunque lleguen más nombres", () => {
  assert.equal(out.full_count, 300);
  assert.equal(out.full.added, 300);
});

test("cookies: a mano, lo vacío se rellena con la propuesta y lo escrito se respeta; un nombre malo es un error", () => {
  assert.equal(out.save_manual.category, "maps");
  assert.equal(out.save_manual.purpose, "Mapas de Google");
  assert.equal(out.save_manual.provider, "Google", "proveedor de la propuesta");
  assert.equal(out.save_manual.source, "manual");
  assert.equal(out.save_bad, true);
});

test("cookies: duraciones que se traducen solas y texto libre", () => {
  assert.deepEqual(out.duration.years, [2, "year"]);
  assert.deepEqual(out.duration.one, [1, "day"]);
  assert.deepEqual(out.duration.session, [0, "session"]);
  assert.equal(out.duration.free, null);
});

test("consent 1.1.0: alias de categorías y orden de enseñar SIN tocar el de la revisión", () => {
  assert.deepEqual(out.normalize, ["statistics", "marketing", "marketing", "maps"]);
  assert.deepEqual(out.display, ["preferences", "statistics", "marketing", "maps"]);
  assert.deepEqual(out.known_order, ["statistics", "maps", "marketing", "preferences"], "preferences va AL FINAL: el orden entra en la revisión");
});

test("cookies: el extracto lleva su licencia y su atribución (Apache 2.0)", () => {
  assert.equal(out.ocd.license, "Apache-2.0");
  assert.match(out.ocd.attribution, /Open Cookie Database/);
  assert.ok(out.ocd.count > 1000, `el extracto tiene ${out.ocd.count} filas`);
});
