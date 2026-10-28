// NIVEL: 2 — Playground WordPress, no browser: the list is painted by PHP (a WP_List_Table) and the edit screen's frame too, so the HTML a logged-in administrator gets, and the REST call its page makes with the page's own nonce, are enough to follow list → edit → save → list and the stale-URL redirect. What only exists painted by JS (tabs, rows, drag, keyboard) is level 3: constructor-editor.test.mjs.
//
// The site under test speaks es_ES; the texts new in 2.0.0 are not in the
// .po yet when this runs, so the test looks for the data-* hooks and the
// definitions' own words («Cursos»), never for translated labels.
//
// Seen red (27-10-2026): before the two screens existed (the 1.7 page: one
// <div> painted by JS), «the list» failed on the missing wp-list-table; with
// Page::load() not hooked (the stale ?type= painted an empty editor),
// «a stale link to one type» got 200 instead of 302.
import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { BASE, adminSession, credentials, reset, saveCompany, testApi } from "../../../tests/lib.mjs";

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

let s;
before(async () => {
  await reset();
  const { loaded } = await testApi("/modules");
  await testApi("/modules", { method: "POST", body: { active: [...new Set([...loaded, "content-types"])] } });
  await saveCompany({ enabled_types: [] });
  await ct("/constructor-reset", { method: "POST" });
  await ct("/setup", { method: "POST", body: { on: true } }); // the theme files: gment_restaurant & co.
  const made = await ew("", { method: "POST", body: { definition: course() } });
  assert.equal(made.status, 201, JSON.stringify(made.json));
  s = await adminSession();
});
after(async () => {
  await ct("/setup", { method: "POST", body: { on: false } });
  await ct("/constructor-reset", { method: "POST" });
  await reset();
});

