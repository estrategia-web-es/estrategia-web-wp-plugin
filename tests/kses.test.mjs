// NIVEL: 1 — no browser or emulator. Reads the source: every directive of the cookie notice is in Kses::DIRECTIVES, and the code that prints it goes through that list.
//
// What and why: wp_kses() removes what is not listed and says nothing, so a
// directive added to the notice and not to includes/class-kses.php gives a
// button that does nothing, on every site, with no error. «data-*» in the list
// does not cover a directive with a double hyphen.
//
// How to maintain: if another piece of HTML with directives starts going
// through Kses::allowed(), its file goes in FILES.
import { test } from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";

const read = (file) => fs.readFileSync(new URL(`../${file}`, import.meta.url), "utf8");
const FILES = ["modules/consent/includes/class-consent.php"];

test("every directive with a double hyphen printed through Kses::allowed() is in its list", () => {
  const kses = read("includes/class-kses.php");
  for (const file of FILES) {
    const directives = new Set(read(file).match(/data-wp-[a-z-]*--[a-z-]+/g) ?? []);
    assert.ok(directives.size > 0, `${file} has directives`);
    for (const directive of directives) {
      assert.ok(kses.includes(`'${directive}'`), `${directive} of ${file} is missing from Kses::DIRECTIVES`);
    }
  }
});

test("the cookie notice is printed through wp_kses() with that list", () => {
  assert.match(read(FILES[0]), /echo wp_kses\( wp_interactivity_process_directives\( .* \), \\EstrategiaWeb\\Kses::allowed\(\) \);/);
});
