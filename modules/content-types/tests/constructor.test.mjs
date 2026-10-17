// NIVEL: 2 — Playground WordPress, because it WRITES: definitions stored in the database through the constructor's REST route, registered on the next request, the templates, the protections when a definition changes (entries, removed fields, URLs that move and their 301s), switching off and deleting, export / import, revisions, and the three new field types' sanitizers.
//
// The site under test speaks es_ES (tests/wp/estrategia-web-site-test.php).
// It starts and ends with estrategia-web-ct-test/v1/constructor-reset (no
// definitions, no gment_ posts) and with modules/site's types switched off,
// so the other modules' tests never see what is created here.
//
// Seen red (17-10-2026): with Store::save() not slashing, «a definition
// round-trips» failed (the \u escapes and the backslash were eaten); with
// Rest::delete() not counting entries, «deleting a type with entries» got
// 200; with the redirects step skipped, «a URL that moves» had no 301. And
// for real, the first run: a second type declaring the SAME taxonomy as a
// stored one was saved (201) — Rest::write() put the new definition first
// for Schema::check_all(), which lets the first one keep a name, so the
// error went to the stored one. Now the new one goes last.
import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { BASE, credentials, reset, saveCompany, testApi, wp } from "../../../tests/lib.mjs";

const ct = async (path, { method = "GET", body } = {}) => {
  const res = await fetch(BASE + "/wp-json/estrategia-web-ct-test/v1" + path, { method, headers: body ? { "Content-Type": "application/json" } : {}, body: body ? JSON.stringify(body) : undefined });
  if (!res.ok) throw new Error(`${method} ${path} -> ${res.status} ${await res.text()}`);
  return res.json();
};
/** The constructor's route, as the administrator. Returns { status, json }. */
async function ew(path = "", { method = "GET", body, as = "administrator" } = {}) {
  const who = (await credentials())[as];
  const res = await fetch(BASE + "/wp-json/estrategia-web/v1/content-types" + path, {
    method,
    headers: { Authorization: "Basic " + Buffer.from(`${who.user}:${who.password}`).toString("base64"), ...(body ? { "Content-Type": "application/json" } : {}) },
    body: body ? JSON.stringify(body) : undefined,
  });
  const text = await res.text();
  let json = null;
  try {
    json = JSON.parse(text);
  } catch {}
  return { status: res.status, json, text };
}
const raw = (name, extra = "") => ct(`/constructor-raw?name=${name}${extra}`);

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

before(async () => {
  await reset();
  const { loaded } = await testApi("/modules");
  await testApi("/modules", { method: "POST", body: { active: [...new Set([...loaded, "content-types", "redirects"])] } });
  await saveCompany({ enabled_types: [] });
  await ct("/constructor-reset", { method: "POST" });
});
after(async () => {
  await ct("/constructor-reset", { method: "POST" });
  await reset();
});

test("only administrators: an author gets 403", async () => {
  assert.equal((await ew("", { as: "author" })).status, 403);
  assert.equal((await ew("", { as: "author", method: "POST", body: { definition: course() } })).status, 403);
});

test("a definition created through REST is stored and registered on the next request, with its fields and taxonomy", async () => {
  const created = await ew("", { method: "POST", body: { definition: course() } });
  assert.equal(created.status, 201, created.text);
  assert.equal(created.json.source, "database");
  assert.equal(created.json.active, true);
  const type = await wp("/types/gment_course");
  assert.equal(type.status, 200, "registered from the database");
  assert.equal(type.json.name, "Cursos", "the es label");
  assert.ok(type.json.taxonomies.includes("gment_level"));
  const state = await ct("/state");
  assert.equal(state.meta.gment_course.gment_hours.type, "integer");
  const list = await ew();
  assert.ok(list.json.types.some((t) => t.name === "gment_course" && t.source === "database"));
  assert.deepEqual(list.json.languages, ["es"], "the site language (no WPML here)");
  assert.equal((await raw("gment_course", "&slug=courses")).rules.length > 0, true, "rewrite rules for /courses/ after the flush");
});

