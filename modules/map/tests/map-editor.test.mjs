// NIVEL: 3 — browser. NOT LEVEL 2 BECAUSE: block registration in the editor (block.json, autoRegister and its translated labels), inserting a block and the server preview (ServerSideRender) only exist when the WordPress block editor runs.
/**
 * The map AND the WhatsApp blocks in the editor, with ONE login and ONE
 * editor boot (28-10-2026: each module booted the editor once just to see its
 * block registered; whatsapp-editor.test.mjs is merged here):
 * - estrategia-web/map exists with its Spanish title (the test site's
 *   locale), inserts valid and its server preview arrives;
 * - estrategia-web/whatsapp exists with its Spanish title, the automatic
 *   autoRegister controls carry the translated labels from labels.php, and
 *   it inserts valid.
 * Came from the parent theme's tests/editor.test.mjs (17-10-2026), which
 * checked the three blocks at once.
 * Careful: Playground's --login does NOT serve a new browser; the session
 * comes from adminSession() (wp-login.php without a browser, round 2,
 * 28-10-2026). No seed: the editor needs no page, only the modules.
 *
 * Round 2 (28-10-2026): ONE /setup request and the session from
 * adminSession(). Seen red again with the label expected in other words.
 *
 * How to maintain: another block module whose editor check is «registered,
 * translated, inserts valid» adds its step here instead of booting the
 * editor again.
 *
 * Seen red (28-10-2026) with the whatsapp «phone» label expected in other
 * words («autoRegister label untranslated»).
 */
import { test, before } from "node:test";
import assert from "node:assert/strict";
import { withBrowser, BASE_URL, BLOCK_MODULES } from "../../../tests/browser.mjs";
import { adminSession, testApi } from "../../../tests/lib.mjs";

let session;
before(async () => {
  // ONE request: the reset and the block modules (/setup, tests/wp/estrategia-web-site-test.php).
  await testApi("/setup", { method: "POST", body: { modules: BLOCK_MODULES } });
  // The login without a browser (one request): its cookies are handed to the
  // browser, which goes straight to the editor (no login page, no dashboard).
  session = await adminSession();
});

test("map and whatsapp in the editor: registered and translated, insert valid, the map's server preview arrives", () =>
  withBrowser(async ({ newPage }) => {
    const page = await newPage({ viewport: { width: 1400, height: 1000 } });
    await page.context().addCookies(
      session.cookie().split("; ").map((pair) => ({ name: pair.slice(0, pair.indexOf("=")), value: pair.slice(pair.indexOf("=") + 1), url: BASE_URL }))
    );
    await page.goto(`${BASE_URL}/wp-admin/post-new.php?post_type=page`, { waitUntil: "domcontentloaded" });
    await page.waitForFunction(() => window.wp?.blocks?.getBlockType?.("estrategia-web/map") && window.wp.blocks.getBlockType("estrategia-web/whatsapp"), null, { timeout: 60000 });

    const types = await page.evaluate(() => {
      const map = window.wp.blocks.getBlockType("estrategia-web/map");
      const whatsapp = window.wp.blocks.getBlockType("estrategia-web/whatsapp");
      return { map: map.title, whatsapp: whatsapp.title, label: whatsapp.attributes.phone?.label };
    });
    assert.equal(types.map, "Mapa (en dos clics)");
    assert.equal(types.whatsapp, "Botón de WhatsApp");
    assert.equal(types.label, "Teléfono en formato internacional (vacío: la opción del sitio)", "autoRegister label untranslated");

    const inserted = await page.evaluate(() => {
      const { createBlock } = window.wp.blocks;
      window.wp.data.dispatch("core/block-editor").insertBlocks([createBlock("estrategia-web/map", { address: "Calle Falsa 1" }), createBlock("estrategia-web/whatsapp", { phone: "34600111222" })]);
      return window.wp.data.select("core/block-editor").getBlocks().map((b) => [b.name, b.isValid, b.innerBlocks.length]);
    });
    assert.deepEqual(inserted.slice(-2), [
      ["estrategia-web/map", true, 0],
      ["estrategia-web/whatsapp", true, 0],
    ]);

    // The map's server preview (ServerSideRender) arrives.
    const editorFrame = page.frameLocator('iframe[name="editor-canvas"]');
    await editorFrame.getByText("Calle Falsa 1").first().waitFor({ timeout: 30000 });

    const errors = page.errors.filter((e) => !/favicon/.test(e));
    assert.deepEqual(errors, [], errors.join("\n"));
  })
);
