// NIVEL: 2 — Playground WordPress. NOT LEVEL 1 BECAUSE: what is tested is WordPress's own list screen
// (edit.php?post_type=eweb_banner) with the views, the zone dropdown and the sorting that
// \EstrategiaWeb\Admin\Admin_List declares (2.5.0); the state rule itself is level 1 (pure.test.mjs).
//
// Five banners cover the four states and the edge case by name: an OLD banner without the date
// fields (migrated before they existed) must count as «Showing» and must not vanish when the list
// is sorted by dates (the meta_key trap, header of includes/admin/class-admin-list.php).
// Seen red (02-11-2026), one break per case: the view counted 1 instead of 2 (the old banner left
// out); the expired view listed the draft too; sorting by dates dropped the old banner.
//
// How to maintain: a new view or filter gets its case here. The trap: the site reset does not
// delete banners, so before() and after() delete this test's own (title prefix «prueba-listado»).
import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { BASE, testApi, wp, adminSession } from "../../../tests/lib.mjs";

const day = 86400;
const now = Math.floor(Date.now() / 1000);
const ids = {};
let zones;

async function clean() {
  const { json } = await wp("/eweb-banners?per_page=100&status=any&search=prueba-listado&context=edit");
  for (const b of json || []) await wp(`/eweb-banners/${b.id}?force=true`, { method: "DELETE" });
  const { json: terms } = await wp("/eweb_banner_zone?per_page=100&search=prueba-listado");
  for (const t of terms || []) await wp(`/eweb_banner_zone/${t.id}?force=true`, { method: "DELETE" });
}

