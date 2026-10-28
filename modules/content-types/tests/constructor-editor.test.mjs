// NIVEL: 3 — browser. NOT LEVEL 2 BECAUSE: the edit screen only exists painted (assets/constructor.js), and what is tested is that a person can make a type THERE — «Restaurantes» with three fields — with the tabs, the collapsed rows that open on click, reordering by keyboard (Alt + arrow, announced) and by dragging, the field groups, and that the type, its menu and its fields appear; plus that errors and confirmations land in the alert region with the focus, and the «Add from template» menu.
//
// The site under test speaks es_ES. Controls are found by their data-*
// hooks (data-key, data-lang, data-action, data-tab), which constructor.js
// and class-page.php set on purpose so the test does not depend on the
// translated labels; that every control HAS a label is checked too. The
// list's HTML itself (its rows, the «Edit» link, the save with the page's
// nonce) is level 2: constructor-screens.test.mjs.
//
// 28-10-2026, from ~90-130 s: logged in by cookie (admin-browser.mjs), no
// modules/company requests the reset already leaves as needed, and ONE pass
// through the screen: the error and the creation are the same new-type
// screen (the person fixes what the alert said and saves again); the menu
// entry is looked for in the editor page that is loaded anyway for the
// fields panel; the list's row after saving, the registration through REST
// and the list's «Edit» link went (level 2 has them); the type with entries
// is opened by its URL while the entry is created; that a refused or
// unconfirmed save writes nothing is checked once, at level 2
// (constructor.test.mjs), not again here.
//
// Round 2 (28-10-2026): the change that asks for confirmation is made on
// the same screen right after the creation (no reload), and the new-entry
// screen is opened once, at the end, to see the menu and the fields where
// the person put them (the main area, after «where the fields are edited»).
// The sidebar panel itself is editor-panel.test.mjs's. Seen red (28-10-2026,
// round 2): expecting another group for «Terraza», and «gment_terraza» still
// in the main-area box: both changed tests red; restored, green. The list is
// not loaded again after adding a template: that it is then disabled is the
// list's HTML (constructor-screens.test.mjs, «the list»); seen red expecting
// the client's screen after adding the testimonial. And what was stored is
// read from the REST answer to the screen's own save (no extra request), the
// entry is made while the person goes on, and the reset and constructor-reset
// go in one request (batch.mjs); seen red again with the two breaks above.
//
// Seen red (17-10-2026): with the submit handler not calling save(), «create
// Restaurantes» never got the row; with alarm() not focusing the region,
// «an error goes to the alert region» failed on document.activeElement.
// 2.0.0 (27-10-2026), the two screens: run against the 1.7 page, the four
// tests that use the screen failed waiting for the new hooks
// ([data-action="add"] as a link, the templates button); with the Alt + arrow handler removed, «create Restaurantes» failed
// on the order after the keypress; with the drop handler not calling
// moveField(), on the order after the drag.
// Seen red (28-10-2026) after the merge: expecting the menu link of a type
// that is not there (gment_nope), «create Restaurantes» failed on «in the
// menu»; expecting the focus NOT on the alert, «an error» failed; expecting
// fields_box «sidebar», «a change that moves» failed. Restored, green.
import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { BASE, credentials, wp } from "../../../tests/lib.mjs";
import { adminBrowser } from "./admin-browser.mjs";
import { batch } from "./batch.mjs";

const PAGE = BASE + "/wp-admin/admin.php?page=estrategia-web-content-types";
const ct = async (path, { method = "GET", body } = {}) => {
  const res = await fetch(BASE + "/wp-json/estrategia-web-ct-test/v1" + path, { method, headers: body ? { "Content-Type": "application/json" } : {}, body: body ? JSON.stringify(body) : undefined });
  if (!res.ok) throw new Error(`${method} ${path} -> ${res.status} ${await res.text()}`);
  return res.json();
};

let browser, p, errors, entry;

before(async () => {
  // reset() (content-types on, the default; no «enabled_types») and constructor-reset (no gment_ posts either) in ONE request (batch.mjs), next to the login.
  [, { browser, page: p, errors }] = await Promise.all([batch({ method: "POST", path: "/estrategia-web-site-test/v1/reset" }, { method: "POST", path: "/estrategia-web-ct-test/v1/constructor-reset" }), adminBrowser()]);
  credentials(); // The test users, for the entry made later: created while the screens load.
  p.on("dialog", (d) => d.accept());
});
// What reset() does not undo: the gment_ posts.
after(() => Promise.all([browser?.close(), ct("/constructor-reset", { method: "POST" })]));

const list = async () => {
  await p.goto(PAGE);
  await p.locator('[data-action="add"]').waitFor({ timeout: 20000 });
};
const field = (i) => p.locator(`[data-field="${i}"]`);
const order = () => p.$$eval("[data-field] .ewct-col-name", (cells) => cells.map((c) => c.textContent));
const closeAll = async () => {
  const opened = p.locator('[data-action="toggle-field"][aria-expanded="true"]');
  while ((await opened.count()) > 0) await opened.first().click();
};

