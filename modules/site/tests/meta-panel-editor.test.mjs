// NIVEL: 3 — navegador. NECESITA NAVEGADOR PORQUE: el panel «Datos de la sede» solo existe cuando el editor de bloques (JS) lo pinta, y lo que se prueba es que lo escrito AHÍ acabe guardado en la meta.
//
// El caso real (07-10-2026): cambiar el teléfono de la sede Maioris desde su
// pantalla de edición, sin WP-CLI.
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
  // El editor puede abrir con la guía de bienvenida encima.
  await p.evaluate(() => window.wp.data.dispatch("core/preferences")?.set("core/edit-post", "welcomeGuide", false));
  await p.evaluate(() => window.wp.data.dispatch("core/edit-post")?.openGeneralSidebar?.("edit-post/document"));
  await p.locator(".estrategia-web-meta-panel").waitFor({ timeout: 15000 });
}

async function save() {
  await p.evaluate(() => window.wp.data.dispatch("core/editor").savePost());
  await p.waitForFunction(() => {
    const s = window.wp.data.select("core/editor");
    return !s.isSavingPost() && !s.isEditedPostDirty();
  }, null, { timeout: 30000 });
}

test("sede: el panel «Datos de la sede» enseña los campos con sus valores, y cambiar el teléfono ahí lo guarda en la meta", async () => {
  const sede = await wp(`/${type("location")}`, { method: "POST", body: { title: "Maioris", slug: "maioris", status: "publish", meta: { estrategia_web_location_phone: "971 00 00 00", estrategia_web_location_address: "Nave 14" } } });
  assert.equal(sede.status, 201, sede.text);
  await openEditor(sede.json.id);

  const panel = p.locator(".estrategia-web-meta-panel");
  assert.match(await panel.locator(".components-panel__body-title").first().textContent(), /Datos de la sede/);
  const phone = panel.locator('[data-meta-key="estrategia_web_location_phone"] input');
  assert.equal(await phone.inputValue(), "971 00 00 00");
  assert.equal(await panel.locator('[data-meta-key="estrategia_web_location_address"] textarea').inputValue(), "Nave 14");
  for (const key of ["schedule", "map_url", "map_embed", "static_map_id", "email"]) {
    assert.equal(await panel.locator(`[data-meta-key="estrategia_web_location_${key}"]`).count(), 1, `falta ${key}`);
  }
  assert.equal(await panel.locator('[data-meta-key="estrategia_web_location_static_map_id"] button').first().textContent(), "Elegir imagen");

  await phone.fill("971 99 88 77");
  await panel.locator('[data-meta-key="estrategia_web_location_map_embed"] textarea').fill('<iframe src="https://www.google.com/maps/embed?pb=abc" width="600"></iframe>');
  await save();

  const saved = (await wp(`/${type("location")}/${sede.json.id}?context=edit`)).json.meta;
  assert.equal(saved.estrategia_web_location_phone, "971 99 88 77");
  assert.equal(saved.estrategia_web_location_map_embed, "https://www.google.com/maps/embed?pb=abc", "del iframe pegado, solo el src");
  assert.equal(saved.estrategia_web_location_address, "Nave 14", "lo que no se tocó sigue igual");
  assert.deepEqual(errors, [], "sin errores de JavaScript en el editor");
});

test("cliente (pantalla CLÁSICA: no tiene 'editor'): la meta box «Datos del cliente» guarda tipo de negocio y ajuste de imagen", async () => {
  const cliente = await wp(`/${type("client")}`, { method: "POST", body: { title: "Portic Villas", status: "publish", meta: { client_url: "https://portic.test" } } });
  await p.goto(`${BASE}/wp-admin/post.php?post=${cliente.json.id}&action=edit`);
  const box = p.locator("#estrategia-web-meta");
  assert.match(await box.locator("h2").first().textContent(), /Datos del cliente/);
  assert.equal(await box.locator('[data-meta-key="client_url"] input').inputValue(), "https://portic.test");
  await box.locator('[data-meta-key="estrategia_web_client_business_type"] input').fill("Agencia de <b>alquiler</b> vacacional");
  await box.locator('[data-meta-key="estrategia_web_client_image_fit"] select').selectOption("contain");
  await Promise.all([p.waitForURL(/message=/), p.click("#publish")]);
  const saved = (await wp(`/${type("client")}/${cliente.json.id}?context=edit`)).json.meta;
  assert.equal(saved.estrategia_web_client_business_type, "Agencia de alquiler vacacional", "saneado como por REST");
  assert.equal(saved.estrategia_web_client_image_fit, "contain");
  assert.equal(saved.client_url, "https://portic.test", "lo que no se tocó sigue igual");
  assert.deepEqual(errors, [], "sin errores de JavaScript");
});

test("opinión: su panel con autor, valoración y cliente relacionado (las enteras viajan como número)", async () => {
  const cliente = (await wp(`/${type("client")}?search=Portic`)).json[0];
  assert.ok(cliente, "el cliente de la prueba anterior");
  const opinion = await wp(`/${type("testimonial")}`, { method: "POST", body: { title: "Muy bien", status: "publish" } });
  await openEditor(opinion.json.id);
  const panel = p.locator(".estrategia-web-meta-panel");
  assert.match(await panel.locator(".components-panel__body-title").first().textContent(), /Datos de la opinión/);
  await panel.locator('[data-meta-key="estrategia_web_testimonial_author"] input').fill("Marta G.");
  await panel.locator('[data-meta-key="estrategia_web_testimonial_rating"] select').selectOption("4");
  const related = panel.locator('[data-meta-key="testimonial_client"] select');
  await related.locator(`option[value="${cliente.id}"]`).waitFor({ state: "attached", timeout: 15000 });
  await related.selectOption(String(cliente.id));
  await save();
  const saved = (await wp(`/${type("testimonial")}/${opinion.json.id}?context=edit`)).json.meta;
  assert.equal(saved.estrategia_web_testimonial_author, "Marta G.");
  assert.equal(saved.estrategia_web_testimonial_rating, 4);
  assert.equal(saved.testimonial_client, cliente.id);
  assert.deepEqual(errors, [], "sin errores de JavaScript en el editor");
});

test("una página no lleva el panel (solo sede, cliente y opinión)", async () => {
  const page = await wp("/pages", { method: "POST", body: { title: "Una página", status: "publish" } });
  await p.goto(`${BASE}/wp-admin/post.php?post=${page.json.id}&action=edit`);
  await p.waitForFunction(() => window.wp?.data?.select("core/editor")?.getCurrentPostId(), null, { timeout: 60000 });
  assert.equal(await p.evaluate(() => typeof window.estrategiaWebMetaPanel), "undefined");
  await wp(`/pages/${page.json.id}?force=true`, { method: "DELETE" });
});
