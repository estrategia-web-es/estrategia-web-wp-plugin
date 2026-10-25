// NIVEL: BANCO DE TRABAJO — not a test: it is the runner (Armazón, row 7).
//
//   node tests/run.mjs             1 → 2 → 3, stops at the first red level
//   node tests/run.mjs --nivel 1   only level 1 (the per-commit one: ~1 s)
//   node tests/run.mjs webp        level 1 and, of the rest, only those starting with «webp» (while developing)
//   node tests/run.mjs forms/      level 1 and, of the rest, those of the forms module
//   node tests/run.mjs --serve     only starts the test WordPress (and the WPML one, if there is a copy)
//                                  and keeps them up (Ctrl+C stops them): the next runs find them on
//                                  their ports and reuse them, one boot for many runs
//
// It discovers the tests, it does not list them: tests/*.test.mjs (the core
// ones) and modules/<slug>/tests/*.test.mjs (each module's). The level is
// given by the FIRST line of each one; a test with no declared level stops
// the whole run. A new module with tests does not touch this file.
//
// If level 2 or 3 is needed and there is no WordPress on port 9404, it STARTS
// Playground (WP 7.1, PHP 8.3, the plugin mounted as plugins/estrategia-web and
// tests/blueprint.json, which loads the test mu-plugin of each module that
// has one) and KILLS it at the end with taskkill /T /F: killing the parent
// does not kill the children on Windows. If one was already running, it
// leaves it as it was.
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
// - Who: whoever writes a test that needs real WPML. It adds «// WPML: REAL»
//   to its header and calls requireWpml() (tests/lib.mjs) first. Nothing else.
// - The criterion: «// WPML: REAL» only if what is tested is WPML's own
//   behaviour with the plugin (it reads the XML, it routes /en/, String
//   Translation translates). Anything that only needs the plugin to BELIEVE
//   there is WPML uses the double (?wpml-double, tests/wp/estrategia-web-site-test.php),
//   which runs in the normal WordPress. Edge case: wplang.test.mjs and
//   catalan.test.mjs test the locale the plugin picks per language — the
//   double, because it is the plugin's decision, not WPML's.
// - Without the copy, the WPML tests run against the normal WordPress and
//   FAIL with requireWpml()'s message (they are not skipped), the same rule
//   as «no php on the PATH»: a test that does not run looks as if it covered
//   something. Lavandería skipped two of its WPML tests and failed the rest;
//   here they all fail.
// - Why two WordPresses and not WPML in the only one (measured 25-10-2026,
//   with the WPML one up): every request costs about twice as much with WPML
//   on SQLite (the home page 4,1-4,8 s against 1,8-2,8 s; consent.test.mjs
//   51 s without WPML and ~2,2 times that with it). With WPML for every test
//   the whole suite would pay that for the 8 tests that need it; with two,
//   it pays one more boot (~1 min) and only when one of those is selected.
// - The trap: on SQLite WPML failed SILENTLY at three things (advisory locks,
//   an INSERT … LAST_INSERT_ID(), the String Translation collation); the
//   fixes, and how to find a new one, in tests/wp/estrategia-web-wpml-test.php.
//   And when WPML is updated on the server, the copy is brought again
//   (Lavandería's header): an old copy tests an old WPML.
//
// The tests of ALL modules share one WordPress (each of the two): each one
// leaves the database as it found it (reset() in its lib.mjs) or does not
// overwrite the others'. A module with its own test mu-plugin adds it to
// tests/blueprint.json.
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
// EW_PORT=9412 node tests/run.mjs … (or AS_PORT, the same) to run two
// batches at once, each with its own Playground. The tests read the base URL
// from AS_BASE (tests/lib.mjs and the modules' lib.mjs files), which is set
// here for each batch so they inherit it.
const PORT = Number(process.env.EW_PORT || process.env.AS_PORT) || 9404;
const WPML_PORT = Number(process.env.EW_WPML_PORT) || PORT + 10;
const only = process.argv.includes("--nivel") ? process.argv[process.argv.indexOf("--nivel") + 1] : null;

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

// The two WordPresses. Without a WPML copy, the WPML tests go to the normal one (and fail saying why).
const normal = { name: "WordPress", port: PORT, mounts: () => [], child: null };
const withWpml = WPML_DIR ? { name: `WordPress with WPML (${WPML_DIR})`, port: WPML_PORT, mounts: wpmlMounts, child: null } : normal;
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
const needsWpml = (rel) => /^\/\/ WPML: REAL\b/m.test(fs.readFileSync(path.join(ROOT, rel), "utf8"));

// Individual tests by name: `node tests/run.mjs external-links` runs the
// whole of level 1 and, of the rest, only those that match (by file name or
// by «module/»). It is the rule of row 7 («When the suite runs»): while
// developing, level 1 always plus the new test; the whole run, on demand or
// before publishing.
const picked = process.argv.slice(2).filter((a, i, list) => !a.startsWith("--") && list[i - 1] !== "--nivel");
const files = all.filter((rel) => {
  if (!picked.length) return true;
  if (fs.readFileSync(path.join(ROOT, rel), "utf8").startsWith("// NIVEL: 1")) return true;
  const short = nameOf(rel);
  const base = path.posix.basename(rel, ".test.mjs");
  return picked.some((p) => base.startsWith(p.replace(/\.test\.mjs$/, "")) || short.startsWith(p));
});
const byLevel = {};
for (const f of files) {
  const first = fs.readFileSync(path.join(ROOT, f), "utf8").split("\n")[0];
  const m = first.match(/^\/\/ NIVEL: (\d)\b/);
  if (!m) {
    console.error(`✖ ${f} does not declare its level on the first line («// NIVEL: n — …»). Nothing is run.`);
    process.exit(1);
  }
  if (m[1] === "1" && needsWpml(f)) {
    console.error(`✖ ${f} is level 1 and says «// WPML: REAL»: a level 1 test touches no WordPress. Nothing is run.`);
    process.exit(1);
  }
  (byLevel[m[1]] ??= []).push(f);
}

