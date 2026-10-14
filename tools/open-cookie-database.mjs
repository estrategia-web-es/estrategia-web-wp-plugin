// NIVEL: BANCO DE TRABAJO — no es una prueba. Regenera el extracto de la Open Cookie Database.
//
//   node tools/open-cookie-database.mjs             descarga el CSV de GitHub y reescribe el JSON
//   node tools/open-cookie-database.mjs --seco      descarga y cuenta, sin escribir nada
//   node tools/open-cookie-database.mjs --csv f.csv usa un CSV ya descargado (sin red)
//
// Qué produce: modules/consent/data/open-cookie-database.json, el extracto que
// el detector de cookies (modules/consent/includes/class-cookie-registry.php)
// usa para PROPONER proveedor, categoría, finalidad y duración de cada nombre
// de cookie que ve. El administrador lo confirma o lo cambia: es una
// propuesta, no la verdad.
//
// Fuente: https://github.com/jkwakman/Open-Cookie-Database (Jan Kwakman y
// colaboradores), licencia Apache 2.0. La atribución viaja DENTRO del JSON
// (campos source, license, attribution) y en modules/consent/data/NOTICE. Si
// se cambia el formato, que siga llevándola: la licencia lo pide.
//
// Formato del JSON (compacto: son ~2.300 filas y se lee en PHP en cada
// consulta del panel, no en cada visita):
//   { source, license, attribution, commit, generated,
//     cookies: [ [ nombre, proveedor, categoría, finalidad, duración, comodín ], … ] }
//   - categoría ya traducida a las del aviso: necessary, preferences,
//     statistics, marketing (MAP, abajo).
//   - comodín 1 = el nombre es un PREFIJO (_ga_ casa con _ga_ABC123).
//   - finalidad y duración, en inglés, tal cual la base.
//
// Cómo se mantiene:
// - Quién y cuándo: quien toque el detector, o cada pocos meses (la base
//   crece: el 14-10-2026 tenía 2.277 filas). Se corre, se mira el diff del
//   JSON por encima y se commitea con el número de filas en el mensaje.
// - Criterio de MAP: Functional y Security de la base son lo que la AEPD
//   llama técnicas (necesarias); Personalization es preferencias. Si la base
//   añade una categoría nueva, el script PARA y lo dice: se decide aquí.
// - Lo que la base clasifica mal para nuestras webs (wp-wpml_current_language,
//   la propia cookie del aviso) NO se arregla aquí: va en
//   modules/consent/data/base-cookies.json, que manda sobre este extracto.
//   Así regenerar no deshace una decisión nuestra.
// - Caso frontera: un mismo nombre en varias filas (p. ej. _pk_id de Matomo
//   y _pk_id. de Piwik PRO). Se quedan todas; el detector usa la PRIMERA que
//   casa exacta y, si ninguna, el prefijo más largo.
// - La trampa: el CSV tiene descripciones con comas, comillas dobladas ("")
//   y saltos de línea dentro de comillas. Partir por líneas y luego por comas
//   corta filas por la mitad y descoloca las columnas SIN AVISAR. Por eso el
//   lector de abajo va carácter a carácter, y el script comprueba que cada
//   fila tiene las 10 columnas de la cabecera.
import fs from "node:fs";
import path from "node:path";

const ROOT = path.resolve(import.meta.dirname, "..");
const OUT = path.join(ROOT, "modules", "consent", "data", "open-cookie-database.json");
const CSV_URL = "https://raw.githubusercontent.com/jkwakman/Open-Cookie-Database/master/open-cookie-database.csv";
const COMMITS_URL = "https://api.github.com/repos/jkwakman/Open-Cookie-Database/commits?per_page=1";

const MAP = {
  Functional: "necessary",
  Security: "necessary",
  Necessary: "necessary",
  Personalization: "preferences",
  Analytics: "statistics",
  Marketing: "marketing",
};

const args = process.argv.slice(2);
if (args.includes("--ayuda") || args.includes("-h")) {
  console.log(fs.readFileSync(import.meta.filename, "utf8").split("\nimport ")[0]);
  process.exit(0);
}
const dry = args.includes("--seco");
const csvArg = args.includes("--csv") ? args[args.indexOf("--csv") + 1] : null;

