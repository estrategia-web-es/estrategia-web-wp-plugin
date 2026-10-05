// Traducciones (Armazón, fila 11): extrae, fusiona y compila.
//
//   node tools/i18n.mjs          todo: .pot → fusiona en cada .po → compila
//   node tools/i18n.mjs compile  solo compilar los .po (tras traducir)
//
// Qué produce en languages/:
//   armazon-site.pot                         plantilla (solo PHP)
//   armazon-site-<locale>.po                 lo que se traduce (Poedit o a mano)
//   armazon-site-<locale>.l10n.php           lo que lee WordPress 6.5+ (PHP)
//
// Copiado de armazon-wp-formularios y recortado: aquí NO hay JSON del editor
// porque assets/editor.js no lleva textos (los nombres le llegan de PHP ya
// traducidos). Si un día el editor necesita un texto propio, se recupera la
// parte de JS de aquel script, no se inventa otra.
// Desde 0.5.0 hay UN block.json (blocks/consent/, el enlace del aviso de
// cookies): se extraen su título, descripción y palabras clave con sus
// contextos, y el PHP de blocks/. Su view.js no lleva textos (los pinta PHP).
//
// Necesita xgettext, msgmerge y msgcat (GNU gettext; vienen con Git para
// Windows). No hay .mo: WordPress 6.5+ prefiere el .l10n.php y el plugin pide
// 7.1, así que un .mo sería un tercer fichero que mantener sin lector.
//
// Cómo se mantiene:
// - Quién y cuándo: quien añada o cambie un texto, en el mismo commit. Se
//   corre esto, se traduce lo nuevo en el .po (lo que msgmerge deja «fuzzy» o
//   vacío) y se vuelve a correr `compile`.
// - Un idioma nuevo: copiar el .pot a armazon-site-<locale>.po, poner la
//   cabecera Language y traducir. Solo idiomas que vaya a leer alguien.
// - Caso frontera: los slugs de URL (_x( 'clients', 'URL slug' )). Se
//   traducen como un texto más, pero traducirlos CAMBIA las URL de las webs
//   ya publicadas en ese idioma: un cambio ahí es versión mayor.
// - La trampa: el msgid ES el texto en inglés (convención de WordPress, ver
//   README, «Textos y traducciones»). Cambiar una coma del inglés deja la
//   traducción como fuzzy, y una fuzzy NO se compila: hasta que alguien la
//   repase, sale en inglés. Por eso este script avisa de cuántas hay.
import { spawnSync } from "node:child_process";
import fs from "node:fs";
import path from "node:path";

const ROOT = path.resolve(import.meta.dirname, "..");
const LANG = path.join(ROOT, "languages");
const DOMAIN = "armazon-site";
const POT = path.join(LANG, `${DOMAIN}.pot`);

function run(cmd, args) {
  const r = spawnSync(cmd, args, { cwd: ROOT, encoding: "utf8" });
  if (r.status !== 0) {
    console.error(`${cmd} falló:\n${r.stderr || r.error}`);
    process.exit(1);
  }
  return r;
}

function walk(dir, ext) {
  return fs.readdirSync(path.join(ROOT, dir), { withFileTypes: true }).flatMap((d) => {
    const rel = path.posix.join(dir, d.name);
    if (d.isDirectory()) return ["tests", "tools", ".git"].includes(d.name) ? [] : walk(rel, ext);
    return rel.endsWith(ext) ? [rel] : [];
  });
}


function extract() {
  fs.mkdirSync(LANG, { recursive: true });
  const tmp = fs.mkdtempSync(path.join(LANG, ".tmp-"));
  const common = ["--from-code=UTF-8", "--add-comments=translators:", "--sort-by-file", "--package-name=" + DOMAIN, "--msgid-bugs-address="];
  const php = ["armazon-site.php", ...walk("includes", ".php"), ...walk("blocks", ".php")];
  run("xgettext", [...common, "--language=PHP", "--keyword=__", "--keyword=_e", "--keyword=esc_html__", "--keyword=esc_html_e", "--keyword=esc_attr__", "--keyword=esc_attr_e", "--keyword=_x:1,2c", "--keyword=_n:1,2", "-o", path.join(tmp, "php.pot"), ...php]);

  // block.json (0.5.0, el bloque del aviso de cookies): título, descripción y
  // palabras clave, con los contextos que usa WordPress al traducirlos
  // (block-i18n.json del núcleo). xgettext no lee JSON: se escribe a mano.
  const q = (s) => '"' + s.replace(/\\/g, "\\\\").replace(/"/g, '\\"') + '"';
  let blockPot = 'msgid ""\nmsgstr ""\n"Content-Type: text/plain; charset=UTF-8\\n"\n\n';
  for (const file of walk("blocks", "block.json")) {
    const data = JSON.parse(fs.readFileSync(path.join(ROOT, file), "utf8"));
    const add = (msgid, ctx) => msgid && (blockPot += `#: ${file}\nmsgctxt ${q(ctx)}\nmsgid ${q(msgid)}\nmsgstr ""\n\n`);
    add(data.title, "block title");
    add(data.description, "block description");
    (data.keywords || []).forEach((k) => add(k, "block keyword"));
  }
  fs.writeFileSync(path.join(tmp, "blocks.pot"), blockPot);

  run("msgcat", ["--use-first", "--sort-by-file", "-o", POT, path.join(tmp, "php.pot"), path.join(tmp, "blocks.pot")]);
  fs.rmSync(tmp, { recursive: true, force: true });

  // Cabecera estable: sin fecha de creación, para que el .pot no cambie si nada cambió.
  const pot = fs.readFileSync(POT, "utf8").replace(/"POT-Creation-Date: [^"]*"\n/, "").replace(/charset=CHARSET/, "charset=UTF-8");
  fs.writeFileSync(POT, pot);
  console.log(`.pot: ${(pot.match(/^msgid /gm).length - 1)} textos`);

  for (const po of poFiles()) run("msgmerge", ["--quiet", "--update", "--backup=none", "--previous", path.join(LANG, po), POT]);
}

function poFiles() {
  return fs.readdirSync(LANG).filter((f) => /^armazon-site-[a-z]{2,3}(_[A-Z]{2})?\.po$/.test(f));
}

// Lector de .po mínimo: lo justo para lo que escribe msgmerge.
function parsePo(text) {
  const entries = [];
  let cur = null;
  let field = null;
  const unq = (s) => JSON.parse(s.replace(/\\(?!["\\nt])/g, "\\\\"));
  const flush = () => cur && cur.msgid !== undefined && entries.push(cur);
  for (const raw of text.split(/\r?\n/)) {
    const line = raw.trim();
    if (line === "" ) { flush(); cur = null; field = null; continue; }
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

    let php = "<?php\n// Generado por tools/i18n.mjs desde " + po + ". No se edita a mano: se edita el .po y se recompila.\nreturn array(\n";
    php += `\t'domain' => 'armazon-site',\n\t'language' => ${phpString(locale)},\n\t'plural-forms' => ${phpString(plural)},\n\t'messages' => array(\n`;
    for (const e of usable) php += `\t\t${phpString(key(e))} => ${phpString(e.msgstr.join("\0"))},\n`;
    php += "\t),\n);\n";
    fs.writeFileSync(path.join(LANG, `${DOMAIN}-${locale}.l10n.php`), php);

    console.log(`${po}: ${usable.length} compiladas · ${fuzzy} fuzzy · ${empty} sin traducir${fuzzy + empty ? "  ← REPASAR" : ""}`);
  }
}

if (process.argv[2] !== "compile") extract();
compile();
