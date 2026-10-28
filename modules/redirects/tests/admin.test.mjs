// NIVEL: 2 — Playground WordPress. NOT LEVEL 1 BECAUSE: the «Estrategia Web → Redirects» screen is wp-admin HTML (WP_List_Table, nonces, the admin POST → redirect → GET handlers) with an administrator session, writing to the module's tables.
//
// Was level 3 (a browser) until 28-10-2026: the screen has NO JavaScript —
// its search is a GET form, adding, bulk actions and importing are plain
// POST forms, exporting is a link that answers a CSV — so a cookie session
// (adminSession(), tests/lib.mjs) sends exactly what a browser sends. If the
// screen ever gets JavaScript that matters, that part goes back to level 3.
// Which other redirect systems count as a conflict is Conflicts::detect(),
// level 1 (pure.test.mjs); here, only that the screen shows the warning,
// played with the Redirection plugin (since 2.0.1 Rank Math with its
// redirects module is not a conflict but the OWNER: the module steps aside
// and this screen is not there, server.test.mjs).
//
// In Spanish (the core test mu-plugin sets es_ES): along the way it checks
// that the module's texts are translated.
//
// Seen red (14-10-2026), and it was a real bug: «bulk action: deactivate»
// kept waiting because the table's form carried its own nonce and
// WP_List_Table prints its own (bulk-redirects) with the same name
// _wpnonce, which overwrote it: «the link you followed has expired». The
// form is sent here with ALL its fields, both _wpnonce included, in the
// browser's order (the last one wins in PHP, as with a browser).
// Seen red (28-10-2026), at level 2: the bulk handler's nonce check pointed
// at the screen's own nonce (as the 14-10-2026 bug: 403 «Ha ocurrido un
// error» instead of the redirect), 40 rows per page (the list: 50), the
// error notice's class renamed (the loop), the export without its BOM and
// the «Crear redirección» link with «src» instead of «source» (the log).
// Round 2 (28-10-2026): the core reset, the tables and «module on» are one request. Seen red
// again with the export without its BOM («with BOM for Excel»). «Adding a rule» and the 404 log
// run at the same time (the export counts 83 + 2); seen red with the «Crear redirección» link
// carrying «src» instead of «source».
import { test, describe, before, after } from "node:test";
import assert from "node:assert/strict";
import { BASE, CSV, api, get } from "./lib.mjs";
import { adminSession } from "../../../tests/lib.mjs";

const PAGE = "/wp-admin/admin.php?page=estrategia-web-redirects";
let s, editForm, importPage;

/** Text without tags (what is read on screen), and the inner HTML of the first element with that class. */
const plain = (html) => html.replace(/<[^>]+>/g, " ").replace(/\s+/g, " ");
const byClass = (html, cls) => html.match(new RegExp(`<(\\w+)[^>]*class="[^"]*\\b${cls}\\b[^"]*"[^>]*>([\\s\\S]*?)</\\1>`))?.[2] ?? null;
const rows = (html) => (html.match(/<table[^>]*wp-list-table[\s\S]*?<tbody[^>]*>([\s\S]*?)<\/tbody>/)?.[1].match(/<tr\b/g) ?? []).length;
const value = (html, id) => html.match(new RegExp(`<input[^>]*id="${id}"[^>]*>`))?.[0].match(/\svalue="([^"]*)"/)?.[1];
const unescape = (u) => u.replace(/&#0?38;|&amp;/g, "&");
/** The POST → redirect of the screen must carry ew_msg=<msg>; with follow, the GET it leads to. */
const landed = async (r, msg, follow = true) => {
  assert.equal(r.status, 302, `expected the redirect with ew_msg=${msg}, got ${r.status}: ${plain(r.text).slice(0, 300)}`);
  assert.match(r.location, new RegExp(`ew_msg=${msg}`));
  return follow ? s.get(r.location) : r;
};

before(async () => {
  // The core reset, the module's tables and the module on in ONE request (round 2), the login at the same time.
  [, s] = await Promise.all([api("/reset", { method: "POST", body: { core: true, on: true } }), adminSession()]);
  let r;
  // The 404 log on and the Redirection plugin played from the start (nothing else here depends on them).
  [r] = await Promise.all([
    api("/import", { method: "POST", body: { csv: CSV } }),
    api("/settings", { method: "POST", body: { log_404: true } }),
    api("/fake-seo", { method: "POST", body: { plugin: "redirection" } }),
  ]);
  assert.equal(r.created, 83, "seed: Lavandería's 83");
});
// The module's tables, its settings and the fake plugin: the core reset() does not undo them.
after(() => api("/reset", { method: "POST" }));

test("paginated list with the 83; the two-systems warning with Redirection", async () => {
  // And, at the same time, a lost path for the 404 log test and the forms the next tests send (their nonces last).
  let list;
  [list, , { text: editForm }, { text: importPage }] = await Promise.all([s.get(PAGE), get("/pagina-perdida-xyz/?utm_source=x"), s.get(PAGE + "&view=edit"), s.get(PAGE + "&view=import")]);
  assert.equal(list.status, 200);
  assert.match(list.text, /<h1>Redirecciones<\/h1>/);
  assert.match(byClass(list.text, "displaying-num"), /83/);
  assert.equal(rows(list.text), 50);
  assert.match(plain(byClass(list.text, "ew-redirects-conflict") || ""), /dos sistemas de redirecciones.*Redirection/s);
  assert.match(plain(list.text), /410 Ya no existe/);
});

// Two independent lines at the same time (round 2, 28-10-2026): adding a rule (and the loop)
// and creating one from the 404 log. Each reads only its own rule; the export below, which runs
// after both, counts them both (83 + 2).
describe("adding rules", { concurrency: 2 }, () => {
test("adding a rule, and a loop is rejected without losing what was typed", async () => {
  const form = editForm;
  const [saved, loop] = await Promise.all([
    s.submit(form, "page=estrategia-web-redirects", { source: "/promo-verano/", target: "/autoservicio/" }),
    s.submit(form, "page=estrategia-web-redirects", { source: "/quienes-somos/", target: "/nosotros/" }),
  ]);
  const [notice, rules, redirect] = await Promise.all([landed(saved, "saved"), api("/rules"), get("/promo-verano/")]);
  assert.match(byClass(notice.text, "notice-success"), /Redirección guardada/);
  assert.ok(rules.some((r) => r.source === "/promo-verano/" && r.target === "/autoservicio/"));
  assert.equal(redirect.location, BASE + "/autoservicio/");

  assert.equal(loop.status, 200, "a rejected rule is shown again, not redirected");
  assert.match(byClass(loop.text, "ew-redirects-error"), /bucle/);
  assert.equal(value(loop.text, "ew-source"), "/quienes-somos/", "what was typed is still there");
  assert.equal(value(loop.text, "ew-target"), "/nosotros/");
});

test("404 log: «Crear redirección» fills in the source and, on save, the path leaves the log", async () => {
  const log = (await s.get(PAGE + "&view=log")).text;
  assert.match(log, /<input type="checkbox" name="log_404" value="1"\s+checked='checked'/);
  const row = log.match(/<tr[^>]*>(?:(?!<\/tr>)[\s\S])*\/pagina-perdida-xyz(?:(?!<\/tr>)[\s\S])*<\/tr>/)?.[0];
  assert.ok(row, "the lost path is not in the log");
  const create = row.match(/<a href="([^"]*)"[^>]*>Crear redirección<\/a>/)?.[1];
  assert.ok(create, "no «Crear redirección» link");
  const form = (await s.get(unescape(create))).text;
  assert.equal(value(form, "ew-source"), "/pagina-perdida-xyz");
  await landed(await s.submit(form, "page=estrategia-web-redirects", { target: "/" }), "saved", false);
  const [after, r] = await Promise.all([api("/log"), get("/pagina-perdida-xyz/")]);
  assert.ok(!after.some((x) => x.path === "/pagina-perdida-xyz"));
  assert.equal(r.location, BASE + "/");
});
});

