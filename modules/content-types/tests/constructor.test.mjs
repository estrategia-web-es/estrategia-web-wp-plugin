// NIVEL: 2 — Playground WordPress. NOT LEVEL 1 BECAUSE: what is tested is what the constructor's REST route WRITES and what WordPress does with it on the next request — definitions stored as posts (slashes, kses, revisions), registered with their meta, the protections that count real entries, the 301s written into the redirects module's table and served. The validator, Changes::check() (what is refused or warned, which URLs move) and redirect_paths() are level 1: constructor-pure.test.mjs. The templates, the field-type sanitizers, a stored definition beating a theme file and the 1.4.x migration: constructor-templates.test.mjs.
//
// The site under test speaks es_ES (tests/wp/estrategia-web-site-test.php).
// It starts with estrategia-web-ct-test/v1/constructor-reset (no
// definitions, no gment_ posts) and the redirects module's reset, and ends
// (after()) with the redirects it created deleted, so the other modules'
// tests never see them.
//
// HOW IT IS FAST (28-10-2026; 144 s and ~85 requests before, ~37 after round
// 1, ~19 now): the REST calls go through batch.mjs — several calls in ONE
// request — and a batch ends exactly where WordPress has to boot again to
// see what was written (a type registered, its meta, the modules list: the
// trap in tests/wp/estrategia-web-content-types-test.php, /batch). And two
// independent tracks run at once (the test WordPress answers two requests at
// a time): gment_course and gment_workshop. The export/import test is the
// round-trip test (GET is the export); «empty, it can be deleted» is the
// workshop deleted there; the 409 that asks for confirmation is proven once
// (protections) and the URL test confirms directly. What a batch skips is
// core's HTTP authentication; that only an administrator writes is still
// checked (the author's call, as the author).
//
// ISOLATION (28-10-2026). «a URL that moves» failed on the SECOND run against
// the same WordPress: {"created":0,"skipped":["/courses/ (Ya hay una
// redirección para este origen.)", …]}. Root cause: the 301s the constructor
// writes live in the redirects module's table, and neither the core reset()
// nor constructor-reset empties it, so the first run's rules were still
// there and the second run's were refused as duplicates. Now before() and
// after() call estrategia-web-redirects-test/v1/reset. Seen red (28-10-2026)
// by running the file twice on one WordPress before the fix; green twice in
// a row after it.
//
// Seen red (17-10-2026): with Store::save() not slashing, «a definition
// round-trips» failed (the \u escapes and the backslash were eaten); with
// Rest::delete() not counting entries, «deleting a type with entries» got
// 200; with the redirects step skipped, «a URL that moves» had no 301. And
// for real, the first run: a second type declaring the SAME taxonomy as a
// stored one was saved (201) — Rest::write() put the new definition first
// for Schema::check_all(), which lets the first one keep a name, so the
// error went to the stored one. Now the new one goes last.
// Seen red (28-10-2026, round 2, batched), each test with one expected value
// changed (the es label, the teacher that comes back, the entry count, the
// imported definition, the clashing taxonomy, the 301's target): all six
// red; restored, green.
import { test, describe, before, after } from "node:test";
import assert from "node:assert/strict";
import { BASE, credentials } from "../../../tests/lib.mjs";
import { batch } from "./batch.mjs";

const call = async (base, route, { method = "GET", body } = {}) => {
  const res = await fetch(BASE + base + route, { method, headers: body ? { "Content-Type": "application/json" } : {}, body: body ? JSON.stringify(body) : undefined });
  if (!res.ok) throw new Error(`${method} ${route} -> ${res.status} ${await res.text()}`);
  return res.json();
};
const ct = (route, opts) => call("/wp-json/estrategia-web-ct-test/v1", route, opts);
const redirects = (route, opts) => call("/wp-json/estrategia-web-redirects-test/v1", route, opts);
// The calls of a batch (batch.mjs).
const CT = "/estrategia-web/v1/content-types";
const save = (definition, extra = {}, path = "") => ({ method: "POST", path: CT + path, body: { definition, ...extra } });
const raw = (name, extra = "") => ({ path: `/estrategia-web-ct-test/v1/constructor-raw?name=${name}${extra}` });

