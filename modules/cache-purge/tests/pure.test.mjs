// NIVEL: 1 — no browser or emulator. The cache-purge module (includes/class-purger.php) with the machine's PHP and stubs: which field changes purge, that a post is purged once, and that only the installed cache plugins are called.
//
// If there is no `php` on the PATH, the test FAILS (it is not skipped).
//
// What level 1 cannot see, and was checked on the real site instead: that
// SiteGround's purge actually empties the page (Gastronòmicament, 23-11-2026,
// curl without parameters after writing a field from WP-CLI).
//
// Seen red (23-11-2026): with who_counts() returning true for everyone (the
// visitor test), with flush() purging once per field instead of once per post
// (three SiteGround calls in the editor test) and with the URL built with a
// trailing slash (the URL assert).
import { test } from "node:test";
import assert from "node:assert/strict";
import { spawnSync } from "node:child_process";
import path from "node:path";

const php = (...args) => {
  const r = spawnSync("php", [path.join(import.meta.dirname, "php", "pure.php"), ...args], { encoding: "utf8" });
  assert.equal(r.status, 0, "php did not run: " + (r.error?.message || r.stderr || r.stdout));
  return JSON.parse(r.stdout);
};
const web = php();
const cli = php("cli");

test("a visitor's request never purges, even writing a field of a published post", () => {
  assert.deepEqual(web.visitor, []);
});

test("an editor's three field changes on one post purge it ONCE, with the installed plugins only", () => {
  assert.deepEqual(web.editor, [
    ["siteground", "https://example.test/ca/post-10"],
    ["wp-rocket", 10],
    ["action:estrategia_web_purge_post", 10, "https://example.test/ca/post-10"],
  ]);
});

test("SiteGround gets the URL exactly as get_permalink() gives it: no trailing slash", () => {
  assert.ok(!web.editor[0][1].endsWith("/"));
});

test("WordPress's own bookkeeping fields and drafts do not purge", () => {
  assert.deepEqual(web.bookkeeping, []);
  assert.deepEqual(web.draft, []);
});

test("from WP-CLI a change counts with nobody logged in; from the web it does not", () => {
  assert.deepEqual(web.nobody, []);
  assert.equal(cli.nobody.length, 3);
});
