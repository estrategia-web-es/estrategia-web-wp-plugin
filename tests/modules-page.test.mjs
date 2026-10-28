// NIVEL: 2 — Playground WordPress. NOT LEVEL 1 BECAUSE: it WRITES the estrategia_web_modules option and what is checked is what WordPress registers on the NEXT request (blocks, REST routes) and how a page with a block from a disabled module renders; which modules are wanted for a saved list is level 1 (tests/modules.test.mjs).
//
// The screen itself (the switches) is not tested here: it saves through
// options.php with the same option and the same sanitize() this test uses.
// Seen in red (14-10-2026): with Modules::saved() not writing _known, «saving
// records which modules existed» fails.
// Seen red (28-10-2026) with carousel left out of the expected ON list, with
// the map expected missing after saving and with a text that is not on the
// page («AfterX») expected: each of the three tests fails.
//
// 28-10-2026: what is registered is read in ONE request (/registered: the
// block names and the REST namespaces) instead of one /wp/v2/block-types call
// per block, the page is seeded in the reset (/setup), and «turning it on
// again brings it back» is gone: it re-proved the first test (a module that is
// on registers its block), with the same code path.
import { test, before } from "node:test";
import assert from "node:assert/strict";
import { html, testApi } from "./lib.mjs";

// The modules that exist and are neither test modules nor Default: off with
// their own test (redirects, google-tags). A new module goes in here and, if
// it starts off, also in OFF.
// content-types: Default: on since 17-10-2026 (the site's types are its templates). local-business: on, 22-10-2026.
const ALL = ["carousel", "comments-off", "consent", "content-types", "external-links", "featured-images", "forms", "language-switcher", "local-business", "map", "matomo", "site", "social", "uploads", "webp", "whatsapp"];
// Those that start off (Default: off): with no saved option they are not loaded.
const OFF = ["comments-off"];
const ON = ALL.filter((s) => !OFF.includes(s));

let page;
before(async () => {
  ({
    pages: [page],
  } = await testApi("/setup", {
    method: "POST",
    body: {
      pages: [
        {
          slug: "module-off",
          content:
            '<!-- wp:paragraph --><p>Before</p><!-- /wp:paragraph --><!-- wp:estrategia-web/carousel {"label":"Photos"} --><!-- wp:paragraph --><p>Slide</p><!-- /wp:paragraph --><!-- /wp:estrategia-web/carousel --><!-- wp:paragraph --><p>After</p><!-- /wp:paragraph -->',
        },
      ],
    },
  }));
});

test("with no saved option the modules with Default: on are loaded, and only those", async () => {
  const m = await testApi("/registered");
  assert.equal(m.saved, null);
  assert.deepEqual([...m.loaded].sort(), ON);
  assert.ok(m.blocks.includes("estrategia-web/carousel"), "the carousel block");
  assert.ok(m.blocks.includes("armazon/carousel"), "the old-name alias exists with the module on");
  assert.ok(m.namespaces.includes("estrategia-web-forms/v1"), "the forms' REST routes");
});

let off;
test("saving records which modules existed, and a disabled module registers nothing", async () => {
  await testApi("/modules", { method: "POST", body: { active: ALL.filter((s) => s !== "carousel" && s !== "forms").concat("no-existe") } });
  let m;
  [m, off] = await Promise.all([testApi("/registered"), html(page.url)]);
  // Against what is on disk, not against ALL: a Default: off module (redirects) exists without being loaded.
  assert.deepEqual([...m.known].sort(), [...m.available].sort(), "saving records which modules existed");
  for (const slug of ALL) assert.ok(m.known.includes(slug), slug);
  assert.ok(!m.saved.includes("no-existe"), "a slug that does not exist is not saved");
  assert.ok(!m.loaded.includes("carousel") && !m.loaded.includes("forms"));

  assert.ok(!m.blocks.includes("estrategia-web/carousel"), "no block");
  assert.ok(!m.blocks.includes("armazon/carousel"), "nor its alias");
  assert.ok(!m.blocks.includes("estrategia-web-forms/form"));
  assert.ok(!m.namespaces.includes("estrategia-web-forms/v1"), "nor the module's REST routes");
  assert.ok(m.blocks.includes("estrategia-web/map"), "the others remain");
});

test("a page with the block of a disabled module renders without it and without errors", () => {
  assert.ok(off, "the page was read after disabling the module (previous test)");
  assert.equal(off.status, 200);
  assert.ok(off.text.includes("Before") && off.text.includes("After"));
  assert.ok(!off.text.includes("estrategia-web-carousel__track"), "the carousel is not rendered");
  assert.ok(!/Fatal error|Warning:|Notice:/.test(off.text), "no PHP errors on the page");
});
