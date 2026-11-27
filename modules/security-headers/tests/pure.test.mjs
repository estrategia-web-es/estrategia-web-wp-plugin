// NIVEL: 1 — no browser or emulator. The security-headers module (module.php) with the machine's PHP and stubs: which headers it adds, that it never repeats one someone else sends, HSTS only over https, and the site's filter.
//
// If there is no `php` on the PATH, the test FAILS (it is not skipped).
//
// What level 1 cannot see, and is checked on the real site instead (curl -I
// on a page): that the headers actually go out, that a header the SERVER adds
// is not duplicated (invisible to PHP, see module.php) and that X-Powered-By
// is gone (some hostings add it after PHP).
//
// Seen red (26-11-2026): with the comparison case-sensitive (the «sg» test:
// nosniff twice), with headers_list() not read (the «queued» test) and with
// the https check removed (the «http» test).
import { test } from "node:test";
import assert from "node:assert/strict";
import { spawnSync } from "node:child_process";
import path from "node:path";

const r = spawnSync("php", [path.join(import.meta.dirname, "php", "pure.php")], { encoding: "utf8" });
const out = r.status === 0 ? JSON.parse(r.stdout) : null;
const ok = () => assert.ok(out, "php did not run: " + (r.error?.message || r.stderr || r.stdout));

test("nothing sent by anyone, over https: the five headers, after everyone else's filters", () => {
  ok();
  assert.equal(out.priority, 100);
  assert.ok(out.send_hook, "X-Powered-By is removed on send_headers");
  assert.deepEqual(out.empty, {
    "Content-Type": "text/html",
    "Strict-Transport-Security": "max-age=31536000",
    "X-Frame-Options": "SAMEORIGIN",
    "X-Content-Type-Options": "nosniff",
    "Referrer-Policy": "strict-origin-when-cross-origin",
    "Permissions-Policy": "camera=(), microphone=(), geolocation=()",
  });
  assert.ok(!/preload/.test(out.empty["Strict-Transport-Security"]), "no preload: the site owner's decision");
});

test("a header another plugin already sends is not repeated, whatever its case (SiteGround Security's nosniff)", () => {
  ok();
  assert.equal(Object.keys(out.sg).filter((k) => k.toLowerCase() === "x-content-type-options").length, 1);
  assert.equal(out.sg["X-CONTENT-TYPE-OPTIONS"], "nosniff");
  assert.equal(out.sg["X-Frame-Options"], "SAMEORIGIN");
});

test("a header already queued with header() is not added either", () => {
  ok();
  assert.ok(!("X-Frame-Options" in out.queued));
  assert.ok(!("Referrer-Policy" in out.queued));
  assert.ok("Permissions-Policy" in out.queued);
});

test("over http, no HSTS (a browser ignores it there, and it must never pin a site without https)", () => {
  ok();
  assert.ok(!("Strict-Transport-Security" in out.http));
  assert.equal(out.http["X-Frame-Options"], "SAMEORIGIN");
});

test("estrategia_web_security_headers: a site can leave a header out or change its value", () => {
  ok();
  assert.ok(!("Permissions-Policy" in out.filtered));
  assert.equal(out.filtered["X-Frame-Options"], "DENY");
});

test("WPML's generator meta tag (its version) is unhooked from wp_head", () => {
  ok();
  assert.deepEqual(out.wpml, [["wp_head", "meta_generator_tag"]]);
});
