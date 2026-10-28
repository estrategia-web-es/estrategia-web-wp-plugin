// NIVEL: 3 — browser. NOT LEVEL 2 BECAUSE: what is tested only exists once the block editor's JavaScript runs: what the canvas shows for a paragraph bound to our sources (the value, or the field's name in brackets when empty) and what the sources declare to the editor (label, fields list, not editable, the Query context); that what a person types in the fields box ends up saved — the box is a «legacy» meta box that the editor's JavaScript posts after the post's own save —; that the box is visible and wide in the main area (a size the layout computes); and the box's own JavaScript (the media library button, «Copiar el lunes a todos los días»). The server's HTML of bound pages is bindings.test.mjs and location.test.mjs, the box's HTML and its save as a request content-types/tests/location-box.test.mjs (level 2).
//
// The real case (07-10-2026): changing the phone of the Maioris location from its
// edit screen, without WP-CLI. Since 2.0.0 (David, 27-10-2026: «una página vacía y
// todos los datos en el panel estrecho») location, client and testimonial edit their
// fields in a wide box in the MAIN area of the block editor, grouped, not in the sidebar.
//
// 28-10-2026, from ~83 s: ONE editor boot. Gone: the client's and the
// testimonial's boxes (four editor boots in all), which re-proved the same
// editor save of the same meta box with other fields — their boxes in the
// main area are location-box.test.mjs (level 2) and their sanitizing
// types.test.mjs (level 2); «a page does not get the fields» went down to
// settings.test.mjs (level 2: it is in the server's HTML). Round 2 (same
// day): editor.test.mjs merged in — its bound paragraphs are now the content
// of the location being edited, so ONE browser, ONE login and ONE editor boot
// serve both (it was a page of its own and a second boot).
// Seen red (17-10-2026): with the location template's «details» label removed,
// the panel title was «Sede: datos» and the first test failed. (27-10-2026, the first
// run of this version, for real) the box was in the page but INVISIBLE: WordPress 7.1
// keeps the meta boxes in a «Cajas meta» drawer that starts closed, so the editor still
// looked empty. Fields_Panel::enqueue() now opens it for these types.
// Seen red (28-10-2026) with the expected Sunday hours after «Copiar el lunes»
// changed, with the expected location phone in the canvas changed (the wait
// times out) and with the company source's label changed.
import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { BASE, playwright, reset, saveCompany, wp, type } from "../../../tests/lib.mjs";

let browser, p, loc, box;
const errors = [];
const para = (source, args) => `<!-- wp:paragraph ${JSON.stringify({ metadata: { bindings: { content: { source, args } } } })} -->\n<p>Placeholder text</p>\n<!-- /wp:paragraph -->`;
const CONTENT = [
  para("estrategia-web-site/company", { key: "phone" }),
  para("estrategia-web-site/company", { key: "tax_id" }),
  para("estrategia-web-site/location", { slug: "maioris", key: "estrategia_web_location_phone" }),
  para("estrategia-web-site/location", { slug: "maioris", key: "estrategia_web_location_email" }),
].join("\n");

before(async () => {
  await reset();
  await saveCompany({ phone: "971 12 34 56", email: "hola@ejemplo.test", enabled_types: ["location"] });
  [loc, browser] = await Promise.all([
    wp(`/${type("location")}`, { method: "POST", body: { title: "Maioris", slug: "maioris", status: "publish", content: CONTENT, meta: { estrategia_web_location_phone: "971 00 00 00", estrategia_web_location_locality: "Llucmajor" } } }),
    playwright().chromium.launch(),
  ]);
  assert.equal(loc.status, 201, loc.text);
  p = await (await browser.newContext({ viewport: { width: 1400, height: 1000 } })).newPage();
  p.on("pageerror", (e) => errors.push(e.message));
  // One login and one editor boot for the whole file.
  await p.goto(`${BASE}/wp-admin/post.php?post=${loc.json.id}&action=edit`);
  if (p.url().includes("wp-login.php")) {
    await p.fill("#user_login", "admin");
    await p.fill("#user_pass", "password");
    // The PATH, not the URL: the login page's own URL carries «post.php» in redirect_to, so /post\.php/ matched
    // at once and a login that never finished was reported 60 s later as «no blocks» (28-10-2026).
    // Seen red with a wrong password: «page.waitForURL: Timeout», on this line.
    await Promise.all([p.waitForURL((u) => u.pathname.endsWith("/wp-admin/post.php")), p.click("#wp-submit")]);
  }
  await p.waitForFunction(() => window.wp?.data?.select("core/block-editor")?.getBlocks().length === 4, null, { timeout: 60000 });
  await p.evaluate(() => window.wp.data.dispatch("core/preferences")?.set("core/edit-post", "welcomeGuide", false));
  box = p.locator("#estrategia-web-content-types");
  await box.waitFor({ timeout: 20000 });
});
after(() => browser?.close());

