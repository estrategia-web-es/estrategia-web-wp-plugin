// NIVEL: 3 — browser. NOT LEVEL 2 BECAUSE: adding, reordering and removing rows is the screen's JavaScript, and what is tested is that the SAVED order is the one on screen (the name="…[n]…" are renumbered on submit); the block's preview only exists in the running editor.
//
// Requests (28-10-2026): ONE login, which lands straight on the editor
// (wp-login.php?redirect_to=…, no dashboard in between); the «remove every
// network» case runs on the screen the first save reloads (it was another
// visit); «Company details has no fixed network fields and the menu has
// Social networks» is plain HTML and went to level 2 (social.test.mjs).
// Seen red (28-10-2026) with the screen's renumbering of name="…[n]…" on
// submit removed from the module's admin JavaScript (the saved order came
// out as typed, not as on screen), and with the expected block title at
// «Redes».
// Round 2 (28-10-2026): reset, company details and the editor's page in ONE request (core
// /setup). Seen red with migrate() returning early (the editor: no Instagram in the preview; the
// screen: «the company one and the empty one»).
import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { BASE, playwright, testApi } from "../../../tests/lib.mjs";

let browser, p, editorUrl;
const errors = [];

before(async () => {
  // ONE request (round 2, the core /setup route): reset (the list not saved), the company's
  // instagram (migrated to the list by the next request) and the editor's page.
  const { pages: [page] } = await testApi("/setup", {
    method: "POST",
    body: { company: { social: { instagram: "https://instagram.com/prueba" } }, pages: [{ slug: "editor-redes", content: '<!-- wp:estrategia-web/social-links {"display":"icons-names"} /-->' }] },
  });
  editorUrl = `${BASE}/wp-admin/post.php?post=${page.id}&action=edit`;
  browser = await playwright().chromium.launch();
  p = await (await browser.newContext()).newPage();
  p.on("pageerror", (e) => errors.push(e.message));
});
// reset() deletes the list and the company details: only the browser to close.
after(() => browser?.close());

const rows = () => p.locator(".estrategia-web-social__row");

test("editor: the “Social networks” block shows in the editor (server preview, with the migrated network), without errors", async () => {
  await p.goto(`${BASE}/wp-login.php?redirect_to=${encodeURIComponent(editorUrl)}`);
  await p.fill("#user_login", "admin");
  await p.fill("#user_pass", "password");
  await Promise.all([p.waitForURL(/post\.php/), p.click("#wp-submit")]);
  await p.waitForFunction(() => window.wp?.data?.select("core/block-editor")?.getBlocks().length === 1, null, { timeout: 60000 });
  const canvas = p.frameLocator('iframe[name="editor-canvas"]');
  await canvas.locator(".estrategia-web-social-links__name", { hasText: "Instagram" }).waitFor({ timeout: 20000 });
  assert.equal(await p.evaluate(() => wp.blocks.getBlockType("estrategia-web/social-links")?.title), "Redes sociales");
  assert.deepEqual(errors, [], "no JavaScript errors in the editor");
});

test("“Social networks” screen: starts from the company ones (migrated), adds, moves up, removes and saves in the order on screen; removing every network and saving leaves the list EMPTY", async () => {
  await p.goto(`${BASE}/wp-admin/admin.php?page=estrategia-web-social`);
  assert.equal(await p.locator("h1").first().textContent(), "Redes sociales");
  assert.equal(await rows().count(), 2, "the company one and the empty one");
  assert.equal(await rows().nth(0).locator('[data-field="url"]').inputValue(), "https://instagram.com/prueba");
  assert.match(await rows().nth(0).locator(".estrategia-web-social__preview").innerHTML(), /<svg/);

  // The empty row: a TikTok. And another new one with “Add network”: an email.
  await rows().nth(1).locator('[data-field="url"]').fill("https://www.tiktok.com/@prueba");
  assert.match(await rows().nth(1).locator(".estrategia-web-social__preview").innerHTML(), /<svg/, "the icon preview is guessed while typing");
  await p.click(".estrategia-web-social__add");
  assert.equal(await rows().count(), 3);
  await rows().nth(2).locator('[data-field="name"]').fill("Correo");
  await rows().nth(2).locator('[data-field="url"]').fill("hola@ejemplo.test");

  // The email, at the very top; the TikTok, out.
  await rows().nth(2).locator(".is-up").click();
  await rows().nth(1).locator(".is-up").click();
  await rows().nth(2).locator(".is-remove").click();
  assert.equal(await rows().count(), 2);

  await Promise.all([p.waitForURL(/settings-updated/), p.click("#submit")]);
  // The reloaded screen is drawn from the SAVED option (no REST round trip needed to read it).
  const shown = async () =>
    rows().evaluateAll((list) => list.map((r) => [r.querySelector("[data-field=name]").value, r.querySelector("[data-field=url]").value]));
  assert.deepEqual(await shown(), [["Correo", "mailto:hola@ejemplo.test"], ["", "https://instagram.com/prueba"], ["", ""]], "saved in the order on screen (and the new empty row)");

  // On the reloaded screen: every network out (the last one leaves a new empty row) and save.
  while ((await rows().count()) > 1) await rows().first().locator(".is-remove").click();
  await rows().first().locator(".is-remove").click();
  assert.equal(await rows().count(), 1);
  // The URL already says settings-updated: wait for the new load, not for the URL.
  await Promise.all([p.waitForNavigation(), p.click("#submit")]);
  assert.deepEqual(await shown(), [["", ""]], "an EMPTY list is saved: it does not go back to the company ones (they would be migrated and shown)");
  assert.deepEqual(errors, [], "no JavaScript errors");
});
