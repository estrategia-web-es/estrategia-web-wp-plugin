// NIVEL: 3 — browser. NEEDS A BROWSER BECAUSE: the «Datos de la sede» panel only exists once the block editor (JS) renders it, and what is tested is that what is typed THERE ends up saved in the meta.
//
// The real case (07-10-2026): changing the phone of the Maioris location from its
// edit screen, without WP-CLI. Since 17-10-2026 the panel is the content-types
// module's (the types are its templates): this test is the proof that the editor
// reads the same — same titles, same fields, same saving — after the move.
//
// Seen red (17-10-2026): with the location template's «details» label removed,
// the panel title was «Sede: datos» and the first test failed.
import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { BASE, playwright, reset, saveCompany, wp, type } from "../../../tests/lib.mjs";

let browser, p;
const errors = [];

before(async () => {
  await reset();
  await saveCompany({ enabled_types: ["location", "client", "testimonial"] });
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
after(() => browser?.close());

async function openEditor(id) {
  await p.goto(`${BASE}/wp-admin/post.php?post=${id}&action=edit`);
  await p.waitForFunction(() => window.wp?.data?.select("core/editor")?.getCurrentPostId(), null, { timeout: 60000 });
  // The editor may open with the welcome guide on top.
  await p.evaluate(() => window.wp.data.dispatch("core/preferences")?.set("core/edit-post", "welcomeGuide", false));
  await p.evaluate(() => window.wp.data.dispatch("core/edit-post")?.openGeneralSidebar?.("edit-post/document"));
  await p.locator(".estrategia-web-content-types-panel").waitFor({ timeout: 15000 });
}

async function save() {
  await p.evaluate(() => window.wp.data.dispatch("core/editor").savePost());
  await p.waitForFunction(() => {
    const s = window.wp.data.select("core/editor");
    return !s.isSavingPost() && !s.isEditedPostDirty();
  }, null, { timeout: 30000 });
}

test("location: the «Datos de la sede» panel shows the fields with their values, and changing the phone there saves it to the meta", async () => {
  const loc = await wp(`/${type("location")}`, { method: "POST", body: { title: "Maioris", slug: "maioris", status: "publish", meta: { estrategia_web_location_phone: "971 00 00 00", estrategia_web_location_address: "Unit 14" } } });
  assert.equal(loc.status, 201, loc.text);
  await openEditor(loc.json.id);

  const panel = p.locator(".estrategia-web-content-types-panel");
  assert.match(await panel.locator(".components-panel__body-title").first().textContent(), /Datos de la sede/);
  const phone = panel.locator('[data-meta-key="estrategia_web_location_phone"] input');
  assert.equal(await phone.inputValue(), "971 00 00 00");
  assert.equal(await panel.locator('[data-meta-key="estrategia_web_location_address"] textarea').inputValue(), "Unit 14");
  for (const key of ["schedule", "map_url", "map_embed", "static_map_id", "email"]) {
    assert.equal(await panel.locator(`[data-meta-key="estrategia_web_location_${key}"]`).count(), 1, `missing ${key}`);
  }
  assert.equal(await panel.locator('[data-meta-key="estrategia_web_location_static_map_id"] button').first().textContent(), "Elegir imagen");

  await phone.fill("971 99 88 77");
  await panel.locator('[data-meta-key="estrategia_web_location_map_embed"] textarea').fill('<iframe src="https://www.google.com/maps/embed?pb=abc" width="600"></iframe>');
  await save();

  const saved = (await wp(`/${type("location")}/${loc.json.id}?context=edit`)).json.meta;
  assert.equal(saved.estrategia_web_location_phone, "971 99 88 77");
  assert.equal(saved.estrategia_web_location_map_embed, "https://www.google.com/maps/embed?pb=abc", "from the pasted iframe, only the src");
  assert.equal(saved.estrategia_web_location_address, "Unit 14", "what was not touched stays the same");
  assert.deepEqual(errors, [], "no JavaScript errors in the editor");
});

test("client (CLASSIC screen: it has no 'editor'): the «Datos del cliente» meta box saves business type and image fit", async () => {
  const client = await wp(`/${type("client")}`, { method: "POST", body: { title: "Portic Villas", status: "publish", meta: { estrategia_web_client_url: "https://portic.test" } } });
  await p.goto(`${BASE}/wp-admin/post.php?post=${client.json.id}&action=edit`);
  const box = p.locator("#estrategia-web-content-types");
  assert.match(await box.locator("h2").first().textContent(), /Datos del cliente/);
  assert.equal(await box.locator('[data-meta-key="estrategia_web_client_url"] input').inputValue(), "https://portic.test");
  await box.locator('[data-meta-key="estrategia_web_client_business_type"] input').fill("Agencia de <b>alquiler</b> vacacional");
  await box.locator('[data-meta-key="estrategia_web_client_image_fit"] select').selectOption("contain");
  await Promise.all([p.waitForURL(/message=/), p.click("#publish")]);
  const saved = (await wp(`/${type("client")}/${client.json.id}?context=edit`)).json.meta;
  assert.equal(saved.estrategia_web_client_business_type, "Agencia de alquiler vacacional", "sanitized as via REST");
  assert.equal(saved.estrategia_web_client_image_fit, "contain");
  assert.equal(saved.estrategia_web_client_url, "https://portic.test", "what was not touched stays the same");
  assert.deepEqual(errors, [], "no JavaScript errors");
});

test("testimonial: its panel with author, rating and related client (integers travel as numbers)", async () => {
  const client = (await wp(`/${type("client")}?search=Portic`)).json[0];
  assert.ok(client, "the client from the previous test");
  const testimonial = await wp(`/${type("testimonial")}`, { method: "POST", body: { title: "Very good", status: "publish" } });
  await openEditor(testimonial.json.id);
  const panel = p.locator(".estrategia-web-content-types-panel");
  assert.match(await panel.locator(".components-panel__body-title").first().textContent(), /Datos de la opinión/);
  await panel.locator('[data-meta-key="estrategia_web_testimonial_author"] input').fill("Marta G.");
  await panel.locator('[data-meta-key="estrategia_web_testimonial_rating"] select').selectOption("4");
  const related = panel.locator('[data-meta-key="estrategia_web_testimonial_client"] select');
  await related.locator(`option[value="${client.id}"]`).waitFor({ state: "attached", timeout: 15000 });
  await related.selectOption(String(client.id));
  await save();
  const saved = (await wp(`/${type("testimonial")}/${testimonial.json.id}?context=edit`)).json.meta;
  assert.equal(saved.estrategia_web_testimonial_author, "Marta G.");
  assert.equal(saved.estrategia_web_testimonial_rating, 4);
  assert.equal(saved.estrategia_web_testimonial_client, client.id);
  assert.deepEqual(errors, [], "no JavaScript errors in the editor");
});

test("a page does not get the panel (only location, client and testimonial)", async () => {
  const page = await wp("/pages", { method: "POST", body: { title: "A page", status: "publish" } });
  await p.goto(`${BASE}/wp-admin/post.php?post=${page.json.id}&action=edit`);
  await p.waitForFunction(() => window.wp?.data?.select("core/editor")?.getCurrentPostId(), null, { timeout: 60000 });
  assert.equal(await p.evaluate(() => typeof window.estrategiaWebContentTypesPanel), "undefined");
  await wp(`/pages/${page.json.id}?force=true`, { method: "DELETE" });
});
