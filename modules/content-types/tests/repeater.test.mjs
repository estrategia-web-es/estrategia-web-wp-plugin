// NIVEL: 1 — no browser, no emulator. The «repeater» and «time» field types (includes/class-repeater.php): what is stored whatever comes in, subfield by subfield; when a list stops a publish; what a translation keeps; a row's format; the REST schema. With the machine's PHP (tests/php/repeater.php, which says what each WordPress shim does).
//
// If there is no `php` on the PATH the test FAILS (it is not skipped).
//
// The field of the cases is the real one: llest_esquela's «actes»
// (examples/llest_esquela.json), plus ALL, one subfield of each type.
//
// Seen red (24-11-2026), each by breaking the code on purpose and putting it
// back: with rows() keeping an empty row, «sanitize: … empty rows dropped»
// failed ([{}] stored); with time() accepting «9:30», «time: strict HH:MM»
// failed; with apply_format() keeping every joining text, «format: an empty
// value takes its joining text» failed («17:00–, Sant Miquel»).
import { test } from "node:test";
import assert from "node:assert/strict";
import { spawnSync } from "node:child_process";
import fs from "node:fs";
import path from "node:path";

const HERE = import.meta.dirname;
const run = (cases) => {
  const r = spawnSync("php", [path.join(HERE, "php", "repeater.php")], { encoding: "utf8", input: JSON.stringify(cases) });
  assert.equal(r.status, 0, "php did not run: " + (r.error?.message || r.stderr || r.stdout));
  return JSON.parse(r.stdout);
};
const one = (fn, ...args) => run([{ fn, args }])[0];

const ACTES = JSON.parse(fs.readFileSync(path.join(HERE, "..", "examples", "llest_esquela.json"), "utf8")).fields[0];
const ALL = {
  name: "x_all",
  type: "repeater",
  label: "All",
  max: 3,
  fields: [
    { name: "t", type: "text", label: "T" },
    { name: "a", type: "textarea", label: "A" },
    { name: "n", type: "number", label: "N" },
    { name: "d", type: "date", label: "D" },
    { name: "h", type: "time", label: "H" },
    { name: "s", type: "select", label: "S", options: ["one", { value: "two", label: { ca: "dos" } }] },
    { name: "u", type: "url", label: "U" },
    { name: "e", type: "email", label: "E" },
    { name: "p", type: "tel", label: "P" },
  ],
};

test("sanitize: subfield by subfield, by its type; unknown keys out; only what has a value", () => {
  const got = one("sanitize", [{
    t: " <b>Funeral</b>  a  les cinc ", a: "line 1\r\nline 2", n: "12.50", d: "2026-11-25", h: "17:05",
    s: "two", u: "https://example.test/x", e: "Info@Example.test", p: "971 12 34 56 ext. 3", zz: "unknown",
  }], ALL);
  assert.deepEqual(JSON.parse(got), [{
    t: "Funeral a les cinc", a: "line 1\nline 2", n: 12.5, d: "2026-11-25", h: "17:05",
    s: "two", u: "https://example.test/x", e: "info@example.test", p: "971 12 34 56 . 3",
  }]);
  assert.ok(!got.includes("\\u00"), "Unicode kept as it is in the JSON: " + got);
});

test("sanitize: what does not fit empties that subfield, never the row; empty rows dropped; \"\" for nothing", () => {
  const bad = { t: "ok", n: "twelve", d: "2026-02-30", h: "25:00", s: "three", u: "javascript:alert(1)", e: "no-at", p: "abc" };
  assert.equal(one("sanitize", [bad], ALL), '[{"t":"ok"}]');
  assert.equal(one("sanitize", [{}, { t: "   " }, { zz: "x" }], ALL), "", "rows with nothing are not stored, and no rows is \"\" (never [] or [{}])");
  assert.equal(one("sanitize", "", ALL), "");
  assert.equal(one("sanitize", "not json", ALL), "");
  assert.equal(one("sanitize", { t: "a row on its own is not a list" }, ALL), "");
  assert.equal(one("sanitize", [{ n: 0 }], ALL), '[{"n":0}]', "the boundary case: a 0 is a value");
});

test("sanitize: at most «max» rows; the meta box's rows in the order they come (their numbers are only names)", () => {
  assert.equal(JSON.parse(one("sanitize", [{ t: "1" }, { t: "2" }, { t: "3" }, { t: "4" }], ALL)).length, 3);
  // As PHP gets estrategia_web_ct[x_all][7][t], [2][t]: integer keys in the form's order (a JS object would sort them).
  const posted = '{"7":{"t":"first"},"2":{"t":"second"}}';
  assert.equal(one("sanitize", posted, ALL), '[{"t":"first"},{"t":"second"}]');
  assert.equal(one("sanitize", '[{"t":"from JSON"}]', ALL), '[{"t":"from JSON"}]', "the stored text sanitizes to itself");
});

