// NIVEL: BANCO DE TRABAJO — not a test: it is the runner (Armazón, row 7).
//
//   node tests/run.mjs             level 1, then 2 and 3 (a level 3 file does not start once a level 2 one is red)
//   node tests/run.mjs --nivel 1   only level 1 (the per-commit one: ~5 s)
//   node tests/run.mjs webp        level 1 and, of the rest, only those starting with «webp» (while developing)
//   node tests/run.mjs forms/      level 1 and, of the rest, those of the forms module
//   node tests/run.mjs --serve     only starts the test WordPress (and the WPML one, if there is a copy)
//                                  and keeps them up (Ctrl+C stops them): the next runs find them on
//                                  their ports and reuse them, one boot for many runs
//   EW_SHARDS=1 node tests/run.mjs …   one WordPress per kind, every test in a row (as before 28-10-2026)
//
// TARGET: the whole battery in 5 minutes or less, MEASURED (David, 28-10-2026;
// it was 31-55 min: 3331 s on 28-10-2026). The «Time:» line at the end is the
// measurement, and it says ⚠ when it goes over. The same treatment Lavandería
// got on 27-10-2026 (its tests/run.mjs, and ARMAZON.md «Los niveles se
// IMPONEN, no se declaran»):
// - A test proves that ONE problem is fixed, with the minimum data. It does
//   not rebuild a whole scenario («para eso está producción») and it does not
//   test what WordPress core or WPML do: only what OUR code does with them.
// - The first line says the level and, from level 2 up, why the level below
//   is not enough. THE RUNNER ENFORCES IT: a level 2 test without «NOT LEVEL
//   1 BECAUSE:» or a level 3 without «NOT LEVEL 2 BECAUSE:» stops the run
//   (ARMAZON's «NO ES NIVEL 1 PORQUE», in this repo's language). Writing it
//   BEFORE the test is what makes you look at the level below.
// - One example of each level, good ones to copy:
//     level 1: modules/content-types/tests/location-data.test.mjs (the derived
//              texts and the migration's parsers, PHP without WordPress);
//     level 2: modules/forms/tests/forms-list.test.mjs (what one screen finds
//              in a real database, read as HTML with a cookie session);
//     level 3: modules/map/tests/map.test.mjs, step 1 (a height the layout
//              engine computes: only exists painted; one page, two widths
//              by resizing the window, not loading it twice).
// - Each test FILE has a time limit, and going over it makes it RED (not an
//   alarm): LIMIT below, per level. The exception is written in the test, with
//   its reason, on a line of its header:
//       // TIEMPO: 60 s — <why this one needs more, and what was tried first>
//   The runner honours it and lists every exception at the end: an exception
//   is seen, and one that grows is discussed.
// - Several tests that load the same pages share ONE browser pass per page and
//   width; the editor tests share one login and one editor boot per file.
//
// It discovers the tests, it does not list them: tests/*.test.mjs (the core
// ones) and modules/<slug>/tests/*.test.mjs (each module's). The level is
// given by the FIRST line of each one; a test with no declared level stops
// the whole run. A new module with tests does not touch this file.
//
// HOW IT IS FAST (28-10-2026). A request to the test WordPress costs ~1,1 s
// whatever it does (measured with /ping?timing of tests/wp/estrategia-web-site-test.php:
// ~800 ms are WordPress and the SQLite drop-in BEFORE any plugin loads, the
// plugin ~150 ms; a plain .php file 12 ms; the legacy SQLite driver and one
// worker change nothing). So the battery's time is its NUMBER OF REQUESTS,
// and it was ONE file after another against ONE WordPress that made 55
// minutes. Hence the cuts (each test file's header says what it proves) and:
// - SHARDS: levels 2 and 3 are split across several WordPresses that run at
//   the same time, each its own Playground and its own database (EW_SHARDS,
///   default 5, plus EW_WPML_SHARDS, default 1, for the WPML ones: measured
//   28-10-2026, the WPML files are ~15 % of the work and a WPML boot takes
//   twice as long, so one WPML WordPress ends with the five others). Inside a
//   shard the files run one after another, as before: a test still has the
//   WordPress to itself and still starts with its reset().
// - The files are dealt by their last measured time (tests/.timings.json,
//   written at the end of each run, gitignored; a file never measured counts
//   30 s), longest first to the least loaded shard.
// - Every Playground boots while level 1 runs (level 1 needs none), and each
//   one starts its files as soon as IT is ready: alone a boot is ~45 s (15 of
//   them npx), six at once 75-140 s, the WPML ones last (they also open
//   String Translation's screen once, warmWpml()).
// - Levels 2 and 3 are dealt together, level 2 first inside each pile: no
//   WordPress waits idle at a barrier between the levels.
// - Playground gets --workers=2: a test can send two requests at once (an
//   editor's REST calls, a Promise.all), and two workers per shard keep the
//   memory at ~0,5 GB each (six workers, Playground's default, were 1,1 GB).
// - The trap of the two workers: two requests that CREATE files in the same
//   new folder at the same time race (two parallel uploads: «Unable to create
//   directory wp-content/uploads/2026/10», 28-10-2026). tests/blueprint.json
//   creates the month's uploads folder at boot; parallel uploads are fine
//   after that, but a test that creates folders of its own does it in turn.
// Ports: the first shard keeps the family's port (9404, and 9414 for WPML);
// the others are PORT + 16 + n (9421, 9422…) and WPML_PORT + 16 + n (9431…).
//
// If level 2 or 3 is needed and there is no WordPress on a shard's port, it
// STARTS Playground (WP 7.1, PHP 8.3, the plugin mounted as
// plugins/estrategia-web and tests/blueprint.json, which loads the test
// mu-plugin of each module that has one) and KILLS it at the end with
// taskkill /T /F: killing the parent does not kill the children on Windows.
// If one was already running, it leaves it as it was.
//
// Ports of the family: 9401 the parent theme, 9404 this plugin, 9405
// Lavandería, 9414 this plugin WITH WPML (below). (9402 was
// armazon-wp-formularios', archived.)
//
// WPML (25-10-2026). WPML is a paid plugin and is NEVER in git, but part of
// what this plugin does only exists with it (wpml-config.xml, /en/, String
// Translation). A test that needs it carries the line «// WPML: REAL — …» in
// its header, and those tests run against a SECOND WordPress, on port
// PORT + 10 (9414; EW_WPML_PORT to change it): the same blueprint plus the
// WPML copy mounted (sitepress-multilingual-cms and wpml-string-translation),
// which tests/wp/setup-wpml.php leaves as a FINISHED install (es by default,
// en in /en/, both marks of the wizard). It is only started when a selected
// test needs it. The copy is looked for, in this order:
//   1. EW_WPML_DIR=<folder>                     (the folder that holds the two plugin folders)
//   2. tests/fixtures/wpml/ of this repo        (gitignored)
//   3. E:/Repositories/lavanderia-mallorca/tests/fixtures/wpml/  (the copy that exists today;
//      how it is brought from the server, in the header of Lavandería's tests/server.mjs)
// EW_WPML_DIR=none: as if there were no copy.
//
// How to maintain it:
// - Who: whoever writes a test. Its first line (level and «NOT LEVEL n
//   BECAUSE:»), its reset() in before(), and, if it needs real WPML, the line
//   «// WPML: REAL» and requireWpml() (tests/lib.mjs) first. Nothing here.
// - The criterion for «// WPML: REAL»: only if what is tested is what OUR code
//   does with WPML's own behaviour (it reads our XML, it routes /en/ to our
//   block, String Translation translates our strings). Anything that only
//   needs the plugin to BELIEVE there is WPML uses the double (?wpml-double,
//   tests/wp/estrategia-web-site-test.php), which runs in the normal
//   WordPress. Edge case: wplang.test.mjs and catalan.test.mjs test the
//   locale the plugin picks per language — the double, because it is the
//   plugin's decision, not WPML's.
// - Without the copy, the WPML tests run against the normal WordPress and
//   FAIL with requireWpml()'s message (they are not skipped), the same rule
//   as «no php on the PATH»: a test that does not run looks as if it covered
//   something.
// - Edge case of the shards: a test that only passes AFTER another one
//   (forms-list needed core's Spanish language pack that some earlier request
//   had made WordPress download, 28-10-2026) now fails depending on the deal.
//   That is a badly isolated test, and the fix goes in the test, never in
//   the order: with EW_SHARDS=1 the order is alphabetical again, to tell
//   «isolation» from «my change» quickly.
// - The trap of the first editor: the admin's FIRST block editor load in a
//   fresh WordPress opens the welcome guide (a modal) and paints our fields
//   panel collapsed. With shards any editor test can be that first one
//   (content-types/editor-panel went red that way, 28-10-2026), so
//   tests/blueprint.json switches the guide off at boot for user 1.
// - The trap of the shards: memory. Each Playground is ~0,5 GB with two
//   workers, plus a Chromium per browser test; 6 of them fit in the 15,7 GB
//   of this machine with room to spare. On a smaller machine, EW_SHARDS=2.
// - The trap of WPML on SQLite: it failed SILENTLY at three things (advisory
//   locks, an INSERT … LAST_INSERT_ID(), the String Translation collation);
//   the fixes, and how to find a new one, in tests/wp/estrategia-web-wpml-test.php.
//   And when WPML is updated on the server, the copy is brought again
//   (Lavandería's header): an old copy tests an old WPML.
//
// The trap (the same as in armazon-wp-formularios): from Git Bash,
// `npx … --mount-dir E:/x /wordpress/…` arrives with the Linux path converted
// to «C:/Program Files/Git/wordpress/…» and the mount fails with
// «[object Object]». Launched from here (Node, no MSYS) it does not happen.
// If you launch it by hand from Git Bash: put MSYS_NO_PATHCONV=1 in front.
import { spawn, spawnSync } from "node:child_process";
import fs from "node:fs";
import path from "node:path";