const canvas = () => p.frameLocator('iframe[name="editor-canvas"]');

test("editor: a paragraph bound to estrategia-web-site/company:phone SHOWS the phone, and the source offers the list of fields", async () => {
  // Wait for the fact: the paragraph shows the value (the editor renders it after registering the source).
  await canvas().locator("p.wp-block-paragraph", { hasText: "971 12 34 56" }).waitFor({ timeout: 15000 });
  const paragraphs = canvas().locator("p.wp-block-paragraph");
  assert.equal((await paragraphs.nth(0).textContent()).trim(), "971 12 34 56");
  assert.match(await paragraphs.nth(1).textContent(), /\[(NIF\/CIF|Tax ID)\]/, "an empty field shows its name in brackets");
  const source = await p.evaluate(() => {
    const s = wp.blocks.getBlockBindingsSource("estrategia-web-site/company");
    // Without setValues core does not even store canUserEditValue: absent = not editable.
    return s && { label: s.label, fields: s.getFieldsList?.({}).map((f) => f.args.key), editable: Boolean(s.setValues || s.canUserEditValue?.({})) };
  });
  assert.ok(source, "the source is registered in the editor");
  assert.equal(source.label, "Datos de la empresa");
  assert.ok(source.fields.includes("phone") && source.fields.includes("whatsapp_href") && source.fields.includes("social.instagram"));
  assert.equal(source.editable, false, "the field is not edited from the paragraph");
});

test("editor (0.2.0): a paragraph bound to estrategia-web-site/location with slug SHOWS that location's field, and the source lists its keys", async () => {
  await canvas().locator("p.wp-block-paragraph", { hasText: "971 00 00 00" }).waitFor({ timeout: 15000 });
  assert.match(await canvas().locator("p.wp-block-paragraph").nth(3).textContent(), /\[(Correo electrónico|Email)\]/, "an empty field shows its name");
  const source = await p.evaluate(() => {
    const s = wp.blocks.getBlockBindingsSource("estrategia-web-site/location");
    return s && { label: s.label, fields: s.getFieldsList?.({}).map((f) => f.args.key), context: s.usesContext };
  });
  assert.ok(source, "the locations source is registered in the editor");
  assert.equal(source.label, "Datos de la sede");
  assert.ok(source.fields.includes("title") && source.fields.includes("estrategia_web_location_address") && source.fields.includes("estrategia_web_location_phone_href"));
  assert.ok(source.context.includes("postId"), "uses the Query context");
});

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

test("location: the «Datos de la sede» box visible in the main area, wide, grouped, with no sidebar panel", async () => {
  assert.match(await box.locator("h2").first().textContent(), /Datos de la sede/);
  assert.equal(await p.locator(".estrategia-web-content-types-panel").count(), 0, "no sidebar panel");
  assert.equal(await p.evaluate(() => typeof window.estrategiaWebContentTypesPanel), "undefined");
  assert.deepEqual(await box.locator("details.estrategia-web-ct-group > summary").allTextContents(), ["Contacto", "Dirección", "Horario", "Mapa", "Para Google"]);
  assert.equal(await box.locator('details[data-group="google"]').getAttribute("open"), null, "«Para Google» starts closed");
  assert.ok((await box.boundingBox()).width > 700, "wide: the main area, not the 280 px sidebar");
});

test("location: what is typed in the box (and filled by its JavaScript) is saved with the editor's Save", async () => {
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
});

test("no JavaScript errors in the editor, from the boot to the save", () => {
  assert.deepEqual(errors, []);
});
