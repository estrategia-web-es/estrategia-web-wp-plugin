// NIVEL: 2 — Playground WordPress, no browser. NOT LEVEL 1 BECAUSE: the list is painted by PHP (a WP_List_Table) from what is stored and from the theme files, and the edit screen's frame too, so the HTML a logged-in administrator gets, and the REST call its page makes with the page's own nonce, are enough to follow list → edit → save → list and the stale-URL redirect — all of it needs WordPress serving the admin. What only exists painted by JS (tabs, rows, drag, keyboard) is level 3: constructor-editor.test.mjs.
//
// The site under test speaks es_ES; the texts new in 2.0.0 are not in the
// .po yet when this runs, so the test looks for the data-* hooks and the
// definitions' own words («Cursos»), never for translated labels.
//
// 28-10-2026, from ~49 s: the reset and the theme files in one request
// (estrategia-web-site-test/v1/setup) with the login alongside; the list's
// HTML and the page's nonce read once and reused; the three screens with no
// type or a stale one asked at once. It also took the level 3 check of
// editor-panel.test.mjs on the list (a theme file's row and its URL, the
// rejected files with their errors): that list is PHP, its HTML is all
// there is to see.
//
// Seen red (27-10-2026): before the two screens existed (the 1.7 page: one
// <div> painted by JS), «the list» failed on the missing wp-list-table; with
// Page::load() not hooked (the stale ?type= painted an empty editor),
// «a stale link to one type» got 200 instead of 302.
// Seen red (28-10-2026), one expected value changed in each reworked test
// (the theme file's URL /restaurants/, 4 fields, data-active="2",
// data-import="0"): all four red; restored, green. Round 2: the save
// without and with the nonce sent at once; seen red expecting 200 without
// the nonce; restored, green. It also took constructor-editor.test.mjs's
// «only once» (an added template disabled in «Add from template»: PHP);
// seen red expecting the client's entry disabled too.
import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { BASE, adminSession, credentials, testApi } from "../../../tests/lib.mjs";

