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
//     the browser (what was level 3): modules/map/tests/browser/29-map-plain.json
//              and its check modules/map/tests/browser-map-plain.test.mjs (a
//              height the layout engine computes, the requests before and
//              after a click: only exist painted and running).
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
// THE BROWSER (29-10-2026, ARMAZON row 7, «Los niveles se IMPONEN» point 7;
// it replaces level 3): the browser tests are SCENARIOS — JSON in the Chrome
// Recorder format, modules/<slug>/tests/browser/NN-name.json — that
// tests/browser.mjs runs with ONE Chromium, and CHECKS — level 1,
// modules/<slug>/tests/browser-<name>.test.mjs — that read what it saved.
// How a scenario is written, the freshness rule and the no-fixed-wait rule:
// the header of tests/browser.mjs. Here:
// - the scenarios are dealt to the shards with the level 2 files (by their
//   last measured time, «browser:<id>» in tests/.timings.json) and each
//   shard runs ITS scenarios, after its level 2 files, in ONE process with
//   ONE browser (tests/browser.mjs --base <that shard>): at most one
//   Chromium per shard. Inside a shard the scenarios go one after another:
//   each one starts by setting its WordPress (its setup step);
//   a scenario is red if a step failed or it took over LIMIT.browser seconds;
// - once every shard is done, the checks of the scenarios that ran go in ONE
//   `node --test` (phase «checks»). A check is level 1 but is NOT in the
//   level 1 phase: before the scenarios run it would read the previous run
//   (and fail for being older than the code). With --nivel 1 (pre-commit)
//   they are not run at all, and it says so.
// - a level 2 file in red stops the browser as it stopped level 3: no
//   shard STARTS its scenarios once a level 2 file is red.
// - A file that still declares «NIVEL: 3» runs as before, after level 2;
//   there are none since 29-10-2026: a new browser test is a scenario.
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
//   by default one per three logical CPUs (AUTO_SHARDS: 4 here, chosen by
//   measuring 3 to 6), plus EW_WPML_SHARDS, default 1, for the WPML ones: the
//   WPML files are ~15 % of the work, and with its snapshot the WPML
//   WordPress ends before the others). Inside a
//   shard the files run one after another, as before: a test still has the
//   WordPress to itself and still starts with its reset().
// - The files are dealt by their last measured time (tests/.timings.json,
//   written at the end of each run, gitignored; a file never measured counts
//   30 s), longest first to the least loaded shard.
// - Every Playground boots while level 1 runs (level 1 needs none), and each
//   one starts its files as soon as IT is ready. Since 28-10-2026 (evening) a
//   normal shard starts from a copy of the golden WordPress and the WPML one
//   from the WPML snapshot (both explained where they are built, below):
//   ready at 31-45 s and ~55 s, against 75-155 s before.
// - SAME RESULT EVERY RUN (28-10-2026, evening): the CLI and WordPress are
//   pinned (CLI_VERSION, the URL in tests/blueprint.json), the test WordPress
//   is offline and without WP-cron (tests/wp/estrategia-web-test-env.php),
//   a file that hangs is killed (runFile()), a boot fails only if Playground
//   exits or never says «Ready!», and the «machine:» line at the start says
//   how busy the machine was (a slow run is then explainable, not a mystery).
//   PHP's errors go to a log per shard and are printed under a red file.
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
// STARTS Playground (WP 7.1.4, PHP 8.3, the plugin mounted as
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
//   workers, plus a Chromium per browser test; AUTO_SHARDS takes fewer when
//   the free memory at the start does not hold ~0,8 GB per WordPress.
// - The trap of the CPU: the battery is CPU-bound (80-95 % busy during the
//   run). Windows Defender scanning Playground's files used ~1000-1400 CPU
//   seconds per run, nearly as much as all the Playgrounds (1400-2000); an
//   exclusion for %TEMP%\estrategia-web-tests is the owner's decision, not
//   this file's.
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
import { listScenarios, checksOf, checkFiles, OUT_DIR as BROWSER_OUT } from "./browser.mjs";
import crypto from "node:crypto";
import fs from "node:fs";
import os from "node:os";
import path from "node:path";