const course = () => ({
  prefix: "gment_",
  name: "gment_course",
  slug: "courses",
  labels: { singular: { es: "Curso", en: "Course" }, plural: { es: "Cursos", en: "Courses" } },
  icon: "dashicons-welcome-learn-more",
  supports: ["title", "editor"],
  taxonomies: [{ name: "gment_level", labels: { singular: "Nivel", plural: "Niveles" } }],
  fields: [
    { name: "gment_hours", type: "number", label: { es: "Horas" }, integer: true },
    { name: "gment_teacher", type: "text", label: { es: "Profesor" } },
  ],
});
const workshop = (slug = "workshops") => ({
  ...course(),
  name: "gment_workshop",
  slug,
  taxonomies: [{ name: "gment_topic", labels: { singular: "Tema", plural: "Temas" } }],
  description: 'Taller «práctico» de 2\\3 días <b>& más</b> "comillas"',
});

before(async () => {
  // reset() (content-types is on by default), the gment_ posts and the redirects table emptied, at once;
  // the redirects module switched on after reset(), which empties the modules list.
  // ONE request (batch.mjs): the redirects module is switched on after reset() empties the modules list, and is
  // loaded from the next request. The test users (for the author's call) alongside.
  await Promise.all([
    batch(
      { method: "POST", path: "/estrategia-web-site-test/v1/reset" },
      { method: "POST", path: "/estrategia-web-ct-test/v1/constructor-reset" },
      { method: "POST", path: "/estrategia-web-redirects-test/v1/reset" },
      { method: "POST", path: "/estrategia-web-redirects-test/v1/setup", body: { on: true } }
    ),
    credentials(),
  ]);
});
// What reset() does not undo: the 301s in the redirects module's table (ISOLATION).
after(() => redirects("/reset", { method: "POST" }));

