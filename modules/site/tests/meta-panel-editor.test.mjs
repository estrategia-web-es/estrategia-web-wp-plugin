// NIVEL: 3 — browser. NEEDS A BROWSER BECAUSE: what is tested is that what a person types in the fields box of the block editor ends up saved — the box is a «legacy» meta box that the editor's JavaScript posts after the post's own save — and the box's own JavaScript (the media library button, «Copiar el lunes a todos los días»). The HTML and the save as a request are level 2 (content-types/tests/location-box.test.mjs).
//
// The real case (07-10-2026): changing the phone of the Maioris location from its
// edit screen, without WP-CLI. Since 1.8.0 (David, 27-10-2026: «una página vacía y
// todos los datos en el panel estrecho») location, client and testimonial edit their
// fields in a wide box in the MAIN area of the block editor, grouped, not in the sidebar.
//
// Seen red (17-10-2026): with the location template's «details» label removed,
// the panel title was «Sede: datos» and the first test failed. (27-10-2026, the first
// run of this version, for real) the box was in the page but INVISIBLE: WordPress 7.1
// keeps the meta boxes in a «Cajas meta» drawer that starts closed, so the editor still
// looked empty. Fields_Panel::enqueue() now opens it for these types.
import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { BASE, playwright, reset, saveCompany, wp, type } from "../../../tests/lib.mjs";

let browser, p;
const errors = [];

before(async () => {
  await reset();
  await saveCompany({ enabled_types: ["location", "client", "testimonial"] });
  await wp("/types"); // Stored on the save; registered from the next request.
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
  await reset();
});

/** The editor of a post, with its fields box in the main area. */
async function openEditor(id) {
  await p.goto(`${BASE}/wp-admin/post.php?post=${id}&action=edit`);
  await p.waitForFunction(() => window.wp?.data?.select("core/editor")?.getCurrentPostId(), null, { timeout: 60000 });
  await p.evaluate(() => window.wp.data.dispatch("core/preferences")?.set("core/edit-post", "welcomeGuide", false));
  const box = p.locator("#estrategia-web-content-types");
  await box.waitFor({ timeout: 20000 });
  return box;
}

/** The post's save AND the meta box's, as the Save button does. */
async function save() {
  await p.evaluate(() => window.wp.data.dispatch("core/editor").savePost());
  await p.waitForFunction(() => {
    const s = window.wp.data.select("core/editor");
    const m = window.wp.data.select("core/edit-post");
    return !s.isSavingPost() && !(m.isSavingMetaBoxes && m.isSavingMetaBoxes());
  }, null, { timeout: 30000 });
  await p.waitForTimeout(500); // isSavingMetaBoxes turns true a tick after the post's save ends.
  await p.waitForFunction(() => !window.wp.data.select("core/edit-post").isSavingMetaBoxes?.(), null, { timeout: 30000 });
}

test("location: the «Datos de la sede» box in the main area, grouped, with no sidebar panel; what is typed there is saved", async () => {
  const loc = await wp(`/${type("location")}`, { method: "POST", body: { title: "Maioris", slug: "maioris", status: "publish", meta: { estrategia_web_location_phone: "971 00 00 00", estrategia_web_location_locality: "Llucmajor" } } });
  assert.equal(loc.status, 201, loc.text);
  const box = await openEditor(loc.json.id);
  assert.match(await box.locator("h2").first().textContent(), /Datos de la sede/);
  assert.equal(await p.locator(".estrategia-web-content-types-panel").count(), 0, "no sidebar panel");
  assert.equal(await p.evaluate(() => typeof window.estrategiaWebContentTypesPanel), "undefined");
  assert.deepEqual(await box.locator("details.estrategia-web-ct-group > summary").allTextContents(), ["Contacto", "Dirección", "Horario", "Mapa", "Para Google"]);
  assert.equal(await box.locator('details[data-group="google"]').getAttribute("open"), null, "«Para Google» starts closed");
  assert.ok((await box.boundingBox()).width > 700, "wide: the main area, not the 280 px sidebar");

  const phone = box.locator('[data-meta-key="estrategia_web_location_phone"] input');
  assert.equal(await phone.inputValue(), "971 00 00 00");
  await phone.fill("971 99 88 77");
  await box.locator('[data-meta-key="estrategia_web_location_street"] textarea').fill("Centro Comercial Maioris\nCalle Imperial, 2, Local 12");
  // The box's JavaScript: «Copiar el lunes a todos los días».
  await box.locator('[data-meta-key="estrategia_web_location_hours"] input[data-day="mo"]').fill("08:00-23:00");
  await box.locator('[data-meta-key="estrategia_web_location_hours"] button', { hasText: "Copiar el lunes" }).click();
  assert.equal(await box.locator('[data-meta-key="estrategia_web_location_hours"] input[data-day="su"]').inputValue(), "08:00-23:00");
  // …and the media library button instead of the number.
  assert.equal(await box.locator('[data-meta-key="estrategia_web_location_static_map_id"] button').first().textContent(), "Elegir imagen");
  await save();

  const saved = (await wp(`/${type("location")}/${loc.json.id}?context=edit`)).json.meta;
  assert.equal(saved.estrategia_web_location_phone, "971 99 88 77");
  assert.equal(saved.estrategia_web_location_street, "Centro Comercial Maioris\nCalle Imperial, 2, Local 12");
  assert.equal(saved.estrategia_web_location_hours, JSON.stringify(Object.fromEntries(["mo", "tu", "we", "th", "fr", "sa", "su"].map((d) => [d, ["08:00-23:00"]]))));
  assert.equal(saved.estrategia_web_location_locality, "Llucmajor", "what was not touched stays the same");
  assert.deepEqual(errors, [], "no JavaScript errors in the editor");
});

