// NIVEL: 3 — browser. NOT LEVEL 2 BECAUSE: what is tested only exists when the block editor (JS) paints it: what a block bound to estrategia-web/content-field shows in the canvas and the fields the source offers (assets/bindings.js), and the «<Singular> details» panel (assets/fields-panel.js), where what is typed must end up stored in the meta with the right JSON types.
//
// One browser, one login, one setup for the theme files' editor tests
// (28-10-2026, round 2: bindings-editor.test.mjs was merged in here — it
// paid its own reset, theme files, login and browser for one editor boot).
// The bindings page goes FIRST: its Queries list every restaurant and wine,
// and the panel tests add more.
//
// 28-10-2026, from ~53 s (+ ~36 s bindings-editor): logged in by cookie
// (admin-browser.mjs) while the site is set up in one request
// (estrategia-web-site-test/v1/setup); what was stored is read from the
// editor's own post after saving (the REST answer to its save), not with
// another request; the «Content types» screen test moved to level 2
// (constructor-screens.test.mjs, «the list»); the save that only showed
// core's notice for a required field left empty went (that the REST answer
// is a 400 naming the field is register.test.mjs, «a required field empty
// on publish»; core shows it). One editor boot per post: the page, the
// restaurant (whose content is the bindings' Queries: one boot for both, round
// 2), the wine.
//
// Seen red (15-10-2026): with the number case of Field() sending the raw
// string, «wine» stayed GREEN at first — WP 7.1 accepts "2019" for an
// integer, so the check was looking at nothing. The real break is the
// emptied field: "" is refused by REST. With the step that empties
// gment_alcohol, the same bug fails «wine» (the post stays unsaved).
// Seen red (16-10-2026, bindings): with format() returning the stored meta as
// it is, «the editor shows the formatted value» failed (timeout: the canvas
// never showed the restaurant's title, only its ID). And for real, the first
// run: a Query paints each paragraph twice in the canvas — .first().
// Seen red (28-10-2026, round 2), one expected value changed in each test
// (the price label in the canvas, the source's label, the stored price, the
// stored alcohol): the four red; restored, green. The two restaurants are
// made in one request (batch.mjs) in before().
import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { BASE, credentials, testApi, wp } from "../../../tests/lib.mjs";
import { adminBrowser } from "./admin-browser.mjs";
import { batch } from "./batch.mjs";

const SOURCE = "estrategia-web/content-field";
const para = (name, args) => `<!-- wp:paragraph {"className":"ct-${name}","metadata":{"bindings":{"content":${JSON.stringify({ source: SOURCE, args })}}}} -->\n<p class="ct-${name}">x</p>\n<!-- /wp:paragraph -->`;
const query = (id, postType, inner) =>
  `<!-- wp:query ${JSON.stringify({ queryId: id, query: { perPage: 10, pages: 0, offset: 0, postType, order: "asc", orderBy: "title", inherit: false } })} -->\n<div class="wp-block-query"><!-- wp:post-template -->\n${inner}\n<!-- /wp:post-template --></div>\n<!-- /wp:query -->`;

let browser, p, errors, restaurant, draft;

before(async () => {
  const site = (async () => {
    // reset() and the theme files on (the option the ct-test setup route sets), in one request.
    const content = [
      query(1, "gment_restaurant", para("price", { key: "gment_price_range" })),
      query(2, "gment_wine", [para("winery", { key: "gment_winery" }), para("organic", { key: "gment_organic" }), para("alcohol", { key: "gment_alcohol" }), para("released", { key: "gment_released" })].join("\n")),
    ].join("\n");
    await Promise.all([testApi("/setup", { method: "POST", body: { options: { estrategia_web_ct_test: 1 } } }), credentials()]);
    // The published restaurant the Queries show, and the draft whose editor is opened — its content is the bound
    // blocks, its sidebar the fields panel (a Query shows no drafts, so it does not list itself): one request.
    const [r, d] = await batch(
      { method: "POST", path: "/wp/v2/gment_restaurant", body: { title: "Ca n'Andreu", status: "publish", meta: { gment_address: "Carrer Major, 1", gment_price_range: "€€€" } } },
      { method: "POST", path: "/wp/v2/gment_restaurant", body: { title: "Es Racó", status: "draft", content, meta: { gment_address: "Plaça Major, 3" } } }
    );
    assert.equal(r.status, 201, r.text);
    assert.equal(d.status, 201, d.text);
    draft = d.json;
    restaurant = r.json;
    const w = await wp("/gment_wine", { method: "POST", body: { title: "Anima Negra", status: "publish", meta: { gment_winery: r.json.id, gment_vintage: 2019, gment_alcohol: 13.5, gment_organic: true } } });
    assert.equal(w.status, 201, w.text);
  })();
  [, { browser, page: p, errors }] = await Promise.all([site, adminBrowser()]);
});
// What reset() does not undo: the theme files switched on (off also deletes the gment_ posts).
after(() => Promise.all([browser?.close(), fetch(BASE + "/wp-json/estrategia-web-ct-test/v1/setup", { method: "POST", headers: { "Content-Type": "application/json" }, body: '{"on":false}' })]));

async function openEditor(url) {
  await p.goto(BASE + url);
  await p.waitForFunction(() => window.wp?.data?.select("core/editor")?.getCurrentPostType(), null, { timeout: 60000 });
  await p.evaluate(() => window.wp.data.dispatch("core/preferences")?.set("core/edit-post", "welcomeGuide", false));
  await p.evaluate(() => window.wp.data.dispatch("core/edit-post")?.openGeneralSidebar?.("edit-post/document"));
  await p.locator(".estrategia-web-content-types-panel").waitFor({ timeout: 15000 });
  return showPanel();
}

