// NIVEL: 3 — browser. NEEDS A BROWSER BECAUSE: what the block editor shows for a block bound to estrategia-web/content-field, and the fields its «Attributes» panel offers, only exist once the editor (assets/bindings.js) renders them.
//
// Seen red (16-10-2026): with format() returning the stored meta as it is,
// «the editor shows the formatted value» failed (timeout: the canvas never
// showed the restaurant's title, only its ID). And for real, the first run:
// a Query paints each paragraph twice in the canvas — .first().
import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { BASE, playwright, reset, seedPage, testApi, wp, upload, png } from "../../../tests/lib.mjs";

const ct = async (path, { method = "GET", body } = {}) => {
  const res = await fetch(BASE + "/wp-json/estrategia-web-ct-test/v1" + path, { method, headers: body ? { "Content-Type": "application/json" } : {}, body: body ? JSON.stringify(body) : undefined });
  if (!res.ok) throw new Error(`${method} ${path} -> ${res.status} ${await res.text()}`);
  return res.json();
};
const SOURCE = "estrategia-web/content-field";
const para = (name, args) => `<!-- wp:paragraph {"className":"ct-${name}","metadata":{"bindings":{"content":${JSON.stringify({ source: SOURCE, args })}}}} -->\n<p class="ct-${name}">x</p>\n<!-- /wp:paragraph -->`;
const query = (id, postType, inner) =>
  `<!-- wp:query ${JSON.stringify({ queryId: id, query: { perPage: 10, pages: 0, offset: 0, postType, order: "asc", orderBy: "title", inherit: false } })} -->\n<div class="wp-block-query"><!-- wp:post-template -->\n${inner}\n<!-- /wp:post-template --></div>\n<!-- /wp:query -->`;

let browser, p, page;
const errors = [];

before(async () => {
  await reset();
  const { loaded } = await testApi("/modules");
  await testApi("/modules", { method: "POST", body: { active: [...new Set([...loaded, "content-types"])] } });
  await ct("/setup", { method: "POST", body: { on: true } });
  await ct("/state");
  const r = await wp("/gment_restaurant", { method: "POST", body: { title: "Ca n'Andreu", status: "publish", meta: { gment_address: "Carrer Major, 1", gment_price_range: "€€€" } } });
  assert.equal(r.status, 201, r.text);
  const up = await upload("bottle.png", "image/png", png(40, 20));
  await wp(`/media/${up.json.id}`, { method: "POST", body: { alt_text: "Botella" } });
  const w = await wp("/gment_wine", { method: "POST", body: { title: "Anima Negra", status: "publish", meta: { gment_winery: r.json.id, gment_label: up.json.id, gment_vintage: 2019, gment_alcohol: 13.5, gment_organic: true } } });
  assert.equal(w.status, 201, w.text);
  page = await seedPage(
    [
      query(1, "gment_restaurant", para("price", { key: "gment_price_range" })),
      query(2, "gment_wine", [para("winery", { key: "gment_winery" }), para("organic", { key: "gment_organic" }), para("alcohol", { key: "gment_alcohol" }), para("released", { key: "gment_released" })].join("\n")),
    ].join("\n"),
    "ct-editor"
  );
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

test("the editor shows the formatted value: the option's label, the related post's title, the boolean's text, the number", async () => {
  await p.goto(`${BASE}/wp-admin/post.php?post=${page.id}&action=edit`);
  await p.waitForFunction(() => window.wp?.data?.select("core/block-editor")?.getBlocks().length === 2, null, { timeout: 60000 });
  const canvas = p.frameLocator('iframe[name="editor-canvas"]');
  // Wait for the fact: the related post is fetched after the first paint.
  await canvas.locator("p.ct-winery", { hasText: "Andreu" }).first().waitFor({ timeout: 20000 });
  const text = async (name) => (await canvas.locator(`p.ct-${name}`).first().textContent()).trim();
  assert.equal(await text("price"), "€€€ — alto");
  assert.equal(await text("winery"), "Ca n’Andreu");
  assert.equal(await text("organic"), "Ecológico");
  assert.match(await text("alcohol"), /^13[.,]5$/);
  assert.equal(await text("released"), "[Released]", "an empty field shows its name in brackets");
  assert.deepEqual(errors, [], "no JavaScript errors in the editor");
});

test("the source is registered with its label, is not editable from the block, and lists the fields of the type in context", async () => {
  const source = await p.evaluate((name) => {
    const s = wp.blocks.getBlockBindingsSource(name);
    const list = (postType) => s.getFieldsList({ select: wp.data.select, context: { postType } });
    return s && {
      label: s.label,
      context: s.usesContext,
      editable: Boolean(s.setValues || s.canUserEditValue?.({})),
      wine: list("gment_wine").map((f) => `${f.args.key}:${f.type}`),
      page: list("page"),
    };
  }, SOURCE);
  assert.ok(source, "registered in the editor");
  assert.equal(source.label, "Campo de contenido");
  assert.ok(source.context.includes("postId") && source.context.includes("postType"), "uses the Query context");
  assert.equal(source.editable, false);
  assert.ok(source.wine.includes("gment_winery:string") && source.wine.includes("gment_organic:string"), source.wine.join(", "));
  assert.ok(source.wine.includes("gment_label:number"), "image (and relation) also as number, for core/image's id");
  assert.deepEqual(source.page, [], "a page has no fields of ours: the source is not offered");
});