test("time: strict HH:MM (24 h) or nothing", () => {
  const ok = ["00:00", "09:30", "23:59"];
  const bad = ["9:30", "24:00", "09:60", "09:30:00", "0930", "", null, 930];
  const got = run([...ok, ...bad, " 18:00 "].map((v) => ({ fn: "time", args: [v] })));
  assert.deepEqual(got, [...ok, ...bad.map(() => ""), "18:00"]);
});

test("incomplete: a required field with no rows, a row without a required subfield, fewer rows than min", () => {
  const acts = (rows) => one("incomplete", rows, ACTES);
  assert.equal(acts([]), false, "the acts are not required: none is fine");
  assert.equal(acts([{ tipus: "Funeral", dia: "2026-11-25" }]), false);
  assert.equal(acts([{ tipus: "Funeral", hora: "17:00" }]), true, "dia is required in each row");
  assert.equal(one("incomplete", [], { ...ACTES, required: true }), true);
  assert.equal(one("incomplete", [{ tipus: "Funeral", dia: "2026-11-25" }], { ...ACTES, min: 2 }), true);
});

test("merge: a translation keeps its texts, takes the rest and the rows from the original, by position", () => {
  const original = [{ tipus: "Funeral", dia: "2026-11-25", hora: "18:00", lloc: "Sant Miquel" }, { tipus: "Vetlla", dia: "2026-11-24", lloc: "Tanatori" }];
  const translation = [{ tipus: "Missa", dia: "2000-01-01", lloc: "San Miguel" }];
  assert.deepEqual(JSON.parse(one("merge", original, translation, ACTES)), [
    { tipus: "Funeral", dia: "2026-11-25", hora: "18:00", lloc: "San Miguel" },
    { tipus: "Vetlla", dia: "2026-11-24", lloc: "Tanatori" },
  ]);
  assert.equal(one("merge", "", translation, ACTES), "", "the original emptied: the translation too");
  assert.equal(one("mixed", ACTES), true);
  assert.equal(one("mixed", { ...ACTES, fields: [{ name: "lloc", type: "text", label: "L" }] }), false, "only texts: WPML's translate is enough");
});

test("suggestions (2.15.0): most used first, then A→Z; capitals and spaces are one value shown as its most used spelling; empties out", () => {
  const used = ["Tanatori de Campos", "Parròquia de Santanyí", " tanatori de  campos ", "Tanatori de Campos", "", "Església de Porreres", "Parròquia de Santanyí"];
  assert.deepEqual(one("rank", used), ["Tanatori de Campos", "Parròquia de Santanyí", "Església de Porreres"]);
  const field = { name: "llest_actes", type: "repeater", label: "Actes", fields: [{ name: "lloc", type: "text", label: "Lloc", suggest: "used" }] };
  assert.equal(one("suggest", field), "used", "the subfield keeps «suggest» through normalize_field()");
});

test("format: {subfield} replaced; an empty value takes its joining text with it", () => {
  const F = "{tipus}: {dia} {hora}–{fins}, {lloc}";
  const all = { tipus: "Funeral", dia: "25/11", hora: "17:00", fins: "18:00", lloc: "Sant Miquel" };
  assert.equal(one("format", F, all), "Funeral: 25/11 17:00–18:00, Sant Miquel");
  assert.equal(one("format", F, { ...all, fins: "" }), "Funeral: 25/11 17:00, Sant Miquel");
  assert.equal(one("format", F, { ...all, lloc: "" }), "Funeral: 25/11 17:00–18:00");
  assert.equal(one("format", F, { ...all, hora: "", fins: "" }), "Funeral: 25/11, Sant Miquel");
  assert.equal(one("format", F, { ...all, tipus: "" }), "25/11 17:00–18:00, Sant Miquel", "the first one takes the text AFTER it");
  assert.equal(one("format", F, {}), "", "nothing at all: no row");
});

test("REST schema: a list of objects; a number subfield takes a number or a numeric string; unknown keys left to the sanitizer", () => {
  const s = one("schema", ALL);
  assert.equal(s.type, "array");
  assert.deepEqual(s.default, []);
  assert.equal(s.items.type, "object");
  assert.deepEqual(s.items.properties.n.type, ["number", "string"]);
  assert.equal(s.items.properties.h.type, "string");
  assert.equal(s.items.additionalProperties, true);
});
