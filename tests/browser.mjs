// NIVEL: BANCO DE TRABAJO — not a test. Shared helpers for the browser tests of the BLOCK modules (carousel, map, WhatsApp).
//
// Those tests lived in the parent theme's repo (armazon-wp-padre/tests/) up to
// 17-10-2026, with their own Playground, the theme and a seed of its own. They
// moved here with their bodies untouched: this file gives them the same
// vocabulary they had there (withBrowser, BASE_URL, assert) on top of the
// plugin's shared WordPress (tests/run.mjs, port 9404 or EW_PORT).
//
//   import { test, withBrowser, assert, BASE_URL, blockSite } from "../../../tests/browser.mjs";
//   blockSite("carousel");     // before(): only the block modules + this module's seed; after(): modules back to default
//   test("…", () => withBrowser(async ({ newPage }) => { … }));
//
// What blockSite() leaves, and why:
// - reset() first (tests/lib.mjs), like every test of the shared WordPress.
//   Not optional: the forms tests leave a marker that switches the site to
//   en_US until the next reset(), and these tests look for Spanish texts
//   («Ver mapa», «Diapositiva siguiente»).
// - Modules: ONLY carousel, map, whatsapp and language-switcher, the same set
//   the theme's test site had. Without consent on purpose (the map is tested
//   working without a cookie notice; with the notice it is tested in
//   modules/consent and in Lavandería), and without site, forms, webp or
//   featured-images, which would change the seeded images and pages. after()
//   deletes the list (all modules by default, as reset() leaves it).
// - Seed: POST /wp-json/estrategia-web-<module>-test/v1/seed, the route of
//   modules/<module>/tests/wp/estrategia-web-<module>-test.php. Idempotent:
//   each test file seeds again in its before() (another file's reset() may
//   have run in between) and the pages are rewritten, not duplicated.
// - Theme: Playground's default one (WP 7.1 pins it). The criterion, written
//   on 17-10-2026: a plugin block must work in ANY block theme, so it is
//   measured in a theme that is NOT ours; what a site's theme adds and a test
//   needs (the two-row grid of Lavandería's client strip, a post field to bind
//   the map to) is played by the module's test mu-plugin, as
//   consent-setup already does for the notice. A test theme of our own was
//   discarded: it would be a second parent theme to maintain, and switching
//   theme in a WordPress shared with every other module changes their
//   conditions too.
//
// How to maintain it:
// - A new browser test of a block module uses blockSite(<its module>) and
//   gets its pages from ITS module's seed route: a page belongs to one module.
// - A new module that needs to be on for these tests goes into BLOCK_MODULES
//   (and is then on for all of them: check they still pass).
// - The trap: the modules list is saved in one request and read in the NEXT
//   one (the modules are loaded when the plugin file loads, before
//   plugins_loaded). That is why the seed is a second
//   request after saving the list, never the same one.
import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { BASE, playwright, reset, testApi } from "./lib.mjs";

export const BASE_URL = BASE;
export const BLOCK_MODULES = ["carousel", "map", "whatsapp", "language-switcher"];
export { assert, test };

/** before(): only the block modules and the seed of `module`; after(): default modules. */
export function blockSite(module) {
  before(async () => {
    await reset();
    await testApi("/modules",{ method: "POST", body: { active: BLOCK_MODULES } });
    const res = await fetch(`${BASE}/wp-json/estrategia-web-${module}-test/v1/seed`, { method: "POST" });
    if (!res.ok) throw new Error(`seed ${module} -> ${res.status} ${await res.text()}`);
  });
  after(() => testApi("/modules", { method: "POST", body: { active: null } }));
}

/**
 * Runs fn({ browser, newPage }) and closes the browser whatever happens.
 * newPage() returns a page in a fresh context (no cookies) that records
 * console errors and page errors in page.errors.
 */
export async function withBrowser(fn) {
  const browser = await playwright().chromium.launch();
  try {
    await fn({
      browser,
      async newPage(options = {}) {
        const context = await browser.newContext(options);
        const page = await context.newPage();
        page.errors = [];
        page.on("console", (message) => {
          if (message.type() === "error") page.errors.push(`console: ${message.text()} @ ${message.location().url}`);
        });
        page.on("pageerror", (error) => page.errors.push(`pageerror: ${error.message}`));
        return page;
      },
    });
  } finally {
    await browser.close();
  }
}

/** Logs into wp-admin as Playground's default user (only exists in this ephemeral WordPress). */
export async function login(page) {
  await page.goto(`${BASE}/wp-login.php`);
  await page.fill("#user_login", "admin");
  await page.fill("#user_pass", "password");
  await Promise.all([page.waitForURL(/wp-admin/), page.click("#wp-submit")]);
}