const levels = Object.keys(byLevel).sort().filter((l) => !only || l === only);

async function up(wp) {
  try {
    return (await fetch(baseOf(wp) + "/wp-json/")).ok;
  } catch {
    return false;
  }
}

async function startPlayground(wp) {
  if (await up(wp)) return;
  console.log(`· starting ${wp.name} on port ${wp.port}…`);
  wp.child = spawn(
    "npx",
    ["-y", "@wp-playground/cli@latest", "server", "--wp=7.1", "--php=8.3", `--port=${wp.port}`,
      "--mount-dir", ROOT, "/wordpress/wp-content/plugins/estrategia-web",
      ...wp.mounts(),
      "--blueprint=" + path.join(ROOT, "tests", "blueprint.json"), "--login"],
    { cwd: ROOT, shell: true, stdio: ["ignore", "pipe", "pipe"] }
  );
  await new Promise((resolve, reject) => {
    let log = "";
    const onData = (d) => {
      log += d;
      fs.appendFileSync(path.join(ROOT, "tests", ".playground.log"), d);
      if (log.includes("Ready!")) resolve();
      else if (/\bError\b/.test(log)) reject(new Error(log));
    };
    wp.child.stdout.on("data", onData);
    wp.child.stderr.on("data", onData);
    wp.child.on("exit", (code) => reject(new Error("Playground exited with " + code + "\n" + log)));
  });
  // «Ready!» is not enough: it waits for the FACT the tests need, that the
  // routes of the test mu-plugins respond (and, with WPML, that it is set up).
  const fact = wp === normal ? "/wp-json/estrategia-web-site-test/v1/ping" : "/wp-json/estrategia-web-wpml-test/v1/state";
  for (let i = 0; i < 120; i++) {
    try {
      if ((await fetch(baseOf(wp) + fact)).ok) return;
    } catch {}
    await new Promise((r) => setTimeout(r, 500));
  }
  throw new Error(`${wp.name} started but ${fact} does not respond (did the blueprint fail?). See tests/.playground.log`);
}

function stopPlayground(wp) {
  if (!wp.child) return;
  if (process.platform === "win32") spawnSync("taskkill", ["/pid", String(wp.child.pid), "/T", "/F"], { stdio: "ignore" });
  else wp.child.kill();
  wp.child = null;
}
const stopAll = () => [normal, withWpml].forEach(stopPlayground);

if (process.argv.includes("--serve")) {
  for (const wp of new Set([normal, withWpml])) {
    if (await up(wp)) console.log(`There is already a WordPress on ${baseOf(wp)}: left as it is.`);
    else await startPlayground(wp);
    console.log(`${baseOf(wp)}/  ${wp.name}`);
  }
  console.log("(Ctrl+C stops what this command started)");
  process.on("SIGINT", () => {
    stopAll();
    process.exit(0);
  });
  await new Promise(() => {});
}

let failed = false;
const t0 = Date.now();
const times = [];
try {
  for (const level of levels) {
    // Level 1 needs no WordPress; 2 and 3 split between the normal one and the WPML one.
    const batches = level === "1"
      ? [[normal, byLevel[level]]]
      : [[normal, byLevel[level].filter((f) => withWpml === normal || !needsWpml(f))], [withWpml, withWpml === normal ? [] : byLevel[level].filter(needsWpml)]];
    for (const [wp, list] of batches) {
      if (!list.length) continue;
      if (level !== "1") await startPlayground(wp);
      const where = level === "1" ? "" : ` (${baseOf(wp)}${wp === withWpml && wp !== normal ? ", WPML" : ""})`;
      console.log(`\n=== Level ${level}${where}: ${list.map(nameOf).join(", ")}`);
      const t = Date.now();
      const r = spawnSync(process.execPath, ["--test", "--test-concurrency=1", ...list], { cwd: ROOT, stdio: "inherit", env: { ...process.env, AS_BASE: process.env.AS_BASE ?? baseOf(wp) } });
      times.push(`level ${level}${wp === withWpml && wp !== normal && level !== "1" ? " WPML" : ""} ${((Date.now() - t) / 1000).toFixed(0)} s`);
      if (r.status !== 0) {
        console.error(`\n✖ Level ${level} in red: the following ones are not run.`);
        failed = true;
        break;
      }
    }
    if (failed) break;
  }
} finally {
  stopAll();
}
console.log(`\nTime: ${((Date.now() - t0) / 1000).toFixed(0)} s — ${times.join(" · ")}${WPML_DIR ? "" : " (no WPML copy: the WPML tests ran without it)"}`);
process.exit(failed ? 1 : 0);