const ROOT = path.resolve(import.meta.dirname, "..");
// EW_PORT=9412 node tests/run.mjs … (or AS_PORT, the same) to move the family
// of ports. The tests read the base URL from AS_BASE (tests/lib.mjs and the
// modules' lib.mjs files), which is set here for each shard so they inherit it.
const PORT = Number(process.env.EW_PORT || process.env.AS_PORT) || 9404;
const WPML_PORT = Number(process.env.EW_WPML_PORT) || PORT + 10;
const SHARDS = Math.max(1, Number(process.env.EW_SHARDS) || 5);
const WPML_SHARDS = Math.max(1, Number(process.env.EW_WPML_SHARDS) || 1);
const only = process.argv.includes("--nivel") ? process.argv[process.argv.indexOf("--nivel") + 1] : null;
// Seconds per test FILE, per level (see the header). Over it, the file is red.
// Level 1 runs as one process (all its files at once) and is not timed per file.
// Measured INSIDE the whole battery (28-10-2026), where six WordPresses share the machine
// and a file takes ~1,4 times what it takes alone.
const LIMIT = { 2: 60, 3: 75 };
let stopping = false;
const TARGET = 5 * 60;
// Request-handling workers per Playground (see the header, «--workers»).
const WORKERS = Number(process.env.EW_WORKERS) || 2;
const TIMINGS = path.join(ROOT, "tests", ".timings.json");

