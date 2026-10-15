// NIVEL: 1 — sin navegador ni emulador. Los nombres con prefijo de los tipos y taxonomías (1.2.0), el renombrado del contenido del migrador y el alias de las Consultas viejas, con el PHP de la máquina.
//
// Por qué existe (15-10-2026): WordPress.org exige prefijo y WordPress corta
// un tipo en 20 caracteres. El prefijo vive en UN sitio (Type_Names::PREFIX,
// «eweb_» desde el 15-10-2026); aquí nada lo escribe a fuego: se lee y se
// compara contra él, así que la prueba vale igual si un día cambiara.
//
// Vista en rojo (15-10-2026): con Unprefixed_Names::tax_query() sin entrar
// en «include»/«exclude» (el formato de WordPress 6.8, que ya usa la semilla
// de Lavandería), «alias: una Consulta guardada…» falla.
import { test } from "node:test";
import assert from "node:assert/strict";
import { spawnSync } from "node:child_process";
import path from "node:path";

const r = spawnSync("php", [path.join(import.meta.dirname, "php", "prefix.php")], { encoding: "utf8" });
assert.equal(r.status, 0, "php no corrió: " + (r.error?.message || r.stderr || r.stdout));
const out = JSON.parse(r.stdout);
const P = out.prefix;
const n = (key) => P + key;

test("el prefijo cabe: tipo ≤ 20 caracteres y taxonomía ≤ 32 (límites de WordPress)", () => {
  assert.ok(P.length > 0 && P.length <= 9, `prefijo «${P}»: entre 1 y 9 caracteres`);
  assert.match(P, /^[a-z][a-z0-9_]*$/, "minúsculas, cifras y guion bajo");
  for (const [, name] of Object.entries(out.renamed_types)) assert.ok(name.length <= 20, `${name} pasa de 20`);
  for (const [, name] of Object.entries(out.renamed_taxes)) assert.ok(name.length <= 32, `${name} pasa de 32`);
});

test("las claves son las de la opción: Type_Names::KEYS = Settings::REWRITE_NAMES, y los tipos = Settings::TYPES", () => {
  assert.deepEqual([...out.keys].sort(), [...out.rewrite_names].sort());
  assert.deepEqual(Object.keys(out.renamed_types).sort(), [...out.types].sort());
});

test("name() y key() van y vuelven; lo que no es nuestro sale igual o vacío", () => {
  assert.equal(out.name_client, n("client"));
  assert.equal(out.name_page, "page");
  assert.equal(out.key_back, "client_sector");
  assert.equal(out.key_foreign, "", "«client» sin prefijo no es un nombre registrado nuestro");
});

test("migrador: postType y taxQuery de una Consulta, y el texto de la página NO", () => {
  assert.ok(out.markup_once.includes(`"postType":"${n("client")}"`));
  assert.ok(out.markup_once.includes(`"taxQuery":{"${n("client_sector")}":[12]}`));
  assert.ok(out.markup_once.includes("<p>Nuestros clientes: client, location</p>"), "el texto no se toca");
  assert.equal(out.markup_count, 2);
});

test("migrador: idempotente (la segunda pasada no cambia nada)", () => {
  assert.equal(out.markup_twice, out.markup_once);
  assert.equal(out.markup_count2, 0);
});

test("migrador: taxQuery con «include» (WordPress 6.8)", () => {
  assert.ok(out.markup_include.includes(`"include":{"${n("client_sector")}":[3,4]}`), out.markup_include);
});

test("migrador: post-terms y navigation-link sí; postType post y un párrafo que lo cite, no", () => {
  assert.ok(out.markup_others.includes(`"postType":"post"`));
  assert.ok(out.markup_others.includes(`{"term":"${n("client_sector")}"}`));
  assert.ok(out.markup_others.includes(`"type":"${n("location")}"`));
  assert.ok(out.markup_others.includes(`<p>"postType":"client"</p>`), "un párrafo no es un bloque Consulta");
  assert.equal(out.markup_others_n, 2);
});

test("alias: una Consulta guardada con los nombres viejos pide los nuevos", () => {
  assert.deepEqual(out.alias_query, { postType: n("client"), taxQuery: { [n("client_sector")]: [12] } });
  assert.deepEqual(out.alias_include, { postType: n("location"), taxQuery: { include: { [n("client_sector")]: [3] } } });
  assert.deepEqual(out.alias_new, { postType: n("client") });
  assert.deepEqual(out.alias_post, { postType: "post" });
  assert.equal(out.alias_terms.attrs.term, n("client_sector"));
});