const PAGE = "/wp-admin/admin.php?page=estrategia-web-content-types";
const ct = async (path, { method = "GET", body } = {}) => {
  const res = await fetch(BASE + "/wp-json/estrategia-web-ct-test/v1" + path, { method, headers: body ? { "Content-Type": "application/json" } : {}, body: body ? JSON.stringify(body) : undefined });
  if (!res.ok) throw new Error(`${method} ${path} -> ${res.status} ${await res.text()}`);
  return res.json();
};
/** The constructor's route with an application password (setup only). */
async function ew(path = "", { method = "GET", body } = {}) {
  const who = (await credentials()).administrator;
  const res = await fetch(BASE + "/wp-json/estrategia-web/v1/content-types" + path, {
    method,
    headers: { Authorization: "Basic " + Buffer.from(`${who.user}:${who.password}`).toString("base64"), ...(body ? { "Content-Type": "application/json" } : {}) },
    body: body ? JSON.stringify(body) : undefined,
  });
  return { status: res.status, json: await res.json().catch(() => null) };
}
// As a browser reads an attribute: esc_url() writes «&» as &#038;.
const decode = (s) => s.replace(/&#0?38;/g, "&").replace(/&amp;/g, "&").replace(/&quot;/g, '"');
const row = (html, name) => {
  const m = html.match(new RegExp(`<tr data-type="${name}"[\\s\\S]*?</tr>`));
  assert.ok(m, `no row for ${name}`);
  return decode(m[0]);
};
const cell = (tr, column) => {
  const m = tr.match(new RegExp(`class='[^']*column-${column}[^']*'[^>]*>([\\s\\S]*?)</td>`));
  assert.ok(m, `no ${column} cell`);
  return m[1].trim();
};
const nonceOf = (html) => html.match(/createNonceMiddleware\(\s*"([0-9a-f]+)"\s*\)/)?.[1];

const course = () => ({
  prefix: "gment_",
  name: "gment_course",
  slug: "cursos",
  labels: { singular: { es: "Curso" }, plural: { es: "Cursos" } },
  fields: [
    { name: "gment_hours", type: "number", label: { es: "Horas" } },
    { name: "gment_teacher", type: "text", label: { es: "Profesor" } },
  ],
});

let s, list, nonce;
before(async () => {
  // reset() and the theme files on (gment_restaurant & co.: the option the ct-test setup route sets), one request; content-types is on by default.
  [, s] = await Promise.all([testApi("/setup", { method: "POST", body: { options: { estrategia_web_ct_test: 1 } } }), adminSession(), credentials()]);
  // The course, and the testimonial template added (its entry in «Add from template» is then disabled).
  const [made, tpl] = await Promise.all([ew("", { method: "POST", body: { definition: course() } }), ew("/templates/testimonial", { method: "POST" })]);
  assert.equal(made.status, 201, JSON.stringify(made.json));
  assert.equal(tpl.status, 201, JSON.stringify(tpl.json));
});
// What reset() does not undo: the theme files switched on.
after(() => ct("/setup", { method: "POST", body: { on: false } }));

test("the list: a WordPress list table, one row per type with its field count and row actions; the theme files read-only, the rejected ones with their errors; the page-title actions", async () => {
  const r = await s.get(PAGE);
  assert.equal(r.status, 200);
  list = decode(r.text);
  assert.match(list, /data-view="list"/, "the single page's URL is the list");
  assert.match(list, /<table class="wp-list-table widefat fixed striped/);
  const tr = row(list, "gment_course");
  assert.match(cell(tr, "title"), /Cursos/);
  assert.equal(cell(tr, "fields"), "2");
  assert.match(cell(tr, "url"), /\/cursos\//);
  assert.match(tr, /data-action="edit" href|href="[^"]*page=estrategia-web-content-types&type=gment_course"[^>]*data-action="edit"/);
  assert.match(tr, /data-action="toggle" data-active="1"/);
  assert.match(tr, /data-action="export"/);
  assert.match(tr, /data-action="delete"/);
  // A type from a theme file: its URL in the site language (es: the example's slug per language); copied to the panel, never edited, switched off or deleted here.
  const file = row(list, "gment_restaurant");
  assert.match(file, /data-source="file"/);
  assert.match(cell(file, "url"), /\/restaurantes\//);
  assert.match(file, /data-action="copy"/);
  assert.doesNotMatch(file, /data-action="(edit|toggle|delete)"/);
  // «Add new», «Add from template» (a button with its menu of templates) and «Import JSON».
  assert.match(list, /href="[^"]*page=estrategia-web-content-types&new=1" class="page-title-action" data-action="add"/);
  assert.match(list, /<button type="button" class="page-title-action[^"]*" aria-expanded="false" aria-controls="ewct-templates" data-action="templates"/);
  assert.match(list, /<div id="ewct-templates"[^>]*hidden>[\s\S]*data-template="testimonial"/);
  assert.match(list, /data-template="testimonial"[^>]* disabled aria-describedby="ewct-tpl-testimonial"/, "a template already added: only once");
  assert.doesNotMatch(list, /data-template="client"[^>]* disabled/, "one not added yet: offered");
  assert.match(list, /&new=1&import=1" class="page-title-action" data-action="import"/);
  // The files with errors are still listed, with their errors.
  assert.match(list, /data-file="gment_not_json.json"/);
  assert.match(list.match(/data-file="gment_too_long.json"[\s\S]*?<\/div>/)?.[0] ?? "", /gment_restaurant_menu/);
});

test("list → edit → save (as the page does: cookie + its own REST nonce) → the list shows the change", async () => {
  const href = row(list, "gment_course").match(/href="([^"]*)" data-action="edit"/)[1];
  assert.match(href, /&type=gment_course$/);
  const edit = await s.get(href);
  assert.equal(edit.status, 200);
  assert.match(edit.text, /<div id="estrategia-web-constructor" data-view="edit" data-type="gment_course"/);
  assert.match(edit.text, /<h1[^>]*id="ewct-title">[^<]*Cursos/);
  assert.match(edit.text, /window\.estrategiaWebConstructor = \{"route":"\\\/estrategia-web\\\/v1\\\/content-types"/);
  assert.match(edit.text, /constructor-model\.js/, "the pure part is enqueued before the screen's script");
  nonce = nonceOf(edit.text);
  assert.ok(nonce, "the page carries the wp_rest nonce of wp.apiFetch");

  const def = course();
  def.labels.plural.es = "Cursos y talleres";
  def.fields.push({ name: "gment_level", type: "select", label: { es: "Nivel" }, options: ["a", "b"], group: "extra" });
  def.fields_box = "main";
  def.groups = [{ key: "extra", label: { es: "Más datos" }, collapsed: true }];
  const send = (headers) =>
    fetch(BASE + "/wp-json/estrategia-web/v1/content-types/gment_course", { method: "POST", headers: { Cookie: s.cookie(), "Content-Type": "application/json", ...headers }, body: JSON.stringify({ definition: def, confirm: false }) });
  const [refused, saved] = await Promise.all([send({}), send({ "X-WP-Nonce": nonce })]);
  assert.notEqual(refused.status, 200, "without the nonce the cookie is not enough");
  assert.equal(saved.status, 200, await saved.clone().text());

  const [raw, after] = await Promise.all([ct("/constructor-raw?name=gment_course"), s.get(PAGE)]);
  const stored = raw.row.definition;
  assert.equal(stored.fields_box, "main");
  assert.deepEqual(stored.groups, [{ key: "extra", label: { es: "Más datos" }, collapsed: true }]);
  assert.equal(stored.fields[2].group, "extra");
  const tr = row(decode(after.text), "gment_course");
  assert.match(cell(tr, "title"), /Cursos y talleres/);
  assert.equal(cell(tr, "fields"), "3");
});

test("deactivated from the row's action (its REST call): the row offers Activate and says it is inactive", async () => {
  const off = await fetch(BASE + "/wp-json/estrategia-web/v1/content-types/gment_course", { method: "POST", headers: { Cookie: s.cookie(), "X-WP-Nonce": nonce, "Content-Type": "application/json" }, body: JSON.stringify({ active: false }) });
  assert.equal(off.status, 200);
  const r = await s.get(PAGE + "&ewct_done=deactivated&ewct_name=gment_course");
  assert.match(r.text, /data-done="deactivated"[^>]*><p>[^<]*gment_course/, "the notice the list reloads with");
  assert.match(row(decode(r.text), "gment_course"), /data-action="toggle" data-active="0"/);
});

test("the edit screen with no type (new, import), a type from a file, and a stale ?type= that goes to the list with a notice", async () => {
  const [fresh, stale, file] = await Promise.all([s.get(PAGE + "&new=1&import=1"), s.get(PAGE + "&type=gment_nope"), s.get(PAGE + "&type=gment_restaurant")]);
  assert.equal(fresh.status, 200);
  assert.match(fresh.text, /data-view="edit" data-type="" data-import="1"/);
  assert.equal(file.status, 200, "a type from a file has an edit screen (copy to this panel)");
  assert.equal(stale.status, 302);
  assert.match(decode(stale.location), /page=estrategia-web-content-types&ewct_done=not-found&ewct_name=gment_nope$/);
  const landed = await s.get(stale.location);
  assert.equal(landed.status, 200);
  assert.match(landed.text, /notice-warning[^>]*data-done="not-found"[^>]*><p>[^<]*gment_nope/);
});