test("client (the block editor since 1.8.0): the «Datos del cliente» box saves business type and image fit", async () => {
  const client = await wp(`/${type("client")}`, { method: "POST", body: { title: "Portic Villas", status: "publish", meta: { estrategia_web_client_url: "https://portic.test" } } });
  const box = await openEditor(client.json.id);
  assert.match(await box.locator("h2").first().textContent(), /Datos del cliente/);
  assert.equal(await box.locator('[data-meta-key="estrategia_web_client_url"] input').inputValue(), "https://portic.test");
  await box.locator('[data-meta-key="estrategia_web_client_business_type"] input').fill("Agencia de <b>alquiler</b> vacacional");
  await box.locator('[data-meta-key="estrategia_web_client_image_fit"] select').selectOption("contain");
  await save();
  const saved = (await wp(`/${type("client")}/${client.json.id}?context=edit`)).json.meta;
  assert.equal(saved.estrategia_web_client_business_type, "Agencia de alquiler vacacional", "sanitized as via REST");
  assert.equal(saved.estrategia_web_client_image_fit, "contain");
  assert.equal(saved.estrategia_web_client_url, "https://portic.test", "what was not touched stays the same");
  assert.deepEqual(errors, [], "no JavaScript errors");
});

test("testimonial: its box with author, rating and related client (integers stored as numbers)", async () => {
  const client = (await wp(`/${type("client")}?search=Portic`)).json[0];
  assert.ok(client, "the client from the previous test");
  const testimonial = await wp(`/${type("testimonial")}`, { method: "POST", body: { title: "Very good", status: "publish" } });
  const box = await openEditor(testimonial.json.id);
  assert.match(await box.locator("h2").first().textContent(), /Datos de la opinión/);
  await box.locator('[data-meta-key="estrategia_web_testimonial_author"] input').fill("Marta G.");
  await box.locator('[data-meta-key="estrategia_web_testimonial_rating"] select').selectOption("4");
  await box.locator('[data-meta-key="estrategia_web_testimonial_client"] select').selectOption(String(client.id));
  await save();
  const saved = (await wp(`/${type("testimonial")}/${testimonial.json.id}?context=edit`)).json.meta;
  assert.equal(saved.estrategia_web_testimonial_author, "Marta G.");
  assert.equal(saved.estrategia_web_testimonial_rating, 4);
  assert.equal(saved.estrategia_web_testimonial_client, client.id);
  assert.deepEqual(errors, [], "no JavaScript errors in the editor");
});

test("a page does not get the fields (neither the box nor the panel)", async () => {
  const page = await wp("/pages", { method: "POST", body: { title: "A page", status: "publish" } });
  await p.goto(`${BASE}/wp-admin/post.php?post=${page.json.id}&action=edit`);
  await p.waitForFunction(() => window.wp?.data?.select("core/editor")?.getCurrentPostId(), null, { timeout: 60000 });
  assert.equal(await p.evaluate(() => typeof window.estrategiaWebContentTypesPanel), "undefined");
  assert.equal(await p.locator("#estrategia-web-content-types").count(), 0);
  await wp(`/pages/${page.json.id}?force=true`, { method: "DELETE" });
});
