// NIVEL: 2 — Playground WordPress. NOT LEVEL 1 BECAUSE: what is tested is the «Forms → Submissions» list as WordPress serves it to an administrator (2.8.0): its views with their counts, its sortable columns and its form dropdown, over the real log table — the SQL that sorts and counts runs in WordPress's database (SQLite here), which a pure test would not touch.
//
// Five submissions seeded as log rows (seed(), lib.mjs), in three forms and three notification
// states. The edge case by name: a submission whose form had NO title (form_title ''): the column
// shows its id, and sorting by form must sort by what is shown, not put it first.
//
// Seen red (02-11-2026), one break each: sorting by form_title alone (the untitled one came first:
// dan,ana,bea,eva,cai); the dropdown without the hidden fields (choosing a form dropped the view).
//
// How to maintain: a new view or sortable column of Submissions_Table gets its case here. The trap:
// rows inserted in the same request share created_at to the second, so the date order is proven
// by backdating one (/backdate), not by insertion order.
import { test, before } from "node:test";
import assert from "node:assert/strict";
import { adminSession } from "../../../tests/lib.mjs";
import { seed, testApi } from "./lib.mjs";

const LIST = "/wp-admin/admin.php?page=estrategia-web-forms";
const sub = (form_id, form_title, status, nombre) => ({ form_id, form_title, status, data: [{ name: "nombre", label: "Nombre", type: "text", value: nombre }] });
const SEEDS = {
  ana: sub("contacto", "Contacto", "failed", "Ana"),
  bea: sub("contacto", "Contacto", "sent", "Bea"),
  cai: sub("presupuesto", "Presupuesto", "partial", "Cai"),
  dan: sub("sin-titulo", "", "sent", "Dan"),
  eva: sub("contacto", "Contacto", "failed", "Eva"),
};
const ids = {};
const name = (id) => Object.keys(ids).find((k) => ids[k] === id);
const rows = (html) => [...(html.match(/<tbody id="the-list"[\s\S]*?<\/tbody>/)?.[0] ?? "").matchAll(/name="ids\[\]" value="(\d+)"/g)].map((m) => name(Number(m[1])));
/** The views as {status: count}; «all» for the first. */
const views = (html) =>
  Object.fromEntries([...(html.match(/<ul class='subsubsub'>[\s\S]*?<\/ul>/)?.[0] ?? "").matchAll(/href="([^"]*)"[^>]*>[^<]*<span class="count">\((\d+)\)/g)].map((m) => [m[1].match(/ew_status=([a-z]+)/)?.[1] ?? "all", Number(m[2])]));

let s;
before(async () => {
  let seeded;
  [seeded, s] = await Promise.all([seed({ submissions: Object.values(SEEDS) }), adminSession()]);
  Object.keys(SEEDS).forEach((k, i) => (ids[k] = seeded.submissions[i]));
  await testApi("/backdate", { method: "POST", body: { id: ids.bea, days: 3 } }); // The oldest.
});

test("views by notification state, with their counts; a state without submissions has no view", async () => {
  const { status, text } = await s.get(LIST);
  assert.equal(status, 200);
  assert.deepEqual(views(text), { all: 5, failed: 2, partial: 1, sent: 2 });
  assert.deepEqual(rows((await s.get(LIST + "&ew_status=failed")).text).sort(), ["ana", "eva"]);
});

test("the views count within the chosen form, and keep it", async () => {
  const { text } = await s.get(LIST + "&form_id=presupuesto");
  assert.deepEqual(views(text), { all: 1, partial: 1 });
  assert.match(text, /href="[^"]*form_id=presupuesto[^"]*ew_status=partial/);
});

test("sorting by form sorts by what the column shows: the untitled form by its id, not first", async () => {
  const asc = rows((await s.get(LIST + "&orderby=form&order=asc")).text);
  assert.deepEqual(asc.slice(3), ["cai", "dan"], asc.join(","));
  assert.deepEqual(asc.slice(0, 3).sort(), ["ana", "bea", "eva"]);
});

test("sorting by date: newest first by default, the backdated one last; the other way round with asc", async () => {
  const first = rows((await s.get(LIST)).text);
  assert.equal(first.at(-1), "bea", first.join(","));
  const asc = rows((await s.get(LIST + "&orderby=date&order=asc")).text);
  assert.equal(asc[0], "bea", asc.join(","));
});

test("the form dropdown keeps the view and the order it was in (the form is a GET)", async () => {
  const { text } = await s.get(LIST + "&ew_status=failed&orderby=form&order=asc");
  for (const [n, v] of [["ew_status", "failed"], ["orderby", "form"], ["order", "asc"]]) {
    assert.match(text, new RegExp(`<input type="hidden" name="${n}" value="${v}">`), n);
  }
});
