// NIVEL: 3 — browser. NEEDS A BROWSER BECAUSE: the constructor only exists painted (assets/constructor.js), and what is tested is that a person can make a type THERE — «Restaurantes» with three fields — and that the type, its menu and its fields panel appear; plus that errors and confirmations land in the alert region with the focus.
//
// The site under test speaks es_ES. Controls are found by their data-*
// hooks (data-key, data-lang, data-action), which constructor.js sets on
// purpose so the test does not depend on the translated labels; that every
// control HAS a label is checked too.
//
// Seen red (17-10-2026): with the submit handler not calling save(), «create
// Restaurantes» never got the row; with alarm() not focusing the region,
// «an error goes to the alert region» failed on document.activeElement.
import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { BASE, playwright, reset, saveCompany, testApi, wp } from "../../../tests/lib.mjs";

const ct = async (path, { method = "GET", body } = {}) => {
  const res = await fetch(BASE + "/wp-json/estrategia-web-ct-test/v1" + path, { method, headers: body ? { "Content-Type": "application/json" } : {}, body: body ? JSON.stringify(body) : undefined });
  if (!res.ok) throw new Error(`${method} ${path} -> ${res.status} ${await res.text()}`);
  return res.json();
};

let browser, p;
const errors = [];

before(async () => {
  await reset();
  const { loaded } = await testApi("/modules");
  await testApi("/modules", { method: "POST", body: { active: [...new Set([...loaded, "content-types"])] } });
  await saveCompany({ enabled_types: [] });
  await ct("/constructor-reset", { method: "POST" });
  browser = await playwright().chromium.launch();
  p = await (await browser.newContext({ viewport: { width: 1400, height: 1000 } })).newPage();
  p.on("pageerror", (e) => errors.push(e.message));
  p.on("dialog", (d) => d.accept());
  await p.goto(BASE + "/wp-admin/");
  if (p.url().includes("wp-login.php")) {
    await p.fill("#user_login", "admin");
    await p.fill("#user_pass", "password");
    await Promise.all([p.waitForURL(/wp-admin/), p.click("#wp-submit")]);
  }
});
after(async () => {
  await browser?.close();
  await ct("/constructor-reset", { method: "POST" });
  await reset();
});

const screen = async () => {
  await p.goto(BASE + "/wp-admin/admin.php?page=estrategia-web-content-types");
  await p.locator('[data-action="add"]').waitFor({ timeout: 20000 });
};
const field = (i) => p.locator(`[data-field="${i}"]`);

test("an error goes to the alert region, which takes the focus; nothing is saved", async () => {
  await screen();
  await p.click('[data-action="add"]');
  await p.click('[data-action="save"]');
  const alert = p.locator('.ewct-alert[role="alert"]');
  await alert.waitFor({ state: "visible", timeout: 15000 });
  assert.match(await alert.textContent(), /prefix|prefijo/i);
  assert.equal(await p.evaluate(() => document.activeElement && document.activeElement.classList.contains("ewct-alert")), true, "focus on the alert");
  assert.equal((await ct("/constructor-raw?name=")).row, null);
});

