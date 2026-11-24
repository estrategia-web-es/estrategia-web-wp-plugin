// NIVEL: 1 — no browser, no WordPress: assets/constructor-model.js is the constructor's pure part (what is written into the draft), loaded with require() as WordPress loads it as a classic script.
//
// What the page does with it (tabs, rows, drag) is level 3:
// constructor-editor.test.mjs. Seen red (27-10-2026): with move() not
// refusing a «to» past the end, «move: out of the list» failed (the field
// went to undefined's place and the list grew a hole); with setSlug() always
// going through setText(), «a plain slug stays a string» got {"es": …}.
// Seen red (24-11-2026, repeater): with setSubfieldType() keeping the options
// on a time subfield, «repeater subfields» failed; restored, green.
import { test } from "node:test";
import assert from "node:assert/strict";
import { createRequire } from "node:module";
import path from "node:path";

const model = createRequire(import.meta.url)(path.join(import.meta.dirname, "..", "assets", "constructor-model.js"));

test("fill: %s, %d and %1$s / %2$s", () => {
  assert.equal(model.fill("Edit “%s”", "Cursos"), "Edit “Cursos”");
  assert.equal(model.fill("Moved %1$s to %2$d of %3$d", "Horas", 2, 5), "Moved Horas to 2 of 5");
});

test("text: exact, two-letter, en, first; a plain string as it is", () => {
  assert.equal(model.text({ es_ES: "Hola", en: "Hi" }, "es"), "Hola");
  assert.equal(model.text({ ca: "Hola", en: "Hi" }, "fr"), "Hi");
  assert.equal(model.text({ ca: "Bon dia" }, "fr"), "Bon dia");
  assert.equal(model.text("Restaurant", "es"), "Restaurant");
  assert.equal(model.text(undefined, "es"), "");
});

test("setText / getText: a plain string belongs to the first language and becomes an object only when edited", () => {
  const h = { label: "Teléfono" };
  assert.equal(model.getText(h, "label", "es", "es"), "Teléfono");
  assert.equal(model.getText(h, "label", "en", "es"), "");
  model.setText(h, "label", "en", "Phone", "es");
  assert.deepEqual(h.label, { es: "Teléfono", en: "Phone" });
  const regional = { label: { es_ES: "Uno" } };
  model.setText(regional, "label", "es", "Dos", "es");
  assert.deepEqual(regional.label, { es_ES: "Dos" }, "the key that already holds the language");
  model.setText(regional, "label", "es", "", "es");
  assert.equal("label" in regional, false, "emptied: the key goes");
});

test("setSlug: a plain slug stays a string for the first language; a second language makes it an object", () => {
  const def = {};
  model.setSlug(def, "es", "cursos", "es");
  assert.equal(def.slug, "cursos");
  model.setSlug(def, "en", "courses", "es");
  assert.deepEqual(def.slug, { es: "cursos", en: "courses" });
  model.setSlug(def, "es", "formacion", "es");
  assert.deepEqual(def.slug, { es: "formacion", en: "courses" }, "an object is edited per language");
  model.setSlug(def, "en", "", "es");
  assert.deepEqual(def.slug, { es: "formacion" }, "never turned back into a string");
  const empty = { slug: "x" };
  model.setSlug(empty, "es", "", "es");
  assert.equal("slug" in empty, false);
});

test("move: up, down, to any place; out of the list does nothing", () => {
  const list = ["a", "b", "c", "d"];
  assert.equal(model.move(list, 0, 2), true);
  assert.deepEqual(list, ["b", "c", "a", "d"]);
  assert.equal(model.move(list, 3, 0), true);
  assert.deepEqual(list, ["d", "b", "c", "a"]);
  assert.equal(model.move(list, 0, -1), false);
  assert.equal(model.move(list, 3, 4), false);
  assert.equal(model.move(list, 1, 1), false);
  assert.deepEqual(list, ["d", "b", "c", "a"], "unchanged");
});

test("keyFrom: a group key from its label, never repeated", () => {
  assert.equal(model.keyFrom("Dirección y mapa"), "direccion_y_mapa");
  assert.equal(model.keyFrom("2 Horario"), "horario", "a key starts with a letter");
  assert.equal(model.keyFrom(""), "group");
  assert.equal(model.keyFrom("Mapa", ["mapa", "mapa_2"]), "mapa_3");
});

test("groupChoices: none, the groups in order, and a key no longer declared is kept", () => {
  const groups = [{ key: "address", label: { es: "Dirección", en: "Address" } }, { key: "map", label: "Mapa" }];
  assert.deepEqual(model.groupChoices(groups, "", "es", "—"), [["", "—"], ["address", "Dirección"], ["map", "Mapa"]]);
  assert.deepEqual(model.groupChoices(groups, "gone", "en", "—").at(-1), ["gone", "gone"]);
  assert.deepEqual(model.groupChoices(undefined, "", "es", "—"), [["", "—"]]);
});

test("repeater: becoming one adds a first text subfield (none is refused), keeps the ones it had, and drops a default", () => {
  const f = { name: "llest_actes", type: "text", label: "Actes", default: "x" };
  model.toRepeater(f);
  assert.deepEqual(f, { name: "llest_actes", type: "repeater", label: "Actes", fields: [{ name: "field_1", type: "text", label: "" }] });
  const back = { name: "llest_actes", type: "text", fields: [{ name: "dia", type: "date", label: "Dia" }] };
  model.toRepeater(back);
  assert.deepEqual(back.fields, [{ name: "dia", type: "date", label: "Dia" }], "the JSON box's subfields are kept");
});

test("repeater subfields: add with a free name, type change drops or creates the options, remove, move", () => {
  const f = { type: "repeater", fields: [{ name: "field_2", type: "text", label: "A" }] };
  const added = model.addSubfield(f);
  assert.equal(added.name, "field_3", "never a name already taken (field_2 is)");
  assert.equal(model.subfieldName([{ name: "field_1" }, { name: "field_3" }]), "field_4");
  model.setSubfieldType(added, "select");
  assert.deepEqual(added.options, [], "a select gets its list to fill");
  added.options = ["Vetlla"];
  model.setSubfieldType(added, "select");
  assert.deepEqual(added.options, ["Vetlla"], "the same type keeps what was typed");
  model.setSubfieldType(added, "time");
  assert.equal("options" in added, false, "any other type loses them: the server refuses options there");
  assert.equal(model.move(f.fields, 1, 0), true);
  assert.deepEqual(f.fields.map((s) => s.name), ["field_3", "field_2"]);
  assert.equal(model.removeSubfield(f, 5), false);
  assert.equal(model.removeSubfield(f, 0), true);
  assert.deepEqual(f.fields.map((s) => s.name), ["field_2"]);
  const empty = {};
  model.addSubfield(empty);
  assert.deepEqual(empty.fields, [{ name: "field_1", type: "text", label: "" }]);
});

test("repeater item_format: the {placeholders} that are not subfields", () => {
  const subs = [{ name: "tipus" }, { name: "dia" }];
  assert.deepEqual(model.unknownPlaceholders("{tipus}: {dia} {hora}", subs), ["hora"]);
  assert.deepEqual(model.unknownPlaceholders("{tipus}: {dia}", subs), []);
  assert.deepEqual(model.unknownPlaceholders(undefined, subs), []);
});

test("editUrl: the list's URL plus &type=", () => {
  assert.equal(model.editUrl("/wp-admin/admin.php?page=estrategia-web-content-types", "gment_course"), "/wp-admin/admin.php?page=estrategia-web-content-types&type=gment_course");
});
