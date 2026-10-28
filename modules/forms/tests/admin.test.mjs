// NIVEL: 3 — browser. NOT LEVEL 2 BECAUSE: what is tested is the block editor, which only exists running in a browser — the blocks registered by editor.js, the inner template a new Form block gets, the side panel that keeps the preselection on its option's TEXT, and what the editor's save sends back for a synced form it cannot render.
//
// ONE login and ONE editor boot for the whole file (28-10-2026), on the page
// that uses the synced form: first it is saved as it is (the save case),
// then its blocks are replaced in the open editor for the other two, which
// are never saved. What was here and needed no browser went down to level 2
// that day: the «Forms → Submissions» screens to submissions-admin.test.mjs,
// and the form post editor's template and allowed blocks (settings the
// server writes into the page) to forms-list.test.mjs.
//
// Seen red (28-10-2026), every test, with these mutants at once: the select
// block.json without «pattern/overrides» in usesContext (the saved page no
// longer preselects), the form's inner template with a text field instead of
// the textarea, and followOption() in editor.js returning the old position.
// Seen red again after round 2 (28-10-2026), every test, with the mutants above (one by one where a test reads what the one before left).
import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { BASE, playwright, seed, testApi } from "./lib.mjs";
import { adminSession } from "../../../tests/lib.mjs";

const OPTIONS = "Hoteles\\nRestaurantes\\nBarcos";
let browser, p, page, session;
const errors = [];

before(async () => {
  [{ pages: { page } }, browser] = await Promise.all([
    seed({
      pages: [
        { key: "pattern", type: "wp_block", slug: "presupuesto-sincronizado", title: "Presupuesto", content: `<!-- wp:estrategia-web-forms/form {"formId":"presupuesto"} --><!-- wp:estrategia-web-forms/select {"label":"Servicio","name":"servicio","options":"${OPTIONS}"} /--><!-- /wp:estrategia-web-forms/form -->` },
        { key: "page", slug: "barcos-editor", title: "Barcos", content: `<!-- wp:block {"ref":{{id:pattern}},"content":{"servicio":{"defaultOption":3}}} /-->` },
      ],
    }),
    playwright().chromium.launch(),
    adminSession().then((s) => (session = s)),
  ]);
  // Round 2 (28-10-2026): the login is done without the browser, at once with launching it, and its
  // cookies handed to the browser (two page loads less).
  const ctx = await browser.newContext();
  await ctx.addCookies(session.cookie().split("; ").map((pair) => {
    const i = pair.indexOf("=");
    return { name: pair.slice(0, i), value: pair.slice(i + 1), url: BASE };
  }));
  p = await ctx.newPage();
  p.on("pageerror", (e) => errors.push(e.message));
  await p.goto(`${BASE}/wp-admin/post.php?post=${page.id}&action=edit`);
  await p.waitForFunction(() => window.wp?.data?.select("core/block-editor").getBlocks().length > 0 && window.wp.blocks.getBlockType("estrategia-web-forms/form"));
  await p.evaluate(() => wp.data.dispatch("core/preferences").set("core/edit-post", "welcomeGuide", false));
});
after(() => browser?.close());

test("editor: opening and saving a page with the synced form keeps its preselection", async () => {
  await p.evaluate(() => {
    wp.data.dispatch("core/editor").editPost({ title: "Barcos (saved in the editor)" });
    return wp.data.dispatch("core/editor").savePost();
  });
  await p.waitForFunction(() => !wp.data.select("core/editor").isSavingPost() && !wp.data.select("core/editor").isEditedPostDirty());
  const [{ content }, html] = await Promise.all([testApi(`/post/${page.id}`), fetch(page.url).then((r) => r.text())]);
  assert.match(content, /"content":\{"servicio":\{"defaultOption":3\}\}/, "the editor does not drop the content it cannot render: " + content);
  assert.match(html, /<option value="Barcos" selected>/, "and the page still preselects");
});

test("editor: the blocks are registered and a new form comes with its template with two notifications", async () => {
  const names = await p.evaluate(() => wp.blocks.getBlockTypes().map((b) => b.name).filter((n) => n.startsWith("estrategia-web-forms/")).sort());
  assert.deepEqual(names, ["checkbox", "email", "form", "form-ref", "notification", "select", "submit", "tel", "text", "textarea"].map((n) => "estrategia-web-forms/" + n));
  const inserter = await p.evaluate(() => ({ form: wp.blocks.hasBlockSupport("estrategia-web-forms/form", "inserter", true), ref: wp.blocks.hasBlockSupport("estrategia-web-forms/form-ref", "inserter", true) }));
  assert.deepEqual(inserter, { form: false, ref: true }, "2.0.0: a page gets the reference block; the form tree lives in a form post");

  await p.evaluate(() => wp.data.dispatch("core/block-editor").resetBlocks([wp.blocks.createBlock("estrategia-web-forms/form")]));
  await p.waitForFunction(() => wp.data.select("core/block-editor").getBlocks()[0]?.innerBlocks.length > 0);
  const form = await p.evaluate(() => {
    const b = wp.data.select("core/block-editor").getBlocks()[0];
    return { formId: b.attributes.formId, inner: b.innerBlocks.map((i) => i.name.replace("estrategia-web-forms/", "")) };
  });
  assert.match(form.formId, /^form-[a-z0-9]+$/, "the identifier is generated automatically");
  assert.deepEqual(form.inner, ["text", "email", "textarea", "checkbox", "submit", "notification", "notification"]);
  assert.deepEqual(errors, [], "no JavaScript errors in the editor");
});

test("editor: the preselected option follows its TEXT when options are reordered or removed", async () => {
  const clientId = await p.evaluate(() => {
    const select = wp.blocks.createBlock("estrategia-web-forms/select", { label: "Servicio", name: "servicio", options: "Hoteles\nRestaurantes\nBarcos", defaultOption: 2 });
    wp.data.dispatch("core/block-editor").resetBlocks([wp.blocks.createBlock("estrategia-web-forms/form", { formId: "f" }, [select])]);
    wp.data.dispatch("core/block-editor").selectBlock(select.clientId);
    wp.data.dispatch("core/edit-post").openGeneralSidebar("edit-post/block");
    return select.clientId;
  });
  const attr = () => p.evaluate((id) => wp.data.select("core/block-editor").getBlockAttributes(id).defaultOption, clientId);
  const preselected = p.getByLabel("Preselected option");
  await preselected.waitFor();
  assert.equal(await preselected.inputValue(), "2", "the panel shows the saved option");

  // getByRole and not getByLabel: «Options» is also the top toolbar's menu and the block toolbar's.
  const options = p.getByRole("textbox", { name: "Options" });
  await options.fill("Barcos\nHoteles\nRestaurantes");
  await p.waitForFunction((id) => wp.data.select("core/block-editor").getBlockAttributes(id).options.startsWith("Barcos"), clientId);
  assert.equal(await attr(), 3, "«Restaurantes» moved to the third position and the preselection with it");

  await options.fill("Barcos\nHoteles");
  await p.waitForFunction((id) => !wp.data.select("core/block-editor").getBlockAttributes(id).options.includes("Restaurantes"), clientId);
  assert.equal(await attr(), 0, "if the option disappears, no other one is preselected by accident");
  assert.deepEqual(errors, [], "no JavaScript errors in the editor");
});
