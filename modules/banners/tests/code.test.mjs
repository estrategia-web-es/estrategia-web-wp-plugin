// NIVEL: 1 — no browser or emulator. The code banner (2.10.2 to 2.10.4, the WordPress.org reviews): what
// OUR list Kses::banner() lets through, read with the machine's PHP, and that every place the plugin
// saves or prints that code passes it through wp_kses() with that list, read from the source.
//
// Why level 1 (22-11-2026, «el cupo no está para ampliar»): this file was level 2 and came with the
// review branch, leaving the server tests at 59 against 58. What it checked was ours — the list and the
// call sites — plus wp_kses() itself, which is WordPress's. The list and the calls came down here; what
// wp_kses() does with a list (e.g. breaking inline JavaScript with «&&», the trap the edit screen warns
// about) is not ours to test.
//
// The tags and attributes are those of the four kinds of code banner Gastronòmicament had on 09-11-2026:
// a linked image, a <div>, an <iframe>, and AdSense (external script, <ins>, inline script).
// If there is no `php` on the PATH, the test FAILS (it is not skipped).
//
// Seen red (22-11-2026): with «script» taken out of Kses::BANNER the AdSense case fails; with the
// sanitize_callback of the code meta changed to sanitize_text_field, the save case fails.
//
// How to maintain: a new kind of ad code that must work goes in NEEDS with the attributes it uses; a new
// place that saves or prints a banner's code goes in CALLS.
import { test } from "node:test";
import assert from "node:assert/strict";
import { spawnSync } from "node:child_process";
import fs from "node:fs";
import path from "node:path";

const MODULE = path.join(import.meta.dirname, "..");
const r = spawnSync("php", [path.join(import.meta.dirname, "php", "kses.php")], { encoding: "utf8" });
assert.equal(r.status, 0, "php did not run: " + (r.error?.message || r.stderr || r.stdout));
const banner = JSON.parse(r.stdout);

const NEEDS = {
  "linked image": { a: ["href", "target", "rel"], img: ["src", "alt", "width", "height"] },
  div: { div: ["class", "style"] },
  iframe: { iframe: ["src", "width", "height", "frameborder", "scrolling", "loading"] },
  adsense: { script: ["src", "async", "crossorigin"], ins: ["class", "style", "data-*"] },
};

for (const [kind, tags] of Object.entries(NEEDS)) {
  test(`Kses::banner() keeps what a ${kind} code banner uses`, () => {
    for (const [tag, attributes] of Object.entries(tags)) {
      assert.ok(banner[tag], `<${tag}> is not in Kses::banner()`);
      for (const a of attributes) assert.ok(banner[tag].includes(a), `<${tag} ${a}> is not in Kses::banner(): ${banner[tag].join(" ")}`);
    }
  });
}

const read = (file) => fs.readFileSync(path.join(MODULE, file), "utf8");
const CALLS = [
  // [file, what must be there, why]
  ["includes/class-post-type.php", /'sanitize_callback'\s*=>\s*static fn\( \$value \) => wp_kses\( \(string\) \$value, \\EstrategiaWeb\\Kses::banner\(\) \)/, "the code meta filters on save (REST and wp-cli too), 2.10.3"],
  ["includes/class-post-type.php", /wp_kses\( wp_unslash\( \$_POST\['ewb'\]\['code'\] \), \\EstrategiaWeb\\Kses::banner\(\) \)/, "the meta box reads the code already filtered, 2.10.4"],
  ["blocks/banner-zone/render.php", /wp_kses\( \$estrategia_web_d\['code'\], \\EstrategiaWeb\\Kses::banner\(\) \)/, "each code banner is filtered when shown, 2.10.2"],
  ["blocks/banner-zone/render.php", /wp_kses\( \$estrategia_web_items, \\EstrategiaWeb\\Kses::banner\(\) \)/, "the whole zone is filtered at the echo, 2.10.2"],
];

test("every place that saves or prints a banner's code passes it through wp_kses() with Kses::banner()", () => {
  for (const [file, pattern, why] of CALLS) assert.match(read(file), pattern, `${file}: ${why}`);
});

test("the code is never read from the request without the filter around it (review of 15-11-2026)", () => {
  const src = read("includes/class-post-type.php");
  const raw = src.split("\n").filter((l) => l.includes("$_POST['ewb']['code']") && !/wp_kses\(|isset\(|is_string\(/.test(l));
  assert.deepEqual(raw, [], "a raw read of $_POST['ewb']['code']");
});
