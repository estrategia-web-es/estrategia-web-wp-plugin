// NIVEL: BANCO DE TRABAJO — not a test. Translations (Armazón, row 11): extracts, merges and compiles.
//
//   node tools/i18n.mjs              everything: .pot → merge into each .po → compile
//   node tools/i18n.mjs compile      only compile the .po files (after translating)
//   node tools/i18n.mjs new <locale> start a language: estrategia-web-<locale>.po with
//                                    every string empty, then everything as above
//
// Languages: every languages/estrategia-web-<locale>.po there is (today es_ES
// and ca). Nothing here lists them: a new .po is merged and compiled like the others.
//
// ONE text domain for the whole plugin, estrategia-web (since 1.1.0, which merged
// Site, Forms and the theme's blocks). What it produces in languages/:
//   estrategia-web.pot                 template
//   estrategia-web-<locale>.po         what gets translated (Poedit or by hand)
//   estrategia-web-<locale>.l10n.php   what WordPress 6.5+ reads (server AND editor)
//
// Where it takes the strings from:
//   - PHP: estrategia-web.php, includes/ and modules/ (without tests/).
//   - JS: every .js in modules/ (editor.js, edit.js, view.js).
//   - block.json in modules/*/blocks/*: title, description and keywords,
//     with the contexts from core's block-i18n.json.
//   - Header of modules/*/module.php: "Module Name" (context "module
//     name"), "Description" ("module description") and, since 1.7.0,
//     "Category" ("module category", plus «Other», which no header says),
//     which the Modules screen translates with _x(). xgettext does not see
//     them: they are written by hand.
//
// There is NO editor JSON (WordPress' <domain>-<locale>-<md5>.json): the
// plugin core passes the same PHP translations inline to the editor
// (includes/class-blocks.php). A single source, with no md5 of paths that
// break when a file moves.
//
// Needs xgettext, msgmerge and msgcat (GNU gettext; they come with Git for
// Windows). There is no .mo: WordPress 6.5+ prefers the .l10n.php and the
// plugin requires 7.1, so a .mo would be a third file to maintain with no reader.
//
// How to maintain:
// - Who and when: whoever adds or changes a string, in the same commit. Run
//   this, translate what is new in the .po (whatever msgmerge leaves "fuzzy"
//   or empty) and run `compile` again.
// - Two new modules at once touch the SAME .po: it is one of the three
//   shared files (see includes/class-modules.php). It is regenerated, not
//   merged by hand: if git conflicts, take either side, run this again and
//   translate whatever comes out empty.
// - A new language: `node tools/i18n.mjs new <locale>` (the WordPress locale,
//   the one WPML resolves the language code to: ca, de_DE, en_GB…), translate
//   the .po and run `compile`. Only languages someone is going to read.
//   tests/translations.test.mjs (level 1) goes red while it has an empty or
//   fuzzy string, so a half-done language cannot be committed by accident.
// - Plural forms: PLURALS below. A locale not in it stops `new`: look up its
//   Plural-Forms (WordPress' GlotPress, or the gettext manual) and add it.
// - Edge case: URL slugs (_x( 'clients', 'URL slug' )). They are translated
//   like any other string, but translating them CHANGES the URLs of sites
//   already published in that language: a change there is a major version.
// - The pitfall: the msgid IS the English text (WordPress convention).
//   Changing a comma in the English leaves the translation fuzzy, and a fuzzy
//   one is NOT compiled: until someone reviews it, it shows in English. That
//   is why this script reports how many there are.
import { spawnSync } from "node:child_process";
import fs from "node:fs";
import path from "node:path";

const ROOT = path.resolve(import.meta.dirname, "..");
const LANG = path.join(ROOT, "languages");
const DOMAIN = "estrategia-web";
const POT = path.join(LANG, `${DOMAIN}.pot`);

function run(cmd, args) {
  const r = spawnSync(cmd, args, { cwd: ROOT, encoding: "utf8" });
  if (r.status !== 0) {
    console.error(`${cmd} failed:\n${r.stderr || r.error}`);
    process.exit(1);
  }
  return r;
}

function walk(dir, test) {
  if (!fs.existsSync(path.join(ROOT, dir))) return [];
  return fs.readdirSync(path.join(ROOT, dir), { withFileTypes: true }).flatMap((d) => {
    const rel = path.posix.join(dir, d.name);
    if (d.isDirectory()) return ["tests", "tools", ".git", "node_modules"].includes(d.name) ? [] : walk(rel, test);
    return test(rel) ? [rel] : [];
  });
}

