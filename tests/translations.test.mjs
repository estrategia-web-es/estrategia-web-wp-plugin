// NIVEL: 1 — no browser or emulator. Every languages/estrategia-web-<locale>.po is complete (no empty or fuzzy string), keeps the placeholders of the English, has every string of the .pot and is compiled into its .l10n.php.
//
// Why: a fuzzy or empty string is NOT compiled (tools/i18n.mjs), so the site
// shows it in English with no warning; a placeholder lost in a translation
// (%s, %1$s, {{name}}) breaks the sentence or, with sprintf(), throws. This
// is the check that a half-translated language cannot be committed.
//
// Seen in red on 16-10-2026 with languages/estrategia-web-ca.po just created
// by `node tools/i18n.mjs new ca` (727 empty strings).
//
// What it also checks, the edge case: URL slugs (msgctxt «URL slug») are
// lowercase ASCII letters, digits and hyphens. An accent or an apostrophe in
// one would be a URL WordPress encodes as %C3%A0.
//
// How to maintain: nothing to list, it reads every .po in languages/. If it
// goes red after adding or changing a text: `node tools/i18n.mjs`, translate
// what comes out empty or fuzzy in each .po and commit the .po and the
// .l10n.php together.
import { test } from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";

const LANG = path.resolve(import.meta.dirname, "..", "languages");
const DOMAIN = "estrategia-web";

function parsePo(text) {
  const entries = [];
  let cur = null;
  let field = null;
  const unq = (s) => JSON.parse(s.replace(/\\(?!["\\nt])/g, "\\\\"));
  const flush = () => cur && cur.msgid !== undefined && cur.msgid !== "" && entries.push(cur);
  for (const raw of text.split(/\r?\n/)) {
    const line = raw.trim();
    if (line === "") { flush(); cur = null; field = null; continue; }
    if (line.startsWith("#~")) continue;
    cur ??= { flags: [], msgstr: [] };
    if (line.startsWith("#,")) cur.flags.push(...line.slice(2).split(",").map((s) => s.trim()));
    else if (line.startsWith("#")) continue;
    else {
      const m = line.match(/^(msgctxt|msgid_plural|msgid|msgstr(?:\[(\d+)\])?)\s+(".*")$/);
      if (m) {
        field = m[2] !== undefined ? ["msgstr", Number(m[2])] : m[1] === "msgstr" ? ["msgstr", 0] : [m[1]];
        if (field[0] === "msgstr") cur.msgstr[field[1]] = unq(m[3]);
        else cur[field[0]] = unq(m[3]);
      } else if (line.startsWith('"') && field) {
        if (field[0] === "msgstr") cur.msgstr[field[1]] += unq(line);
        else cur[field[0]] += unq(line);
      }
    }
  }
  flush();
  return entries;
}

const key = (e) => (e.msgctxt !== undefined ? e.msgctxt + " | " : "") + e.msgid;
// %s, %d, %1$s… and {{marker}}, as a sorted list; and how many {text} links (their text IS translated).
const placeholders = (s) => [...s.matchAll(/%(?:\d+\$)?[sd]|\{\{[a-z_]+\}\}/g)].map((m) => m[0]).sort().join(" ");
const links = (s) => (s.replace(/\{\{[a-z_]+\}\}/g, "").match(/\{[^{}]+\}/g) || []).length;

const pot = parsePo(fs.readFileSync(path.join(LANG, `${DOMAIN}.pot`), "utf8"));
const pos = fs.readdirSync(LANG).filter((f) => new RegExp(`^${DOMAIN}-[a-z]{2,3}(_[A-Z]{2})?\\.po$`).test(f));

test("there is at least the Spanish and the Catalan translation", () => {
  assert.ok(pos.includes(`${DOMAIN}-es_ES.po`) && pos.includes(`${DOMAIN}-ca.po`), pos.join(", "));
});

for (const po of pos) {
  const entries = parsePo(fs.readFileSync(path.join(LANG, po), "utf8"));

  test(`${po}: every string of the .pot, none empty or fuzzy`, () => {
    const have = new Set(entries.map(key));
    assert.deepEqual(pot.map(key).filter((k) => !have.has(k)), [], "missing from the .po: run node tools/i18n.mjs");
    assert.deepEqual(entries.filter((e) => e.flags.includes("fuzzy")).map(key), [], "fuzzy (not compiled: shown in English)");
    assert.deepEqual(entries.filter((e) => !e.msgstr.length || !e.msgstr.every((s) => s)).map(key), [], "untranslated");
  });

  test(`${po}: the placeholders of the English are kept`, () => {
    const bad = entries
      .filter((e) => e.msgstr.some((s) => s && (placeholders(s) !== placeholders(e.msgid) || links(s) !== links(e.msgid))))
      .map((e) => `${key(e)} → ${e.msgstr.join(" / ")}`);
    assert.deepEqual(bad, []);
  });

  test(`${po}: URL slugs are lowercase ASCII with hyphens`, () => {
    const bad = entries.filter((e) => e.msgctxt === "URL slug" && !/^[a-z0-9]+(-[a-z0-9]+)*$/.test(e.msgstr[0])).map((e) => `${e.msgid} → ${e.msgstr[0]}`);
    assert.deepEqual(bad, []);
  });

  test(`${po}: compiled, its .l10n.php has every string`, () => {
    const file = path.join(LANG, po.replace(/\.po$/, ".l10n.php"));
    assert.ok(fs.existsSync(file), `${path.basename(file)} is missing: node tools/i18n.mjs compile`);
    const php = fs.readFileSync(file, "utf8");
    // One entry per line that starts a key (a message with a line break goes on over several lines).
    const count = (php.match(/^\t\t'/gm) || []).length;
    assert.equal(count, entries.length, "the .l10n.php is behind the .po: node tools/i18n.mjs compile");
    const esc = (s) => s.replace(/\\/g, "\\\\").replace(/'/g, "\\'");
    const stale = entries.filter((e) => !e.msgstr.every((s) => php.includes(esc(s)))).map(key);
    assert.deepEqual(stale, [], "translations not in the .l10n.php: node tools/i18n.mjs compile");
  });
}
