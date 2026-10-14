// NIVEL: 1 — sin navegador ni emulador. Las clases puras (marcadores, IP, token, preselección) con el PHP de la máquina, y una regla del CSS base.
//
// Si no hay `php` en el PATH, la prueba FALLA (no se salta): una prueba que
// se salta en silencio parece que cubre algo. En esta máquina está en
// C:\wamp64\bin\php\php8.3.6.
import { test } from "node:test";
import assert from "node:assert/strict";
import { spawnSync } from "node:child_process";
import path from "node:path";
import fs from "node:fs";

const r = spawnSync("php", [path.join(import.meta.dirname, "php", "pure.php")], { encoding: "utf8" });
assert.equal(r.status, 0, "php no corrió: " + (r.error?.message || r.stderr));
const out = JSON.parse(r.stdout);

test("marcadores: conocido, con espacios y mayúsculas, vacío y desconocido", () => {
  assert.equal(out.known, "Hola Ana <b>");
  assert.equal(out.spaces_case, "Hola Ana <b>");
  assert.equal(out.empty_known, "Tel: .", "un campo opcional vacío se sustituye por nada");
  assert.equal(out.unknown_kept, "Mal: {{emial}}", "una errata en la plantilla se tiene que ver en el correo");
});

test("marcadores: se escapan en HTML salvo los declarados como HTML ya montado", () => {
  assert.equal(out.escaped, "Ana &lt;b&gt;");
  assert.equal(out.raw_not_escaped, "<table>");
});

test("asunto en una sola línea: no hay inyección de cabeceras", () => {
  assert.equal(out.single_line, "Asunto Bcc: x@y.z");
});

test("IP truncada (RGPD)", () => {
  assert.equal(out.ipv4, "203.0.113.0");
  assert.equal(out.ipv6, "2001:db8:85a3::");
  assert.equal(out.ip_bad, "");
});

test("token: bueno, demasiado rápido, caducado, de otra definición, manipulado", () => {
  assert.equal(out.token_ok, "ok");
  assert.equal(out.token_fast, "too_fast");
  assert.equal(out.token_expired, "expired");
  assert.equal(out.token_other_def, "invalid");
  assert.equal(out.token_tampered, "invalid", "cambiar la hora del token rompe la firma");
  assert.equal(out.token_garbage, "invalid");
});

test("preselección: por posición, por texto exacto y por slug", () => {
  assert.equal(out.pre_position, "Lavandería para barcos y yates", "la posición empieza en 1");
  assert.equal(out.pre_position_str, "Lavandería para barcos y yates", "la posición también llega como texto (URL)");
  assert.equal(out.pre_text, "Lavandería para restaurantes");
  assert.equal(out.pre_slug, "Lavandería para restaurantes", "el slug evita %20 y tildes en los enlaces");
});

test("preselección: lo que no está entre las opciones no se pinta nunca", () => {
  assert.equal(out.pre_out_of_range, "", "posición fuera de la lista");
  assert.equal(out.pre_zero, "", "0 es «ninguna»");
  assert.equal(out.pre_unknown, "", "un texto que no es opción no se cuela en el select");
  assert.equal(out.pre_empty_slug, "", "un slug vacío no casa con nada");
  assert.equal(out.pre_negative, "");
});

test("estilo base: el plugin no quita la flecha nativa del desplegable (el chevron propio es del tema)", () => {
  const css = fs.readFileSync(path.join(import.meta.dirname, "..", "assets", "style.css"), "utf8").replace(/\/\*[\s\S]*?\*\//g, "");
  assert.doesNotMatch(css, /appearance\s*:\s*none/, "con appearance:none en el plugin, un tema que pinte su fondo con el atajo «background» deja el select como una caja lisa");
});

test("preselección: gana la primera fuente válida; una inválida cede a la siguiente", () => {
  assert.equal(out.pre_first_wins, "Lavandería para hoteles");
  assert.equal(out.pre_falls_through, "Lavandería para barcos y yates", "una URL mala no borra el valor de la página");
  assert.equal(out.pre_none, "");
});