describe("two tracks at once", { concurrency: true }, () => {
  describe("gment_course", { concurrency: 1 }, () => {
    let entry;

    test("only administrators write; a definition created through REST is stored and registered on the next request, with its taxonomy", async () => {
      const [author, created] = await batch({ ...save(course()), as: "author" }, save(course()));
      assert.equal(author.status, 403, "the author, logged in: forbidden");
      assert.equal(created.status, 201, created.text);
      assert.equal(created.json.source, "database");
      assert.equal(created.json.active, true);
      // The next request: registered. Its first entry goes in the same batch, for «protections».
      let type;
      [type, entry] = await batch({ path: "/wp/v2/types/gment_course" }, { method: "POST", path: "/wp/v2/gment_course", body: { title: "Curso de pan", status: "publish", meta: { gment_teacher: "Joan", gment_hours: 20 } } });
      assert.equal(type.status, 200, "registered from the database");
      assert.equal(type.json.name, "Cursos", "the es label");
      assert.ok(type.json.taxonomies.includes("gment_level"));
      assert.equal(entry.status, 201, entry.text);
    });

    test("protections: the internal name is fixed once there are entries; a removed field keeps its data and comes back", async () => {
      const renamed = course();
      renamed.name = "gment_class";
      const fewer = course();
      fewer.fields = fewer.fields.filter((f) => f.name !== "gment_teacher");
      const [r, ask, before, done] = await batch(save(renamed, { confirm: true }, "/gment_course"), save(fewer, {}, "/gment_course"), raw("gment_course"), save(fewer, { confirm: true }, "/gment_course"));
      assert.equal(r.status, 400, r.text);
      assert.match(r.json.data.errors.join(" "), /gment_course/);
      assert.equal(ask.status, 409, "a warning needs confirm");
      assert.equal(ask.json.code, "estrategia_web_ct_confirm");
      assert.match(ask.json.data.warnings.join(" "), /gment_teacher/);
      assert.equal(before.json.row.definition.fields.length, 2, "nothing written before confirming");
      assert.equal(done.status, 200, done.text);
      // The next request registers the field list without gment_teacher.
      const [hidden, back] = await batch({ path: `/wp/v2/gment_course/${entry.json.id}?context=edit` }, save(course(), { confirm: true }, "/gment_course"));
      assert.equal(hidden.json.meta.gment_teacher, undefined, "no longer in REST (the value stays in the database: it comes back below)");
      assert.equal(back.status, 200, back.text);
      const [again, revisions] = await batch({ path: `/wp/v2/gment_course/${entry.json.id}?context=edit` }, raw("gment_course"));
      assert.equal(again.json.meta.gment_teacher, "Joan", "added back with the same name: it is there again");
      assert.ok(revisions.json.revisions >= 2, "every save keeps a revision of the JSON");
    });

    test("deleting a type with entries is refused; switching it off keeps its entries and unregisters it", async () => {
      // «Empty, it can be deleted»: the workshop, in «round-trips».
      const [del, off] = await batch({ method: "DELETE", path: CT + "/gment_course" }, { method: "POST", path: CT + "/gment_course", body: { active: false } });
      assert.equal(del.status, 409, del.text);
      assert.equal(del.json.code, "estrategia_web_ct_has_entries");
      assert.equal(off.json.active, false);
      const [type, stored] = await batch({ path: "/wp/v2/types/gment_course" }, raw("gment_course"));
      assert.equal(type.status, 404, "switched off: not registered");
      assert.equal(stored.json.entries, 1, "its entry is still there");
    });
  });

  describe("gment_workshop", { concurrency: 1 }, () => {
    test("a definition round-trips exactly (accents, quotes, a backslash, < and &: slashes and kses); GET is the export and POST takes it back; empty, it is deleted", async () => {
      const def = workshop();
      const [r, back, stored, del, imported] = await batch(save(def), { path: CT + "/gment_workshop" }, raw("gment_workshop"), { method: "DELETE", path: CT + "/gment_workshop" }, save({ $schema: "../content-types.schema.json", ...def }));
      assert.equal(r.status, 201, r.text);
      assert.deepEqual(back.json.definition, def);
      assert.ok(!stored.json.row.json.includes("<b>"), "nothing that kses could take for a tag is stored raw");
      assert.equal(del.status, 200, "no entries: it can be deleted");
      assert.equal(imported.status, 201, imported.text);
      assert.deepEqual(imported.json.definition, def, "the same definition (the $schema of a file is dropped)");
    });

    test("errors: 400 with the validator's list and nothing written; a duplicate is 409; a taxonomy already stored is 400", async () => {
      const bad = workshop();
      bad.name = "gment_bad";
      bad.taxonomies = [];
      bad.fields[0].requird = true;
      const clash = workshop("workshops2");
      clash.name = "gment_workshop2";
      clash.fields = [];
      const [r, dup, r2, nothing] = await batch(save(bad), save(workshop()), save(clash), raw("gment_bad"));
      assert.equal(r.status, 400);
      assert.match(r.json.data.errors.join(" | "), /requird/);
      assert.equal(dup.status, 409, "gment_workshop already exists");
      assert.equal(r2.status, 400, "the same taxonomy declared twice (check_all against what is stored)");
      assert.match(r2.json.data.errors.join(" "), /gment_topic/);
      assert.equal(nothing.json.row, null, "nothing stored");
    });

    test("a URL that moves: 301s in the redirects module, and the rules of the new one; with that module off, the warning says so", async () => {
      // That it is warned first (409) is Changes::check(), level 1; the 409 itself, «protections».
      // The entry is published and the slug moved in the same request: the old registration builds the old URLs (Rest's «The trap»).
      const [entry, done] = await batch({ method: "POST", path: "/wp/v2/gment_workshop", body: { title: "Taller de pan", status: "publish" } }, save(workshop("talleres"), { confirm: true }, "/gment_workshop"));
      assert.equal(entry.status, 201, entry.text);
      assert.equal(done.status, 200, done.text);
      assert.ok(done.json.redirects.created >= 2, "the archive and the post: " + JSON.stringify(done.json.redirects));
      const [old, now] = await Promise.all([fetch(BASE + "/workshops/taller-de-pan/", { redirect: "manual" }), fetch(BASE + "/talleres/taller-de-pan/")]);
      assert.equal(old.status, 301);
      assert.match(old.headers.get("location"), /\/talleres\/taller-de-pan\/$/);
      assert.equal(now.status, 200, "registered with the new slug and the rules flushed");
      await redirects("/setup", { method: "POST", body: { on: false } }); // The modules are loaded at boot: the warning comes in the next request.
      const [warn] = await batch(save(workshop(), {}, "/gment_workshop"));
      assert.equal(warn.status, 409);
      assert.match(warn.json.data.warnings.join(" "), /Redirecciones|Redirects/);
    });
  });
});