test("search, and bulk action: deactivate", async () => {
  const list = (await s.get(PAGE + "&s=promo-verano")).text;
  assert.equal(rows(list), 1, "the search finds only /promo-verano/");
  const id = list.match(/name="ids\[\]" value="(\d+)"/)?.[1];
  assert.ok(id, "the promo-verano row has no checkbox");
  await landed(await s.submit(list, "page=estrategia-web-redirects", { "ids[]": id, action: "deactivate" }), "updated", false);
  const [rules, r] = await Promise.all([api("/rules"), get("/promo-verano/")]);
  assert.equal(Number(rules.find((x) => x.source === "/promo-verano/").active), 0);
  assert.equal(r.status, 404, "disabled doesn't redirect");
});

test("exporting, and importing by uploading what was exported: nothing new, nothing duplicated", async () => {
  const page = importPage;

  // Exporting: the link answers the CSV. Bytes, not text(): text() drops the BOM this test is looking for.
  const exportUrl = unescape(page.match(/<a[^>]*class="[^"]*ew-redirects-export[^"]*"[^>]*href="([^"]*)"|<a[^>]*href="([^"]*)"[^>]*class="[^"]*ew-redirects-export/).slice(1).find(Boolean));
  const exported = Buffer.from(await (await fetch(exportUrl, { headers: { Cookie: s.cookie() } })).arrayBuffer());
  assert.deepEqual([...exported.subarray(0, 3)], [0xef, 0xbb, 0xbf], "with BOM for Excel");
  const csv = exported.subarray(3).toString("utf8");
  assert.ok(csv.startsWith("origen,destino,tipo,motivo,activa,visitas,ultima_visita"), csv.slice(0, 80));
  assert.equal(csv.trim().split("\n").length, 1 + 85);

  // Importing: the EXPORTED file (BOM included, as it comes back from Excel), uploaded with the
  // import form as a browser sends it (its hidden fields and the file). The real CSV went in
  // through the same Importer in before(): uploading it again would only re-prove «unchanged».
  const form = page.match(/<form[^>]*class="ew-redirects-import"[^>]*>([\s\S]*?)<\/form>/);
  assert.ok(form, "no import form");
  const action = unescape(form[0].match(/action="([^"]*)"/)[1]);
  const body = new FormData();
  for (const [, name, val] of form[1].matchAll(/<input[^>]*type="hidden"[^>]*name="([^"]*)"[^>]*value="([^"]*)"/g)) body.append(name, unescape(val));
  body.append("csv", new Blob([exported], { type: "text/csv" }), "redirecciones.csv");
  const posted = await fetch(action, { method: "POST", redirect: "manual", headers: { Cookie: s.cookie() }, body });
  const report = await landed({ status: posted.status, location: posted.headers.get("location") || "", text: await posted.text() }, "imported");
  assert.match(plain(byClass(report.text, "ew-redirects-report") || ""), /0 nuevas, 0 actualizadas, 85 sin cambios, 0 con errores/, "what was exported re-imports without changes, nothing duplicated");
});