const ROOT = path.resolve(import.meta.dirname, "..");
// EW_PORT=9412 node tests/run.mjs … (or AS_PORT, the same) to move the family
// of ports. The tests read the base URL from AS_BASE (tests/lib.mjs and the
// modules' lib.mjs files), which is set here for each shard so they inherit it.
const PORT = Number(process.env.EW_PORT || process.env.AS_PORT) || 9404;
const WPML_PORT = Number(process.env.EW_WPML_PORT) || PORT + 10;
// How many normal WordPresses, by default (28-10-2026, evening): ONE PER THREE LOGICAL CPUs, and
// fewer if the free memory does not hold them (~0,8 GB each with its browser). Measured on this
// machine (12 logical CPUs, 6 cores; same code, back to back, plus the WPML one each time):
//   3: 433 s (CPU 75 %)   4: 349 s (83 %)   5: 368 s (95 %)   6: 432 s (98 %, and one editor
//   wait timed out: starved, not wrong).
// The sum of the files' times grows faster than the shards past four (1454 s → 1903 → 2476):
// past that point they only share the same CPU. EW_SHARDS=n still forces it.
const AUTO_SHARDS = Math.max(1, Math.min(Math.floor(os.cpus().length / 3), Math.floor(os.freemem() / 2 ** 30 / 0.8) - 1));
const SHARDS = Math.max(1, Number(process.env.EW_SHARDS) || AUTO_SHARDS);
const SHARDS_WHY = process.env.EW_SHARDS ? `EW_SHARDS=${SHARDS}` : `${SHARDS} WordPress (one per 3 logical CPUs, within the free memory; EW_SHARDS=n to force)`;
const WPML_SHARDS = Math.max(1, Number(process.env.EW_WPML_SHARDS) || 1);
const only = process.argv.includes("--nivel") ? process.argv[process.argv.indexOf("--nivel") + 1] : null;
// Seconds per test FILE, per level (see the header). Over it, the file is red.
// Level 1 runs as one process (all its files at once) and is not timed per file.
// Measured INSIDE the whole battery (28-10-2026), where six WordPresses share the machine
// and a file takes ~1,4 times what it takes alone.
// Raised from 60/75 (28-10-2026, evening): across ten full runs of the SAME code the same file
// took between 1,3 and 2 times its fastest time (redirects/server 43-70 s, site/types 38-64 s,
// content-types/editor-panel 47-78 s), because this machine is shared (Defender, other sessions'
// WordPresses and browsers: the «machine:» line at the start says how busy it was). With 60/75,
// most runs had one file over by a few seconds, a different one each time: red that says nothing
// about the code. 90/100 is ~1,3 times the slowest time seen; what keeps the battery fast is the
// 5-minute TARGET, and a file that hangs is KILLED at twice its limit (runFile()).
// «browser»: per SCENARIO (ARMAZON: 100 s per browser scenario, measured on this plugin).
const LIMIT = { 2: 90, 3: 100, browser: 100 };
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
    .flatMap((p) => ["--mount-dir-before-install", path.join(WPML_DIR, p), `/wordpress/wp-content/plugins/${p}`]);

// The WordPresses: SHARDS normal ones and, with a WPML copy, WPML_SHARDS with WPML.
const shardPort = (base, n) => (n === 0 ? base : base + 16 + n);
const normal = Array.from({ length: SHARDS }, (_, n) => ({ name: `WordPress ${n + 1}/${SHARDS}`, port: shardPort(PORT, n), mounts: () => [], wpml: false, child: null }));
const withWpml = WPML_DIR
  ? Array.from({ length: WPML_SHARDS }, (_, n) => ({ name: `WordPress with WPML ${n + 1}/${WPML_SHARDS}`, port: shardPort(WPML_PORT, n), mounts: wpmlMounts, wpml: true, child: null }))
  : null;
const baseOf = (wp) => `http://127.0.0.1:${wp.port}`;

// The browser checks (browser-*.test.mjs): level 1, but they run AFTER the scenarios (header, «THE BROWSER»).
const CHECKS = new Set(checkFiles());
// All the tests, with their path relative to the root and a short name to pick them.
const all = [
  ...fs.readdirSync(path.join(ROOT, "tests")).filter((f) => f.endsWith(".test.mjs")).map((f) => path.posix.join("tests", f)),
  ...fs.readdirSync(path.join(ROOT, "modules")).flatMap((m) => {
    const dir = path.join(ROOT, "modules", m, "tests");
    return fs.existsSync(dir) ? fs.readdirSync(dir).filter((f) => f.endsWith(".test.mjs")).map((f) => path.posix.join("modules", m, "tests", f)) : [];
  }),
].filter((rel) => !CHECKS.has(rel)).sort();
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
// The browser scenarios picked like the files (a name, «module/», or all), unless --nivel says otherwise
// (they replace level 3: --nivel 3 runs them, --nivel 1 and 2 do not).
const scenarios = only && only !== "3"
  ? []
  : listScenarios().filter((s) => !picked.length || picked.some((p) => s.id.startsWith(p) || s.name.startsWith(p) || `${s.module}/${s.name}`.startsWith(p) || `${s.module}/` === p));
