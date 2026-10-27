// NIVEL: 3 — browser. NEEDS A BROWSER BECAUSE: the edit screen only exists painted (assets/constructor.js), and what is tested is that a person can make a type THERE — «Restaurantes» with three fields — with the tabs, the collapsed rows that open on click, reordering by keyboard (Alt + arrow, announced) and by dragging, the field groups, and that the type, its menu and its fields panel appear; plus that errors and confirmations land in the alert region with the focus, and the «Add from template» menu.
//
// The site under test speaks es_ES. Controls are found by their data-*
// hooks (data-key, data-lang, data-action, data-tab), which constructor.js
// and class-page.php set on purpose so the test does not depend on the
// translated labels; that every control HAS a label is checked too. The
// list's HTML itself (and save with the page's nonce) is level 2:
// constructor-screens.test.mjs.
//
// Seen red (17-10-2026): with the submit handler not calling save(), «create
// Restaurantes» never got the row; with alarm() not focusing the region,
// «an error goes to the alert region» failed on document.activeElement.
// 1.8.0 (27-10-2026), the two screens: run against the 1.7 page, the four
// tests that use the screen failed waiting for the new hooks
// ([data-action="add"] as a link, the templates button); with the Alt + arrow handler removed, «create Restaurantes» failed
// on the order after the keypress; with the drop handler not calling
// moveField(), on the order after the drag.
import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { BASE, playwright, reset, saveCompany, testApi, wp } from "../../../tests/lib.mjs";

const PAGE = BASE + "/wp-admin/admin.php?page=estrategia-web-content-types";
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

const list = async () => {
  await p.goto(PAGE);
  await p.locator('[data-action="add"]').waitFor({ timeout: 20000 });
};
/** From the list to a new type's edit screen, as a person: the «Add new» link. */
const newType = async () => {
  await list();
  await Promise.all([p.waitForURL(/new=1/), p.click('[data-action="add"]')]);
  await p.locator('[data-action="save"]').waitFor({ timeout: 20000 });
};
const field = (i) => p.locator(`[data-field="${i}"]`);
const order = () => p.$$eval("[data-field] .ewct-col-name", (cells) => cells.map((c) => c.textContent));
const closeAll = async () => {
  const opened = p.locator('[data-action="toggle-field"][aria-expanded="true"]');
  while ((await opened.count()) > 0) await opened.first().click();
};

test("an error goes to the alert region, which takes the focus; nothing is saved", async () => {
  await newType();
  await p.click('[data-action="save"]');
  const alert = p.locator('.ewct-alert[role="alert"]');
  await alert.waitFor({ state: "visible", timeout: 15000 });
  assert.match(await alert.textContent(), /prefix|prefijo/i);
  assert.equal(await p.evaluate(() => document.activeElement && document.activeElement.classList.contains("ewct-alert")), true, "focus on the alert");
  assert.equal((await ct("/constructor-raw?name=")).row, null);
});

