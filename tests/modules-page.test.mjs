// NIVEL: 2 — Playground WordPress, because it WRITES the estrategia_web_modules option and what is checked is what WordPress registers on the NEXT request (blocks, REST routes) and how a page with a block from a disabled module renders.
//
// The screen itself (the switches) is not tested here: it saves through
// options.php with the same option and the same sanitize() this test uses.
// Seen in red (14-10-2026): with Modules::saved() not writing _known, «saving
// records which modules existed» fails.
import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { BASE, reset, seedPage, testApi, html, wp } from "./lib.mjs";

// The modules that exist and are neither test modules nor Default: off with
// their own test (redirects, google-tags). A new module goes in here and, if
// it starts off, also in OFF.
// content-types: Default: on since 17-10-2026 (the site's types are its templates).
const ALL = ["carousel", "comments-off", "consent", "content-types", "external-links", "featured-images", "forms", "language-switcher", "map", "matomo", "site", "social", "uploads", "webp", "whatsapp", "wpml-front-page"];
// Those that start off (Default: off): with no saved option they are not loaded.
const OFF = ["comments-off"];
const ON = ALL.filter((s) => !OFF.includes(s));

before(reset);
after(() => testApi("/modules", { method: "POST", body: { active: null } }));

test("with no saved option the modules with Default: on are loaded, and only those", async () => {
  const m = await testApi("/modules");
  assert.equal(m.saved, null);
  assert.deepEqual([...m.loaded].sort(), ON);
  assert.equal((await wp("/block-types/estrategia-web/carousel")).status, 200);
  assert.equal((await wp("/block-types/armazon/carousel")).status, 200, "the old-name alias exists with the module on");
});

test("saving records which modules existed, and a disabled module registers nothing", async () => {
  await testApi("/modules", { method: "POST", body: { active: ALL.filter((s) => s !== "carousel" && s !== "forms").concat("no-existe") } });
  const m = await testApi("/modules");
  // Against what is on disk, not against ALL: a Default: off module (redirects) exists without being loaded.
  assert.deepEqual([...m.known].sort(), [...m.available].sort(), "saving records which modules existed");
  for (const slug of ALL) assert.ok(m.known.includes(slug), slug);
  assert.ok(!m.saved.includes("no-existe"), "a slug that does not exist is not saved");
  assert.ok(!m.loaded.includes("carousel") && !m.loaded.includes("forms"));

  assert.equal((await wp("/block-types/estrategia-web/carousel")).status, 404, "no block");
  assert.equal((await wp("/block-types/armazon/carousel")).status, 404, "nor its alias");
  assert.equal((await wp("/block-types/estrategia-web-forms/form")).status, 404);
  const token = await fetch(BASE + "/wp-json/estrategia-web-forms/v1/token");
  assert.equal(token.status, 404, "nor the module's REST routes");
  assert.equal((await wp("/block-types/estrategia-web/map")).status, 200, "the others remain");
});

test("a page with the block of a disabled module renders without it and without errors", async () => {
  const page = await seedPage(
    '<!-- wp:paragraph --><p>Before</p><!-- /wp:paragraph --><!-- wp:estrategia-web/carousel {"label":"Photos"} --><!-- wp:paragraph --><p>Slide</p><!-- /wp:paragraph --><!-- /wp:estrategia-web/carousel --><!-- wp:paragraph --><p>After</p><!-- /wp:paragraph -->',
    "module-off"
  );
  const r = await html(page.url);
  assert.equal(r.status, 200);
  assert.ok(r.text.includes("Before") && r.text.includes("After"));
  assert.ok(!r.text.includes("estrategia-web-carousel__track"), "the carousel is not rendered");
  assert.ok(!/Fatal error|Warning:|Notice:/.test(r.text), "no PHP errors on the page");
});

test("turning it on again brings it back as it was", async () => {
  await testApi("/modules", { method: "POST", body: { active: ALL } });
  assert.equal((await wp("/block-types/estrategia-web/carousel")).status, 200);
  assert.equal((await wp("/block-types/estrategia-web-forms/form")).status, 200);
});
