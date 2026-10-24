// NIVEL: 3 — browser. NEEDS A BROWSER BECAUSE: adding, reordering and removing rows is the screen's JavaScript, and what is tested is that the SAVED order is the one on screen (the name="…[n]…" are renumbered on submit).
import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { BASE, playwright, reset, wp, saveCompany, seedPage } from "../../../tests/lib.mjs";

const OPTION = "estrategia_web_social_links";
let browser, p;
const errors = [];

before(async () => {
  await reset();
  await wp("/settings", { method: "POST", body: { [OPTION]: null } });
  await saveCompany({ social: { instagram: "https://instagram.com/prueba" } });
  browser = await playwright().chromium.launch();
  p = await (await browser.newContext()).newPage();
  p.on("pageerror", (e) => errors.push(e.message));
  await p.goto(BASE + "/wp-admin/");
  if (p.url().includes("wp-login.php")) {
    await p.fill("#user_login", "admin");
    await p.fill("#user_pass", "password");
    await Promise.all([p.waitForURL(/wp-admin/), p.click("#wp-submit")]);
  }
});
after(async () => {
  await browser?.close();
  await wp("/settings", { method: "POST", body: { [OPTION]: null } });
});

const rows = () => p.locator(".estrategia-web-social__row");

test("“Company details” no longer has the fixed network fields: “Social networks” is in the Estrategia Web menu", async () => {
  await p.goto(`${BASE}/wp-admin/admin.php?page=estrategia-web-site`);
  assert.equal(await p.locator('input[name^="estrategia_web_site[social]"]').count(), 0);
  assert.match(await p.locator('#adminmenu a[href*="page=estrategia-web-social"]').first().textContent(), /Redes sociales/);
});

test("“Social networks” screen: starts from the company ones (migrated), adds, moves up, removes and saves in the order on screen", async () => {
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
  const saved = (await wp("/settings")).json[OPTION];
  assert.deepEqual(saved.map((i) => [i.name, i.url]), [["Correo", "mailto:hola@ejemplo.test"], ["", "https://instagram.com/prueba"]]);
  assert.deepEqual(errors, [], "no JavaScript errors");
});

test("editor: the “Social networks” block shows in the editor (server preview), without errors", async () => {
  await wp("/settings", { method: "POST", body: { [OPTION]: [{ name: "", url: "https://instagram.com/prueba" }] } });
  const page = await seedPage('<!-- wp:estrategia-web/social-links {"display":"icons-names"} /-->', "editor-redes");
  await p.goto(`${BASE}/wp-admin/post.php?post=${page.id}&action=edit`);
  await p.waitForFunction(() => window.wp?.data?.select("core/block-editor")?.getBlocks().length === 1, null, { timeout: 60000 });
  const canvas = p.frameLocator('iframe[name="editor-canvas"]');
  await canvas.locator(".estrategia-web-social-links__name", { hasText: "Instagram" }).waitFor({ timeout: 20000 });
  assert.equal(await p.evaluate(() => wp.blocks.getBlockType("estrategia-web/social-links")?.title), "Redes sociales");
  assert.deepEqual(errors, [], "no JavaScript errors in the editor");
});

test("removing every network and saving leaves the list EMPTY (does not go back to the company ones)", async () => {
  await p.goto(`${BASE}/wp-admin/admin.php?page=estrategia-web-social`);
  while ((await rows().count()) > 1) await rows().first().locator(".is-remove").click();
  await rows().first().locator(".is-remove").click(); // The last one: a new empty one remains.
  assert.equal(await rows().count(), 1);
  await Promise.all([p.waitForURL(/settings-updated/), p.click("#submit")]);
  assert.deepEqual((await wp("/settings")).json[OPTION], []);
});
