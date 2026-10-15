// NIVEL: 3 — navegador. NECESITA NAVEGADOR PORQUE: añadir, reordenar y quitar filas es JavaScript de la pantalla, y lo que se prueba es que el orden GUARDADO sea el que se ve (los name="…[n]…" se renumeran al enviar).
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

test("«Datos de la empresa» ya no tiene los campos fijos de redes: enlaza a «Redes sociales»", async () => {
  await p.goto(`${BASE}/wp-admin/admin.php?page=estrategia-web-site`);
  assert.equal(await p.locator('input[name^="estrategia_web_site[social]"]').count(), 0);
  assert.match(await p.locator('a[href*="page=estrategia-web-social"]').first().textContent(), /Redes sociales/);
});

test("pantalla «Redes sociales»: parte de las de la empresa (migradas), añade, sube, quita y guarda en el orden que se ve", async () => {
  await p.goto(`${BASE}/wp-admin/admin.php?page=estrategia-web-social`);
  assert.equal(await p.locator("h1").first().textContent(), "Redes sociales");
  assert.equal(await rows().count(), 2, "la de la empresa y la vacía");
  assert.equal(await rows().nth(0).locator('[data-field="url"]').inputValue(), "https://instagram.com/prueba");
  assert.match(await rows().nth(0).locator(".estrategia-web-social__preview").innerHTML(), /<svg/);

  // La fila vacía: un TikTok. Y otra nueva con «Añadir red»: un correo.
  await rows().nth(1).locator('[data-field="url"]').fill("https://www.tiktok.com/@prueba");
  assert.match(await rows().nth(1).locator(".estrategia-web-social__preview").innerHTML(), /<svg/, "la vista del icono se adivina al escribir");
  await p.click(".estrategia-web-social__add");
  assert.equal(await rows().count(), 3);
  await rows().nth(2).locator('[data-field="name"]').fill("Correo");
  await rows().nth(2).locator('[data-field="url"]').fill("hola@ejemplo.test");

  // El correo, arriba del todo; el TikTok, fuera.
  await rows().nth(2).locator(".is-up").click();
  await rows().nth(1).locator(".is-up").click();
  await rows().nth(2).locator(".is-remove").click();
  assert.equal(await rows().count(), 2);

  await Promise.all([p.waitForURL(/settings-updated/), p.click("#submit")]);
  const saved = (await wp("/settings")).json[OPTION];
  assert.deepEqual(saved.map((i) => [i.name, i.url]), [["Correo", "mailto:hola@ejemplo.test"], ["", "https://instagram.com/prueba"]]);
  assert.deepEqual(errors, [], "sin errores de JavaScript");
});

test("editor: el bloque «Redes sociales» se ve en el editor (vista previa del servidor), sin errores", async () => {
  await wp("/settings", { method: "POST", body: { [OPTION]: [{ name: "", url: "https://instagram.com/prueba" }] } });
  const page = await seedPage('<!-- wp:estrategia-web/social-links {"display":"icons-names"} /-->', "editor-redes");
  await p.goto(`${BASE}/wp-admin/post.php?post=${page.id}&action=edit`);
  await p.waitForFunction(() => window.wp?.data?.select("core/block-editor")?.getBlocks().length === 1, null, { timeout: 60000 });
  const canvas = p.frameLocator('iframe[name="editor-canvas"]');
  await canvas.locator(".estrategia-web-social-links__name", { hasText: "Instagram" }).waitFor({ timeout: 20000 });
  assert.equal(await p.evaluate(() => wp.blocks.getBlockType("estrategia-web/social-links")?.title), "Redes sociales");
  assert.deepEqual(errors, [], "sin errores de JavaScript en el editor");
});

test("quitar todas las redes y guardar deja la lista VACÍA (no vuelve a las de la empresa)", async () => {
  await p.goto(`${BASE}/wp-admin/admin.php?page=estrategia-web-social`);
  while ((await rows().count()) > 1) await rows().first().locator(".is-remove").click();
  await rows().first().locator(".is-remove").click(); // La última: queda una vacía nueva.
  assert.equal(await rows().count(), 1);
  await Promise.all([p.waitForURL(/settings-updated/), p.click("#submit")]);
  assert.deepEqual((await wp("/settings")).json[OPTION], []);
});
