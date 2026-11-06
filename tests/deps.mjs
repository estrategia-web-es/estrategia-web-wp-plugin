// NIVEL: BANCO DE TRABAJO — not a test: the tests' own npm packages, on any machine.
//
// Playwright and jsdom used to come from E:/Repositories/portal-hs, a folder
// only David's PC has: on anybody else's machine the level 2 tests and the
// browser ones could not start. Now they live in tests/.cache/ (gitignored),
// installed once with the versions below, and the repo still has no
// package.json (the same trick tests/run.mjs uses for the Playground CLI).
//
// What the first run does, and it needs Internet: npm installs both packages
// and Playwright downloads its Chromium to its shared cache
// (~/AppData/Local/ms-playwright on Windows), about 180 MB. The next runs do
// nothing: the «.ready» file says it is done.
//
// How to maintain it:
// - Who and when: whoever needs a newer Playwright or jsdom changes VERSIONS
//   and runs the whole battery once. A new version is a new folder, so it
//   installs itself on every machine at its next run; the old folder can be
//   deleted by hand.
// - The criterion: only what a test imports and this repo does not ship. A
//   package for the plugin itself does not go here.
// - Edge case: tests/run.mjs calls ensureTestDeps() BEFORE starting the
//   parallel files, so that two of them never install at once. Launching
//   tests/browser.mjs by hand installs on its first load(), which is fine
//   because it is alone.
// - The trap: `npm --prefix <folder>` goes through a shell on Windows and a
//   space in the path («C:/Users/Juan Pérez/…») splits the argument. That is
//   why npm runs with cwd and --prefix «.». Same in ensureCli() of tests/run.mjs.
// - The trap that hangs: Playwright 1.59.1 on Node 26 downloads Chromium and then
//   stays unpacking it FOREVER, with no error (measured 05-11-2026: 1 h 26 min
//   stopped; the same on Node 24, 18 s). And `winget install OpenJS.NodeJS.LTS`
//   gives Node 26. So the install refuses a Node above MAX_NODE at once. When
//   VERSIONS moves to a Playwright that works there, raise MAX_NODE.
// - The copy of this file in the parent theme's repo (armazon-wp-padre) is
//   kept with the same VERSIONS.
//
// PHP (ensurePhp): the level 1 tests call the `php` of the PATH, and a PHP just
// installed on Windows (winget, the official zip) has NO php.ini, so mbstring
// is off and those tests die with «Call to undefined function mb_strlen()»,
// which reads like a bug of the plugin. tests/run.mjs calls ensurePhp() first:
// it asks PHP which extensions of PHP_EXTENSIONS are missing and, if the DLL is
// in its ext folder, turns them on through PHP_INI_SCAN_DIR, an ini of ours
// that the test processes inherit. Nobody edits a php.ini, and a PHP that
// already has them (WAMP, David's) is left alone.
// - Who and when: a test that starts needing another extension adds it to
//   PHP_EXTENSIONS (the name of the DLL without php_ and .dll).
// - Edge case: running ONE test by hand without the runner does not get the
//   ini; use `node tests/run.mjs <name>`.
// - The trap: winget's php is a symlink in WinGet/Links, with no ext folder
//   beside it, hence the realpath. And no php on the PATH is not handled here:
//   those tests fail by themselves, which is the rule (tests/run.mjs, «WPML»).
import { spawnSync } from "node:child_process";
import fs from "node:fs";
import { createRequire } from "node:module";
import path from "node:path";

const VERSIONS = { playwright: "1.59.1", jsdom: "29.1.0" };
const MAX_NODE = 24;

const DIR = path.join(import.meta.dirname, ".cache", `deps-playwright-${VERSIONS.playwright}-jsdom-${VERSIONS.jsdom}`);
const READY = path.join(DIR, ".ready");

export function ensureTestDeps() {
  if (fs.existsSync(READY)) return;
  if (parseInt(process.versions.node, 10) > MAX_NODE) throw new Error(`Node ${process.versions.node}: Playwright ${VERSIONS.playwright} hangs installing Chromium above Node ${MAX_NODE}. Use Node 22 (winget install OpenJS.NodeJS.22). Header of tests/deps.mjs.`);
  console.log(`· installing ${Object.entries(VERSIONS).map(([n, v]) => `${n}@${v}`).join(" and ")} into tests/.cache, and Chromium (once)`);
  fs.mkdirSync(DIR, { recursive: true });
  const specs = Object.entries(VERSIONS).map(([name, version]) => `${name}@${version}`);
  const npm = spawnSync("npm", ["install", "--prefix", ".", "--no-save", "--no-audit", "--no-fund", ...specs], { cwd: DIR, shell: true, stdio: "inherit" });
  if (npm.status !== 0) throw new Error(`could not install ${specs.join(" ")}`);
  const browser = spawnSync(process.execPath, [path.join(DIR, "node_modules", "playwright", "cli.js"), "install", "chromium"], { stdio: "inherit" });
  if (browser.status !== 0) throw new Error("could not download Playwright's Chromium");
  fs.writeFileSync(READY, "");
}

const PHP_EXTENSIONS = ["mbstring"];

export function ensurePhp() {
  const ask = spawnSync("php", ["-r", `echo PHP_BINARY, "\n", implode(",", array_filter(${JSON.stringify(PHP_EXTENSIONS).replaceAll('"', "'")}, fn($e) => !extension_loaded($e)));`], { encoding: "utf8" });
  if (ask.status !== 0) return;
  const [binary, missing = ""] = ask.stdout.trim().split("\n");
  if (!missing.trim()) return;
  const ext = path.join(path.dirname(fs.realpathSync(binary)), "ext");
  const found = missing.trim().split(",").filter((name) => fs.existsSync(path.join(ext, `php_${name}.dll`)));
  if (!found.length) return;
  const dir = path.join(import.meta.dirname, ".cache", "php-ini");
  fs.mkdirSync(dir, { recursive: true });
  fs.writeFileSync(path.join(dir, "tests.ini"), [`extension_dir="${ext.replaceAll("\\", "/")}"`, ...found.map((name) => `extension=${name}`), ""].join("\n"));
  process.env.PHP_INI_SCAN_DIR = dir;
  console.log(`· PHP without ${found.join(", ")}: on for these tests (tests/.cache/php-ini)`);
}

export function load(name) {
  ensureTestDeps();
  return createRequire(path.join(DIR, "package.json"))(name);
}
