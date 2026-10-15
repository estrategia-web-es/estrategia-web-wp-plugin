// NIVEL: BANCO DE TRABAJO — not a test: it is the runner (Armazón, row 7).
//
//   node tests/run.mjs             1 → 2 → 3, stops at the first red level
//   node tests/run.mjs --nivel 1   only level 1 (the per-commit one: ~1 s)
//   node tests/run.mjs webp        level 1 and, of the rest, only those starting with «webp» (while developing)
//   node tests/run.mjs forms/      level 1 and, of the rest, those of the forms module
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
// Lavandería. (9402 was armazon-wp-formularios', archived.)
//
// The tests of ALL modules share one WordPress: each one leaves the database
// as it found it (reset() in its lib.mjs) or does not overwrite the others'.
// A module with its own test mu-plugin adds it to tests/blueprint.json.
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
// here so they inherit it.
const PORT = Number(process.env.EW_PORT || process.env.AS_PORT) || 9404;
const BASE = `http://127.0.0.1:${PORT}`;
process.env.AS_BASE ??= BASE;
const only = process.argv.includes("--nivel") ? process.argv[process.argv.indexOf("--nivel") + 1] : null;

// All the tests, with their path relative to the root and a short name to pick them.
const all = [
  ...fs.readdirSync(path.join(ROOT, "tests")).filter((f) => f.endsWith(".test.mjs")).map((f) => path.posix.join("tests", f)),
  ...fs.readdirSync(path.join(ROOT, "modules")).flatMap((m) => {
    const dir = path.join(ROOT, "modules", m, "tests");
    return fs.existsSync(dir) ? fs.readdirSync(dir).filter((f) => f.endsWith(".test.mjs")).map((f) => path.posix.join("modules", m, "tests", f)) : [];
  }),
].sort();
const nameOf = (rel) => (rel.startsWith("modules/") ? rel.split("/")[1] + "/" : "") + path.posix.basename(rel, ".test.mjs");

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
  (byLevel[m[1]] ??= []).push(f);
}

const levels = Object.keys(byLevel).sort().filter((l) => !only || l === only);
let server = null;

async function up() {
  try {
    return (await fetch(BASE + "/wp-json/")).ok;
  } catch {
    return false;
  }
}

async function startPlayground() {
  if (await up()) return;
  console.log(`· starting Playground on port ${PORT}…`);
  server = spawn(
    "npx",
    ["-y", "@wp-playground/cli@latest", "server", "--wp=7.1", "--php=8.3", `--port=${PORT}`,
      "--mount-dir", ROOT, "/wordpress/wp-content/plugins/estrategia-web",
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
    server.stdout.on("data", onData);
    server.stderr.on("data", onData);
    server.on("exit", (code) => reject(new Error("Playground exited with " + code + "\n" + log)));
  });
  // «Ready!» is not enough: it waits for the FACT the tests need, that the
  // routes of the test mu-plugins respond.
  for (let i = 0; i < 120; i++) {
    try {
      if ((await fetch(BASE + "/wp-json/estrategia-web-site-test/v1/ping")).ok) return;
    } catch {}
    await new Promise((r) => setTimeout(r, 500));
  }
  throw new Error("Playground started but the test routes do not respond (did the blueprint fail?). See tests/.playground.log");
}

function stopPlayground() {
  if (!server) return;
  if (process.platform === "win32") spawnSync("taskkill", ["/pid", String(server.pid), "/T", "/F"], { stdio: "ignore" });
  else server.kill();
}

let failed = false;
try {
  for (const level of levels) {
    if (level !== "1") await startPlayground();
    console.log(`\n=== Level ${level}: ${byLevel[level].map(nameOf).join(", ")}`);
    const r = spawnSync(process.execPath, ["--test", "--test-concurrency=1", ...byLevel[level]], { cwd: ROOT, stdio: "inherit" });
    if (r.status !== 0) {
      console.error(`\n✖ Level ${level} in red: the following ones are not run.`);
      failed = true;
      break;
    }
  }
} finally {
  stopPlayground();
}
process.exit(failed ? 1 : 0);
