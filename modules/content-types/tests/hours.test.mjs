// NIVEL: 1 — no browser, no emulator. The «hours» field type (includes/class-hours.php): what is stored whatever comes in, schema.org openingHoursSpecification and the line people read, with the machine's PHP (tests/php/hours.php).
//
// If there is no `php` on the PATH the test FAILS (it is not skipped).
//
// Seen red (22-10-2026): with merge() returning the ranges unmerged,
// «overlapping or touching ranges are merged» failed (three ranges stored).
import { test } from "node:test";
import assert from "node:assert/strict";
import { spawnSync } from "node:child_process";
import path from "node:path";

const HERE = import.meta.dirname;
const run = (cases) => {
  const r = spawnSync("php", [path.join(HERE, "php", "hours.php")], { encoding: "utf8", input: JSON.stringify(cases) });
  assert.equal(r.status, 0, "php did not run: " + (r.error?.message || r.stderr || r.stdout));
  return JSON.parse(r.stdout);
};
const one = (fn, ...args) => run([{ fn, args }])[0];

const EVERY_DAY = JSON.stringify({ mo: ["08:00-23:00"], tu: ["08:00-23:00"], we: ["08:00-23:00"], th: ["08:00-23:00"], fr: ["08:00-23:00"], sa: ["08:00-23:00"], su: ["08:00-23:00"] });
const MON_SAT = JSON.stringify({ mo: ["08:00-16:00"], tu: ["08:00-16:00"], we: ["08:00-16:00"], th: ["08:00-16:00"], fr: ["08:00-16:00"], sa: ["08:00-16:00"] });

test("sanitize: the canonical JSON, days in week order, closed days out", () => {
  assert.equal(one("sanitize", { su: ["10:00-14:00"], mo: ["08:00-14:00"] }), '{"mo":["08:00-14:00"],"su":["10:00-14:00"]}');
  assert.equal(one("sanitize", '{"tu":["09:00 – 13:00"]}'), '{"tu":["09:00-13:00"]}', "an en dash and spaces are read");
  assert.equal(one("sanitize", { we: [["09:00", "13:00"]] }), '{"we":["09:00-13:00"]}', "pairs are accepted");
  assert.equal(one("sanitize", { mo: [] }), "", "an empty week is stored as \"\", never {} or []");
  assert.equal(one("sanitize", ""), "");
  assert.equal(one("sanitize", "not json"), "");
  assert.equal(one("sanitize", 42), "");
});

test("sanitize: a bad range is dropped, never the whole week", () => {
  assert.equal(one("sanitize", { mo: ["08:00-14:00", "25:00-26:00", "8-14", "10:00-10:00"], xx: ["08:00-09:00"] }), '{"mo":["08:00-14:00"]}');
});

test("sanitize: overlapping or touching ranges are merged and sorted", () => {
  assert.equal(one("sanitize", { mo: ["16:00-20:00", "13:00-16:00", "08:00-14:00"] }), '{"mo":["08:00-20:00"]}');
  assert.equal(one("sanitize", { mo: ["16:00-20:00", "08:00-14:00"] }), '{"mo":["08:00-14:00","16:00-20:00"]}');
});

test("sanitize: a range past midnight is kept as it is", () => {
  assert.equal(one("sanitize", { fr: ["22:00-03:00", "12:00-16:00"] }), '{"fr":["12:00-16:00","22:00-03:00"]}');
});

test("specification: one entry per range with all its days (Maioris, Son Oms)", () => {
  assert.deepEqual(one("specification", EVERY_DAY), [
    { "@type": "OpeningHoursSpecification", dayOfWeek: ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday"], opens: "08:00", closes: "23:00" },
  ]);
  assert.deepEqual(one("specification", MON_SAT), [
    { "@type": "OpeningHoursSpecification", dayOfWeek: ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday"], opens: "08:00", closes: "16:00" },
  ]);
  assert.deepEqual(one("specification", { mo: ["08:00-14:00", "16:00-20:00"], tu: ["08:00-14:00"] }), [
    { "@type": "OpeningHoursSpecification", dayOfWeek: ["Monday", "Tuesday"], opens: "08:00", closes: "14:00" },
    { "@type": "OpeningHoursSpecification", dayOfWeek: ["Monday"], opens: "16:00", closes: "20:00" },
  ]);
  assert.deepEqual(one("specification", ""), []);
});

test("summary: runs of consecutive days with the same hours", () => {
  assert.deepEqual(one("summary", EVERY_DAY), ["Mo–Su: 8:00–23:00"]);
  assert.deepEqual(one("summary", MON_SAT), ["Mo–Sa: 8:00–16:00"]);
  assert.deepEqual(one("summary", { mo: ["08:00-14:00", "16:00-20:00"], tu: ["08:00-14:00", "16:00-20:00"], th: ["00:00-23:59"], su: ["10:00-14:00"] }), [
    "Mo–Tu: 8:00–14:00, 16:00–20:00",
    "Th: 0:00–23:59",
    "Su: 10:00–14:00",
  ]);
  assert.deepEqual(one("summary", ""), []);
});

test("error: what the panel says about one range", () => {
  assert.equal(one("error", "08:00-14:00"), "");
  assert.equal(one("error", "8:00-14:00"), "format");
  assert.equal(one("error", "08:00-08:00"), "empty");
  assert.equal(one("error", ""), "format");
});

test("the classic screen's line for a day, both ways", () => {
  assert.equal(one("to_line", '{"mo":["08:00-14:00","16:00-20:00"]}', "mo"), "08:00-14:00, 16:00-20:00");
  assert.equal(one("to_line", '{"mo":["08:00-14:00"]}', "tu"), "");
  assert.deepEqual(one("from_line", "08:00-14:00, 16:00-20:00; "), ["08:00-14:00", "16:00-20:00"]);
});