test("the list: a WordPress list table, one row per type with its field count and row actions; the page-title actions", async () => {
  const r = await s.get(PAGE);
  assert.equal(r.status, 200);
  const html = decode(r.text);
  assert.match(html, /<table class="wp-list-table widefat fixed striped/);
  const tr = row(html, "gment_course");
  assert.match(cell(tr, "title"), /Cursos/);
  assert.equal(cell(tr, "fields"), "2");
  assert.match(cell(tr, "url"), /\/cursos\//);
  assert.match(tr, /data-action="edit" href|href="[^"]*page=estrategia-web-content-types&type=gment_course"[^>]*data-action="edit"/);
  assert.match(tr, /data-action="toggle" data-active="1"/);
  assert.match(tr, /data-action="export"/);
  assert.match(tr, /data-action="delete"/);
  // A type from a theme file: copied to the panel, never switched off or deleted here.
  const file = row(html, "gment_restaurant");
  assert.match(file, /data-source="file"/);
  assert.match(file, /data-action="copy"/);
  assert.doesNotMatch(file, /data-action="(toggle|delete)"/);
  // «Add new», «Add from template» (a button with its menu of templates) and «Import JSON».
  assert.match(html, /href="[^"]*page=estrategia-web-content-types&new=1" class="page-title-action" data-action="add"/);
  assert.match(html, /<button type="button" class="page-title-action[^"]*" aria-expanded="false" aria-controls="ewct-templates" data-action="templates"/);
  assert.match(html, /<div id="ewct-templates"[^>]*hidden>[\s\S]*data-template="testimonial"/);
  assert.match(html, /&new=1&import=1" class="page-title-action" data-action="import"/);
  assert.match(html, /data-file="gment_not_json.json"/, "the files with errors are still listed");
});

test("list → edit → save (as the page does: cookie + its own REST nonce) → the list shows the change", async () => {
  const list = decode((await s.get(PAGE)).text);
  const href = row(list, "gment_course").match(/href="([^"]*)" data-action="edit"/)[1];
  assert.match(href, /&type=gment_course$/);
  const edit = await s.get(href);
  assert.equal(edit.status, 200);
  assert.match(edit.text, /<div id="estrategia-web-constructor" data-view="edit" data-type="gment_course"/);
  assert.match(edit.text, /<h1[^>]*id="ewct-title">[^<]*Cursos/);
  assert.match(edit.text, /window\.estrategiaWebConstructor = \{"route":"\\\/estrategia-web\\\/v1\\\/content-types"/);
  assert.match(edit.text, /constructor-model\.js/, "the pure part is enqueued before the screen's script");
  const nonce = edit.text.match(/createNonceMiddleware\(\s*"([0-9a-f]+)"\s*\)/)?.[1];
  assert.ok(nonce, "the page carries the wp_rest nonce of wp.apiFetch");

  const def = course();
  def.labels.plural.es = "Cursos y talleres";
  def.fields.push({ name: "gment_level", type: "select", label: { es: "Nivel" }, options: ["a", "b"], group: "extra" });
  def.fields_box = "main";
  def.groups = [{ key: "extra", label: { es: "Más datos" }, collapsed: true }];
  const send = (headers) =>
    fetch(BASE + "/wp-json/estrategia-web/v1/content-types/gment_course", { method: "POST", headers: { Cookie: s.cookie(), "Content-Type": "application/json", ...headers }, body: JSON.stringify({ definition: def, confirm: false }) });
  assert.notEqual((await send({})).status, 200, "without the nonce the cookie is not enough");
  const saved = await send({ "X-WP-Nonce": nonce });
  assert.equal(saved.status, 200, await saved.clone().text());

  const stored = (await ct("/constructor-raw?name=gment_course")).row.definition;
  assert.equal(stored.fields_box, "main");
  assert.deepEqual(stored.groups, [{ key: "extra", label: { es: "Más datos" }, collapsed: true }]);
  assert.equal(stored.fields[2].group, "extra");

  const tr = row(decode((await s.get(PAGE)).text), "gment_course");
  assert.match(cell(tr, "title"), /Cursos y talleres/);
  assert.equal(cell(tr, "fields"), "3");
});

test("deactivated from the row's action (its REST call): the row offers Activate and says it is inactive", async () => {
  const nonce = (await s.get(PAGE)).text.match(/createNonceMiddleware\(\s*"([0-9a-f]+)"\s*\)/)[1];
  const off = await fetch(BASE + "/wp-json/estrategia-web/v1/content-types/gment_course", { method: "POST", headers: { Cookie: s.cookie(), "X-WP-Nonce": nonce, "Content-Type": "application/json" }, body: JSON.stringify({ active: false }) });
  assert.equal(off.status, 200);
  const r = await s.get(PAGE + "&ewct_done=deactivated&ewct_name=gment_course");
  assert.match(r.text, /data-done="deactivated"[^>]*><p>[^<]*gment_course/, "the notice the list reloads with");
  assert.match(row(decode(r.text), "gment_course"), /data-action="toggle" data-active="0"/);
});

test("a new type, and an import: the edit screen with no type", async () => {
  const r = await s.get(PAGE + "&new=1&import=1");
  assert.equal(r.status, 200);
  assert.match(r.text, /data-view="edit" data-type="" data-import="1"/);
});

test("old and stale URLs: the single page's URL is the list; a ?type= that is not there goes to the list with a notice", async () => {
  const old = await s.get(PAGE);
  assert.equal(old.status, 200);
  assert.match(old.text, /data-view="list"/);
  const stale = await s.get(PAGE + "&type=gment_nope");
  assert.equal(stale.status, 302);
  assert.match(decode(stale.location), /page=estrategia-web-content-types&ewct_done=not-found&ewct_name=gment_nope$/);
  const landed = await s.get(stale.location);
  assert.equal(landed.status, 200);
  assert.match(landed.text, /notice-warning[^>]*data-done="not-found"[^>]*><p>[^<]*gment_nope/);
  assert.equal((await s.get(PAGE + "&type=gment_restaurant")).status, 200, "a type from a file has an edit screen (copy to this panel)");
});
