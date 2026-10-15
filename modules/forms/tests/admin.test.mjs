// NIVEL: 3 — browser. NEEDS A BROWSER BECAUSE: the admin screens and the block editor only exist rendered and with an administrator session; the CSV is a browser download.
import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import { BASE, playwright, reset, submissions, seedPage, loadForm, submit, testApi, GOOD, waitMinTime } from "./lib.mjs";

let browser, ctx, p;

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
  const page = await seedPage();
  const { hidden } = await loadForm(page.url);
  await waitMinTime();
  await submit(hidden, GOOD);
  await submit(hidden, { ...GOOD, nombre: "Bernat", email: "=cmd@ejemplo.test" });
  const other = await seedPage(
    `<!-- wp:estrategia-web-forms/form {"formId":"presupuesto","title":"Presupuesto"} --><!-- wp:estrategia-web-forms/text {"label":"Empresa","name":"empresa","required":true} /--><!-- /wp:estrategia-web-forms/form -->`,
    "presupuesto"
  );
  const second = await loadForm(other.url);
  await waitMinTime();
  await submit(second.hidden, { empresa: "Acme" });
  assert.equal((await submissions()).length, 3, "seed: three submissions from two forms");

  browser = await playwright().chromium.launch();
  ctx = await browser.newContext({ acceptDownloads: true });
  p = await ctx.newPage();
  await login(p);
});
after(() => browser?.close());

const LIST = BASE + "/wp-admin/admin.php?page=estrategia-web-forms";

test("list, filter by form and a submission's detail with each notification's result", async () => {
  await p.goto(LIST);
  assert.equal(await p.locator("table.wp-list-table tbody tr").count(), 3);
  await p.selectOption("#af-filter-form", "presupuesto");
  await Promise.all([p.waitForURL(/form_id=presupuesto/), p.click("#post-query-submit, [name=filter_action]")]);
  assert.equal(await p.locator("table.wp-list-table tbody tr").count(), 1);
  assert.match(await p.textContent("table.wp-list-table tbody"), /Acme/);

  await p.goto(LIST + "&form_id=contacto");
  await p.click("table.wp-list-table tbody tr:last-child .column-date strong a");
  const data = await p.textContent("table.estrategia-web-forms-data");
  assert.match(data, /Ana/);
  const notes = await p.textContent("table.estrategia-web-forms-notifications");
  assert.match(notes, /Aviso al equipo/);
  assert.match(notes, /fail@ejemplo\.test/);
});

test("CSV export: header, rows and dangerous cells neutralized", async () => {
  await p.goto(LIST + "&form_id=contacto");
  const [download] = await Promise.all([p.waitForEvent("download"), p.click("a.page-title-action")]);
  const csv = fs.readFileSync(await download.path(), "utf8").replace(/^﻿/, "");
  // A multi-line message is ONE quoted cell with line breaks inside:
  // records are counted by their id at the start of a line, not by lines.
  const lines = csv.split("\n");
  assert.equal(csv.match(/^\d+;/gm).length, 2, "2 submissions from «contacto», not the one from «presupuesto»");
  assert.match(lines[0], /^id;date_utc;form_id;form;page;language;status;notifications;ip;user_agent;Nombre;Correo/);
  assert.ok(csv.includes("'=cmd@ejemplo.test"), "a cell starting with = does not reach Excel as a formula");
  assert.ok(!csv.includes("Acme"));
});

test("export or delete without nonce: denied", async () => {
  const res = await p.request.get(BASE + "/wp-admin/admin-post.php?action=estrategia_web_forms_export");
  assert.notEqual(res.status(), 200, "no nonce, no CSV");
  const del = await p.request.post(BASE + "/wp-admin/admin-post.php", { form: { action: "estrategia_web_forms_delete", "ids[]": "1" } });
  assert.notEqual(del.status(), 302);
  assert.equal((await submissions()).length, 3, "nothing was deleted");
});

