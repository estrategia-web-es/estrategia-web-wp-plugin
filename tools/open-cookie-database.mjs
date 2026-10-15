// NIVEL: BANCO DE TRABAJO — not a test. Regenerates the Open Cookie Database extract.
//
//   node tools/open-cookie-database.mjs             downloads the CSV from GitHub and rewrites the JSON
//   node tools/open-cookie-database.mjs --seco      downloads and counts, writes nothing (dry run)
//   node tools/open-cookie-database.mjs --csv f.csv uses an already downloaded CSV (no network)
//
// What it produces: modules/consent/data/open-cookie-database.json, the extract
// the cookie detector (modules/consent/includes/class-cookie-registry.php)
// uses to PROPOSE provider, category, purpose and duration for each cookie
// name it sees. The administrator confirms or changes it: it is a proposal,
// not the truth.
//
// Source: https://github.com/jkwakman/Open-Cookie-Database (Jan Kwakman and
// contributors), Apache 2.0 license. The attribution travels INSIDE the JSON
// (source, license, attribution fields) and in modules/consent/data/NOTICE. If
// the format changes, it must keep carrying it: the license requires it.
//
// JSON format (compact: ~2,300 rows, read in PHP on each admin panel request,
// not on each visit):
//   { source, license, attribution, commit, generated,
//     cookies: [ [ name, provider, category, purpose, duration, wildcard ], … ] }
//   - category already mapped to the notice's: necessary, preferences,
//     statistics, marketing (MAP, below).
//   - wildcard 1 = the name is a PREFIX (_ga_ matches _ga_ABC123).
//   - purpose and duration in English, as in the database.
//
// How to maintain:
// - Who and when: whoever touches the detector, or every few months (the
//   database grows: on 14-10-2026 it had 2,277 rows). Run it, skim the JSON
//   diff and commit with the row count in the message.
// - MAP criterion: the database's Functional and Security are what the AEPD
//   calls technical (necessary); Personalization is preferences. If the
//   database adds a new category, the script STOPS and says so: decide here.
// - What the database misclassifies for our sites (wp-wpml_current_language,
//   the notice's own cookie) is NOT fixed here: it goes in
//   modules/consent/data/base-cookies.json, which overrides this extract.
//   That way regenerating does not undo a decision of ours.
// - Edge case: the same name in several rows (e.g. Matomo's _pk_id and
//   Piwik PRO's _pk_id.). All are kept; the detector uses the FIRST exact
//   match and, if none, the longest prefix.
// - The pitfall: the CSV has descriptions with commas, doubled quotes ("")
//   and line breaks inside quotes. Splitting by lines and then by commas
//   cuts rows in half and shifts the columns WITHOUT WARNING. That is why the
//   reader below goes character by character, and the script checks that each
//   row has the header's 10 columns.
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

/** CSV (RFC 4180) to rows: quotes, "" inside quotes and line breaks between quotes. */
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
  if (i < 0) throw new Error(`The CSV has no "${name}" column. Header: ${header.join(" | ")}`);
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

// "Category" as a value is the repeated header row the database carries: it is not a category.
unknown.delete("Category");
if (unknown.size) {
  console.error("✖ Database categories MAP does not know (decide where they go and add them):", Object.fromEntries(unknown));
  process.exit(1);
}
if (bad) console.warn(`· ${bad} rows with a column count different from the header, discarded`);

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
console.log(`${cookies.length} cookies (${Object.entries(byCat).map(([k, v]) => `${k} ${v}`).join(", ")}), ${(json.length / 1024).toFixed(0)} KB, commit ${commit ?? "unknown"}`);
if (dry) {
  console.log("--seco: nothing is written");
} else {
  fs.mkdirSync(path.dirname(OUT), { recursive: true });
  fs.writeFileSync(OUT, json + "\n");
  console.log("wrote " + path.relative(ROOT, OUT));
}