const scenarioKey = (s) => `browser:${s.id}`;
const scenarioName = (s) => `${s.module}/${s.id}`;
const needsWp = levels.some((l) => l !== "1") || scenarios.length > 0;
const wpmlWanted = levels.some((l) => l !== "1" && byLevel[l].some(needsWpml));

async function up(wp) {
  try {
    return (await fetch(baseOf(wp) + "/wp-json/")).ok;
  } catch {
    return false;
  }
}

// PINNED, NOT «latest» (28-10-2026, «A battery that gives the same result every run»). Measured
// causes of the boots that failed with `npx @wp-playground/cli@latest --wp=7.1`:
// - --wp=7.1 asks api.wordpress.org on EVERY boot which 7.1.x is the newest. The day 7.1.4 came
//   out, five shards found no 7.1.4.zip in the CLI's cache (~/.wordpress-playground) and the five
//   downloaded it at once into the SAME «7.1.4.zip.partial»: «ENOENT … rename '7.1.4.zip.partial'
//   -> '7.1.4.zip'» and that WordPress never booted. Now the version is a URL in
//   tests/blueprint.json (the blueprint's preferredVersions WIN over --wp in the CLI), the CLI
//   caches a URL as custom-<sha1 of the URL, 8 hex>.zip, and ensureWordPressZip() downloads it
//   ONCE, before any boot, with a rename of its own.
// - @latest made npx check the registry and, on a release day, install the new CLI from five
//   processes at once; the run used whatever came out that week. The CLI is now installed once
//   into tests/.cache/playground-cli-<version>/ (gitignored) and started with node directly: no
//   npx, no shell (also ~10 s less per boot).
// - Each CLI, when it starts, lstat()s EVERY entry of the system temp folder looking for stale
//   Playground folders of dead processes (5382 entries here on 28-10-2026). Another program
//   deleting one of them meanwhile gives the WARNING «Failed to find stale Playground temp dirs:
//   Error: ENOENT …», and this runner rejected the boot because the output contained «Error».
//   Now each shard gets its own TEMP (tempOf(), emptied before its boot) and a boot fails only
//   when Playground EXITS or does not say «Ready!» within BOOT_LIMIT.
// How to maintain: to move to a newer CLI or WordPress, change CLI_VERSION or the URL in
// tests/blueprint.json, run the whole battery once and commit both with that run's «Time:» line.
const CLI_VERSION = "3.1.61";
const CLI_DIR = path.join(ROOT, "tests", ".cache", `playground-cli-${CLI_VERSION}`);
const CLI_BIN = path.join(CLI_DIR, "node_modules", "@wp-playground", "cli", "wp-playground.js");
const BLUEPRINT = path.join(ROOT, "tests", "blueprint.json");
const WP_ZIP_URL = JSON.parse(fs.readFileSync(BLUEPRINT, "utf8")).preferredVersions.wp;
const BOOT_LIMIT = 300;
// Per shard, outside the repo: Playground's temp folder (the whole WordPress lives there) and the PHP error log.
const tempOf = (wp) => path.join(os.tmpdir(), "estrategia-web-tests", String(wp.port));
const phpLogOf = (wp) => path.join(tempOf(wp), "logs", "php-errors.log");

function ensureCli() {
  if (fs.existsSync(CLI_BIN)) return;
  console.log(`· installing @wp-playground/cli@${CLI_VERSION} into ${path.relative(ROOT, CLI_DIR)} (once)`);
  fs.mkdirSync(CLI_DIR, { recursive: true });
  const r = spawnSync("npm", ["install", "--prefix", CLI_DIR, "--no-save", "--no-audit", "--no-fund", `@wp-playground/cli@${CLI_VERSION}`], { shell: true, stdio: "inherit" });
  if (r.status !== 0 || !fs.existsSync(CLI_BIN)) throw new Error(`could not install @wp-playground/cli@${CLI_VERSION}`);
}