// The local WPML copy (see the header): the folder holding sitepress-multilingual-cms/, or null.
const WPML_DIR = (() => {
  if (process.env.EW_WPML_DIR === "none") return null;
  const candidates = [process.env.EW_WPML_DIR, path.join(ROOT, "tests", "fixtures", "wpml"), "E:/Repositories/lavanderia-mallorca/tests/fixtures/wpml"];
  return candidates.find((dir) => dir && fs.existsSync(path.join(dir, "sitepress-multilingual-cms"))) ?? null;
})();
const wpmlMounts = () =>
  ["sitepress-multilingual-cms", "wpml-string-translation"]
    .filter((p) => fs.existsSync(path.join(WPML_DIR, p)))
    .flatMap((p) => ["--mount-dir", path.join(WPML_DIR, p), `/wordpress/wp-content/plugins/${p}`]);

// The WordPresses: SHARDS normal ones and, with a WPML copy, WPML_SHARDS with WPML.
const shardPort = (base, n) => (n === 0 ? base : base + 16 + n);
const normal = Array.from({ length: SHARDS }, (_, n) => ({ name: `WordPress ${n + 1}/${SHARDS}`, port: shardPort(PORT, n), mounts: () => [], wpml: false, child: null }));
const withWpml = WPML_DIR
  ? Array.from({ length: WPML_SHARDS }, (_, n) => ({ name: `WordPress with WPML ${n + 1}/${WPML_SHARDS}`, port: shardPort(WPML_PORT, n), mounts: wpmlMounts, wpml: true, child: null }))
  : null;
