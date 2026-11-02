// NIVEL: 2 — Playground WordPress. NOT LEVEL 1 BECAUSE: what is tested is the rules list of «Estrategia Web → Redirects» as WordPress serves it to an administrator (2.8.0): its views by type with their counts and the order by last hit, which are SQL over the module's table in WordPress's database (SQLite here), and the hits come from real requests that the module redirects.
//
// Five rules (imported in the same request as the reset): three 301 — one of them switched off —,
// a 302 and a 410. Two of them are visited. The edge case by name: a rule that NEVER fired
// (last_hit NULL, «—»): sorting by last hit puts it last in both directions.
//
// Seen red (02-11-2026), one break each: the inactive 301 left out of «301»; sorting by last hit
// without «(last_hit IS NULL)» (ascending: b,c,d,a,e, the never-hit rules first); the search form
// without the hidden ew_view (searching dropped the view).
//
// How to maintain: a new view or sortable column of Rules_Table gets its case here. The trap: two
// hits in the same second share last_hit, so this checks where the never-hit rules go, not the order
// between two visited ones.
import { test, before } from "node:test";
import assert from "node:assert/strict";
import { api, get } from "./lib.mjs";
import { adminSession } from "../../../tests/lib.mjs";

const PAGE = "/wp-admin/admin.php?page=estrategia-web-redirects";
const CSV = ["source,target,type,note,active", "/a-uno/,/x/,301,,1", "/b-dos/,/y/,302,,1", "/c-tres/,,410,,1", "/d-cuatro/,/z/,301,,0", "/e-cinco/,/x/,301,,1"].join("\n");
const key = (source) => source.split("-")[0].replace("/", "");
/** The rules' sources, in the order of the table, as a b c d e. */
const rows = (html) => [...(html.match(/<tbody id="the-list"[\s\S]*?<\/tbody>/)?.[0] ?? "").matchAll(/<code>(\/[a-e]-[a-z]+\/)<\/code><\/a>/g)].map((m) => key(m[1]));
const views = (html) =>
  Object.fromEntries([...(html.match(/<ul class='subsubsub'>[\s\S]*?<\/ul>/)?.[0] ?? "").matchAll(/href="([^"]*)"[^>]*>[^<]*<span class="count">\((\d+)\)/g)].map((m) => [m[1].match(/ew_view=([a-z0-9]+)/)?.[1] ?? "all", Number(m[2])]));

let s;
before(async () => {
  [, s] = await Promise.all([api("/reset", { method: "POST", body: { core: true, on: true } }), adminSession()]);
  const imported = await api("/import", { method: "POST", body: { csv: CSV } });
  assert.equal(imported.created, 5, JSON.stringify(imported));
  for (const p of ["/a-uno/", "/e-cinco/"]) assert.equal((await get(p)).status, 301, p);
});

test("the views: one per type and «Inactive», with their counts (the switched-off 301 is a 301 too)", async () => {
  const { status, text } = await s.get(PAGE);
  assert.equal(status, 200);
  assert.deepEqual(views(text), { all: 5, 301: 3, 302: 1, 410: 1, inactive: 1 });
  const got = {};
  for (const v of ["301", "410", "inactive"]) got[v] = rows((await s.get(`${PAGE}&ew_view=${v}`)).text).sort();
  assert.deepEqual(got, { 301: ["a", "d", "e"], 410: ["c"], inactive: ["d"] });
});

test("with a search, the views count within it, and the search form keeps the view", async () => {
  const { text } = await s.get(`${PAGE}&ew_view=301&s=uno`);
  assert.deepEqual(views(text), { all: 1, 301: 1 });
  assert.deepEqual(rows(text), ["a"]);
  assert.match(text, /<input type="hidden" name="ew_view" value="301" \/>/);
});

test("sorting by last hit: the rules that never fired go last in both directions", async () => {
  for (const order of ["desc", "asc"]) {
    const got = rows((await s.get(`${PAGE}&orderby=last_hit&order=${order}`)).text);
    assert.deepEqual(got.slice(0, 2).sort(), ["a", "e"], order + ": " + got.join(","));
    assert.deepEqual(got.slice(2), ["b", "c", "d"], order + ": " + got.join(","));
  }
});

test("the 404 log sorts by first seen too", async () => {
  const { text } = await s.get(`${PAGE}&view=log`);
  assert.match(text, /orderby=first_seen/);
});