const q = (s) => '"' + s.replace(/\\/g, "\\\\").replace(/"/g, '\\"').replace(/\n/g, "\\n") + '"';

function extract() {
  fs.mkdirSync(LANG, { recursive: true });
  const tmp = fs.mkdtempSync(path.join(LANG, ".tmp-"));
  const common = ["--from-code=UTF-8", "--add-comments=translators:", "--sort-by-file", "--package-name=" + DOMAIN, "--msgid-bugs-address="];
  const keywords = ["__", "_e", "esc_html__", "esc_html_e", "esc_attr__", "esc_attr_e", "_x:1,2c", "_ex:1,2c", "esc_html_x:1,2c", "esc_attr_x:1,2c", "_n:1,2", "_nx:1,2,4c", "_n_noop:1,2", "_nx_noop:1,2,3c"].map((k) => "--keyword=" + k);
  const php = ["estrategia-web.php", ...walk("includes", (f) => f.endsWith(".php")), ...walk("modules", (f) => f.endsWith(".php"))];
  run("xgettext", [...common, "--language=PHP", "--keyword", ...keywords, "-o", path.join(tmp, "php.pot"), ...php]);
  const js = walk("modules", (f) => f.endsWith(".js"));
  run("xgettext", [...common, "--language=JavaScript", "--keyword", "--keyword=__", "--keyword=_x:1,2c", "--keyword=_n:1,2", "--keyword=_nx:1,2,4c", "-o", path.join(tmp, "js.pot"), ...js]);

  // block.json and module.php headers: xgettext does not read them, so they are written by hand.
  // One entry per context + text (the keyword "form" appears in several blocks), with all its references.
  const seen = new Map();
  const add = (file, ctx, msgid) => msgid && (seen.get(ctx + "\x04" + msgid)?.refs.push(file) ?? seen.set(ctx + "\x04" + msgid, { ctx, msgid, refs: [file] }));
  for (const file of walk("modules", (f) => f.endsWith("/block.json"))) {
    const data = JSON.parse(fs.readFileSync(path.join(ROOT, file), "utf8"));
    add(file, "block title", data.title);
    add(file, "block description", data.description);
    (data.keywords || []).forEach((k) => add(file, "block keyword", k));
  }
  for (const file of walk("modules", (f) => /^modules\/[^/]+\/module\.php$/.test(f))) {
    // Same as get_file_data(): "Key:" at the start of a comment line, in the first 8 KB.
    const head = fs.readFileSync(path.join(ROOT, file), "utf8").slice(0, 8192);
    const header = (key) => (head.match(new RegExp(`^[ \\t/*#@]*${key}:(.*)$`, "mi")) || [, ""])[1].replace(/\s*(?:\*\/|\?>).*$/, "").trim();
    add(file, "module name", header("Module Name"));
    add(file, "module description", header("Description"));
    add(file, "module category", header("Category")); // 1.7.0: the Modules screen's groups.
  }
  // «Other» has no header that says it (it is where a module WITHOUT Category goes): Modules::OTHER.
  add("includes/class-modules.php", "module category", "Other");
  let extra = 'msgid ""\nmsgstr ""\n"Content-Type: text/plain; charset=UTF-8\\n"\n\n';
  for (const e of seen.values()) extra += `#: ${e.refs.join(" ")}\nmsgctxt ${q(e.ctx)}\nmsgid ${q(e.msgid)}\nmsgstr ""\n\n`;
  fs.writeFileSync(path.join(tmp, "extra.pot"), extra);

  run("msgcat", ["--use-first", "--sort-by-file", "-o", POT, path.join(tmp, "php.pot"), path.join(tmp, "js.pot"), path.join(tmp, "extra.pot")]);
  fs.rmSync(tmp, { recursive: true, force: true });

  // Stable header: no creation date, so the .pot does not change if nothing changed.
  const pot = fs.readFileSync(POT, "utf8").replace(/"POT-Creation-Date: [^"]*"\n/, "").replace(/charset=CHARSET/, "charset=UTF-8");
  fs.writeFileSync(POT, pot);
  console.log(`.pot: ${(pot.match(/^msgid /gm).length - 1)} strings`);

  for (const po of poFiles()) run("msgmerge", ["--quiet", "--update", "--backup=none", "--previous", path.join(LANG, po), POT]);
}

function poFiles() {
  return fs.readdirSync(LANG).filter((f) => new RegExp(`^${DOMAIN}-[a-z]{2,3}(_[A-Z]{2})?\\.po$`).test(f));
}

// Minimal .po reader: just enough for what msgmerge writes.
function parsePo(text) {
  const entries = [];
  let cur = null;
  let field = null;
  const unq = (s) => JSON.parse(s.replace(/\\(?!["\\nt])/g, "\\\\"));
  const flush = () => cur && cur.msgid !== undefined && entries.push(cur);
  for (const raw of text.split(/\r?\n/)) {
    const line = raw.trim();
    if (line === "") { flush(); cur = null; field = null; continue; }
    if (line.startsWith("#~")) continue;
    cur ??= { refs: [], flags: [], msgstr: [] };
    if (line.startsWith("#:")) cur.refs.push(...line.slice(2).trim().split(/\s+/));
    else if (line.startsWith("#,")) cur.flags.push(...line.slice(2).split(",").map((s) => s.trim()));
    else if (line.startsWith("#")) continue;
    else {
      const m = line.match(/^(msgctxt|msgid_plural|msgid|msgstr(?:\[(\d+)\])?)\s+(".*")$/);
      if (m) {
        field = m[2] !== undefined ? ["msgstr", Number(m[2])] : m[1] === "msgstr" ? ["msgstr", 0] : [m[1]];
        const v = unq(m[3]);
        if (field[0] === "msgstr") cur.msgstr[field[1]] = v;
        else cur[field[0]] = v;
      } else if (line.startsWith('"') && field) {
        const v = unq(line);
        if (field[0] === "msgstr") cur.msgstr[field[1]] += v;
        else cur[field[0]] += v;
      }
    }
  }
  flush();
  return entries;
}

const phpString = (s) =>
  s
    .split(/([\0\x04])/)
    .map((part) => (part === "\0" ? '"\\0"' : part === "\x04" ? '"\\x04"' : "'" + part.replace(/\\/g, "\\\\").replace(/'/g, "\\'") + "'"))
    .filter((p) => p !== "''")
    .join(" . ") || "''";

function compile() {
  for (const po of poFiles()) {
    const locale = po.slice(DOMAIN.length + 1, -3);
    const entries = parsePo(fs.readFileSync(path.join(LANG, po), "utf8"));
    const header = entries.find((e) => e.msgid === "")?.msgstr[0] || "";
    const plural = (header.match(/Plural-Forms:\s*([^\n]+)/) || [, "nplurals=2; plural=(n != 1);"])[1].trim();
    const usable = entries.filter((e) => e.msgid !== "" && !e.flags.includes("fuzzy") && e.msgstr.every((s) => s) && e.msgstr.length);
    const fuzzy = entries.filter((e) => e.flags.includes("fuzzy")).length;
    const empty = entries.filter((e) => e.msgid !== "" && !e.flags.includes("fuzzy") && !e.msgstr.every((s) => s)).length;
    const key = (e) => (e.msgctxt !== undefined ? e.msgctxt + "\x04" : "") + e.msgid;

    let php = "<?php\n// Generated by tools/i18n.mjs from " + po + ". Do not edit by hand: edit the .po and recompile.\nreturn array(\n";
    php += `\t'domain' => '${DOMAIN}',\n\t'language' => ${phpString(locale)},\n\t'plural-forms' => ${phpString(plural)},\n\t'messages' => array(\n`;
    for (const e of usable) php += `\t\t${phpString(key(e))} => ${phpString(e.msgstr.join("\0"))},\n`;
    php += "\t),\n);\n";
    fs.writeFileSync(path.join(LANG, `${DOMAIN}-${locale}.l10n.php`), php);

    console.log(`${po}: ${usable.length} compiled · ${fuzzy} fuzzy · ${empty} untranslated${fuzzy + empty ? "  ← REVIEW" : ""}`);
  }
}

// Plural-Forms per locale, for `new`. All the ones used today have two forms.
const PLURALS = {
  ca: "nplurals=2; plural=(n != 1);",
  es_ES: "nplurals=2; plural=(n != 1);",
  en_GB: "nplurals=2; plural=(n != 1);",
  de_DE: "nplurals=2; plural=(n != 1);",
};

// A new .po with the header and no strings: extract() then merges every string into it, empty.
function start(locale) {
  if (!/^[a-z]{2,3}(_[A-Z]{2})?$/.test(locale || "")) {
    console.error("Usage: node tools/i18n.mjs new <locale>   (e.g. ca, de_DE)");
    process.exit(1);
  }
  if (!PLURALS[locale]) {
    console.error(`No Plural-Forms known for «${locale}»: add it to PLURALS in tools/i18n.mjs.`);
    process.exit(1);
  }
  const file = path.join(LANG, `${DOMAIN}-${locale}.po`);
  if (fs.existsSync(file)) {
    console.error(`${path.basename(file)} already exists: nothing is overwritten.`);
    process.exit(1);
  }
  const header = ["Project-Id-Version: estrategia-web", "Language: " + locale, "MIME-Version: 1.0", "Content-Type: text/plain; charset=UTF-8", "Content-Transfer-Encoding: 8bit", "Plural-Forms: " + PLURALS[locale]];
  fs.writeFileSync(file, `msgid ""\nmsgstr ""\n${header.map((h) => q(h + "\n")).join("\n")}\n`);
  console.log(`${path.basename(file)}: created`);
}

if (process.argv[2] === "new") start(process.argv[3]);
if (process.argv[2] !== "compile") extract();
compile();