test("a definition round-trips exactly: accents, quotes, a backslash, < and & (slashes and kses)", async () => {
  const def = course();
  def.name = "gment_workshop";
  def.slug = "workshops";
  def.taxonomies = [];
  def.description = 'Taller «práctico» de 2\\3 días <b>& más</b> "comillas"';
  const r = await ew("", { method: "POST", body: { definition: def } });
  assert.equal(r.status, 201, r.text);
  const back = await ew("/gment_workshop");
  assert.equal(back.json.definition.description, def.description);
  assert.deepEqual(back.json.definition, def);
  const stored = (await raw("gment_workshop")).row.json;
  assert.ok(!stored.includes("<b>"), "nothing that kses could take for a tag is stored raw");
});

test("errors: 400 with the validator's list and nothing written; a duplicate is 409", async () => {
  const bad = course();
  bad.name = "gment_bad";
  bad.fields[0].requird = true;
  bad.prefix = "eweb_";
  const r = await ew("", { method: "POST", body: { definition: bad } });
  assert.equal(r.status, 400);
  assert.match(r.json.data.errors.join(" | "), /reservado|reserved/);
  assert.match(r.json.data.errors.join(" | "), /requird/);
  assert.equal((await raw("gment_bad")).row, null, "nothing stored");
  assert.equal((await ew("", { method: "POST", body: { definition: course() } })).status, 409, "gment_course already exists");
  const clash = course();
  clash.name = "gment_course2";
  clash.slug = "courses2";
  clash.fields = [];
  const r2 = await ew("", { method: "POST", body: { definition: clash } });
  assert.equal(r2.status, 400, "the same taxonomy declared twice (check_all against what is stored)");
  assert.match(r2.json.data.errors.join(" "), /gment_level/);
});

test("templates: refused while modules/site registers the type; added, it is the same type, names and labels as before", async () => {
  await saveCompany({ enabled_types: ["client"] });
  const list = await ew();
  assert.equal(list.json.templates.find((t) => t.key === "client").taken, true);
  const taken = await ew("/templates/client", { method: "POST" });
  assert.equal(taken.status, 400, taken.text);
  assert.match(taken.json.data.errors.join(" "), /eweb_client/);
  await saveCompany({ enabled_types: [] });

  for (const key of ["client", "testimonial", "location"]) {
    const r = await ew("/templates/" + key, { method: "POST" });
    assert.equal(r.status, 201, key + ": " + r.text);
    assert.equal(r.json.template, key);
  }
  assert.equal((await ew("/templates/client", { method: "POST" })).status, 409, "only once");
  const t = await wp("/types/eweb_testimonial");
  assert.equal(t.status, 200);
  assert.equal(t.json.name, "Opiniones");
  assert.equal(t.json.rest_base, "eweb_testimonial", "the same REST route as before");
  const state = await ct("/state");
  assert.equal(state.meta.eweb_testimonial.estrategia_web_testimonial_rating.type, "integer");
  assert.equal(state.meta.eweb_location.estrategia_web_location_phone.label, "Teléfono");
  assert.equal((await raw("eweb_location", "&slug=sedes")).rules.length > 0, true, "/sedes/, the es slug, as the old gettext one");
  assert.equal((await ew("/eweb_client")).json.definition.slug, "clientes", "stored with the site language's slug");
  const tax = await wp("/taxonomies/eweb_client_sector");
  assert.equal(tax.status, 200);
  assert.equal(tax.json.name, "Sectores");
});

