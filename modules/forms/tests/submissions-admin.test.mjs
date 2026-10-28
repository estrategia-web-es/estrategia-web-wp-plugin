// NIVEL: 2 — Playground WordPress. NOT LEVEL 1 BECAUSE: what is tested is the «Forms → Submissions» screens as WordPress serves them to a logged-in administrator — the list table over the real log, its filter, a submission's detail, the CSV that admin-post.php sends, and the nonces and capability WordPress checks before exporting or deleting. All of it is plain HTML, links and forms (no JavaScript: the row actions are CSS), read with a cookie session (tests/lib.mjs adminSession).
//
// Moved down from admin.test.mjs (level 3) on 28-10-2026: a browser added
// nothing there but ~100 s. The three submissions are seeded as log rows in
// the same request as the reset (seed() in lib.mjs: submitting them is
// server.test.mjs's), so the screens read a known log.
//
// Seen red (28-10-2026), every test: the list table built without the form
// filter (1); Admin::csv_cell() not neutralizing «=» (2), and, alone,
// check_admin_referer() removed from Admin::export_csv() (2: the export
// without a nonce answered 200); the confirmation screen deleting already (3).
// Seen red again after round 2 (28-10-2026), every test, with the mutants above (one by one where a test reads what the one before left).
import { test, before } from "node:test";
import assert from "node:assert/strict";
import { adminSession } from "../../../tests/lib.mjs";
import { seed, submissions, TEAM, FAIL } from "./lib.mjs";

const LIST = "/wp-admin/admin.php?page=estrategia-web-forms";
const decode = (s) => s.replace(/&#038;|&amp;/g, "&");
const rows = (html) => html.match(/<tbody id="the-list"[\s\S]*?<\/tbody>/)?.[0].match(/<tr\b/g)?.length ?? 0;
const contact = (nombre, email) => ({
  form_id: "contacto",
  form_title: "Contacto",
  status: "partial",
  data: [
    { name: "nombre", label: "Nombre", type: "text", value: nombre },
    { name: "email", label: "Correo", type: "email", value: email },
  ],
  notifications: [
    { label: "Aviso al equipo", to: TEAM, status: "sent" },
    { label: "Aviso que falla", to: FAIL, status: "failed", error: "Could not instantiate mail function." },
  ],
});

let s, ids, all, onlyQuote, onlyContact;
before(async () => {
  [{ submissions: ids }, s] = await Promise.all([
    seed({
      submissions: [
        contact("Ana", "ana@ejemplo.test"),
        contact("Bernat", "=cmd@ejemplo.test"),
        { form_id: "presupuesto", form_title: "Presupuesto", data: [{ name: "empresa", label: "Empresa", type: "text", value: "Acme" }] },
      ],
    }),
    adminSession(),
  ]);
  // Round 2 (28-10-2026): the detail is read at once with the lists, by its URL; the first test checks the list links to it.
  [all, onlyQuote, onlyContact, detail] = (await Promise.all([s.get(LIST), s.get(LIST + "&form_id=presupuesto"), s.get(LIST + "&form_id=contacto"), s.get(`${LIST}&action=view&id=${ids[0]}`)])).map((r) => r.text);
});
let detail, confirm, logAfterRefusals;

test("list, filter by form and a submission's detail with each notification's result", async () => {
  assert.equal(rows(all), 3);
  assert.match(all, /<select[^>]*id="af-filter-form"[\s\S]*?<option value="presupuesto"/, "the filter offers each form");
  assert.equal(rows(onlyQuote), 1);
  assert.match(onlyQuote.match(/<tbody id="the-list"[\s\S]*?<\/tbody>/)[0], /Acme/);
  assert.match(decode(onlyContact), new RegExp(`href="[^"]*page=estrategia-web-forms&action=view&id=${ids[0]}"`), "the list links to the detail");
  assert.match(detail.match(/<table[^>]*estrategia-web-forms-data[\s\S]*?<\/table>/)[0], /Ana/);
  const notes = detail.match(/<table[^>]*estrategia-web-forms-notifications[\s\S]*?<\/table>/)[0];
  assert.match(notes, /Aviso al equipo/);
  assert.match(notes, /fail@ejemplo\.test/);
});

test("CSV export: header, rows and dangerous cells neutralized; without a nonce, neither export nor delete", async () => {
  const exportUrl = decode(onlyContact.match(/<a class="page-title-action" href="([^"]+)"/)[1]);
  // At once, the next test's confirmation screen (a GET); then ONE read of the log for both: nothing deleted.
  const rowDelete = decode(onlyQuote.match(/<span class=['"]delete['"]><a href="([^"]+)"/)[1]);
  const [csvRes, noNonce, deleteNoNonce, confirmRes] = await Promise.all([
    s.get(exportUrl),
    s.get("/wp-admin/admin-post.php?action=estrategia_web_forms_export"),
    fetch(new URL("/wp-admin/admin-post.php", exportUrl), { method: "POST", redirect: "manual", headers: { Cookie: s.cookie(), "Content-Type": "application/x-www-form-urlencoded" }, body: new URLSearchParams({ action: "estrategia_web_forms_delete", "ids[]": String(ids[2]) }).toString() }),
    s.get(rowDelete),
  ]);
  confirm = confirmRes.text;
  logAfterRefusals = await submissions();
  const csv = csvRes.text.replace(/^﻿/, "");
  // A multi-line message is ONE quoted cell with line breaks inside:
  // records are counted by their id at the start of a line, not by lines.
  assert.equal(csv.match(/^\d+;/gm).length, 2, "2 submissions from «contacto», not the one from «presupuesto»");
  assert.match(csv.split("\n")[0], /^id;date_utc;form_id;form;page;language;status;notifications;ip;user_agent;Nombre;Correo/);
  assert.ok(csv.includes("'=cmd@ejemplo.test"), "a cell starting with = does not reach Excel as a formula");
  assert.ok(!csv.includes("Acme"));
  assert.notEqual(noNonce.status, 200, "no nonce, no CSV");
  assert.notEqual(deleteNoNonce.status, 302, "no nonce, no delete");
  assert.equal(logAfterRefusals.length, 3, "nothing was deleted");
});

test("delete asks for confirmation and only deletes on confirm", async () => {
  // The row action and the bulk action lead to the same confirmation screen (a GET that deletes nothing),
  // read in the test before, at once with the refused requests; the log read after all of them.
  assert.match(confirm, /cannot be undone|no se puede deshacer/i);
  assert.match(confirm, new RegExp(`<a class="button" href="[^"]*page=estrategia-web-forms"`), "Cancel goes back to the list");
  assert.equal(logAfterRefusals.length, 3, "reaching the confirmation does not delete");
  const done = await s.submit(confirm, "admin-post.php");
  assert.equal(done.status, 302);
  assert.match(done.location, /deleted=1/);
  const left = await submissions();
  assert.equal(left.length, 2);
  assert.ok(left.every((r) => r.form_id === "contacto"));
});