const baseOf = (wp) => `http://127.0.0.1:${wp.port}`;

// All the tests, with their path relative to the root and a short name to pick them.
const all = [
  ...fs.readdirSync(path.join(ROOT, "tests")).filter((f) => f.endsWith(".test.mjs")).map((f) => path.posix.join("tests", f)),
  ...fs.readdirSync(path.join(ROOT, "modules")).flatMap((m) => {
    const dir = path.join(ROOT, "modules", m, "tests");
    return fs.existsSync(dir) ? fs.readdirSync(dir).filter((f) => f.endsWith(".test.mjs")).map((f) => path.posix.join("modules", m, "tests", f)) : [];
  }),
].sort();
const nameOf = (rel) => (rel.startsWith("modules/") ? rel.split("/")[1] + "/" : "") + path.posix.basename(rel, ".test.mjs");
const source = (rel) => fs.readFileSync(path.join(ROOT, rel), "utf8");
const needsWpml = (rel) => /^\/\/ WPML: REAL\b/m.test(source(rel));
// «// TIEMPO: 60 s — why» in the header (the first 40 lines): the file's own limit.
const ownLimit = (rel) => {
  const m = source(rel).split("\n").slice(0, 40).join("\n").match(/^\/\/ TIEMPO: (\d+) s — (.+)$/m);
  return m ? { seconds: Number(m[1]), why: m[2] } : null;
};

// Individual tests by name: `node tests/run.mjs external-links` runs the
// whole of level 1 and, of the rest, only those that match (by file name or
// by «module/»). It is the rule of row 7 («When the suite runs»): while
// developing, level 1 always plus the new test; the whole run, on demand or
// before publishing.
const picked = process.argv.slice(2).filter((a, i, list) => !a.startsWith("--") && list[i - 1] !== "--nivel");
const files = all.filter((rel) => {
  if (!picked.length) return true;
  if (source(rel).startsWith("// NIVEL: 1")) return true;
  const short = nameOf(rel);
  const base = path.posix.basename(rel, ".test.mjs");
  return picked.some((p) => base.startsWith(p.replace(/\.test\.mjs$/, "")) || short.startsWith(p));
});
const byLevel = {};
for (const f of files) {
  const first = source(f).split("\n")[0];
  const m = first.match(/^\/\/ NIVEL: (\d)\b/);
  if (!m) {
    console.error(`✖ ${f} does not declare its level on the first line («// NIVEL: n — …»). Nothing is run.`);
    process.exit(1);
  }
  if (m[1] === "1" && needsWpml(f)) {
    console.error(`✖ ${f} is level 1 and says «// WPML: REAL»: a level 1 test touches no WordPress. Nothing is run.`);
    process.exit(1);
  }
  // ARMAZON «Los niveles se IMPONEN»: from level 2 up, the first line says why the level below is not enough.
  const below = Number(m[1]) - 1;
  if (below >= 1 && (!only || only === m[1]) && !process.argv.includes("--serve") && !new RegExp(`(NOT LEVEL ${below} BECAUSE|NO ES NIVEL ${below} PORQUE):`).test(first)) {
    console.error(`✖ ${f} is level ${m[1]} and its first line does not say «NOT LEVEL ${below} BECAUSE: …». Write why level ${below} is not enough (header of tests/run.mjs). Nothing is run.`);
    process.exit(1);
  }
  (byLevel[m[1]] ??= []).push(f);
}