test("the special sanitizers, now field types: rating, embed, local_image and the related client", async () => {
  const client = await wp("/eweb_client", { method: "POST", body: { title: "Cliente prueba", status: "publish" } });
  assert.equal(client.status, 201, client.text);
  const page = await wp("/pages", { method: "POST", body: { title: "Una página", status: "publish" } });
  const op = await wp("/eweb_testimonial", { method: "POST", body: { title: "Opinión", status: "publish", meta: { estrategia_web_testimonial_rating: 9, estrategia_web_testimonial_client: page.json.id } } });
  assert.equal(op.status, 201, op.text);
  assert.equal(op.json.meta.estrategia_web_testimonial_rating, 5, "clamped to 5");
  assert.equal(op.json.meta.estrategia_web_testimonial_client, 0, "a page is not a client");
  const ok = await wp(`/eweb_testimonial/${op.json.id}`, { method: "POST", body: { meta: { estrategia_web_testimonial_client: client.json.id, estrategia_web_testimonial_rating: -2 } } });
  assert.equal(ok.json.meta.estrategia_web_testimonial_client, client.json.id);
  assert.equal(ok.json.meta.estrategia_web_testimonial_rating, 0);
  const iframe = '<iframe src="https://www.google.com/maps/embed?pb=!1m18&amp;x=1" width="600"></iframe>';
  const loc = await wp("/eweb_location", { method: "POST", body: { title: "Sede", status: "publish", meta: { estrategia_web_location_map_embed: iframe, estrategia_web_location_static_map: "https://otro.test/mapa.webp" } } });
  assert.equal(loc.status, 201, loc.text);
  assert.equal(loc.json.meta.estrategia_web_location_map_embed, "https://www.google.com/maps/embed?pb=!1m18&x=1");
  assert.equal(loc.json.meta.estrategia_web_location_static_map, "", "another domain: refused");
  const theme = await wp(`/eweb_location/${loc.json.id}`, { method: "POST", body: { meta: { estrategia_web_location_static_map: "./assets/maps/sede.webp" } } });
  assert.equal(theme.json.meta.estrategia_web_location_static_map, "assets/maps/sede.webp");
});

test("protections: the internal name is fixed once there are entries; a removed field keeps its data and comes back", async () => {
  const post = await wp("/gment_course", { method: "POST", body: { title: "Curso de pan", status: "publish", meta: { gment_teacher: "Joan", gment_hours: 20 } } });
  assert.equal(post.status, 201, post.text);
  const renamed = course();
  renamed.name = "gment_class";
  const r = await ew("/gment_course", { method: "POST", body: { definition: renamed, confirm: true } });
  assert.equal(r.status, 400, r.text);
  assert.match(r.json.data.errors.join(" "), /gment_course/);

  const fewer = course();
  fewer.fields = fewer.fields.filter((f) => f.name !== "gment_teacher");
  const ask = await ew("/gment_course", { method: "POST", body: { definition: fewer } });
  assert.equal(ask.status, 409, "a warning needs confirm");
  assert.equal(ask.json.code, "estrategia_web_ct_confirm");
  assert.match(ask.json.data.warnings.join(" "), /gment_teacher/);
  assert.equal((await ew("/gment_course")).json.definition.fields.length, 2, "nothing written before confirming");
  assert.equal((await ew("/gment_course", { method: "POST", body: { definition: fewer, confirm: true } })).status, 200);
  assert.equal((await raw("gment_course", `&post=${post.json.id}`)).meta.gment_teacher, "Joan", "the value stays in the database");
  const hidden = await wp(`/gment_course/${post.json.id}?context=edit`);
  assert.equal(hidden.json.meta.gment_teacher, undefined, "and is no longer in REST");
  assert.equal((await ew("/gment_course", { method: "POST", body: { definition: course(), confirm: true } })).status, 200);
  const back = await wp(`/gment_course/${post.json.id}?context=edit`);
  assert.equal(back.json.meta.gment_teacher, "Joan", "added back with the same name: it is there again");
  assert.ok((await raw("gment_course")).revisions >= 2, "every save keeps a revision of the JSON");
});

