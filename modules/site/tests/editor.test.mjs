// NIVEL: 3 — browser. NEEDS A BROWSER BECAUSE: what the block editor shows for a bound paragraph only exists once the editor (JS) renders it, and the settings screen is a form with an administrator session.
import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { BASE, playwright, reset, saveCompany, seedPage, wp, type } from "../../../tests/lib.mjs";

let browser, p;
const errors = [];

async function login(page) {
  await page.goto(BASE + "/wp-admin/");
  if (page.url().includes("wp-login.php")) {
    await page.fill("#user_login", "admin");
    await page.fill("#user_pass", "password");
    await Promise.all([page.waitForURL(/wp-admin/), page.click("#wp-submit")]);
  }
}

before(async () => {
  await reset();
  await saveCompany({ phone: "971 12 34 56", email: "hola@ejemplo.test", enabled_types: ["client"] });
  browser = await playwright().chromium.launch();
  const ctx = await browser.newContext();
  p = await ctx.newPage();
  p.on("pageerror", (e) => errors.push(e.message));
  await login(p);
});
after(() => browser?.close());

test("editor: a paragraph bound to estrategia-web-site/company:phone SHOWS the phone, and the source offers the list of fields", async () => {
  const page = await seedPage(
    `<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"estrategia-web-site/company","args":{"key":"phone"}}}}} -->
<p>Placeholder text</p>
<!-- /wp:paragraph -->
<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"estrategia-web-site/company","args":{"key":"tax_id"}}}}} -->
<p>x</p>
<!-- /wp:paragraph -->`,
    "editor-bindings"
  );
  await p.goto(`${BASE}/wp-admin/post.php?post=${page.id}&action=edit`);
  await p.waitForFunction(() => window.wp?.data?.select("core/block-editor")?.getBlocks().length === 2, null, { timeout: 60000 });

  const canvas = p.frameLocator('iframe[name="editor-canvas"]');
  const first = canvas.locator("p.wp-block-paragraph").first();
  await first.waitFor();
  // Wait for the fact: the paragraph shows the value (the editor renders it after registering the source).
  await canvas.locator("p.wp-block-paragraph", { hasText: "971 12 34 56" }).waitFor({ timeout: 15000 });
  assert.equal((await first.textContent()).trim(), "971 12 34 56");
  assert.match(await canvas.locator("p.wp-block-paragraph").nth(1).textContent(), /\[(NIF\/CIF|Tax ID)\]/, "an empty field shows its name in brackets");

  const source = await p.evaluate(() => {
    const s = wp.blocks.getBlockBindingsSource("estrategia-web-site/company");
    // Without setValues core does not even store canUserEditValue: absent = not editable.
    return s && { label: s.label, fields: s.getFieldsList?.({}).map((f) => f.args.key), editable: Boolean(s.setValues || s.canUserEditValue?.({})) };
  });
  assert.ok(source, "the source is registered in the editor");
  assert.equal(source.label, "Datos de la empresa");
  assert.ok(source.fields.includes("phone") && source.fields.includes("whatsapp_href") && source.fields.includes("social.instagram"));
  assert.equal(source.editable, false, "the field is not edited from the paragraph");
  assert.deepEqual(errors, [], "no JavaScript errors in the editor");
});

// Since 17-10-2026 the content types are switched on and off in «Content types» (content-types module): the screen
// links there and does not send enabled_types, which therefore stays as it was.
test("«Datos de la empresa» screen: saves, warns about the mistyped email, links to Content types and leaves enabled_types alone", async () => {
  const before = (await wp("/settings")).json.estrategia_web_site;
  await p.goto(`${BASE}/wp-admin/admin.php?page=estrategia-web-site`);
  assert.equal(await p.locator("h1").first().textContent(), "Datos de la empresa");
  await p.fill("#estrategia-web-site-company_name", "Company from the screen");
  await p.fill("#estrategia-web-site-email", "this-is-not-an-email");
  // type=email: the browser would block the submit; it is removed to test the server.
  await p.evaluate(() => (document.getElementById("estrategia-web-site-email").type = "text"));
  assert.equal(await p.locator('input[name="estrategia_web_site[enabled_types][]"]').count(), 0, "no type checkboxes any more");
  // 1.7.0: only Company and Contact here; Content types and Social networks are in the menu, SVG in Settings › Media.
  assert.equal(await p.locator('#adminmenu a[href*="page=estrategia-web-content-types"]').count(), 1, "Content types is in the menu");
  assert.equal(await p.locator('.wrap input[name="estrategia_web_site[allow_svg]"]').count(), 0, "SVG is not on this screen any more");
  await Promise.all([p.waitForURL(/settings-updated/), p.click("#submit")]);

  assert.match(await p.locator(".notice, .error, .updated").allTextContents().then((t) => t.join(" ")), /correo electrónico no es válido/);
  const saved = (await wp("/settings")).json.estrategia_web_site;
  assert.equal(saved.company_name, "Company from the screen");
  assert.equal(saved.email, "hola@ejemplo.test", "the good email was kept");
  assert.deepEqual(saved.enabled_types, before.enabled_types, "the screen does not touch enabled_types");
  assert.equal(saved.phone, "971 12 34 56", "what was not touched stays the same");
});

test("editor (0.2.0): a paragraph bound to estrategia-web-site/location with slug SHOWS that location's field, and the source lists its keys", async () => {
  await saveCompany({ enabled_types: ["location"] });
  const loc = await wp(`/${type("location")}`, { method: "POST", body: { title: "Maioris", slug: "maioris", status: "publish", meta: { estrategia_web_location_phone: "971 11 22 33" } } });
  assert.equal(loc.status, 201, loc.text);
  const page = await seedPage(
    `<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"estrategia-web-site/location","args":{"slug":"maioris","key":"estrategia_web_location_phone"}}}}} -->
<p>Placeholder text</p>
<!-- /wp:paragraph -->
<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"estrategia-web-site/location","args":{"slug":"maioris","key":"estrategia_web_location_email"}}}}} -->
<p>x</p>
<!-- /wp:paragraph -->`,
    "editor-sede"
  );
  await p.goto(`${BASE}/wp-admin/post.php?post=${page.id}&action=edit`);
  await p.waitForFunction(() => window.wp?.data?.select("core/block-editor")?.getBlocks().length === 2, null, { timeout: 60000 });
  const canvas = p.frameLocator('iframe[name="editor-canvas"]');
  await canvas.locator("p.wp-block-paragraph", { hasText: "971 11 22 33" }).waitFor({ timeout: 15000 });
  assert.match(await canvas.locator("p.wp-block-paragraph").nth(1).textContent(), /\[(Correo electrónico|Email)\]/, "an empty field shows its name");

  const source = await p.evaluate(() => {
    const s = wp.blocks.getBlockBindingsSource("estrategia-web-site/location");
    return s && { label: s.label, fields: s.getFieldsList?.({}).map((f) => f.args.key), context: s.usesContext };
  });
  assert.ok(source, "the locations source is registered in the editor");
  assert.equal(source.label, "Datos de la sede");
  assert.ok(source.fields.includes("title") && source.fields.includes("estrategia_web_location_address") && source.fields.includes("estrategia_web_location_phone_href"));
  assert.ok(source.context.includes("postId"), "uses the Query context");
  assert.deepEqual(errors, [], "no JavaScript errors in the editor");
});