async function ensureWordPressZip() {
  if (!/^https:\/\//.test(WP_ZIP_URL)) return; // A plain version: the CLI resolves it (and races, see above).
  const sha = crypto.createHash("sha1").update(WP_ZIP_URL).digest("hex").slice(0, 8);
  const target = path.join(os.homedir(), ".wordpress-playground", `custom-${sha}.zip`);
  if (fs.existsSync(target)) return;
  console.log(`· downloading ${WP_ZIP_URL} into Playground's cache (once)`);
  const res = await fetch(WP_ZIP_URL);
  if (!res.ok) throw new Error(`${WP_ZIP_URL} -> ${res.status}`);
  const partial = `${target}.${process.pid}.partial`;
  fs.mkdirSync(path.dirname(target), { recursive: true });
  fs.writeFileSync(partial, Buffer.from(await res.arrayBuffer()));
  fs.renameSync(partial, target);
}

/** Starts the pinned CLI; resolves with the child once it says «Ready!» (rejects if it exits or takes over BOOT_LIMIT). */
function launch(name, args, temp, log) {
  fs.mkdirSync(temp, { recursive: true });
  const child = spawn(process.execPath, [CLI_BIN, "server", `--wp=${WP_ZIP_URL}`, "--php=8.3", `--workers=${WORKERS}`, ...args], {
    cwd: ROOT,
    stdio: ["ignore", "pipe", "pipe"],
    env: { ...process.env, TEMP: temp, TMP: temp, TMPDIR: temp },
  });
  const ready = new Promise((resolve, reject) => {
    let text = "";
    const timer = setTimeout(() => reject(new Error(`${name}: no «Ready!» after ${BOOT_LIMIT} s. See ${log}`)), BOOT_LIMIT * 1000);
    const onData = (d) => {
      text += d;
      fs.appendFileSync(log, d);
      if (text.includes("Ready!")) clearTimeout(timer), resolve();
    };
    child.stdout.on("data", onData);
    child.stderr.on("data", onData);
    child.on("exit", (code) => (clearTimeout(timer), reject(new Error(`${name}: Playground exited with ${code}\n${text}`))));
  });
  return { child, ready };
}
const kill = (child) => {
  if (process.platform === "win32") spawnSync("taskkill", ["/pid", String(child.pid), "/T", "/F"], { stdio: "ignore" });
  else child.kill();
};

// THE GOLDEN WORDPRESS (28-10-2026). Measured: a boot alone was ~35 s, and six at once 70-135 s,
// while level 1 takes 10: the boots were a third of the battery. Of the 35 s, ~23 are Playground
// unzipping WordPress INSIDE WebAssembly (3300 files through its virtual filesystem) and installing
// it; the rest, the blueprint. So WordPress is unzipped and installed ONCE per CLI and WordPress
// version (here, cached across runs) and each shard starts from a native copy of it (~3 s) with
// --wordpress-install-mode=install-from-existing-files-if-needed: six at once went from 70-80 s to
// ~40 s (scratch measurement, same machine, same hour). Each shard still gets its own fresh copy
// and its own database, so nothing a test writes survives to another shard or another run.
// How to maintain: nothing — the key changes with CLI_VERSION and the WordPress URL, and a stale
// golden-* folder is deleted when a new one is built. The trap: the golden is built WITHOUT the
// plugin and without the blueprint; anything plugin-specific belongs in the blueprint, which still
// runs on every boot. (Its home/siteurl rows name the golden's port; Playground defines WP_HOME and
// WP_SITEURL per shard, which is what WordPress reads.)
const GOLDEN_KEY = crypto.createHash("sha1").update(`${CLI_VERSION}|${WP_ZIP_URL}|8.3`).digest("hex").slice(0, 10);
const GOLDEN_ROOT = path.join(os.tmpdir(), "estrategia-web-tests");
const GOLDEN = path.join(GOLDEN_ROOT, `golden-${GOLDEN_KEY}`, "wordpress");
async function ensureGolden() {
  if (fs.existsSync(path.join(GOLDEN, "wp-config.php"))) return;
  const t = Date.now();
  const building = path.join(GOLDEN_ROOT, `golden-${GOLDEN_KEY}.${process.pid}.building`);
  fs.rmSync(building, { recursive: true, force: true });
  fs.mkdirSync(path.join(building, "wordpress"), { recursive: true });
  const log = path.join(ROOT, "tests", ".playground-golden.log");
  fs.writeFileSync(log, "");
  const { child, ready } = launch("golden WordPress", ["--mount-dir-before-install", path.join(building, "wordpress"), "/wordpress"], path.join(building, "tmp"), log);
  try {
    await ready;
  } finally {
    kill(child);
  }
  fs.rmSync(path.join(building, "tmp"), { recursive: true, force: true });
  try {
    fs.renameSync(building, path.dirname(GOLDEN));
  } catch {
    fs.rmSync(building, { recursive: true, force: true }); // Another run built it first: theirs is as good.
  }
  for (const old of fs.readdirSync(GOLDEN_ROOT)) {
    if (/^golden-/.test(old) && old !== `golden-${GOLDEN_KEY}`) fs.rmSync(path.join(GOLDEN_ROOT, old), { recursive: true, force: true });
  }
  console.log(`· built the golden WordPress (once per CLI and WordPress version) in ${((Date.now() - t) / 1000).toFixed(0)} s`);
}

// THE WPML SNAPSHOT (28-10-2026). With the golden WordPress the normal shards were ready at 33-45 s,
// and the WPML one at 100-136 s: every WPML request costs 4-5 s alone (2160 PHP files and WPML's
// own init, against 658 without it) and 13-17 s with the machine busy, and its boot is five of
// them (WPML's two setup passes, the /state fact, the login and String Translation's first
// screen, warmWpml()). It was the critical path of the whole battery. So a WPML WordPress that
// has finished all that is kept (wordpress/ after warmWpml(), database included) and each WPML
// shard starts from a copy of it, running only BLUEPRINT_SNAPSHOT (the month's uploads folder).
// What makes it safe: its key is a hash of EVERYTHING that could change that state — the golden's
// key, every .php/.json/.xml of this repo (the plugin's activation, the test mu-plugins and their
// WPML-on-SQLite fixes, the blueprint, setup-*.php) and the WPML copy's main files — so a change
// to any of them builds a new one (that run pays the old boot once). A test still gets a fresh
// copy and a database nobody else touched.
// How to maintain: if a WPML shard ever behaves differently from a fresh boot, delete
// %TEMP%/estrategia-web-tests/wpml-* and compare; an input that matters and is not hashed goes
// into snapshotKey(). The trap: the snapshot is made by KILLING Playground; it is taken only after
// warmWpml() has returned, i.e. after the last request that writes.
const BLUEPRINT_SNAPSHOT = path.join(ROOT, "tests", "blueprint-snapshot.json");
function snapshotKey() {
  const h = crypto.createHash("sha1").update(GOLDEN_KEY);
  const walk = (dir) => {
    for (const e of fs.readdirSync(dir, { withFileTypes: true }).sort((a, b) => a.name.localeCompare(b.name))) {
      const p = path.join(dir, e.name);
      if (e.isDirectory()) {
        if (![".git", ".cache", "fixtures", "node_modules"].includes(e.name)) walk(p);
      } else if (/\.(php|json|xml)$/.test(e.name) && !e.name.startsWith(".")) {
        h.update(path.relative(ROOT, p)).update(fs.readFileSync(p));
      }
    }
  };
  walk(ROOT);
  for (const f of ["sitepress-multilingual-cms/sitepress.php", "wpml-string-translation/plugin.php"]) {
    if (fs.existsSync(path.join(WPML_DIR, f))) h.update(f).update(fs.readFileSync(path.join(WPML_DIR, f)));
  }
  return h.digest("hex").slice(0, 10);
}
const snapshotOf = (key) => path.join(GOLDEN_ROOT, `wpml-${key}`, "wordpress");

async function startPlayground(wp) {
  if ((await up(wp)) || stopping) return;
  const log = path.join(ROOT, "tests", `.playground-${wp.port}.log`);
  fs.writeFileSync(log, "");
  const key = wp.wpml ? snapshotKey() : null;
  const snapshot = key && fs.existsSync(path.join(snapshotOf(key), "wp-config.php")) ? snapshotOf(key) : null;
  await fs.promises.rm(tempOf(wp), { recursive: true, force: true });
  await fs.promises.cp(snapshot ?? GOLDEN, path.join(tempOf(wp), "wordpress"), { recursive: true });
  fs.mkdirSync(path.dirname(phpLogOf(wp)), { recursive: true });
  // EW_TRACE=1: every request's ms and URL to logs/trace.log (tests/wp/estrategia-web-test-env.php).
  if (process.env.EW_TRACE === "1") fs.writeFileSync(path.join(path.dirname(phpLogOf(wp)), "trace.on"), "");
  const launched = launch(
    wp.name,
    [`--port=${wp.port}`,
      // This shard's own copy of the golden WordPress (above), installed already.
      "--mount-dir-before-install", path.join(tempOf(wp), "wordpress"), "/wordpress", "--wordpress-install-mode=install-from-existing-files-if-needed",
      // Every mount BEFORE the install check: Playground loads WordPress (wp-load.php, so the
      // mu-plugins) to see whether it is installed, and the WPML snapshot already has the test
      // mu-plugin loader, which requires files of the plugin («Failed opening required …
      // estrategia-web-test-env.php» and the boot died, 28-10-2026, when they were --mount-dir).
      "--mount-dir-before-install", ROOT, "/wordpress/wp-content/plugins/estrategia-web",
      // PHP's error log (tests/wp/estrategia-web-test-env.php points error_log here): read after a red file.
      "--mount-dir-before-install", path.dirname(phpLogOf(wp)), "/wordpress/wp-content/estrategia-web-test-logs",
      ...wp.mounts(),
      "--blueprint=" + (snapshot ? BLUEPRINT_SNAPSHOT : BLUEPRINT), "--login"],
    path.join(tempOf(wp), "tmp"),
    log
  );
  wp.child = launched.child;
  await launched.ready;
  // «Ready!» is not enough: it waits for the FACT the tests need, that the
  // routes of the test mu-plugins respond (and, with WPML, that it is set up).
  const fact = wp.wpml ? "/wp-json/estrategia-web-wpml-test/v1/state" : "/wp-json/estrategia-web-site-test/v1/ping";
  for (let i = 0; ; i++) {
    try {
      if ((await fetch(baseOf(wp) + fact)).ok) break;
    } catch {}
    if (i === 240) throw new Error(`${wp.name} started but ${fact} does not respond (did the blueprint fail?). See ${log}`);
    await new Promise((r) => setTimeout(r, 500));
  }
  if (!wp.wpml || snapshot) return;
  await warmWpml(wp);
  // Kept NOW, before this shard's first test (its files wait for wp.ready): no request is in flight
  // and SQLite closes the database at the end of each request; Playground's opcache and temp files
  // live outside wordpress/. A copy taken after the tests would carry what they wrote.
  const target = path.dirname(snapshotOf(key));
  const building = `${target}.${process.pid}.building`;
  try {
    await fs.promises.rm(building, { recursive: true, force: true });
    await fs.promises.cp(path.join(tempOf(wp), "wordpress"), path.join(building, "wordpress"), { recursive: true });
    fs.renameSync(building, target);
    for (const old of fs.readdirSync(GOLDEN_ROOT)) {
      if (/^wpml-[0-9a-f]+$/.test(old) && old !== path.basename(target)) await fs.promises.rm(path.join(GOLDEN_ROOT, old), { recursive: true, force: true });
    }
    console.log(`· kept the WPML snapshot ${path.basename(target)} (the next runs start their WPML WordPress from it)`);
  } catch {
    await fs.promises.rm(building, { recursive: true, force: true }); // Another run kept one first: theirs is as good.
  }
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
  kill(wp.child);
  wp.child = null;
}
const everyWp = () => [...normal, ...(withWpml ?? [])];
const stopAll = () => everyWp().forEach(stopPlayground);
process.on("SIGINT", () => {
  stopAll();
  process.exit(130);
});

if (process.argv.includes("--serve")) {
  ensureCli();
  await ensureWordPressZip();
  await ensureGolden();
  for (const wp of [normal[0], ...(withWpml ? [withWpml[0]] : [])]) {
    if (await up(wp)) console.log(`There is already a WordPress on ${baseOf(wp)}: left as it is.`);
    else await startPlayground(wp);
    console.log(`${baseOf(wp)}/  ${wp.name}`);
  }
  console.log("(Ctrl+C stops what this command started)");
  await new Promise(() => {});
}

/**
 * Runs one test file in its own process; resolves to { rel, ok, seconds, output, killed }.
 * HARD LIMIT (28-10-2026): a file still running at `hard` seconds is killed (its whole tree:
 * the browser too) and is red saying so. carousel/carousel once hung 1289 s and held its shard;
 * going over the limit made it red only AFTER it had finished.
 */
function runFile(rel, env, hard) {
  return runProcess(["--test", rel], env, hard).then((r) => ({ ...r, rel }));
}
/** Runs node with `args` (cwd ROOT); kills its whole tree at `hard` seconds. Resolves to { ok, seconds, output, killed }. */
function runProcess(args, env, hard) {
  return new Promise((resolve) => {
    const started = Date.now();
    const child = spawn(process.execPath, args, { cwd: ROOT, env: { ...process.env, ...env } });
    let output = "";
    let killed = false;
    const timer = setTimeout(() => {
      killed = true;
      if (process.platform === "win32") spawnSync("taskkill", ["/pid", String(child.pid), "/T", "/F"], { stdio: "ignore" });
      else child.kill("SIGKILL");
    }, hard * 1000);
    child.stdout.on("data", (d) => (output += d));
    child.stderr.on("data", (d) => (output += d));
    child.on("close", (code) => {
      clearTimeout(timer);
      if (killed) output += `\nKILLED by tests/run.mjs after ${hard} s (hard limit: twice its limit). It was hanging: the output above shows the last step it reached.\n`;
      resolve({ ok: code === 0 && !killed, seconds: (Date.now() - started) / 1000, output, killed });
    });
  });
}

// The logs read when a file goes red: PHP's (tests/wp/estrategia-web-test-env.php sends error_log
// there) and wp-content/debug.log, where WPML writes its own database errors.
const logsOf = (wp) => [phpLogOf(wp), path.join(tempOf(wp), "wordpress", "wp-content", "debug.log")];
const sizeOf = (file) => {
  try {
    return fs.statSync(file).size;
  } catch {
    return 0;
  }
};
const phpLogSize = (wp) => logsOf(wp).map(sizeOf);
/** What was logged on this shard since the sizes `from` (phpLogSize()), at most 4 KB per log. */
function phpErrorsSince(wp, from) {
  return logsOf(wp)
    .map((file, i) => {
      try {
        const text = fs.readFileSync(file).subarray(from[i]).toString("utf8").trim();
        return text ? `${file}:\n${text.length > 4096 ? text.slice(0, 4096) + "\n[…]" : text}` : "";
      } catch {
        return "";
      }
    })
    .filter(Boolean)
    .join("\n");
}

// Machine load (28-10-2026): other processes share this machine (Defender scanning, Docker, other
// sessions' node), and a slow run must be explainable. CPU busy % from os.cpus() between two samples.
const cpuSample = () => os.cpus().reduce((a, c) => ({ idle: a.idle + c.times.idle, total: a.total + Object.values(c.times).reduce((x, y) => x + y, 0) }), { idle: 0, total: 0 });
const busySince = (s) => {
  const n = cpuSample();
  return Math.round(100 * (1 - (n.idle - s.idle) / Math.max(1, n.total - s.total)));
};
const load = { samples: [], last: cpuSample() };
const loadTimer = setInterval(() => {
  load.samples.push(busySince(load.last));
  load.last = cpuSample();
}, 5000);
loadTimer.unref();
/** The top CPU users right now (WMI; class names are not localized). ~9 s: started in the background. */
function topProcesses() {
  return new Promise((resolve) => {
    if (process.platform !== "win32") return resolve("");
    const ps = spawn("powershell", ["-NoProfile", "-Command",
      `Get-CimInstance Win32_PerfFormattedData_PerfProc_Process | Where-Object { $_.Name -notin '_Total','Idle' } | Sort-Object PercentProcessorTime -Descending | Select-Object -First 5 | ForEach-Object { $_.Name + ' ' + [math]::Round($_.PercentProcessorTime / ${os.cpus().length}) + '%' }`],
      { stdio: ["ignore", "pipe", "ignore"] });
    let out = "";
    ps.stdout.on("data", (d) => (out += d));
    ps.on("close", () => resolve(out.trim().split(/\r?\n/).filter(Boolean).join(", ")));
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
  console.log(`${ok ? "✔" : "✖"} [${level}] ${nameOf(r.rel)} ${r.seconds.toFixed(1)} s${where}${why}${r.killed ? " — KILLED: it hung (see below)" : ""}`);
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
  if (wanted.length) {
    const s = cpuSample();
    await new Promise((r) => setTimeout(r, 1000));
    console.log(`· machine: ${os.cpus().length} logical CPUs, ${busySince(s)} % busy before starting, ${(os.freemem() / 2 ** 30).toFixed(1)} of ${(os.totalmem() / 2 ** 30).toFixed(1)} GB free; ${SHARDS_WHY}`);
    topProcesses().then((top) => top && console.log(`· top CPU users at the start: ${top}`));
    ensureCli();
    await ensureWordPressZip();
    await ensureGolden();
  }
  for (const wp of wanted) {
    wp.ready = startPlayground(wp).then(
      () => {
        booted.push((Date.now() - t0) / 1000);
        console.log(`· ${wp.port} ready at ${booted.at(-1).toFixed(0)} s`);
        return null;
      },
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
  if (only === "1") console.log(`\n· ${CHECKS.size} browser checks are not run with --nivel 1: they read what the scenarios save, and run after them (header, «THE BROWSER»)`);
  const later = [...levels.filter((l) => l !== "1").flatMap((l) => byLevel[l]), ...scenarios.map(scenarioKey)];
  const browserTimes = [];
  const ranScenarios = [];
  if (!failed && later.length) {
    // Levels 2 and 3 in ONE deal (no idle WordPress waiting at a barrier between them): the WPML
    // files go to the WPML WordPresses, the rest to the normal ones, and inside each pile the
    // level 2 files go first. «Stops at the first red level» becomes: once a level 2 file is
    // red, no level 3 file STARTS (one already running finishes).
    const t = Date.now();
    const wpmlFiles = withWpml ? later.filter((f) => !f.startsWith("browser:") && needsWpml(f)) : [];
    const plain = later.filter((f) => !wpmlFiles.includes(f));
    const piles = [
      ...deal(plain, normal.length).map((p, i) => ({ ...p, wp: normal[i] })),
      ...(wpmlFiles.length ? deal(wpmlFiles, withWpml.length).map((p, i) => ({ ...p, wp: withWpml[i] })) : []),
    ];
    console.log(`\n=== Levels 2 and 3, and the browser: ${later.length - scenarios.length} files and ${scenarios.length} browser scenarios in ${piles.length} WordPress at once`);
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
        for (const rel of [...mine].filter((f) => !f.startsWith("browser:")).sort((a, b) => levelOf(a) - levelOf(b))) {
          if (red2 && levelOf(rel) === 3) {
            notRun.push(rel);
            continue;
          }
          const logFrom = phpLogSize(wp);
          const r = await runFile(rel, { AS_BASE: process.env.AS_BASE ?? baseOf(wp), EW_CREDENTIALS }, 2 * (ownLimit(rel)?.seconds ?? LIMIT[levelOf(rel)]));
          if (!r.ok) {
            const php = phpErrorsSince(wp, logFrom);
            if (php) r.output += `\nPHP logged during this file:\n${php}\n`;
          }
          if (!verdict(levelOf(rel), r, ` (${wp.port})`)) {
            failed = true;
            if (levelOf(rel) === 2) red2 = true;
          }
        }
        // This shard's browser scenarios: ONE process, ONE Chromium, after its level 2 files.
        const mineScenarios = scenarios.filter((s) => mine.includes(scenarioKey(s)));
        if (!mineScenarios.length) return;
        if (red2) {
          notRun.push(...mineScenarios.map(scenarioKey));
          return;
        }
        const started = Date.now();
        const report = path.join(ROOT, "tests", ".cache", `browser-report-${wp.port}.json`);
        fs.mkdirSync(path.dirname(report), { recursive: true });
        fs.rmSync(report, { force: true });
        const r = await runProcess([path.join("tests", "browser.mjs"), "--base", process.env.AS_BASE ?? baseOf(wp), "--report", report, ...mineScenarios.map((s) => String(s.number))], {}, 2 * LIMIT.browser * mineScenarios.length);
        browserTimes.push((Date.now() - started) / 1000);
        let results = [];
        try {
          results = JSON.parse(fs.readFileSync(report, "utf8")).results;
        } catch {}
        for (const s of mineScenarios) {
          const one = results.find((x) => x.id === s.id);
          ranScenarios.push(s);
          measured[scenarioKey(s)] = Math.round((one?.seconds ?? 0) * 10) / 10;
          const slow = one && one.seconds > LIMIT.browser;
          const ok = one?.ok && !slow;
          if (!ok) failed = true;
          const why = !one ? ` — NO RESULT: the browser process ${r.killed ? "was KILLED (it hung)" : "ended without a report"}` : !one.ok ? ` — ${one.error}` : slow ? ` — OVER ITS LIMIT of ${LIMIT.browser} s per scenario: split it, or make it faster` : "";
          console.log(`${ok ? "✔" : "✖"} [browser] ${scenarioName(s)} ${(one?.seconds ?? 0).toFixed(1)} s (${wp.port})${why}`);
        }
        if (!results.length) console.log(r.output.replace(/^/gm, "    "));
      })
    );
    if (booted.length) times.push(`boot ${Math.min(...booted).toFixed(0)}-${Math.max(...booted).toFixed(0)} s`);
    times.push(`levels 2 and 3 with the browser ${((Date.now() - t) / 1000).toFixed(0)} s`);
    if (browserTimes.length) times.push(`browser ${Math.min(...browserTimes).toFixed(0)}-${Math.max(...browserTimes).toFixed(0)} s per shard (${browserTimes.length} Chromium)`);
    if (notRun.length) console.error(`\n✖ ${red2 ? "Level 2 in red: these level 3 files and browser scenarios were not run" : "Not run"}: ${notRun.map((f) => (f.startsWith("browser:") ? f : nameOf(f))).join(", ")}`);
  }
  // The checks of the scenarios that ran: level 1, ONE process, after every shard (header, «THE BROWSER»).
  const checks = [...new Set(ranScenarios.flatMap((s) => checksOf(s.id)))];
  if (checks.length) {
    const t = Date.now();
    console.log(`\n=== Browser checks: ${checks.length} files (level 1, reading ${BROWSER_OUT})`);
    const code = await new Promise((resolve) => {
      const child = spawn(process.execPath, ["--test", ...checks], { cwd: ROOT, stdio: "inherit" });
      child.on("close", (c) => resolve(c));
    });
    times.push(`checks ${((Date.now() - t) / 1000).toFixed(0)} s`);
    if (code !== 0) {
      console.error(`\n✖ Browser checks in red.`);
      failed = true;
    }
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
const cpu = load.samples.length ? ` · CPU ${Math.round(load.samples.reduce((a, b) => a + b, 0) / load.samples.length)} % avg, ${Math.max(...load.samples)} % max` : "";
console.log(`\nTime: ${total.toFixed(0)} s — ${times.join(" · ")}${cpu} · ${normal.length}+${withWpml?.length ?? 0} WordPress${WPML_DIR ? "" : " (no WPML copy: the WPML tests ran without it)"}`);
if (!only && !picked.length && total > TARGET) console.log(`⚠ Over the ${TARGET / 60} min target (header of tests/run.mjs): look at «Slowest files» first.`);
process.exit(failed ? 1 : 0);