const levels = Object.keys(byLevel).sort().filter((l) => !only || l === only);
const needsWp = levels.some((l) => l !== "1");
const wpmlWanted = levels.some((l) => l !== "1" && byLevel[l].some(needsWpml));

async function up(wp) {
  try {
    return (await fetch(baseOf(wp) + "/wp-json/")).ok;
  } catch {
    return false;
  }
}

async function startPlayground(wp) {
  if ((await up(wp)) || stopping) return;
  const log = path.join(ROOT, "tests", `.playground-${wp.port}.log`);
  fs.writeFileSync(log, "");
  wp.child = spawn(
    "npx",
    ["-y", "@wp-playground/cli@latest", "server", "--wp=7.1", "--php=8.3", `--port=${wp.port}`, `--workers=${WORKERS}`,
      "--mount-dir", ROOT, "/wordpress/wp-content/plugins/estrategia-web",
      ...wp.mounts(),
      "--blueprint=" + path.join(ROOT, "tests", "blueprint.json"), "--login"],
    { cwd: ROOT, shell: true, stdio: ["ignore", "pipe", "pipe"] }
  );
  await new Promise((resolve, reject) => {
    let text = "";
    const onData = (d) => {
      text += d;
      fs.appendFileSync(log, d);
      if (text.includes("Ready!")) resolve();
      else if (/\bError\b/.test(text)) reject(new Error(`${wp.name}: ${text}`));
    };
    wp.child.stdout.on("data", onData);
    wp.child.stderr.on("data", onData);
    wp.child.on("exit", (code) => reject(new Error(`${wp.name}: Playground exited with ${code}\n${text}`)));
  });
  // «Ready!» is not enough: it waits for the FACT the tests need, that the
  // routes of the test mu-plugins respond (and, with WPML, that it is set up).
  const fact = wp.wpml ? "/wp-json/estrategia-web-wpml-test/v1/state" : "/wp-json/estrategia-web-site-test/v1/ping";
  for (let i = 0; i < 240; i++) {
    try {
      if ((await fetch(baseOf(wp) + fact)).ok) return wp.wpml ? warmWpml(wp) : undefined;
    } catch {}
    await new Promise((r) => setTimeout(r, 500));
  }
  throw new Error(`${wp.name} started but ${fact} does not respond (did the blueprint fail?). See ${log}`);
}

// On a fresh WPML WordPress the FIRST wp-admin request that opens String
// Translation takes ~30 s (WPML finishing its own setup: tables, caches).
// It is paid here, during the boot, while level 1 runs, and not by whichever
// WPML test happens to come first (site/company-data-wpml needed a 75 s limit
// for it, 28-10-2026).
async function warmWpml(wp) {
  const res = await fetch(baseOf(wp) + "/wp-login.php", {
    method: "POST",
    redirect: "manual",
    headers: { "Content-Type": "application/x-www-form-urlencoded", Cookie: "wordpress_test_cookie=WP%20Cookie%20check" },
    body: new URLSearchParams({ log: "admin", pwd: "password", testcookie: "1", redirect_to: baseOf(wp) + "/wp-admin/" }).toString(),
  });
  const cookie = (res.headers.getSetCookie?.() ?? []).map((c) => c.split(";")[0]).join("; ");
  await (await fetch(baseOf(wp) + "/wp-admin/admin.php?page=wpml-string-translation%2Fmenu%2Fstring-translation.php", { headers: { Cookie: cookie } })).text();
}

function stopPlayground(wp) {
  if (!wp.child) return;
  if (process.platform === "win32") spawnSync("taskkill", ["/pid", String(wp.child.pid), "/T", "/F"], { stdio: "ignore" });
  else wp.child.kill();
  wp.child = null;
}
const everyWp = () => [...normal, ...(withWpml ?? [])];
const stopAll = () => everyWp().forEach(stopPlayground);
process.on("SIGINT", () => {
  stopAll();
  process.exit(130);
});

