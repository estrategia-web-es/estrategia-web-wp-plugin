// NIVEL: 1 — sin navegador ni emulador. Que un campo «copy» de wpml-config.xml escrito en la sede, el cliente o la opinión ORIGINAL pida la copia a WPML (wpml_sync_custom_field), con el PHP de la máquina y un doble de WPML.
//
// Por qué existe (14-10-2026): con WPML 5.1 real, cambiar el teléfono de la
// sede en el panel del editor de bloques dejaba la sede inglesa con el
// teléfono viejo. WPML copia en save_post (prioridad 100) solo las metas que
// han cambiado ANTES en esa misma petición, y la API REST guarda las metas
// DESPUÉS de save_post. La prueba de verdad, con WPML y el editor, se hizo en
// Lavandería local (ver la cabecera de class-meta-panel.php); esta es la que
// corre en cada commit, porque Playground no tiene WPML.
//
// Si no hay `php` en el PATH, la prueba FALLA (no se salta). En esta máquina
// está en C:\wamp64\bin\php\php8.3.6.
//
// Vista en rojo (14-10-2026): sin el add_action de sync_copy_field en
// Meta_Panel::boot(), «teléfono de la sede original» falla con [] en vez de
// la copia pedida.
import { test } from "node:test";
import assert from "node:assert/strict";
import { spawnSync } from "node:child_process";
import path from "node:path";

const run = (mode) => {
  const r = spawnSync("php", [path.join(import.meta.dirname, "php", "wpml-sync.php"), mode], { encoding: "utf8" });
  assert.equal(r.status, 0, "php no corrió: " + (r.error?.message || r.stderr || r.stdout));
  return JSON.parse(r.stdout);
};
const wpml = run("wpml");
const without = run("sin-wpml");
const PHONE = "estrategia_web_location_phone";

test("teléfono de la sede original: se pide la copia una vez (al crearlo y al cambiarlo) y la traducción lo recibe", () => {
  assert.deepEqual(wpml.phone_new, [`10:${PHONE}`]);
  assert.deepEqual(wpml.phone_changed, [`10:${PHONE}`], "una sola copia: la escritura en la traducción no vuelve a pedirla");
  assert.equal(wpml.phone_in_en, "+34 600 000 002");
  assert.deepEqual(wpml.map_embed, ["10:estrategia_web_location_map_embed"]);
});

test("borrar el teléfono en el original también se copia", () => {
  assert.deepEqual(wpml.phone_deleted, [`10:${PHONE}`]);
  assert.equal(wpml.phone_en_deleted, false, "la traducción se queda sin teléfono");
});

test("cliente y opinión: sus campos «copy» también", () => {
  assert.deepEqual(wpml.client_url, ["20:client_url"]);
  assert.deepEqual(wpml.client_fit, ["20:estrategia_web_client_image_fit"]);
  assert.deepEqual(wpml.testimonial_stars, ["30:estrategia_web_testimonial_rating"]);
});

test("no se pide copia: campos «translate», escrituras en la traducción, otros tipos", () => {
  assert.deepEqual(wpml.address_translate, [], "la dirección se traduce");
  assert.deepEqual(wpml.client_business, [], "el tipo de negocio se traduce");
  assert.deepEqual(wpml.testimonial_role, [], "el cargo se traduce");
  assert.deepEqual(wpml.on_translation, [], "en la traducción no se copia hacia ningún sitio");
  assert.equal(wpml.phone_after_trans, "+34 600 000 002", "y el original no se toca");
  assert.deepEqual(wpml.page_same_key, [], "una página con la misma clave no es cosa de este módulo");
});

test("sede sin traducir: se pide (WPML no hace nada si no hay traducciones)", () => {
  assert.deepEqual(wpml.untranslated, [`50:${PHONE}`]);
});

test("sin WPML no se pide nada", () => {
  for (const [key, value] of Object.entries(without)) {
    if (Array.isArray(value)) {
      assert.deepEqual(value, [], key);
    }
  }
});
