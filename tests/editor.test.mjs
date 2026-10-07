// NIVEL: 3 — navegador. NECESITA NAVEGADOR PORQUE: lo que enseña el editor de bloques de un párrafo enlazado solo existe cuando el editor (JS) lo pinta, y la pantalla de ajustes es un formulario con sesión de administrador.
import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { BASE, playwright, reset, saveCompany, seedPage, wp } from "./lib.mjs";

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

test("editor: un párrafo enlazado a estrategia-web-site/company:phone ENSEÑA el teléfono, y la fuente ofrece la lista de datos", async () => {
  const page = await seedPage(
    `<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"estrategia-web-site/company","args":{"key":"phone"}}}}} -->
<p>Texto de relleno</p>
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
  // Espera al hecho: que el párrafo enseñe el valor (el editor lo pinta tras registrar la fuente).
  await canvas.locator("p.wp-block-paragraph", { hasText: "971 12 34 56" }).waitFor({ timeout: 15000 });
  assert.equal((await first.textContent()).trim(), "971 12 34 56");
  assert.match(await canvas.locator("p.wp-block-paragraph").nth(1).textContent(), /\[(NIF\/CIF|Tax ID)\]/, "un dato vacío enseña su nombre entre corchetes");

  const source = await p.evaluate(() => {
    const s = wp.blocks.getBlockBindingsSource("estrategia-web-site/company");
    // Sin setValues el núcleo ni guarda canUserEditValue: ausente = no editable.
    return s && { label: s.label, fields: s.getFieldsList?.({}).map((f) => f.args.key), editable: Boolean(s.setValues || s.canUserEditValue?.({})) };
  });
  assert.ok(source, "la fuente está registrada en el editor");
  assert.equal(source.label, "Datos de la empresa");
  assert.ok(source.fields.includes("phone") && source.fields.includes("whatsapp_href") && source.fields.includes("social.instagram"));
  assert.equal(source.editable, false, "el dato no se edita desde el párrafo");
  assert.deepEqual(errors, [], "sin errores de JavaScript en el editor");
});

test("pantalla «Datos de la empresa»: guarda, avisa del correo mal escrito y desmarcar el último tipo lo desactiva", async () => {
  await p.goto(`${BASE}/wp-admin/admin.php?page=estrategia-web-site`);
  assert.equal(await p.locator("h1").first().textContent(), "Datos de la empresa");
  await p.fill("#estrategia-web-site-company_name", "Empresa desde la pantalla");
  await p.fill("#estrategia-web-site-email", "esto-no-es-correo");
  // type=email: el navegador frenaría el envío; se quita para probar el servidor.
  await p.evaluate(() => (document.getElementById("estrategia-web-site-email").type = "text"));
  await p.uncheck('input[name="estrategia_web_site[enabled_types][]"][value="client"]');
  await Promise.all([p.waitForURL(/settings-updated/), p.click("#submit")]);

  assert.match(await p.locator(".notice, .error, .updated").allTextContents().then((t) => t.join(" ")), /correo electrónico no es válido/);
  const saved = (await wp("/settings")).json.estrategia_web_site;
  assert.equal(saved.company_name, "Empresa desde la pantalla");
  assert.equal(saved.email, "hola@ejemplo.test", "se quedó el correo bueno");
  assert.deepEqual(saved.enabled_types, [], "el oculto hace que «ninguno» llegue");
  assert.equal(saved.phone, "971 12 34 56", "lo que no se tocó sigue igual");
});

test("editor (0.2.0): un párrafo enlazado a estrategia-web-site/location con slug ENSEÑA el dato de esa sede, y la fuente lista sus claves", async () => {
  await saveCompany({ enabled_types: ["location"] });
  const sede = await wp("/location", { method: "POST", body: { title: "Maioris", slug: "maioris", status: "publish", meta: { estrategia_web_location_phone: "971 11 22 33" } } });
  assert.equal(sede.status, 201, sede.text);
  const page = await seedPage(
    `<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"estrategia-web-site/location","args":{"slug":"maioris","key":"estrategia_web_location_phone"}}}}} -->
<p>Texto de relleno</p>
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
  assert.match(await canvas.locator("p.wp-block-paragraph").nth(1).textContent(), /\[(Correo electrónico|Email)\]/, "un dato vacío enseña su nombre");

  const source = await p.evaluate(() => {
    const s = wp.blocks.getBlockBindingsSource("estrategia-web-site/location");
    return s && { label: s.label, fields: s.getFieldsList?.({}).map((f) => f.args.key), context: s.usesContext };
  });
  assert.ok(source, "la fuente de sedes está registrada en el editor");
  assert.equal(source.label, "Datos de la sede");
  assert.ok(source.fields.includes("title") && source.fields.includes("estrategia_web_location_address") && source.fields.includes("estrategia_web_location_phone_href"));
  assert.ok(source.context.includes("postId"), "usa el contexto de la Consulta");
  assert.deepEqual(errors, [], "sin errores de JavaScript en el editor");
});