if (process.argv.includes("--serve")) {
  for (const wp of [normal[0], ...(withWpml ? [withWpml[0]] : [])]) {
    if (await up(wp)) console.log(`There is already a WordPress on ${baseOf(wp)}: left as it is.`);
    else await startPlayground(wp);
    console.log(`${baseOf(wp)}/  ${wp.name}`);
  }
  console.log("(Ctrl+C stops what this command started)");
  await new Promise(() => {});
}

/** Runs one test file in its own process; resolves to { rel, ok, seconds, output }. */
function runFile(rel, env) {
  return new Promise((resolve) => {
    const started = Date.now();
    const child = spawn(process.execPath, ["--test", rel], { cwd: ROOT, env: { ...process.env, ...env } });
    let output = "";
    child.stdout.on("data", (d) => (output += d));
    child.stderr.on("data", (d) => (output += d));
    child.on("close", (code) => resolve({ rel, ok: code === 0, seconds: (Date.now() - started) / 1000, output }));
  });
}

// Last measured time per file (tests/.timings.json), to deal them out evenly.
let known = {};
try {
  known = JSON.parse(fs.readFileSync(TIMINGS, "utf8"));
} catch {}
const measured = {};
/** Deals `list` into `n` piles, longest first to the least loaded one. */
function deal(list, n) {
  const piles = Array.from({ length: n }, () => ({ load: 0, files: [] }));
  for (const rel of [...list].sort((a, b) => (known[b] ?? 30) - (known[a] ?? 30))) {
    const pile = piles.reduce((min, p) => (p.load < min.load ? p : min));
    pile.files.push(rel);
    pile.load += known[rel] ?? 30;
  }
  return piles.filter((p) => p.files.length);
}

const exceptions = new Map();
/** One file's verdict: red if its tests failed or it went over its limit. Prints one line (and the output if red). */
function verdict(level, r, where) {
  measured[r.rel] = Math.round(r.seconds * 10) / 10;
  const own = ownLimit(r.rel);
  if (own) exceptions.set(r.rel, own);
  const limit = own?.seconds ?? LIMIT[level];
  const slow = r.seconds > limit;
  const ok = r.ok && !slow;
  const why = !r.ok ? "" : slow ? ` — OVER ITS LIMIT of ${limit} s (${own ? "its «TIEMPO»" : `level ${level}`}): make it faster, or write a «// TIEMPO:» line with the reason` : "";
  console.log(`${ok ? "✔" : "✖"} [${level}] ${nameOf(r.rel)} ${r.seconds.toFixed(1)} s${where}${why}`);
  if (!r.ok) console.log(r.output.replace(/^/gm, "    "));
  return ok;
}

