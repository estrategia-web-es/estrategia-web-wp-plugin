// NIVEL: 3 — browser. NEEDS A BROWSER BECAUSE: the «<Singular> details» panel only exists when the block editor (JS) paints it, and what is tested is that what is typed THERE ends up stored in the meta, with the right JSON types; and the «Content types» screen only exists painted, with an admin session.
//
// Seen red (15-10-2026): with the number case of Field() sending the raw
// string, «wine» stayed GREEN at first — WP 7.1 accepts "2019" for an
// integer, so the check was looking at nothing. The real break is the
// emptied field: "" is refused by REST. With the step that empties
// gmct_alcohol, the same bug fails «wine» (the post stays unsaved).
import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { BASE, playwright, reset, testApi, wp } from "../../../tests/lib.mjs";

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
  await ct("/setup", { method: "POST", body: { on: true } });
  await ct("/state");
  browser = await playwright().chromium.launch();
  p = await (await browser.newContext({ viewport: { width: 1400, height: 1000 } })).newPage();
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
  await ct("/setup", { method: "POST", body: { on: false } });
  await reset();
});

async function openEditor(url) {
  await p.goto(BASE + url);
  await p.waitForFunction(() => window.wp?.data?.select("core/editor")?.getCurrentPostType(), null, { timeout: 60000 });
  await p.evaluate(() => window.wp.data.dispatch("core/preferences")?.set("core/edit-post", "welcomeGuide", false));
  await p.evaluate(() => window.wp.data.dispatch("core/edit-post")?.openGeneralSidebar?.("edit-post/document"));
  await p.locator(".estrategia-web-content-types-panel").waitFor({ timeout: 15000 });
  return p.locator(".estrategia-web-content-types-panel");
}

async function save(status) {
  await p.evaluate(async (s) => {
    if (s) window.wp.data.dispatch("core/editor").editPost({ status: s });
    await window.wp.data.dispatch("core/editor").savePost().catch(() => {});
  }, status);
  await p.waitForFunction(() => !window.wp.data.select("core/editor").isSavingPost(), null, { timeout: 30000 });
}

test("restaurant: the panel shows the JSON's fields in the site language, and what is typed there is stored", async () => {
  const r = await wp("/gmct_restaurant", { method: "POST", body: { title: "Es Racó", status: "draft", meta: { gmct_address: "Plaça Major, 3" } } });
  assert.equal(r.status, 201, r.text);
  const panel = await openEditor(`/wp-admin/post.php?post=${r.json.id}&action=edit`);

  assert.match(await panel.locator(".components-panel__body-title").first().textContent(), /Restaurante/);
  assert.equal(await panel.locator('[data-meta-key="gmct_address"] textarea').inputValue(), "Plaça Major, 3");
  assert.match(await panel.locator('[data-meta-key="gmct_address"]').textContent(), /Dirección \*/, "required is marked");
  assert.equal(await panel.locator('[data-meta-key="gmct_phone"] input').getAttribute("type"), "tel");
  const price = panel.locator('[data-meta-key="gmct_price_range"] select');
  assert.equal(await price.inputValue(), "€€", "the default is selected");
  assert.deepEqual(await price.locator("option").allTextContents(), ["€ — económico", "€€ — medio", "€€€ — alto"], "es labels, no «None» (it has a default)");

  await panel.locator('[data-meta-key="gmct_phone"] input').fill("971 12 34 56");
  await price.selectOption("€€€");
  await panel.locator('[data-meta-key="gmct_website"] input').fill("https://esraco.example");
  await save("publish");

  const stored = await wp(`/gmct_restaurant/${r.json.id}?context=edit`);
  assert.equal(stored.json.status, "publish");
  assert.equal(stored.json.meta.gmct_phone, "971 12 34 56");
  assert.equal(stored.json.meta.gmct_price_range, "€€€");
  assert.equal(stored.json.meta.gmct_website, "https://esraco.example");
});

test("wine: number, boolean, date and relation travel with their types; publishing without the required vintage shows WordPress's error", async () => {
  const restaurant = (await wp("/gmct_restaurant?status=publish&per_page=1")).json[0];
  const panel = await openEditor("/wp-admin/post-new.php?post_type=gmct_wine");
  await p.evaluate(() => window.wp.data.dispatch("core/editor").editPost({ title: "Ànima Negra" }));

  await save("publish");
  const notice = await p.evaluate(() => window.wp.data.select("core/notices").getNotices().map((n) => n.content).join(" | "));
  assert.match(notice, /Vintage/, "the editor shows which field is missing: " + notice);

  await panel.locator('[data-meta-key="gmct_vintage"] input').fill("2019");
  await panel.locator('[data-meta-key="gmct_alcohol"] input').fill("14.5");
  await panel.locator('[data-meta-key="gmct_released"] input').fill("2021-03-01");
  await panel.locator('[data-meta-key="gmct_organic"] input[type="checkbox"]').check();
  const winery = panel.locator('[data-meta-key="gmct_winery"] select');
  await p.waitForFunction(() => document.querySelectorAll('[data-meta-key="gmct_winery"] option').length > 1, null, { timeout: 15000 });
  await winery.selectOption(String(restaurant.id));
  await save("publish");

  const id = await p.evaluate(() => window.wp.data.select("core/editor").getCurrentPostId());
  const stored = await wp(`/gmct_wine/${id}?context=edit`);
  assert.equal(stored.json.status, "publish", stored.text.slice(0, 300));
  assert.equal(stored.json.meta.gmct_vintage, 2019);
  assert.equal(stored.json.meta.gmct_alcohol, 14.5);
  assert.equal(stored.json.meta.gmct_released, "2021-03-01");
  assert.equal(stored.json.meta.gmct_organic, true);
  assert.equal(stored.json.meta.gmct_winery, restaurant.id);

  // Emptying a number: it has to travel as null (delete), not as "" — REST refuses "" for a number.
  await panel.locator('[data-meta-key="gmct_alcohol"] input').fill("");
  await save();
  assert.equal(await p.evaluate(() => window.wp.data.select("core/editor").isEditedPostDirty()), false, "saved without errors");
  const emptied = await wp(`/gmct_wine/${id}?context=edit`);
  assert.equal(emptied.json.meta.gmct_alcohol, 0, "back to the default");
});

test("the «Content types» screen lists the declared types and the rejected files with their errors, and edits nothing", async () => {
  await p.goto(BASE + "/wp-admin/admin.php?page=estrategia-web-content-types");
  const card = p.locator('[data-type="gmct_restaurant"]');
  assert.equal(await card.count(), 1);
  assert.match(await card.textContent(), /gmct_phone/);
  assert.match(await p.locator('[data-file="gmct_too_long.json"]').textContent(), /gmct_restaurant_menus/);
  assert.equal(await p.locator('[data-file="gmct_not_json.json"]').count(), 1);
  assert.equal(await p.locator(".estrategia-web-content-types form, .estrategia-web-content-types input").count(), 0, "read-only");
});

test("no JavaScript errors in the editor", () => {
  assert.deepEqual(errors, []);
});