test("create «Restaurantes» with three fields: tabs, rows that open, keyboard and drag reordering, a field group; the type, its menu and its fields panel appear", async () => {
  await newType();
  // The tabs: a tablist, arrow keys move between them.
  assert.equal(await p.getAttribute('[data-tab="general"]', "aria-selected"), "true");
  await p.focus('[data-tab="general"]');
  await p.keyboard.press("ArrowRight");
  assert.equal(await p.getAttribute('[data-tab="urls"]', "aria-selected"), "true", "ArrowRight selects the next tab");
  assert.equal(await p.locator("#ewct-panel-general").isHidden(), true);
  await p.click('[data-tab="general"]');

  await p.fill('[data-key="plural"][data-lang="es"]', "Restaurantes");
  await p.fill('[data-key="singular"][data-lang="es"]', "Restaurante");
  await p.fill('[data-key="prefix"]', "gment_");
  assert.equal(await p.inputValue('[data-key="name"]'), "gment_restaurante", "the internal name is suggested");
  await p.fill('[data-key="slug"]', "restaurantes");

  await p.click('[data-action="add-field"]');
  assert.equal(await field(0).locator('[data-action="toggle-field"]').getAttribute("aria-expanded"), "true", "a new field opens");
  assert.equal(await p.evaluate(() => Boolean(document.activeElement.closest('[data-field="0"] .ewct-field-body'))), true, "focus inside it");
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
  assert.match(await field(2).locator('[data-action="toggle-field"]').textContent(), /Terraza.*gment_terraza/, "the row's head follows its settings");

  // Every control has a label pointing at it (the hidden tabs included).
  const unlabelled = await p.evaluate(() =>
    [...document.querySelectorAll("#estrategia-web-constructor input, #estrategia-web-constructor select, #estrategia-web-constructor textarea")]
      .filter((c) => c.type !== "hidden" && !(c.id && document.querySelector(`label[for="${c.id}"]`)))
      .map((c) => c.outerHTML.slice(0, 80))
  );
  assert.deepEqual(unlabelled, []);

  // Collapsed rows; a click opens one and closes it again.
  await closeAll();
  assert.equal(await p.locator(".ewct-field-body:visible").count(), 0, "all collapsed");
  await field(1).locator('[data-action="toggle-field"]').click();
  assert.equal(await field(1).locator(".ewct-field-body").isVisible(), true);
  await field(1).locator('[data-action="toggle-field"]').click();

  // Keyboard: Alt + ArrowUp on «Terraza» moves it up, and it is said aloud.
  await field(2).locator('[data-action="toggle-field"]').focus();
  await p.keyboard.press("Alt+ArrowUp");
  assert.deepEqual(await order(), ["gment_telefono", "gment_terraza", "gment_precio"]);
  assert.equal(await p.evaluate(() => document.activeElement.closest("[data-field]")?.dataset.field), "1", "the focus follows the field");
  await p.waitForFunction(() => /Terraza/.test(document.getElementById("a11y-speak-polite")?.textContent || ""), null, { timeout: 5000 });
  // Drag: «Terraza» by its handle to below «Precio».
  const target = field(2);
  const box = await target.boundingBox();
  await field(1).locator('[data-action="drag"]').dragTo(target, { targetPosition: { x: 40, y: box.height - 4 } });
  assert.deepEqual(await order(), ["gment_telefono", "gment_precio", "gment_terraza"], "dragged back to the end");

  // A field group (Advanced tab) and the field that joins it.
  await p.click('[data-tab="advanced"]');
  await p.click('[data-action="add-group"]');
  await p.locator('[data-group="0"] [data-key="group-label"][data-lang="es"]').fill("Más datos");
  assert.equal(await p.inputValue('[data-group="0"] [data-key="group-key"]'), "mas_datos", "the key follows the title");
  await p.check('[data-group="0"] [data-key="group-collapsed"]');
  await field(2).locator('[data-action="toggle-field"]').click();
  await field(2).locator('[data-key="field-group"]').selectOption("mas_datos");

  await p.click('[data-action="save"]');
  await p.locator("[data-saved]").waitFor({ timeout: 20000 });
  assert.match(p.url(), /&type=gment_restaurante$/, "the screen stays on the type, at its own URL");
  assert.equal(await p.evaluate(() => document.activeElement?.hasAttribute("data-saved")), true, "focus on the notice");

  const def = (await ct("/constructor-raw?name=gment_restaurante")).row.definition;
  assert.deepEqual(def.fields.map((f) => [f.name, f.type]), [["gment_telefono", "tel"], ["gment_precio", "select"], ["gment_terraza", "boolean"]]);
  assert.deepEqual(def.fields[1].options, [{ value: "barato", label: { es: "Barato" } }, { value: "caro", label: { es: "Caro" } }]);
  assert.deepEqual(def.groups, [{ key: "mas_datos", label: { es: "Más datos" }, collapsed: true }]);
  assert.equal(def.fields[2].group, "mas_datos");

  await list();
  const row = p.locator('tr[data-type="gment_restaurante"]');
  assert.match(await row.textContent(), /Restaurantes/);
  assert.equal((await row.locator(".column-fields").textContent()).trim(), "3");

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

test("a change that moves published things asks first: «Save anyway» saves it; «where the fields are edited» is saved too", async () => {
  await wp("/gment_restaurante", { method: "POST", body: { title: "Can Joan", status: "publish" } });
  await list();
  // The row actions show on hover (or on focus), as in every WordPress list.
  await p.hover('tr[data-type="gment_restaurante"]');
  await Promise.all([p.waitForURL(/type=gment_restaurante/), p.click('tr[data-type="gment_restaurante"] [data-action="edit"]')]);
  await field(2).waitFor({ timeout: 20000 });
  await field(2).locator('[data-action="remove"]').click();
  assert.equal(await p.evaluate(() => document.activeElement?.closest("[data-field]")?.dataset.field), "1", "focus to the row that is now last");
  await p.click('[data-tab="advanced"]');
  await p.selectOption('[data-key="fields_box"]', "main");
  await p.click('[data-action="save"]');
  const confirm = p.locator('[data-action="confirm"]');
  await confirm.waitFor({ timeout: 15000 });
  assert.match(await p.locator(".ewct-alert").textContent(), /gment_terraza/);
  assert.equal((await ct("/constructor-raw?name=gment_restaurante")).row.definition.fields.length, 3, "not yet");
  await confirm.click();
  await p.locator("[data-saved]").waitFor({ timeout: 20000 });
  const def = (await ct("/constructor-raw?name=gment_restaurante")).row.definition;
  assert.equal(def.fields.length, 2);
  assert.equal(def.fields_box, "main");
  assert.equal(await p.getAttribute('[data-tab="advanced"]', "aria-selected"), "true", "the tab stays where it was");
});

test("«Add from template»: a menu that Escape closes; a template is added and opens its edit screen", async () => {
  await list();
  const button = p.locator('[data-action="templates"]');
  await button.click();
  assert.equal(await button.getAttribute("aria-expanded"), "true");
  assert.equal(await p.evaluate(() => document.activeElement?.dataset.template !== undefined), true, "focus on the first template");
  await p.keyboard.press("Escape");
  assert.equal(await p.locator("#ewct-templates").isHidden(), true);
  assert.equal(await p.evaluate(() => document.activeElement?.dataset.action), "templates", "focus back on the button");
  await button.click();
  await Promise.all([p.waitForURL(/type=eweb_testimonial/), p.click('[data-template="testimonial"]')]);
  await p.locator('[data-done="added"]').waitFor({ timeout: 20000 });
  await list();
  await p.locator('tr[data-type="eweb_testimonial"]').waitFor({ timeout: 20000 });
  assert.equal(await p.locator('[data-template="testimonial"]').isDisabled(), true, "only once");
});

test("no JavaScript errors", () => {
  assert.deepEqual(errors, []);
});