test("create «Restaurantes» with three fields in the panel: the type, its menu and its fields panel appear", async () => {
  await screen();
  await p.click('[data-action="add"]');
  await p.fill('[data-key="plural"][data-lang="es"]', "Restaurantes");
  await p.fill('[data-key="singular"][data-lang="es"]', "Restaurante");
  await p.fill('[data-key="prefix"]', "gment_");
  assert.equal(await p.inputValue('[data-key="name"]'), "gment_restaurante", "the internal name is suggested");
  await p.fill('[data-key="slug"]', "restaurantes");

  await p.click('[data-action="add-field"]');
  await field(0).locator('[data-key="label"][data-lang="es"]').fill("Teléfono");
  await field(0).locator('[data-key="field-name"]').fill("gment_telefono");
  await field(0).locator('[data-key="field-type"]').selectOption("tel");

  await p.click('[data-action="add-field"]');
  await field(1).locator('[data-key="field-type"]').selectOption("select");
  await field(1).locator('[data-key="label"][data-lang="es"]').fill("Precio");
  await field(1).locator('[data-key="field-name"]').fill("gment_precio");
  await field(1).locator('[data-key="options"]').fill("barato | Barato\ncaro | Caro");

  await p.click('[data-action="add-field"]');
  await field(2).locator('[data-key="field-type"]').selectOption("boolean");
  await field(2).locator('[data-key="label"][data-lang="es"]').fill("Terraza");
  await field(2).locator('[data-key="field-name"]').fill("gment_terraza");

  // Every visible control has a label pointing at it.
  const unlabelled = await p.evaluate(() =>
    [...document.querySelectorAll("#estrategia-web-constructor input, #estrategia-web-constructor select, #estrategia-web-constructor textarea")]
      .filter((c) => c.type !== "hidden" && !(c.id && document.querySelector(`label[for="${c.id}"]`)))
      .map((c) => c.outerHTML.slice(0, 80))
  );
  assert.deepEqual(unlabelled, []);

  await p.click('[data-action="save"]');
  const row = p.locator('tr[data-type="gment_restaurante"]');
  await row.waitFor({ timeout: 20000 });
  assert.match(await row.textContent(), /Restaurantes/);

  const def = (await ct("/constructor-raw?name=gment_restaurante")).row.definition;
  assert.deepEqual(def.fields.map((f) => [f.name, f.type]), [["gment_telefono", "tel"], ["gment_precio", "select"], ["gment_terraza", "boolean"]]);
  assert.deepEqual(def.fields[1].options, [{ value: "barato", label: { es: "Barato" } }, { value: "caro", label: { es: "Caro" } }]);

  const type = await wp("/types/gment_restaurante");
  assert.equal(type.status, 200, "registered");
  assert.equal(type.json.name, "Restaurantes");
  await p.goto(BASE + "/wp-admin/");
  assert.equal(await p.locator('#adminmenu a[href="edit.php?post_type=gment_restaurante"]').count() > 0, true, "in the menu");

  await p.goto(BASE + "/wp-admin/post-new.php?post_type=gment_restaurante");
  await p.waitForFunction(() => window.wp?.data?.select("core/editor")?.getCurrentPostType(), null, { timeout: 60000 });
  await p.evaluate(() => window.wp.data.dispatch("core/preferences")?.set("core/edit-post", "welcomeGuide", false));
  await p.evaluate(() => window.wp.data.dispatch("core/edit-post")?.openGeneralSidebar?.("edit-post/document"));
  const panel = p.locator(".estrategia-web-content-types-panel");
  await panel.waitFor({ timeout: 20000 });
  assert.match(await panel.textContent(), /Restaurante: datos/);
  for (const key of ["gment_telefono", "gment_precio", "gment_terraza"]) {
    assert.equal(await panel.locator(`[data-meta-key="${key}"]`).count(), 1, key + " in the panel");
  }
});

test("a change that moves published things asks first: «Save anyway» saves it", async () => {
  await wp("/gment_restaurante", { method: "POST", body: { title: "Can Joan", status: "publish" } });
  await screen();
  await p.click('tr[data-type="gment_restaurante"] [data-action="edit"]');
  await p.locator('[data-field="2"] [data-action="remove"]').click();
  await p.click('[data-action="save"]');
  const confirm = p.locator('[data-action="confirm"]');
  await confirm.waitFor({ timeout: 15000 });
  assert.match(await p.locator(".ewct-alert").textContent(), /gment_terraza/);
  assert.equal((await ct("/constructor-raw?name=gment_restaurante")).row.definition.fields.length, 3, "not yet");
  await confirm.click();
  await p.locator('tr[data-type="gment_restaurante"]').waitFor({ timeout: 20000 });
  assert.equal((await ct("/constructor-raw?name=gment_restaurante")).row.definition.fields.length, 2);
});

test("a template is added from the screen", async () => {
  await screen();
  await p.click('[data-template="testimonial"]');
  await p.locator('tr[data-type="eweb_testimonial"]').waitFor({ timeout: 20000 });
  assert.equal(await p.locator('[data-template="testimonial"]').isDisabled(), true, "only once");
});

test("no JavaScript errors", () => {
  assert.deepEqual(errors, []);
});
