// NIVEL: 1 — no browser or emulator. The pure classes (tel/mailto/wa.me links, SVG sanitizing and which link is external) with the machine's PHP.
//
// If there is no `php` in the PATH, the test FAILS (it is not skipped): a test
// that is silently skipped looks like it covers something. On this machine it is in
// C:\wamp64\bin\php\php8.3.6.
import { test } from "node:test";
import assert from "node:assert/strict";
import { spawnSync } from "node:child_process";
import path from "node:path";

const r = spawnSync("php", [path.join(import.meta.dirname, "php", "pure.php")], { encoding: "utf8" });
assert.equal(r.status, 0, "php did not run: " + (r.error?.message || r.stderr));
const out = JSON.parse(r.stdout);

test("tel: with default prefix, with + and with 00; empty if there is no number", () => {
  assert.equal(out.tel_local, "tel:+34971123456");
  assert.equal(out.tel_plus, "tel:+442079460000");
  assert.equal(out.tel_00, "tel:+442079460000");
  assert.equal(out.tel_empty, "");
});

test("mailto and wa.me: encoded message, number with digits only", () => {
  assert.equal(out.mailto, "mailto:hola@ejemplo.test");
  assert.equal(out.wa, "https://wa.me/34600111222?text=Hola%2C%20quer%C3%ADa%20informaci%C3%B3n");
  assert.equal(out.wa_local, "https://wa.me/34600111222");
  assert.equal(out.wa_empty, "", "no number means no link, even with a message");
});

test("hostile SVG: script, on*, foreignObject, javascript:, external links, @import and comments are removed", () => {
  const svg = out.clean;
  assert.ok(svg, "an SVG with bad things is cleaned, not rejected");
  for (const bad of ["<script", "onload", "onclick", "foreignObject", "javascript", "evil.test", "@import", "behavior", "sodipodi:namedview", "<a ", "<!--", "xml-stylesheet"]) {
    assert.ok(!svg.includes(bad), `«${bad}» remains in:\n${svg}`);
  }
});

test("hostile SVG: the legitimate stays (gradient with uppercase, url(#id), internal xlink:href, embedded image, text)", () => {
  const svg = out.clean;
  assert.match(svg, /<linearGradient id="grad">/);
  assert.match(svg, /<path d="M0 0h10" fill="url\(#grad\)"\/>/);
  assert.match(svg, /\.b\{fill:url\(#grad\);background:none\}/);
  assert.match(svg, /<use xlink:href="#grad"\/>/);
  assert.match(svg, /href="data:image\/png;base64,/);
  assert.match(svg, /Logo &amp; Co/);
  assert.match(svg, /viewBox="0 0 120 60"/);
});

test("SVG that is not accepted: entities (XXE), root that is not svg, broken XML", () => {
  assert.equal(out.xxe, null);
  assert.equal(out.not_svg, null);
  assert.equal(out.not_xml, null);
});

test("external links (0.5.2): another host yes; the same one, www, subdomains, relative, anchors and other schemes no", () => {
  const ext = Object.entries(out.external).filter(([, v]) => v).map(([k]) => k);
  assert.deepEqual(ext, [
    "https://wa.me/34600111222",
    "https://api.whatsapp.com/send?phone=34600111222",
    "http://maps.google.com/?q=x",
    "//facebook.com/x",
    "HTTPS://WEB-VIEJA.TEST/",
    "https://otroweb.test/",
    "https://web.test.evil.test/",
  ]);
});

test("rel (0.5.2): noopener is added without removing what was there or repeating it", () => {
  assert.equal(out.rel_empty, "noopener");
  assert.equal(out.rel_keep, "nofollow sponsored noopener");
  assert.equal(out.rel_dup, "NoOpener nofollow");
});

test("dimensions: width/height, viewBox, a single side with aspect ratio, percentages, nothing", () => {
  assert.deepEqual(out.dims_attrs, [120, 60]);
  assert.deepEqual(out.dims_viewbox, [301, 150]);
  assert.deepEqual(out.dims_one_side, [200, 100]);
  assert.deepEqual(out.dims_percent, [40, 20]);
  assert.deepEqual(out.dims_none, [0, 0]);
});

test("WebP quality (0.5.4): 75 only for image/webp; the filter changes it, 0 keeps WordPress's, capped at 100", () => {
  assert.deepEqual(out.webp_quality, { webp: 75, jpeg: 82, png: 90, filter: 60, zero: 82, cap: 100 });
});
