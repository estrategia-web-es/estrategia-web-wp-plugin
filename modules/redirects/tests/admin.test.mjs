// NIVEL: 3 — browser. NEEDS A BROWSER BECAUSE: the «Estrategia Web → Redirects» screen only exists rendered and with an administrator session; importing is uploading a file and exporting is a download.
//
// In Spanish (the core test mu-plugin sets es_ES): along the way it checks
// that the module's texts are translated.
//
// Seen red (14-10-2026), and it was a real bug: «bulk action: deactivate»
// kept waiting because the table's form carried its own nonce and
// WP_List_Table prints its own (bulk-redirects) with the same name
// _wpnonce, which overwrote it: «the link you followed has expired».
import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import { BASE, CSV, FIXTURE, api, reset, get, playwright } from "./lib.mjs";

let browser, p;
const PAGE = BASE + "/wp-admin/admin.php?page=estrategia-web-redirects";

before(async () => {
  await reset();
  const r = await api("/import", { method: "POST", body: { csv: CSV } });
  assert.equal(r.created, 83, "seed: Lavandería's 83");
  browser = await playwright().chromium.launch();
  const ctx = await browser.newContext({ acceptDownloads: true });
  p = await ctx.newPage();
  await p.goto(BASE + "/wp-admin/");
  if (p.url().includes("wp-login.php")) {
    await p.fill("#user_login", "admin");
    await p.fill("#user_pass", "password");
    await Promise.all([p.waitForURL(/wp-admin/), p.click("#wp-submit")]);
  }
});
after(async () => {
  await browser?.close();
  await api("/reset", { method: "POST" });
  await api("/setup", { method: "POST", body: { on: false } });
});

test("paginated list with the 83 and search", async () => {
  await p.goto(PAGE);
  assert.equal(await p.locator("h1").first().textContent(), "Redirecciones");
  assert.match(await p.locator(".tablenav.top .displaying-num").textContent(), /83/);
  assert.equal(await p.locator("table.wp-list-table tbody tr").count(), 50);
  await p.fill("#ew-redirects-search-input", "santa-ponsa");
  await Promise.all([p.waitForURL(/s=santa-ponsa/), p.click("#search-submit")]);
  assert.equal(await p.locator("table.wp-list-table tbody tr").count(), 3);
  assert.match(await p.textContent("table.wp-list-table tbody"), /410 Ya no existe/);
});

test("adding a rule, and a loop is rejected without losing what was typed", async () => {
  await p.goto(PAGE + "&view=edit");
  await p.fill("#ew-source", "/promo-verano/");
  await p.fill("#ew-target", "/autoservicio/");
  await Promise.all([p.waitForURL(/ew_msg=saved/), p.click(".ew-redirects-edit [type=submit]")]);
  assert.match(await p.textContent(".notice-success"), /Redirección guardada/);
  assert.ok((await api("/rules")).some((r) => r.source === "/promo-verano/" && r.target === "/autoservicio/"));
  assert.equal((await get("/promo-verano/")).location, BASE + "/autoservicio/");

  await p.goto(PAGE + "&view=edit");
  await p.fill("#ew-source", "/quienes-somos/");
  await p.fill("#ew-target", "/nosotros/");
  await p.click(".ew-redirects-edit [type=submit]");
  await p.waitForSelector(".ew-redirects-error");
  assert.match(await p.textContent(".ew-redirects-error"), /bucle/);
  assert.equal(await p.inputValue("#ew-source"), "/quienes-somos/", "what was typed is still there");
  assert.equal(await p.inputValue("#ew-target"), "/nosotros/");
});

test("bulk action: deactivate", async () => {
  await p.goto(PAGE + "&s=promo-verano");
  await p.check("table.wp-list-table tbody input[name='ids[]']");
  await p.selectOption("#bulk-action-selector-top", "deactivate");
  await Promise.all([p.waitForURL(/ew_msg=updated/), p.click("#doaction")]);
  assert.equal(Number((await api("/rules")).find((r) => r.source === "/promo-verano/").active), 0);
  assert.equal((await get("/promo-verano/")).status, 404, "disabled doesn't redirect");
});

test("two-systems warning: with Rank Math and its redirects module, yes; without that module, no", async () => {
  await p.goto(PAGE);
  assert.equal(await p.locator(".ew-redirects-conflict").count(), 0);
  await api("/fake-rank-math", { method: "POST", body: { on: true } });
  await p.goto(PAGE);
  assert.match(await p.textContent(".ew-redirects-conflict"), /dos sistemas de redirecciones.*Rank Math/s);
  await api("/fake-rank-math", { method: "POST", body: { on: true, modules: ["sitemap"] } });
  await p.goto(PAGE);
  assert.equal(await p.locator(".ew-redirects-conflict").count(), 0, "Rank Math without its redirects module is not a conflict");
  await api("/fake-rank-math", { method: "POST", body: { on: false } });
});

test("importing by uploading the real CSV: nothing new, nothing duplicated; and exporting", async () => {
  await p.goto(PAGE + "&view=import");
  await p.setInputFiles(".ew-redirects-import input[type=file]", FIXTURE);
  await Promise.all([p.waitForURL(/ew_msg=imported/), p.click(".ew-redirects-import [type=submit]")]);
  assert.match(await p.textContent(".ew-redirects-report"), /0 nuevas, 0 actualizadas, 83 sin cambios, 0 con errores/);

  const [download] = await Promise.all([p.waitForEvent("download"), p.click(".ew-redirects-export")]);
  const csv = fs.readFileSync(await download.path(), "utf8");
  assert.ok(csv.startsWith("﻿origen,destino,tipo,motivo,activa,visitas,ultima_visita"), "with BOM for Excel");
  assert.equal(csv.trim().split("\n").length, 1 + 84);
  const back = await api("/import", { method: "POST", body: { csv } });
  assert.deepEqual([back.created, back.updated, back.errors.length], [0, 0, 0], "what was exported re-imports without changes");
});

test("404 log: «Crear redirección» fills in the source and, on save, the path leaves the log", async () => {
  await api("/settings", { method: "POST", body: { log_404: true } });
  await get("/pagina-perdida-xyz/?utm_source=x");
  await p.goto(PAGE + "&view=log");
  assert.ok(await p.isChecked(".ew-redirects-log-settings input[name=log_404]"));
  const row = p.locator("table.wp-list-table tbody tr", { hasText: "/pagina-perdida-xyz" });
  await row.getByRole("link", { name: "Crear redirección" }).click();
  assert.equal(await p.inputValue("#ew-source"), "/pagina-perdida-xyz");
  await p.fill("#ew-target", "/");
  await Promise.all([p.waitForURL(/ew_msg=saved/), p.click(".ew-redirects-edit [type=submit]")]);
  assert.ok(!(await api("/log")).some((r) => r.path === "/pagina-perdida-xyz"));
  assert.equal((await get("/pagina-perdida-xyz/")).location, BASE + "/");
});
