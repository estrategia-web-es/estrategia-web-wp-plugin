// NIVEL: 1 — no browser or emulator. includes/admin/module-strings.php is what tools/i18n.mjs generates from the module.php headers today, and every header text is in it.
//
// Why (2.4.1): the Modules screen translates each module's name, description
// and category through that generated file, with one literal _x() per text,
// because WordPress.org's review rejected _x( $variable ). If someone adds a
// module or changes its header and does not run `node tools/i18n.mjs`, the
// screen would show that text in English with nothing failing: this stops it
// at commit time.
//
// How to maintain: nothing to list. Red here = run `node tools/i18n.mjs`,
// translate what comes out empty in each .po, commit everything together.
import { test } from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import { MODULE_STRINGS, moduleStringsPhp } from "../tools/i18n.mjs";

const ROOT = path.resolve(import.meta.dirname, "..");

test("module-strings.php is up to date with the module headers (node tools/i18n.mjs)", () => {
  const onDisk = fs.readFileSync(path.join(ROOT, MODULE_STRINGS), "utf8").replace(/\r\n/g, "\n");
  assert.equal(onDisk, moduleStringsPhp());
});

test("every module has its name and its category in it, and «Other» is there", () => {
  const php = moduleStringsPhp();
  for (const slug of fs.readdirSync(path.join(ROOT, "modules"))) {
    const file = path.join(ROOT, "modules", slug, "module.php");
    if (!fs.existsSync(file)) continue;
    const name = (fs.readFileSync(file, "utf8").match(/^[ \t/*#@]*Module Name:(.*)$/m) || [])[1]?.trim();
    assert.ok(name, `${slug}: no Module Name`);
    assert.ok(php.includes(`_x( '${name.replace(/'/g, "\\'")}', 'module name',`), `${slug}: «${name}» missing`);
  }
  assert.match(php, /_x\( 'Other', 'module category', 'estrategiaweb-toolkit' \)/);
  assert.doesNotMatch(php, /_x\( \$/, "no variable texts");
});
