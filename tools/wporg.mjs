// NIVEL: BANCO DE TRABAJO — not a test. The WordPress.org package and its Plugin Check.
//
//   node tools/wporg.mjs zip      dist/estrategia-web-<version>.zip from HEAD (what gets uploaded)
//   node tools/wporg.mjs check    the zip, then Plugin Check on it in Playground; exit 1 with errors
//
// WHAT GOES IN is decided by .gitattributes (export-ignore), not here: this
// packs HEAD with `git archive`, so uncommitted changes are NOT in the zip (it
// says so). Its header has the rule and the edge case.
//
// Before packing it checks that the three versions agree: «Version:» in
// estrategia-web.php, ESTRATEGIA_WEB_VERSION and «Stable tag:» in readme.txt,
// and that readme.txt has a «= <version> =» changelog entry. WordPress.org
// serves whatever Stable tag says, so a mismatch publishes the wrong thing.
//
// How to maintain — on each release to WordPress.org:
//   1. Version in the three places + CHANGELOG.md + readme.txt changelog, one commit.
//   2. node tools/wporg.mjs check   → 0 errors (warnings: read them, see below).
//   3. Upload: the first time, the zip at wordpress.org/plugins/developers/add;
//      after approval, SVN: trunk/ = the zip's contents, and tags/<version>/.
//   4. «Tested up to» in readme.txt follows the newest WordPress tested; it
//      can be bumped in SVN trunk without a new version.
// Warnings are not blocking, but the reviewers read them. Known and accepted
// (29-10-2026): hooks named wpml_* (WPML's, not ours), direct queries to our
// own tables, and the prefix of $wpdb->prefix-built table names.
//
// Pitfalls (each cost a run on 29-10-2026):
// - `wp plugin check` in a blueprint: Playground's CLI swallows its stdout.
//   Hence tools/wporg-check.php, which writes result.json to a mounted folder.
// - PHPCS inside Playground: file_put_contents(…, LOCK_EX) fails («Exclusive
//   locks are not supported for this stream») and it reads php://stdin, which
//   does not exist. The blueprint patches both in the throwaway Plugin Check
//   copy (PATCH below). If a Plugin Check update moves them, the error says where.
// - Runtime checks fail creating demo posts: static checks only (the runner).
// - The Playground CLI is the one tests/run.mjs pins (CLI_VERSION, read from
//   there so both move together).

import fs from "node:fs";
import path from "node:path";
import { spawnSync } from "node:child_process";
import { fileURLToPath } from "node:url";

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const DIST = path.join(ROOT, "dist");
const SLUG = "estrategia-web";
const MAX_MB = 10; // WordPress.org's upload limit.

const git = (...args) => {
  const r = spawnSync("git", ["-C", ROOT, ...args], { encoding: "utf8" });
  if (r.status !== 0) throw new Error(`git ${args.join(" ")}: ${r.stderr}`);
  return r.stdout;
};
const show = (file) => git("show", `HEAD:${file}`);

function versions() {
  const main = show(`${SLUG}.php`);
  const readme = show("readme.txt");
  const v = {
    header: (main.match(/^\s*\*\s*Version:\s*(\S+)/m) || [])[1],
    constant: (main.match(/ESTRATEGIA_WEB_VERSION\s*=\s*'([^']+)'/) || [])[1],
    stable: (readme.match(/^Stable tag:\s*(\S+)/m) || [])[1],
  };
  if (!v.header || v.header !== v.constant || v.header !== v.stable) {
    throw new Error(`versions disagree (committed): header ${v.header}, constant ${v.constant}, readme Stable tag ${v.stable}`);
  }
  if (!readme.includes(`\n= ${v.header} =\n`)) throw new Error(`readme.txt has no changelog entry «= ${v.header} =»`);
  return v.header;
}

function zip() {
  const version = versions();
  const dirty = git("status", "--porcelain", "--untracked-files=no").trim();
  if (dirty) console.log(`⚠ uncommitted changes are NOT in the zip (it packs HEAD):\n${dirty}`);
  fs.mkdirSync(DIST, { recursive: true });
  const file = path.join(DIST, `${SLUG}-${version}.zip`);
  git("archive", "--format=zip", `--prefix=${SLUG}/`, "-o", file, "HEAD");
  // Relative name and cwd: GNU tar reads «E:\…» as host «E» (remote archive).
  const t = spawnSync("tar", ["-tf", path.basename(file)], { cwd: DIST, encoding: "utf8" });
  const entries = (t.stdout || "").split("\n").filter((e) => e && !e.endsWith("/"));
  // An empty list would pass every check below: that is a failure, not a clean zip.
  if (t.status !== 0 || !entries.length) throw new Error(`could not list the zip: ${t.stderr}`);
  const dev = entries.filter((e) => /^estrategia-web\/(tests|tools|docs)\/|^estrategia-web\/modules\/[^/]+\/(tests\/|README\.md$)|^estrategia-web\/(README|CHANGELOG|TODO)\.md$/.test(e));
  if (dev.length) throw new Error(`development files in the zip (missing export-ignore in .gitattributes?):\n  ${dev.slice(0, 10).join("\n  ")}`);
  const mb = fs.statSync(file).size / 1024 / 1024;
  if (mb > MAX_MB) throw new Error(`${mb.toFixed(1)} MB: over WordPress.org's ${MAX_MB} MB`);
  const top = {};
  for (const e of entries) {
    const k = e.split("/").slice(1, 3).join("/");
    top[k] = (top[k] || 0) + 1;
  }
  console.log(`✔ ${path.relative(ROOT, file)} — ${version}, ${entries.length} files, ${mb.toFixed(2)} MB`);
  for (const [k, n] of Object.entries(top).sort()) console.log(`   ${String(n).padStart(4)}  ${k}`);
  return file;
}