const views = (html) => Object.fromEntries([...html.matchAll(/ewb_state=([a-z]+)"[^>]*>[^<]*<span class="count">\((\d+)\)/g)].map((m) => [m[1], Number(m[2])]));
const rows = (html) => [...html.matchAll(/<tr id="post-(\d+)"/g)].map((m) => Number(m[1])).filter((id) => Object.values(ids).includes(id));
const name = (id) => Object.keys(ids).find((k) => ids[k] === id);

before(async () => {
  await testApi("/setup", { method: "POST", body: { modules: ["banners"] } });
  await clean();
  zones = {};
  for (const z of ["prueba-listado-a", "prueba-listado-b"]) {
    const { json } = await wp("/eweb_banner_zone", { method: "POST", body: { name: z, slug: z } });
    zones[z] = json.id;
  }
  const create = async (key, status, meta, zone) => {
    const { status: code, json } = await wp("/eweb-banners", {
      method: "POST",
      body: { title: "prueba-listado " + key, status, meta, eweb_banner_zone: [zones[zone]] },
    });
    assert.equal(code, 201, key + ": " + JSON.stringify(json));
    ids[key] = json.id;
  };
  await create("active", "publish", { estrategia_web_banner_start: now - 10 * day, estrategia_web_banner_end: now + 10 * day }, "prueba-listado-a");
  await create("scheduled", "publish", { estrategia_web_banner_start: now + 5 * day, estrategia_web_banner_end: now + 20 * day }, "prueba-listado-a");
  await create("expired", "publish", { estrategia_web_banner_start: now - 30 * day, estrategia_web_banner_end: now - 2 * day }, "prueba-listado-b");
  await create("old", "publish", {}, "prueba-listado-b"); // no date fields at all: «Showing», no limits.
  await create("draft", "draft", { estrategia_web_banner_start: now - 30 * day, estrategia_web_banner_end: now - 2 * day }, "prueba-listado-a");
});

after(clean);

test("the views count each state, the old banner without dates counts as showing", async () => {
  const s = await adminSession();
  const { status, text } = await s.get("/wp-admin/edit.php?post_type=eweb_banner");
  assert.equal(status, 200);
  assert.deepEqual(views(text), { active: 2, scheduled: 1, expired: 1 });
  assert.match(text, /class="ewb-state ewb-state--active"/);
});

test("each view lists only its banners (the expired draft is not «expired»: it is off)", async () => {
  const s = await adminSession();
  const got = {};
  for (const v of ["active", "scheduled", "expired"]) got[v] = rows((await s.get(`/wp-admin/edit.php?post_type=eweb_banner&ewb_state=${v}`)).text).map(name).sort();
  assert.deepEqual(got, { active: ["active", "old"], scheduled: ["scheduled"], expired: ["expired"] });
});

test("the zone dropdown is there and filters", async () => {
  const s = await adminSession();
  const { text } = await s.get("/wp-admin/edit.php?post_type=eweb_banner&eweb_banner_zone=prueba-listado-b");
  assert.match(text, /<select[^>]*name=['"]eweb_banner_zone['"]/); // wp_dropdown_categories writes single quotes.
  assert.match(text, /Todas las zonas/); // The label, not WordPress's «All Categories» (the test site is in es_ES).
  assert.deepEqual(rows(text).map(name).sort(), ["expired", "old"]);
});

test("sorting by state: the first click puts showing, scheduled, expired, off; the other way round on the second", async () => {
  const s = await adminSession();
  const desc = rows((await s.get("/wp-admin/edit.php?post_type=eweb_banner&orderby=ewb_state&order=desc")).text).map(name);
  const rank = { active: 3, old: 3, scheduled: 2, expired: 1, draft: 0 };
  assert.deepEqual(desc.map((k) => rank[k]), [3, 3, 2, 1, 0], desc.join(","));
  const asc = rows((await s.get("/wp-admin/edit.php?post_type=eweb_banner&orderby=ewb_state&order=asc")).text).map(name);
  assert.deepEqual(asc.map((k) => rank[k]), [0, 1, 2, 3, 3], asc.join(","));
});

test("sorting by views and by clicks: the totals of the stats table, banners without any included", async () => {
  // As a visitor would: the zone's view.js posts the ids it showed, and the id clicked.
  const hit = (body) => fetch(BASE + "/wp-json/estrategia-web/v1/banners/hits", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify(body) });
  for (let i = 0; i < 3; i++) await hit({ views: [ids.active] });
  await hit({ views: [ids.old, ids.expired] });
  await hit({ views: [ids.old] });
  await hit({ views: [], click: ids.expired });
  await hit({ views: [], click: ids.expired });
  await hit({ views: [], click: ids.old });
  const s = await adminSession();
  const byViews = rows((await s.get("/wp-admin/edit.php?post_type=eweb_banner&orderby=ewb_views&order=desc")).text).map(name);
  assert.equal(byViews.length, 5, byViews.join(","));
  assert.deepEqual(byViews.slice(0, 3), ["active", "old", "expired"], byViews.join(","));
  const byClicks = rows((await s.get("/wp-admin/edit.php?post_type=eweb_banner&orderby=ewb_clicks&order=desc")).text).map(name);
  assert.deepEqual(byClicks.slice(0, 2), ["expired", "old"], byClicks.join(","));
});

test("the state is STORED on create, and changing the dates through REST re-stores it (2.6.0)", async () => {
  const meta = async (id) => (await wp(`/eweb-banners/${id}?context=edit`)).json.meta.estrategia_web_banner_state;
  assert.deepEqual(
    { active: await meta(ids.active), scheduled: await meta(ids.scheduled), expired: await meta(ids.expired), old: await meta(ids.old), draft: await meta(ids.draft) },
    { active: "active", scheduled: "scheduled", expired: "expired", old: "active", draft: "off" }
  );
  // The trap of class-state.php: save_post runs before REST writes the meta; without rest_after_insert
  // this stayed «expired».
  const extra = (await wp("/eweb-banners", { method: "POST", body: { title: "prueba-listado extra", status: "publish", meta: { estrategia_web_banner_end: now - day } } })).json.id;
  assert.equal(await meta(extra), "expired");
  await wp(`/eweb-banners/${extra}`, { method: "POST", body: { meta: { estrategia_web_banner_end: now + day } } });
  assert.equal(await meta(extra), "active");
  await wp(`/eweb-banners/${extra}?force=true`, { method: "DELETE" });
});

test("sorting by dates keeps the banner without dates and orders by start", async () => {
  const s = await adminSession();
  const asc = rows((await s.get("/wp-admin/edit.php?post_type=eweb_banner&orderby=ewb_dates&order=asc")).text).map(name);
  assert.equal(asc.length, 5, asc.join(","));
  const at = (k) => asc.indexOf(k);
  // expired and draft start on the same day (30 days ago), then active (10 ago), then scheduled (in 5).
  assert.ok(at("expired") < at("active") && at("draft") < at("active") && at("active") < at("scheduled"), asc.join(","));
});