/** CSV (RFC 4180) a filas: comillas, "" dentro de comillas y saltos de línea entre comillas. */
function parseCsv(text) {
  const rows = [];
  let row = [];
  let field = "";
  let quoted = false;
  for (let i = 0; i < text.length; i++) {
    const c = text[i];
    if (quoted) {
      if (c === '"' && text[i + 1] === '"') {
        field += '"';
        i++;
      } else if (c === '"') quoted = false;
      else field += c;
    } else if (c === '"') quoted = true;
    else if (c === ",") {
      row.push(field);
      field = "";
    } else if (c === "\n" || c === "\r") {
      if (c === "\r" && text[i + 1] === "\n") i++;
      row.push(field);
      rows.push(row);
      row = [];
      field = "";
    } else field += c;
  }
  if (field !== "" || row.length) {
    row.push(field);
    rows.push(row);
  }
  return rows.filter((r) => r.length > 1 || r[0] !== "");
}

const clean = (s) => String(s ?? "").replace(/\s+/g, " ").trim();

let csv;
if (csvArg) csv = fs.readFileSync(csvArg, "utf8");
else {
  const res = await fetch(CSV_URL);
  if (!res.ok) throw new Error(`GET ${CSV_URL} -> ${res.status}`);
  csv = await res.text();
}
let commit = null;
try {
  const res = await fetch(COMMITS_URL, { headers: { "User-Agent": "estrategia-web-tools" } });
  if (res.ok) commit = (await res.json())[0]?.sha ?? null;
} catch {}

const [header, ...rows] = parseCsv(csv.replace(/^﻿/, ""));
const col = (name) => {
  const i = header.indexOf(name);
  if (i < 0) throw new Error(`El CSV no tiene la columna «${name}». Cabecera: ${header.join(" | ")}`);
  return i;
};
const C = {
  platform: col("Platform"),
  category: col("Category"),
  name: col("Cookie / Data Key name"),
  description: col("Description"),
  retention: col("Retention period"),
  wildcard: col("Wildcard match"),
};

const cookies = [];
const unknown = new Map();
let bad = 0;
for (const r of rows) {
  if (r.length !== header.length) {
    bad++;
    continue;
  }
  const name = clean(r[C.name]);
  const category = MAP[clean(r[C.category])];
  if (!name) continue;
  if (!category) {
    unknown.set(clean(r[C.category]), (unknown.get(clean(r[C.category])) || 0) + 1);
    continue;
  }
  cookies.push([name, clean(r[C.platform]), category, clean(r[C.description]), clean(r[C.retention]), clean(r[C.wildcard]) === "1" ? 1 : 0]);
}

// «Category» como valor es la fila de cabecera repetida que trae la base: no es una categoría.
unknown.delete("Category");
if (unknown.size) {
  console.error("✖ Categorías de la base que MAP no conoce (decide a cuál van y añádelas):", Object.fromEntries(unknown));
  process.exit(1);
}
if (bad) console.warn(`· ${bad} filas con un número de columnas distinto de la cabecera, descartadas`);

const out = {
  source: "https://github.com/jkwakman/Open-Cookie-Database",
  license: "Apache-2.0",
  attribution: "Open Cookie Database by Jan Kwakman and contributors, licensed under the Apache License 2.0. Extract regenerated by tools/open-cookie-database.mjs; categories mapped to the consent notice ones.",
  commit,
  generated: new Date().toISOString().slice(0, 10),
  cookies,
};
const json = JSON.stringify(out);
const byCat = cookies.reduce((acc, c) => ((acc[c[2]] = (acc[c[2]] || 0) + 1), acc), {});
console.log(`${cookies.length} cookies (${Object.entries(byCat).map(([k, v]) => `${k} ${v}`).join(", ")}), ${(json.length / 1024).toFixed(0)} KB, commit ${commit ?? "desconocido"}`);
if (dry) {
  console.log("--seco: no se escribe nada");
} else {
  fs.mkdirSync(path.dirname(OUT), { recursive: true });
  fs.writeFileSync(OUT, json + "\n");
  console.log("escrito " + path.relative(ROOT, OUT));
}