// Plugin Check in Playground: the pinned CLI of tests/run.mjs.
const PATCH =
  "<?php $d = '/wordpress/wp-content/plugins/plugin-check/vendor/squizlabs/php_codesniffer/src';" +
  " foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d)) as $f) { if (substr($f, -4) !== '.php') continue;" +
  " $s = file_get_contents($f); $t = str_replace(array('FILE_APPEND | LOCK_EX', ', LOCK_EX'), array('FILE_APPEND', ', 0'), $s);" +
  " $t = str_replace(\"fopen('php://stdin', 'r')\", \"fopen('php://memory', 'r')\", $t); if ($t !== $s) file_put_contents($f, $t); }";

function check() {
  const file = zip();
  const runSrc = fs.readFileSync(path.join(ROOT, "tests", "run.mjs"), "utf8");
  const cliVersion = (runSrc.match(/const CLI_VERSION = "([^"]+)"/) || [])[1];
  if (!cliVersion) throw new Error("CLI_VERSION not found in tests/run.mjs");
  const cliDir = path.join(ROOT, "tests", ".cache", `playground-cli-${cliVersion}`);
  const cli = path.join(cliDir, "node_modules", "@wp-playground", "cli", "wp-playground.js");
  if (!fs.existsSync(cli)) {
    console.log(`· installing @wp-playground/cli@${cliVersion} (once, as tests/run.mjs does)`);
    const r = spawnSync("npm", ["install", "--prefix", cliDir, "--no-save", "--no-audit", "--no-fund", `@wp-playground/cli@${cliVersion}`], { shell: true, stdio: "inherit" });
    if (r.status !== 0) throw new Error("could not install the Playground CLI");
  }
  const work = path.join(DIST, ".check");
  fs.rmSync(work, { recursive: true, force: true });
  fs.mkdirSync(path.join(work, "out"), { recursive: true });
  const t = spawnSync("tar", ["-xf", path.join("..", path.basename(file))], { cwd: work, encoding: "utf8" });
  if (t.status !== 0) throw new Error(`could not extract the zip: ${t.stderr}`);
  fs.copyFileSync(path.join(ROOT, "tools", "wporg-check.php"), path.join(work, "out", "runner.php"));
  const wp = (show("readme.txt").match(/^Requires at least:\s*(\S+)/m) || [])[1];
  const blueprint = {
    preferredVersions: { php: "8.3", wp },
    steps: [
      { step: "installPlugin", pluginData: { resource: "wordpress.org/plugins", slug: "plugin-check" } },
      { step: "runPHP", code: `<?php require_once '/wordpress/wp-load.php'; require_once ABSPATH . 'wp-admin/includes/plugin.php'; activate_plugin('${SLUG}/${SLUG}.php');` },
      { step: "runPHP", code: PATCH },
      { step: "runPHP", code: "<?php require '/out/runner.php';" },
    ],
  };
  fs.writeFileSync(path.join(work, "blueprint.json"), JSON.stringify(blueprint, null, 2));
  console.log(`· Plugin Check (static) on WordPress ${wp} in Playground…`);
  const r = spawnSync(
    process.execPath,
    [cli, "run-blueprint", `--wp=${wp}`, `--blueprint=${path.join(work, "blueprint.json")}`,
      "--mount-dir", path.join(work, SLUG), `/wordpress/wp-content/plugins/${SLUG}`,
      "--mount-dir", path.join(work, "out"), "/out"],
    { encoding: "utf8", timeout: 600_000 }
  );
  const resultFile = path.join(work, "out", "result.json");
  if (r.status !== 0 || !fs.existsSync(resultFile)) throw new Error(`Playground failed:\n${(r.stdout || "") + (r.stderr || "")}`);
  const res = JSON.parse(fs.readFileSync(resultFile, "utf8"));
  if (res.exception) throw new Error(`Plugin Check threw: ${res.exception} (${res.at})`);
  let total = { errors: 0, warnings: 0 };
  for (const kind of ["errors", "warnings"]) {
    const byCode = {};
    for (const [f, lines] of Object.entries(res[kind] || {}))
      for (const [line, cols] of Object.entries(lines))
        for (const msgs of Object.values(cols))
          for (const m of msgs) {
            const g = (byCode[m.code] ||= { n: 0, where: [] });
            g.n++;
            g.where.push(`${f}:${line}`);
            total[kind]++;
          }
    console.log(`\n${kind}: ${total[kind]}`);
    for (const [code, g] of Object.entries(byCode).sort((a, b) => b[1].n - a[1].n)) {
      console.log(`  ${String(g.n).padStart(3)}  ${code}\n       ${g.where.slice(0, 4).join("  ")}${g.where.length > 4 ? "  …" : ""}`);
    }
  }
  console.log(`\nFull detail: ${path.relative(ROOT, resultFile)}`);
  process.exit(total.errors ? 1 : 0);
}

const cmd = process.argv[2];
try {
  if (cmd === "zip") zip();
  else if (cmd === "check") check();
  else {
    console.log("node tools/wporg.mjs zip | check");
    process.exit(1);
  }
} catch (e) {
  console.error(`✖ ${e.message}`);
  process.exit(1);
}