test("delete asks for confirmation and only deletes on confirm", async () => {
  await p.goto(LIST + "&form_id=presupuesto");
  await p.hover("table.wp-list-table tbody tr");
  await p.click("table.wp-list-table tbody tr .row-actions .delete a");
  assert.match(await p.textContent(".wrap"), /cannot be undone|no se puede deshacer/i);
  assert.equal((await submissions()).length, 3, "reaching the confirmation does not delete");
  await p.click(".wrap a.button:not(.button-primary)");
  assert.equal((await submissions()).length, 3, "cancelling does not delete");

  await p.goto(LIST + "&form_id=presupuesto");
  await p.check("table.wp-list-table tbody tr input[name='ids[]']");
  await p.selectOption("#bulk-action-selector-top", "delete");
  await p.click("#doaction");
  await Promise.all([p.waitForURL(/deleted=1/), p.click("button.button-primary")]);
  const left = await submissions();
  assert.equal(left.length, 2);
  assert.ok(left.every((r) => r.form_id === "contacto"));
});

test("editor: the blocks are registered and a new form comes with its template with two notifications", async () => {
  const errors = [];
  p.on("pageerror", (e) => errors.push(e.message));
  await p.goto(BASE + "/wp-admin/post-new.php?post_type=page");
  await p.waitForFunction(() => window.wp?.blocks?.getBlockType("estrategia-web-forms/form"));
  const names = await p.evaluate(() => wp.blocks.getBlockTypes().map((b) => b.name).filter((n) => n.startsWith("estrategia-web-forms/")).sort());
  assert.deepEqual(names, ["checkbox", "email", "form", "notification", "select", "submit", "tel", "text", "textarea"].map((n) => "estrategia-web-forms/" + n));

  await p.evaluate(() => wp.data.dispatch("core/block-editor").insertBlocks(wp.blocks.createBlock("estrategia-web-forms/form")));
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
  const errors = [];
  p.on("pageerror", (e) => errors.push(e.message));
  await p.goto(BASE + "/wp-admin/post-new.php?post_type=page");
  await p.waitForFunction(() => window.wp?.blocks?.getBlockType("estrategia-web-forms/select"));
  const clientId = await p.evaluate(() => {
    wp.data.dispatch("core/preferences").set("core/edit-post", "welcomeGuide", false);
    const select = wp.blocks.createBlock("estrategia-web-forms/select", { label: "Servicio", name: "servicio", options: "Hoteles\nRestaurantes\nBarcos", defaultOption: 2 });
    wp.data.dispatch("core/block-editor").insertBlocks(wp.blocks.createBlock("estrategia-web-forms/form", { formId: "f" }, [select]));
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

test("editor: opening and saving a page with the synced form keeps its preselection", async () => {
  const OPTIONS = "Hoteles\\nRestaurantes\\nBarcos";
  const pattern = await testApi("/page", {
    method: "POST",
    body: { type: "wp_block", slug: "presupuesto-sincronizado", title: "Presupuesto", content: `<!-- wp:estrategia-web-forms/form {"formId":"presupuesto"} --><!-- wp:estrategia-web-forms/select {"label":"Servicio","name":"servicio","options":"${OPTIONS}"} /--><!-- /wp:estrategia-web-forms/form -->` },
  });
  const page = await seedPage(`<!-- wp:block {"ref":${pattern.id},"content":{"servicio":{"defaultOption":3}}} /-->`, "barcos-editor");
  await p.goto(`${BASE}/wp-admin/post.php?post=${page.id}&action=edit`);
  await p.waitForFunction(() => window.wp?.data?.select("core/block-editor").getBlocks().length > 0);
  await p.evaluate(() => {
    wp.data.dispatch("core/editor").editPost({ title: "Barcos (saved in the editor)" });
    return wp.data.dispatch("core/editor").savePost();
  });
  await p.waitForFunction(() => !wp.data.select("core/editor").isSavingPost() && !wp.data.select("core/editor").isEditedPostDirty());
  const { content } = await testApi(`/post/${page.id}`);
  assert.match(content, /"content":\{"servicio":\{"defaultOption":3\}\}/, "the editor does not drop the content it cannot render: " + content);
  assert.equal(/<option value="Barcos" selected>/.test(await (await fetch(page.url)).text()), true, "and the page still preselects");
});