/**
 * The panel, open, with no dialog over it. ROOT CAUSE of the red of 28-10-2026 (only on a FRESH WordPress,
 * «waiting for … gment_address textarea»): the administrator's first editor ever loads its preferences after
 * the first paint — a dialog opens over the editor and our panel shows COLLAPSED (seen: aria-expanded="false",
 * no fields, one .components-modal__frame). Any later run had the preferences saved and passed, so it went
 * unseen; merging bindings-editor in here made this file the first to open an editor on its WordPress.
 */
async function showPanel() {
  await p.evaluate(() => window.wp.data.dispatch("core/preferences")?.set("core/edit-post", "welcomeGuide", false));
  for (let i = 0; i < 3 && (await p.locator(".components-modal__frame").count()) > 0; i++) await p.keyboard.press("Escape");
  const panel = p.locator(".estrategia-web-content-types-panel");
  const toggle = panel.locator(".components-panel__body-toggle").first();
  if ((await toggle.getAttribute("aria-expanded")) === "false") await toggle.click();
  await panel.locator("[data-meta-key]").first().waitFor({ timeout: 15000 });
  return panel;
}

/** Saves, and returns the post as the server answered the save: what was stored. */
async function save(status) {
  await p.evaluate(async (s) => {
    if (s) window.wp.data.dispatch("core/editor").editPost({ status: s });
    await window.wp.data.dispatch("core/editor").savePost().catch(() => {});
  }, status);
  await p.waitForFunction(() => !window.wp.data.select("core/editor").isSavingPost(), null, { timeout: 30000 });
  return p.evaluate(() => {
    const e = window.wp.data.select("core/editor");
    return { dirty: e.isEditedPostDirty(), id: e.getCurrentPostId(), post: e.getCurrentPost() };
  });
}

test("bindings: the editor shows the formatted value — the option's label, the related post's title, the boolean's text, the number", async () => {
  // ONE editor boot for the bindings and the restaurant's panel: the draft's content is the two Queries.
  await openEditor(`/wp-admin/post.php?post=${draft.id}&action=edit`);
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
});

test("bindings: the source is registered with its label, is not editable from the block, and lists the fields of the type in context", async () => {
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

test("restaurant: the panel shows the JSON's fields in the site language, and what is typed there is stored", async () => {
  const panel = await showPanel(); // The editor the bindings tests opened (showPanel(): the red of 28-10-2026).

  assert.match(await panel.locator(".components-panel__body-title").first().textContent(), /Restaurante/);
  assert.equal(await panel.locator('[data-meta-key="gment_address"] textarea').inputValue(), "Plaça Major, 3");
  assert.match(await panel.locator('[data-meta-key="gment_address"]').textContent(), /Dirección \*/, "required is marked");
  assert.equal(await panel.locator('[data-meta-key="gment_phone"] input').getAttribute("type"), "tel");
  const price = panel.locator('[data-meta-key="gment_price_range"] select');
  assert.equal(await price.inputValue(), "€€", "the default is selected");
  assert.deepEqual(await price.locator("option").allTextContents(), ["€ — económico", "€€ — medio", "€€€ — alto"], "es labels, no «None» (it has a default)");

  await panel.locator('[data-meta-key="gment_phone"] input').fill("971 12 34 56");
  await price.selectOption("€€€");
  await panel.locator('[data-meta-key="gment_website"] input').fill("https://esraco.example");
  const { post } = await save("publish");
  assert.equal(post.status, "publish");
  assert.equal(post.meta.gment_phone, "971 12 34 56");
  assert.equal(post.meta.gment_price_range, "€€€");
  assert.equal(post.meta.gment_website, "https://esraco.example");
});

test("wine: number, boolean, date and relation travel with their types, and an emptied number as null", async () => {
  const panel = await openEditor("/wp-admin/post-new.php?post_type=gment_wine");
  await p.evaluate(() => window.wp.data.dispatch("core/editor").editPost({ title: "Ànima Negra" }));
  await panel.locator('[data-meta-key="gment_vintage"] input').fill("2019");
  await panel.locator('[data-meta-key="gment_alcohol"] input').fill("14.5");
  await panel.locator('[data-meta-key="gment_released"] input').fill("2021-03-01");
  await panel.locator('[data-meta-key="gment_organic"] input[type="checkbox"]').check();
  const winery = panel.locator('[data-meta-key="gment_winery"] select');
  await p.waitForFunction(() => document.querySelectorAll('[data-meta-key="gment_winery"] option').length > 1, null, { timeout: 15000 });
  await winery.selectOption(String(restaurant.id));
  const { post } = await save("publish");
  assert.equal(post.status, "publish", JSON.stringify(post).slice(0, 300));
  assert.equal(post.meta.gment_vintage, 2019);
  assert.equal(post.meta.gment_alcohol, 14.5);
  assert.equal(post.meta.gment_released, "2021-03-01");
  assert.equal(post.meta.gment_organic, true);
  assert.equal(post.meta.gment_winery, restaurant.id);

  // Emptying a number: it has to travel as null (delete), not as "" — REST refuses "" for a number.
  await panel.locator('[data-meta-key="gment_alcohol"] input').fill("");
  const emptied = await save();
  assert.equal(emptied.dirty, false, "saved without errors");
  // Read from REST: the editor keeps its own copy of a deleted meta key.
  assert.equal((await wp(`/gment_wine/${emptied.id}?context=edit`)).json.meta.gment_alcohol, 0, "back to the default");
});

test("no JavaScript errors in the editor", () => {
  assert.deepEqual(errors, []);
});
