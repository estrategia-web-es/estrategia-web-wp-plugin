// NIVEL: 3 — browser. NEEDS A BROWSER BECAUSE: the «hours» control only exists when the block editor paints the fields panel (assets/fields-panel.js), and what is tested is that a person can enter a week THERE — add ranges, copy the day before, see the warning on a range that will not be kept — and that it is stored canonical; and that the constructor offers a template's NEW fields to a copy made before them (assets/constructor.js), which only exists painted.
//
// The site under test speaks es_ES: controls are found by their data-* hooks (data-day, data-range, data-action).
//
// Seen red (22-10-2026): with HoursField's «Same as the day before» copying the CURRENT day, Tuesday stayed closed
// and «a week entered in the panel» failed on the stored JSON; with offerSection() looking at the stored
// definition instead of the draft, the notice did not go away after adding the fields.
import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { BASE, playwright, reset, saveCompany, testApi, wp, credentials, type } from "../../../tests/lib.mjs";

let browser, p;
const errors = [];

const ctApi = async (path, { method = "GET", body } = {}) => {
  const who = (await credentials()).administrator;
  const res = await fetch(BASE + "/wp-json/estrategia-web/v1/content-types" + path, {
    method,
    headers: { Authorization: "Basic " + Buffer.from(`${who.user}:${who.password}`).toString("base64"), ...(body ? { "Content-Type": "application/json" } : {}) },
    body: body ? JSON.stringify(body) : undefined,
  });
  return { status: res.status, json: await res.json() };
};

before(async () => {
  await reset();
  const { loaded } = await testApi("/modules");
  await testApi("/modules", { method: "POST", body: { active: [...new Set([...loaded, "content-types"])] } });
  await saveCompany({ enabled_types: ["location"] });
  await wp(`/${type("location")}?per_page=1`); // the request that stores the template copy (Site_Types)
  browser = await playwright().chromium.launch();
  p = await (await browser.newContext({ viewport: { width: 1400, height: 1100 } })).newPage();
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
  await reset();
});

test("a week entered in the panel: ranges, «same as the day before», the warning on a range that will not be kept, stored canonical", async () => {
  const r = await wp(`/${type("location")}`, { method: "POST", body: { title: "Son Oms", status: "draft" } });
  assert.equal(r.status, 201, r.text);
  await p.goto(`${BASE}/wp-admin/post.php?post=${r.json.id}&action=edit`);
  await p.waitForFunction(() => window.wp?.data?.select("core/editor")?.getCurrentPostType(), null, { timeout: 60000 });
  await p.evaluate(() => window.wp.data.dispatch("core/preferences")?.set("core/edit-post", "welcomeGuide", false));
  await p.evaluate(() => window.wp.data.dispatch("core/edit-post")?.openGeneralSidebar?.("edit-post/document"));
  const field = p.locator('[data-meta-key="estrategia_web_location_hours"]');
  await field.waitFor({ timeout: 20000 });

  const day = (d) => field.locator(`[data-day="${d}"]`);
  assert.match(await day("mo").locator("legend").textContent(), /lunes/i, "the day's name, in the editor's language, as the group's legend");
  assert.match(await day("mo").textContent(), /Cerrado/, "an empty day reads «Closed»");
  await day("mo").locator('[data-action="add-range"]').click();
  const mo = day("mo").locator('[data-range="0"] input[type="time"]');
  assert.equal(await mo.count(), 2);
  await mo.nth(0).fill("08:00");
  await mo.nth(1).fill("16:00");
  assert.match(await mo.nth(0).getAttribute("id"), /mo-0-0$/);
  assert.match(await p.locator(`label[for="${await mo.nth(0).getAttribute("id")}"]`).textContent(), /lunes.*1/i, "each input has its label with the day and the range");
  for (const d of ["tu", "we", "th", "fr", "sa"]) await day(d).locator('[data-action="copy-previous"]').click();

  // Sunday: a range with opening = closing is marked before saving.
  await day("su").locator('[data-action="add-range"]').click();
  const su = day("su").locator('[data-range="0"] input[type="time"]');
  await su.nth(0).fill("10:00");
  await su.nth(1).fill("10:00");
  const warning = day("su").locator('[role="status"]');
  assert.match(await warning.textContent(), /no se guardará|will not be saved/);
  assert.equal(await su.nth(0).getAttribute("aria-describedby"), await warning.getAttribute("id"), "the warning is tied to the range");

  await p.evaluate(async () => window.wp.data.dispatch("core/editor").savePost());
  await p.waitForFunction(() => !window.wp.data.select("core/editor").isSavingPost(), null, { timeout: 30000 });
  const stored = await wp(`/${type("location")}/${r.json.id}?context=edit`);
  const six = ["08:00-16:00"];
  assert.equal(stored.json.meta.estrategia_web_location_hours, JSON.stringify({ mo: six, tu: six, we: six, th: six, fr: six, sa: six }), "Monday copied down to Saturday; Sunday's 10:00-10:00 not kept");
  assert.deepEqual(errors, []);
});

test("the constructor offers a template's new fields to a copy made before them, and adds them only when asked", async () => {
  const before = await ctApi("/eweb_location");
  assert.equal(before.status, 200, JSON.stringify(before.json));
  const old = structuredClone(before.json.definition);
  old.fields = old.fields.slice(0, 8); // the copy as it was on 17-10-2026
  const put = await ctApi("/eweb_location", { method: "POST", body: { definition: old, confirm: true } });
  assert.equal(put.status, 200, JSON.stringify(put.json));
  assert.equal(put.json.missingFields.length, 9);

  await p.goto(BASE + "/wp-admin/admin.php?page=estrategia-web-content-types");
  await p.click('tr[data-type="eweb_location"] [data-action="edit"]');
  const add = p.locator('[data-action="add-template-fields"]');
  await add.waitFor({ timeout: 20000 });
  assert.match(await p.locator("[data-template-fields]").textContent(), /\(9\)/);
  await add.click();
  assert.equal(await p.locator("[data-template-fields]").textContent(), "", "the notice goes away once they are in the draft");
  const untouched = await ctApi("/eweb_location");
  assert.equal(untouched.json.definition.fields.length, 8, "nothing stored until the person saves");
  await p.click('[data-action="save"]');
  await p.locator('tr[data-type="eweb_location"]').waitFor({ timeout: 20000 });
  const after = await ctApi("/eweb_location");
  assert.deepEqual(after.json.definition.fields.map((f) => f.name), before.json.definition.fields.map((f) => f.name), "the same fields, in the template's order");
  assert.deepEqual(after.json.missingFields, []);
  assert.deepEqual(errors, []);
});