let failed = false;
const t0 = Date.now();
const times = [];
const levelOf = (rel) => Number(source(rel).match(/^\/\/ NIVEL: (\d)/)[1]);
try {
  // Every WordPress the selected tests need boots while level 1 runs; each one
  // starts its files as soon as IT is ready (a WPML one takes twice as long).
  const wanted = needsWp ? [...normal, ...(wpmlWanted && withWpml ? withWpml : [])] : [];
  const booted = [];
  for (const wp of wanted) {
    wp.ready = startPlayground(wp).then(
      () => (booted.push((Date.now() - t0) / 1000), null),
      (e) => e
    );
  }
  if (wanted.length) console.log(`· starting ${wanted.length} WordPress (Playground) in the background: ${wanted.map((wp) => wp.port).join(", ")}`);
  if (levels.includes("1")) {
    const t = Date.now();
    // Level 1 needs no WordPress: one process, all the files, as many at once as there are cores.
    console.log(`\n=== Level 1: ${byLevel["1"].length} files`);
    const r = await new Promise((resolve) => {
      const child = spawn(process.execPath, ["--test", ...byLevel["1"]], { cwd: ROOT, stdio: "inherit" });
      child.on("close", (code) => resolve(code));
    });
    times.push(`level 1 ${((Date.now() - t) / 1000).toFixed(0)} s`);
    if (r !== 0) {
      console.error(`\n✖ Level 1 in red: the following ones are not run.`);
      failed = true;
    }
  }
  const later = levels.filter((l) => l !== "1").flatMap((l) => byLevel[l]);
  if (!failed && later.length) {
    // Levels 2 and 3 in ONE deal (no idle WordPress waiting at a barrier between them): the WPML
    // files go to the WPML WordPresses, the rest to the normal ones, and inside each pile the
    // level 2 files go first. «Stops at the first red level» becomes: once a level 2 file is
    // red, no level 3 file STARTS (one already running finishes).
    const t = Date.now();
    const wpmlFiles = withWpml ? later.filter(needsWpml) : [];
    const plain = later.filter((f) => !wpmlFiles.includes(f));
    const piles = [
      ...deal(plain, normal.length).map((p, i) => ({ ...p, wp: normal[i] })),
      ...(wpmlFiles.length ? deal(wpmlFiles, withWpml.length).map((p, i) => ({ ...p, wp: withWpml[i] })) : []),
    ];
    console.log(`\n=== Levels 2 and 3: ${later.length} files in ${piles.length} WordPress at once`);
    let red2 = false;
    const notRun = [];
    await Promise.all(
      piles.map(async ({ wp, files: mine }) => {
        const error = await wp.ready;
        if (error) {
          console.error(`✖ ${error.message}`);
          failed = true;
          notRun.push(...mine);
          return;
        }
        // The test users' application passwords, created ONCE per WordPress (tests/lib.mjs credentials()):
        // each file asking for its own was one request more per file.
        const users = await fetch((process.env.AS_BASE ?? baseOf(wp)) + "/wp-json/estrategia-web-site-test/v1/users", { method: "POST" });
        const EW_CREDENTIALS = users.ok ? await users.text() : "";
        for (const rel of [...mine].sort((a, b) => levelOf(a) - levelOf(b))) {
          if (red2 && levelOf(rel) === 3) {
            notRun.push(rel);
            continue;
          }
          const r = await runFile(rel, { AS_BASE: process.env.AS_BASE ?? baseOf(wp), EW_CREDENTIALS });
          if (!verdict(levelOf(rel), r, ` (${wp.port})`)) {
            failed = true;
            if (levelOf(rel) === 2) red2 = true;
          }
        }
      })
    );
    if (booted.length) times.push(`boot ${Math.min(...booted).toFixed(0)}-${Math.max(...booted).toFixed(0)} s`);
    times.push(`levels 2 and 3 ${((Date.now() - t) / 1000).toFixed(0)} s`);
    if (notRun.length) console.error(`\n✖ ${red2 ? "Level 2 in red: these level 3 files were not run" : "Not run"}: ${notRun.map(nameOf).join(", ")}`);
  }
} catch (e) {
  console.error(e.message);
  failed = true;
} finally {
  stopping = true;
  stopAll();
}
// The measured times feed the next deal (only files that ran; the rest keep theirs).
try {
  fs.writeFileSync(TIMINGS, JSON.stringify({ ...known, ...measured }, null, 1) + "\n");
} catch {}
const slowest = Object.entries(measured).sort((a, b) => b[1] - a[1]).slice(0, 8);
if (slowest.length) console.log(`\nSlowest files: ${slowest.map(([f, s]) => `${nameOf(f)} ${s} s`).join(" · ")}`);
if (exceptions.size) console.log(`Own time limits («// TIEMPO:»): ${[...exceptions].map(([f, e]) => `${nameOf(f)} ${e.seconds} s (${e.why})`).join(" · ")}`);
const total = (Date.now() - t0) / 1000;
console.log(`\nTime: ${total.toFixed(0)} s — ${times.join(" · ")}${WPML_DIR ? "" : " (no WPML copy: the WPML tests ran without it)"}`);
if (!only && !picked.length && total > TARGET) console.log(`⚠ Over the ${TARGET / 60} min target (header of tests/run.mjs): look at «Slowest files» first.`);
process.exit(failed ? 1 : 0);
