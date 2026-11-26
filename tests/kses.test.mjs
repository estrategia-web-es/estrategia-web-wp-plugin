// NIVEL: 1 — no browser or emulator. Reads the source: every directive the plugin prints is in Kses::DIRECTIVES, the cookie notice goes through that list, and no string of attributes with escaped values goes through wp_kses_data()/wp_kses_post() on its own (WordPress 7.2).
//
// What and why: wp_kses() removes what is not listed and says nothing, so a
// directive added to a block and not to includes/class-kses.php gives a
// button that does nothing, on every site, with no error. «data-*» in the list
// does not cover a directive with a double hyphen.
//
// 2.16.2 (26-11-2026): since WordPress 7.2 the opening tags of the blocks go
// through wp_kses() WHOLE (header of includes/class-kses.php: 7.2's kses
// decodes &quot; in a bare attribute string, and the language switcher
// stopped opening). So the list must hold EVERY directive of the plugin, not
// only the cookie notice's, and the old pattern is refused here on each
// commit — tools/wporg.mjs (reviewRules, rule 4) refuses it again in the zip.
// Seen red (25-11-2026): with data-wp-on-document--click removed from the list,
// and with the language switcher's wp_kses( '<div ' … ) put back as
// wp_kses_data( $estrategia_web_wrapper ).
//
// How to maintain: nothing to list by hand. A new file with directives is
// found by the scan; a new escaped-attribute source (another helper like
// self::atts()) goes in BARE_ATTRIBUTES here AND in rule 4 of tools/wporg.mjs.
import { test } from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";

const ROOT = new URL("../", import.meta.url);
const read = (file) => fs.readFileSync(new URL(file, ROOT), "utf8");

/** The plugin's PHP outside tests/, as paths relative to the root. */
function phpFiles(dir = "") {
  const out = [];
  for (const entry of fs.readdirSync(new URL(dir || ".", ROOT), { withFileTypes: true })) {
    const rel = path.posix.join(dir, entry.name);
    if (entry.isDirectory()) {
      if (["tests", "node_modules", "dist", "vendor", ".git", "tools", "docs"].includes(entry.name)) continue;
      out.push(...phpFiles(rel));
    } else if (entry.name.endsWith(".php")) out.push(rel);
  }
  return out;
}
const FILES = phpFiles().filter((f) => /^(modules|includes)\//.test(f));

test("every directive with a double hyphen the plugin prints is in Kses::DIRECTIVES", () => {
  const kses = read("includes/class-kses.php");
  let seen = 0;
  for (const file of FILES) {
    for (const directive of new Set(read(file).match(/data-wp-[a-z-]*--[a-z0-9-]+/g) ?? [])) {
      seen++;
      assert.ok(kses.includes(`'${directive}'`), `${directive} of ${file} is missing from Kses::DIRECTIVES`);
    }
  }
  assert.ok(seen > 10, `the scan found ${seen} directives: it is not reading the modules`);
});

test("the cookie notice is printed through wp_kses() with that list", () => {
  assert.match(read("modules/consent/includes/class-consent.php"), /echo wp_kses\( wp_interactivity_process_directives\( .* \), \\EstrategiaWeb\\Kses::allowed\(\) \);/);
});

// The same pattern as rule 4 of reviewRules() in tools/wporg.mjs.
const BARE_ATTRIBUTES = /wp_kses_(data|post)\(\s*(get_block_wrapper_attributes|self::atts|\$[a-z_]*(wrapper|wrap|atts)\b)/;

test("no string of escaped attributes goes through wp_kses_data()/wp_kses_post() alone (WordPress 7.2 decodes &quot; in it)", () => {
  const bad = [];
  for (const file of FILES) {
    read(file).split("\n").forEach((line, i) => {
      if (BARE_ATTRIBUTES.test(line)) bad.push(`${file}:${i + 1}  ${line.trim().slice(0, 120)}`);
    });
  }
  assert.deepEqual(bad, [], "pass the whole opening tag: wp_kses( '<div ' . $wrapper . '>', \\EstrategiaWeb\\Kses::allowed() )");
});

test("the blocks that print a wrapper do it through wp_kses() with the whole tag", () => {
  const WHOLE = /wp_kses\(\s*(sprintf\(\s*)?\n?\s*'<(div|nav|p|ul|a|figure|form)\b/;
  for (const file of [
    "modules/banners/blocks/banner-zone/render.php",
    "modules/carousel/blocks/carousel/render.php",
    "modules/language-switcher/blocks/language-switcher/render.php",
    "modules/map/blocks/map/render.php",
    "modules/social/blocks/social-links/render.php",
    "modules/whatsapp/blocks/whatsapp/render.php",
  ]) {
    const src = read(file);
    assert.ok(src.includes("get_block_wrapper_attributes") || src.includes("$estrategia_web_wrapper"), `${file} prints a wrapper`);
    assert.match(src, WHOLE, `${file}: the opening tag does not go through wp_kses() whole`);
  }
});