test("a URL that moves: warned, then 301s in the redirects module; with that module off, the warning says so", async () => {
  const moved = course();
  moved.slug = "cursos";
  const ask = await ew("/gment_course", { method: "POST", body: { definition: moved } });
  assert.equal(ask.status, 409);
  assert.match(ask.json.data.warnings.join(" "), /\/courses\/.*\/cursos\//);
  const done = await ew("/gment_course", { method: "POST", body: { definition: moved, confirm: true } });
  assert.equal(done.status, 200, done.text);
  assert.ok(done.json.redirects.created >= 2, "the archive and the post: " + JSON.stringify(done.json.redirects));
  await wp("/types/gment_course"); // A request that registers the new slug and flushes.
  const res = await fetch(BASE + "/courses/curso-de-pan/", { redirect: "manual" });
  assert.equal(res.status, 301);
  assert.match(res.headers.get("location"), /\/cursos\/curso-de-pan\/$/);
  assert.equal((await fetch(BASE + "/cursos/curso-de-pan/")).status, 200);

  const { loaded } = await testApi("/modules");
  await testApi("/modules", { method: "POST", body: { active: loaded.filter((m) => m !== "redirects") } });
  const back = course();
  const warn = await ew("/gment_course", { method: "POST", body: { definition: back } });
  assert.equal(warn.status, 409);
  assert.match(warn.json.data.warnings.join(" "), /Redirecciones|Redirects/);
  await testApi("/modules", { method: "POST", body: { active: [...loaded] } });
});

test("deleting a type with entries is refused; switching it off keeps everything; empty, it can be deleted", async () => {
  const del = await ew("/gment_course", { method: "DELETE" });
  assert.equal(del.status, 409, del.text);
  assert.equal(del.json.code, "estrategia_web_ct_has_entries");
  assert.equal((await ew("/gment_course", { method: "POST", body: { active: false } })).json.active, false);
  assert.equal((await wp("/types/gment_course")).status, 404, "switched off: not registered");
  assert.equal((await raw("gment_course")).entries, 1, "its entry is still there");
  assert.equal((await ew("/gment_course", { method: "POST", body: { active: true } })).json.active, true);
  assert.equal((await wp("/types/gment_course")).status, 200);
  const posts = await wp("/gment_course?status=any&context=edit");
  for (const p of posts.json) await wp(`/gment_course/${p.id}?force=true`, { method: "DELETE" });
  const gone = await ew("/gment_course", { method: "DELETE" });
  assert.equal(gone.status, 200, gone.text);
  assert.equal((await raw("gment_course")).row, null);
});

test("export and import: the JSON of GET is what POST takes back", async () => {
  const exported = (await ew("/gment_workshop")).json.definition;
  assert.equal((await ew("/gment_workshop", { method: "DELETE" })).status, 200);
  const imported = await ew("", { method: "POST", body: { definition: { $schema: "../content-types.schema.json", ...exported } } });
  assert.equal(imported.status, 201, imported.text);
  assert.deepEqual(imported.json.definition, exported, "the same definition (the $schema of a file is dropped)");
});

test("a type defined in a file and in the database: the database wins and the file is listed as ignored", async () => {
  await ct("/setup", { method: "POST", body: { on: true } });
  await ct("/state");
  const fromFile = (await ew()).json.types.find((t) => t.name === "gment_wine");
  assert.equal(fromFile.source, "file");
  const def = structuredClone(fromFile.definition);
  def.labels.plural = "Vinos de la casa";
  const r = await ew("", { method: "POST", body: { definition: def } });
  assert.equal(r.status, 201, r.text);
  const after = (await ew()).json.types.find((t) => t.name === "gment_wine");
  assert.equal(after.source, "database");
  assert.equal((await wp("/types/gment_wine")).json.name, "Vinos de la casa");
  const state = await ct("/state");
  assert.match((state.errors["gment_wine.json"] || []).join(" "), /base de datos|database/);
  await ct("/setup", { method: "POST", body: { on: false } });
});
