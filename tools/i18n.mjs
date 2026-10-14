// NIVEL: BANCO DE TRABAJO — no es una prueba. Traducciones (Armazón, fila 11): extrae, fusiona y compila.
//
//   node tools/i18n.mjs          todo: .pot → fusiona en cada .po → compila
//   node tools/i18n.mjs compile  solo compilar los .po (tras traducir)
//
// UN dominio para todo el plugin, estrategia-web (desde la 1.1.0, que juntó
// Site, Forms y los bloques del tema). Qué produce en languages/:
//   estrategia-web.pot                 plantilla
//   estrategia-web-<locale>.po         lo que se traduce (Poedit o a mano)
//   estrategia-web-<locale>.l10n.php   lo que lee WordPress 6.5+ (servidor Y editor)
//
// De dónde saca los textos:
//   - PHP: estrategia-web.php, includes/ y modules/ (sin tests/).
//   - JS: todos los .js de modules/ (editor.js, edit.js, view.js).
//   - block.json de modules/*/blocks/*: título, descripción y palabras clave,
//     con los contextos de block-i18n.json del núcleo.
//   - Cabecera de modules/*/module.php: «Module Name» (contexto «module
//     name») y «Description» («module description»), que la pantalla de
//     Módulos traduce con _x(). xgettext no las ve: se escriben a mano.
//
// NO hay JSON del editor (el <dominio>-<locale>-<md5>.json de WordPress): el
// núcleo del plugin le pasa al editor las mismas traducciones PHP en línea
// (includes/class-blocks.php). Una sola fuente, sin md5 de rutas que se
// rompen al mover un fichero.
//
// Necesita xgettext, msgmerge y msgcat (GNU gettext; vienen con Git para
// Windows). No hay .mo: WordPress 6.5+ prefiere el .l10n.php y el plugin pide
// 7.1, así que un .mo sería un tercer fichero que mantener sin lector.
//
// Cómo se mantiene:
// - Quién y cuándo: quien añada o cambie un texto, en el mismo commit. Se
//   corre esto, se traduce lo nuevo en el .po (lo que msgmerge deja «fuzzy» o
//   vacío) y se vuelve a correr `compile`.
// - Dos módulos nuevos a la vez tocan el MISMO .po: es uno de los tres
//   ficheros compartidos (ver includes/class-modules.php). Se regenera, no se
//   fusiona a mano: si git choca, se coge cualquiera de los dos, se vuelve a
//   correr esto y se traduce lo que salga vacío.
// - Un idioma nuevo: copiar el .pot a estrategia-web-<locale>.po, poner la
//   cabecera Language y traducir. Solo idiomas que vaya a leer alguien.
// - Caso frontera: los slugs de URL (_x( 'clients', 'URL slug' )). Se
//   traducen como un texto más, pero traducirlos CAMBIA las URL de las webs
//   ya publicadas en ese idioma: un cambio ahí es versión mayor.
// - La trampa: el msgid ES el texto en inglés (convención de WordPress).
//   Cambiar una coma del inglés deja la traducción como fuzzy, y una fuzzy NO
//   se compila: hasta que alguien la repase, sale en inglés. Por eso este
//   script avisa de cuántas hay.
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
    console.error(`${cmd} falló:\n${r.stderr || r.error}`);
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

  // block.json y cabeceras de module.php: xgettext no los lee, se escriben a mano.
  // Una entrada por contexto + texto (la palabra clave «form» sale en varios bloques), con todas sus referencias.
  const seen = new Map();
  const add = (file, ctx, msgid) => msgid && (seen.get(ctx + "\x04" + msgid)?.refs.push(file) ?? seen.set(ctx + "\x04" + msgid, { ctx, msgid, refs: [file] }));
  for (const file of walk("modules", (f) => f.endsWith("/block.json"))) {
    const data = JSON.parse(fs.readFileSync(path.join(ROOT, file), "utf8"));
    add(file, "block title", data.title);
    add(file, "block description", data.description);
    (data.keywords || []).forEach((k) => add(file, "block keyword", k));
  }
  for (const file of walk("modules", (f) => /^modules\/[^/]+\/module\.php$/.test(f))) {
    // Lo mismo que get_file_data(): «Clave:» al principio de una línea de comentario, en los primeros 8 KB.
    const head = fs.readFileSync(path.join(ROOT, file), "utf8").slice(0, 8192);
    const header = (key) => (head.match(new RegExp(`^[ \\t/*#@]*${key}:(.*)$`, "mi")) || [, ""])[1].replace(/\s*(?:\*\/|\?>).*$/, "").trim();
    add(file, "module name", header("Module Name"));
    add(file, "module description", header("Description"));
  }
  let extra = 'msgid ""\nmsgstr ""\n"Content-Type: text/plain; charset=UTF-8\\n"\n\n';
  for (const e of seen.values()) extra += `#: ${e.refs.join(" ")}\nmsgctxt ${q(e.ctx)}\nmsgid ${q(e.msgid)}\nmsgstr ""\n\n`;
  fs.writeFileSync(path.join(tmp, "extra.pot"), extra);

  run("msgcat", ["--use-first", "--sort-by-file", "-o", POT, path.join(tmp, "php.pot"), path.join(tmp, "js.pot"), path.join(tmp, "extra.pot")]);
  fs.rmSync(tmp, { recursive: true, force: true });

  // Cabecera estable: sin fecha de creación, para que el .pot no cambie si nada cambió.
  const pot = fs.readFileSync(POT, "utf8").replace(/"POT-Creation-Date: [^"]*"\n/, "").replace(/charset=CHARSET/, "charset=UTF-8");
  fs.writeFileSync(POT, pot);
  console.log(`.pot: ${(pot.match(/^msgid /gm).length - 1)} textos`);

  for (const po of poFiles()) run("msgmerge", ["--quiet", "--update", "--backup=none", "--previous", path.join(LANG, po), POT]);
}

function poFiles() {
  return fs.readdirSync(LANG).filter((f) => new RegExp(`^${DOMAIN}-[a-z]{2,3}(_[A-Z]{2})?\\.po$`).test(f));
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

    let php = "<?php\n// Generado por tools/i18n.mjs desde " + po + ". No se edita a mano: se edita el .po y se recompila.\nreturn array(\n";
    php += `\t'domain' => '${DOMAIN}',\n\t'language' => ${phpString(locale)},\n\t'plural-forms' => ${phpString(plural)},\n\t'messages' => array(\n`;
    for (const e of usable) php += `\t\t${phpString(key(e))} => ${phpString(e.msgstr.join("\0"))},\n`;
    php += "\t),\n);\n";
    fs.writeFileSync(path.join(LANG, `${DOMAIN}-${locale}.l10n.php`), php);

    console.log(`${po}: ${usable.length} compiladas · ${fuzzy} fuzzy · ${empty} sin traducir${fuzzy + empty ? "  ← REPASAR" : ""}`);
  }
}

if (process.argv[2] !== "compile") extract();
compile();