test("from the list's «Add new», an error goes to the alert region, which takes the focus", async () => {
  await list();
  await Promise.all([p.waitForURL(/new=1/), p.click('[data-action="add"]')]);
  await p.locator('[data-action="save"]').waitFor({ timeout: 20000 });
  await p.click('[data-action="save"]');
  const alert = p.locator('.ewct-alert[role="alert"]');
  await alert.waitFor({ state: "visible", timeout: 15000 });
  assert.match(await alert.textContent(), /prefix|prefijo/i);
  assert.equal(await p.evaluate(() => document.activeElement && document.activeElement.classList.contains("ewct-alert")), true, "focus on the alert");
  // That a refused save writes nothing is the REST route's: constructor.test.mjs, «errors».
});

test("then «Restaurantes» with three fields: tabs, rows that open, keyboard and drag reordering, a field group, saved", async () => {
  // The same screen, after the error: the person fills in what was missing.
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

  // What was stored: the REST answer to the screen's own save (no other request).
  const [saved] = await Promise.all([p.waitForResponse((r) => /content-types/.test(r.url()) && r.request().method() === "POST", { timeout: 20000 }), p.click('[data-action="save"]')]);
  await p.locator("[data-saved]").waitFor({ timeout: 20000 });
  assert.equal(saved.status(), 201);
  // The published entry of the next test, made while the person goes on with the saved type (registered from this request).
  entry = wp("/gment_restaurante", { method: "POST", body: { title: "Can Joan", status: "publish" } });
  assert.match(p.url(), /&type=gment_restaurante$/, "the screen stays on the type, at its own URL");
  assert.equal(await p.evaluate(() => document.activeElement?.hasAttribute("data-saved")), true, "focus on the notice");

  const def = (await saved.json()).definition;
  assert.deepEqual(def.fields.map((f) => [f.name, f.type]), [["gment_telefono", "tel"], ["gment_precio", "select"], ["gment_terraza", "boolean"]]);
  assert.deepEqual(def.fields[1].options, [{ value: "barato", label: { es: "Barato" } }, { value: "caro", label: { es: "Caro" } }]);
  assert.deepEqual(def.groups, [{ key: "mas_datos", label: { es: "Más datos" }, collapsed: true }]);
  assert.equal(def.fields[2].group, "mas_datos");
});

test("then, on the same screen, a change that moves published things asks first: «Save anyway» saves it; «where the fields are edited» is saved too; the type, its menu and its fields appear there", async () => {
  const made = await entry; // Started at the end of the previous test.
  assert.equal(made.status, 201, made.text);
  await field(2).locator('[data-action="remove"]').click();
  assert.equal(await p.evaluate(() => document.activeElement?.closest("[data-field]")?.dataset.field), "1", "focus to the row that is now last");
  await p.click('[data-tab="advanced"]');
  await p.selectOption('[data-key="fields_box"]', "main");
  await p.click('[data-action="save"]');
  const confirm = p.locator('[data-action="confirm"]');
  await confirm.waitFor({ timeout: 15000 });
  assert.match(await p.locator(".ewct-alert").textContent(), /gment_terraza/);
  // That nothing is written before confirming is the REST route's: constructor.test.mjs, «protections».
  // The notice of the first save is still there: wait for the answer to THIS one.
  const [answer] = await Promise.all([p.waitForResponse((r) => /content-types\/gment_restaurante/.test(r.url()) && r.request().method() === "POST" && r.status() === 200, { timeout: 20000 }), confirm.click()]);
  await p.locator("[data-saved]").waitFor({ timeout: 20000 });
  const def = (await answer.json()).definition; // What was stored: the REST answer to the confirmed save.
  assert.equal(def.fields.length, 2);
  assert.equal(def.fields_box, "main");
  assert.equal(await p.getAttribute('[data-tab="advanced"]', "aria-selected"), "true", "the tab stays where it was");

  // Registered: its new-entry screen opens, with its menu entry and its fields where the person put them (the main area).
  await p.goto(BASE + "/wp-admin/post-new.php?post_type=gment_restaurante");
  await p.waitForFunction(() => window.wp?.data?.select("core/editor")?.getCurrentPostType(), null, { timeout: 60000 });
  assert.equal(await p.locator('#adminmenu a[href="edit.php?post_type=gment_restaurante"]').count() > 0, true, "in the menu");
  const box = p.locator('form.metabox-location-normal #estrategia-web-content-types');
  assert.equal(await box.count(), 1, "the fields in a meta box in the main area");
  assert.deepEqual(await box.locator("[data-meta-key]").evaluateAll((els) => els.map((e) => e.dataset.metaKey)), ["gment_telefono", "gment_precio"], "the two fields left, in their order");
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
  // That the list then offers it no more (disabled) is the list's HTML: constructor-screens.test.mjs, «the list».
  assert.match(p.url(), /type=eweb_testimonial/);
});

test("no JavaScript errors", () => {
  assert.deepEqual(errors, []);
});
