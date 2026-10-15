// NIVEL: 1 — no browser or emulator. The pure classes (markers, IP, token, preselection) with the machine's PHP, and one rule of the base CSS.
//
// If there is no `php` in the PATH, the test FAILS (it is not skipped): a test
// that is silently skipped looks like it covers something. On this machine it is in
// C:\wamp64\bin\php\php8.3.6.
import { test } from "node:test";
import assert from "node:assert/strict";
import { spawnSync } from "node:child_process";
import path from "node:path";
import fs from "node:fs";

const r = spawnSync("php", [path.join(import.meta.dirname, "php", "pure.php")], { encoding: "utf8" });
assert.equal(r.status, 0, "php did not run: " + (r.error?.message || r.stderr));
const out = JSON.parse(r.stdout);

test("markers: known, with spaces and uppercase, empty and unknown", () => {
  assert.equal(out.known, "Hola Ana <b>");
  assert.equal(out.spaces_case, "Hola Ana <b>");
  assert.equal(out.empty_known, "Tel: .", "an empty optional field is replaced by nothing");
  assert.equal(out.unknown_kept, "Mal: {{emial}}", "a typo in the template has to be visible in the email");
});

test("markers: escaped in HTML except those declared as already-built HTML", () => {
  assert.equal(out.escaped, "Ana &lt;b&gt;");
  assert.equal(out.raw_not_escaped, "<table>");
});

test("subject on a single line: no header injection", () => {
  assert.equal(out.single_line, "Asunto Bcc: x@y.z");
});

test("truncated IP (GDPR)", () => {
  assert.equal(out.ipv4, "203.0.113.0");
  assert.equal(out.ipv6, "2001:db8:85a3::");
  assert.equal(out.ip_bad, "");
});

test("token: good, too fast, expired, from another definition, tampered", () => {
  assert.equal(out.token_ok, "ok");
  assert.equal(out.token_fast, "too_fast");
  assert.equal(out.token_expired, "expired");
  assert.equal(out.token_other_def, "invalid");
  assert.equal(out.token_tampered, "invalid", "changing the token's time breaks the signature");
  assert.equal(out.token_garbage, "invalid");
});

test("preselection: by position, by exact text and by slug", () => {
  assert.equal(out.pre_position, "Lavandería para barcos y yates", "position starts at 1");
  assert.equal(out.pre_position_str, "Lavandería para barcos y yates", "the position also arrives as text (URL)");
  assert.equal(out.pre_text, "Lavandería para restaurantes");
  assert.equal(out.pre_slug, "Lavandería para restaurantes", "the slug avoids %20 and accents in links");
});

test("preselection: what is not among the options is never rendered", () => {
  assert.equal(out.pre_out_of_range, "", "position outside the list");
  assert.equal(out.pre_zero, "", "0 means «none»");
  assert.equal(out.pre_unknown, "", "a text that is not an option does not leak into the select");
  assert.equal(out.pre_empty_slug, "", "an empty slug matches nothing");
  assert.equal(out.pre_negative, "");
});

test("base style: the plugin does not remove the dropdown's native arrow (the custom chevron belongs to the theme)", () => {
  const css = fs.readFileSync(path.join(import.meta.dirname, "..", "assets", "style.css"), "utf8").replace(/\/\*[\s\S]*?\*\//g, "");
  assert.doesNotMatch(css, /appearance\s*:\s*none/, "with appearance:none in the plugin, a theme that paints its background with the «background» shorthand leaves the select as a plain box");
});

test("preselection: the first valid source wins; an invalid one yields to the next", () => {
  assert.equal(out.pre_first_wins, "Lavandería para hoteles");
  assert.equal(out.pre_falls_through, "Lavandería para barcos y yates", "a bad URL does not erase the page's value");
  assert.equal(out.pre_none, "");
});
