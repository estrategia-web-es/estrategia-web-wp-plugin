// NIVEL: 2 — Playground WordPress, because it WRITES: a 1.7.0 site (its stored location definition and locations with the duplicated meta, Lavandería's real values) goes through the 2.0.0 migration — dry run, real run, a second run that changes nothing, the backup — and the page bound to the location says the same before and after. Level 1 (location-data.test.mjs) has the derivations; this is the database.
//
// Seen red (27-10-2026), the first runs, for real: the street came out on
// ONE line after the real run — the request that migrates had registered
// the old one-line «text» sanitizer for it (Location_Migration::write(),
// «THE TRAP»).
import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { BASE, reset, saveCompany, seedPage, upload, png, wp, type } from "../../../tests/lib.mjs";

const ct = async (path, { method = "GET", body } = {}) => {
  const res = await fetch(BASE + "/wp-json/estrategia-web-ct-test/v1" + path, { method, headers: body ? { "Content-Type": "application/json" } : {}, body: body ? JSON.stringify(body) : undefined });
  if (!res.ok) throw new Error(`${method} ${path} -> ${res.status} ${await res.text()}`);
  return res.json();
};
const migrate = (dry) => ct("/location-migrate", { method: "POST", body: { dry } });
const meta = (id) => ct(`/location-meta?id=${id}`);
const L = "estrategia_web_location_";
const SRC = "estrategia-web-site/location";

// Lavandería Mallorca's real values (content/semilla/sedes.json, 27-10-2026).
const MAIORIS = {
  address: "Centro Comercial Maioris\nCalle Imperial, 2, Local 12, Urb. Maioris Décima\n07609 Llucmajor, Islas Baleares",
  schedule: "Abierto todos los días\nDe 8:00 a 23:00",
  map_url: "https://www.google.com/maps/search/?api=1&query=Lavander%C3%ADa+Ecol%C3%B3gica+Mallorca+-+Maioris+%28Autoservicio%29",
  map_embed: "https://www.google.com/maps/embed?origin=mfe&pb=!1m3!2m1!1sLavander%C3%ADa+Ecol%C3%B3gica+Mallorca+-+Maioris+%28Autoservicio%29!6i15",
  street: "Centro Comercial Maioris, Calle Imperial, 2, Local 12, Urb. Maioris Décima",
};
const SON_OMS = {
  address: "Calle Camp Franc, 11 Nave 14\nPolígono Son Oms\n07199 Palma, Islas Baleares",
  schedule: "Abierto de lunes a sábado\nDe 8:00 a 16:00",
  map_url: "https://www.google.com/maps/search/?api=1&query=Lavander%C3%ADa+Ecol%C3%B3gica+Mallorca+-+Pol%C3%ADgono+Son+Oms+%28Lavander%C3%ADa+industrial%29",
  map_embed: "https://www.google.com/maps/embed?origin=mfe&pb=!1m3!2m1!1sLavander%C3%ADa+Ecol%C3%B3gica+Mallorca+-+Pol%C3%ADgono+Son+Oms+%28Lavander%C3%ADa+industrial%29!6i15",
  street: "Calle Camp Franc, 11, Nave 14, Polígono Son Oms",
};
const week = (days, range) => JSON.stringify(Object.fromEntries(days.map((d) => [d, [range]])));
let ids = {};
let image;

const paragraph = (slug, key) => `<!-- wp:paragraph ${JSON.stringify({ className: `t-${slug}-${key}`, metadata: { bindings: { content: { source: SRC, args: { slug, key: L + key } } } } })} -->\n<p class="t-${slug}-${key}"></p>\n<!-- /wp:paragraph -->`;
const shown = async (cls) => {
  const page = await (await fetch(BASE + "/prueba-sedes/")).text();
  const m = page.match(new RegExp(`<p class="[^"]*t-${cls}[^"]*">([\\s\\S]*?)</p>`));
  return m ? m[1].replace(/<br\s*\/?>\n?/g, "\n").trim() : null;
};

before(async () => {
  await reset();
  await saveCompany({ enabled_types: ["location", "client", "testimonial"], schedule: "Abierto todos los días\nDe 8:00 a 23:00" });
  await wp("/types"); // The definitions are stored from the templates on this request.
  await ct("/location-legacy", { method: "POST" });
  await wp("/types"); // …and the 1.7.0 one registered from here.
  const up = await upload("mapa-maioris.png", "image/png", png(8, 6));
  assert.equal(up.status, 201, up.text);
  image = up.json;
  const make = async (slug, title, m) => {
    const r = await wp(`/${type("location")}`, { method: "POST", body: { title, slug, status: "publish", meta: m } });
    assert.equal(r.status, 201, r.text);
    return r.json.id;
  };
  const parts = (street, cp, town) => ({ [L + "street"]: street, [L + "postal_code"]: cp, [L + "locality"]: town, [L + "region"]: "Islas Baleares", [L + "country"]: "ES" });
  ids.maioris = await make("maioris", "Lavandería Autoservicio Maioris", {
    ...Object.fromEntries(["address", "schedule", "map_url", "map_embed"].map((k) => [L + k, MAIORIS[k]])),
    [L + "static_map"]: image.source_url,
    [L + "static_map_id"]: image.id,
    ...parts(MAIORIS.street, "07609", "Llucmajor"),
    [L + "hours"]: week(["mo", "tu", "we", "th", "fr", "sa", "su"], "08:00-23:00"),
  });
  ids.sonOms = await make("son-oms", "Lavandería Industrial", {
    ...Object.fromEntries(["address", "schedule", "map_url", "map_embed"].map((k) => [L + k, SON_OMS[k]])),
    ...parts(SON_OMS.street, "07199", "Palma"),
    [L + "hours"]: week(["mo", "tu", "we", "th", "fr", "sa"], "08:00-16:00"),
  });
  // What a site that never filled in the parts has, written in ways nothing can read back.
  ids.odd = await make("rara", "Sede rara", {
    [L + "address"]: "Calle Mayor 1, Palma",
    [L + "schedule"]: "L-V 8-20",
    [L + "map_url"]: "https://maps.app.goo.gl/AbC123",
    [L + "static_map"]: "assets/maps/rara.webp",
  });
  await seedPage([paragraph("maioris", "address"), paragraph("maioris", "schedule"), paragraph("son-oms", "address"), paragraph("maioris", "map_url"), paragraph("maioris", "static_map")].join("\n\n"), "prueba-sedes");
});

after(async () => {
  await reset();
});

test("before migrating, the page shows what it showed in 1.7.0 (the old texts win while they are there)", async () => {
  assert.equal(await shown("maioris-address"), MAIORIS.address);
  assert.equal(await shown("maioris-schedule"), MAIORIS.schedule);
  assert.equal(await shown("son-oms-address"), SON_OMS.address);
});

test("the dry run: before → after per location, no DIFFERENCE for Lavandería, what cannot be read listed — and nothing written", async () => {
  const r = await migrate(true);
  assert.equal(r.dry_run, true);
  const by = Object.fromEntries(r.locations.map((l) => [l.slug, l]));
  const change = (l, f) => by[l].changes.find((c) => c.field === L + f);
  assert.deepEqual(change("maioris", "street"), { field: L + "street", before: MAIORIS.street, after: "Centro Comercial Maioris\nCalle Imperial, 2, Local 12, Urb. Maioris Décima" }, "the street takes the text's lines");
  assert.equal(change("maioris", "map_query").after, "Lavandería Ecológica Mallorca - Maioris (Autoservicio)");
  assert.equal(change("son-oms", "street").after, "Calle Camp Franc, 11 Nave 14\nPolígono Son Oms", "Son Oms: the boundary case of the header");
  assert.deepEqual(by.maioris.differences, [], JSON.stringify(by.maioris.differences));
  assert.deepEqual(by["son-oms"].differences, [], JSON.stringify(by["son-oms"].differences));
  assert.deepEqual(by.maioris.removed.sort(), ["address", "map_embed", "map_url", "schedule", "static_map"].map((k) => L + k).sort());
  assert.equal(by.rara.not_parsed.length, 4, JSON.stringify(by.rara.not_parsed));
  assert.deepEqual(by.rara.removed, []);
  assert.deepEqual(r.company.differences, []);
  assert.deepEqual(r.company.changes.map((c) => c.field), ["estrategia_web_site[hours]", "estrategia_web_site[schedule]"]);
  const def = r.definitions.find((d) => d.type === "eweb_location");
  assert.ok(def && !def.not_saved, JSON.stringify(def));
  assert.ok(r.definitions.find((d) => d.type === "eweb_client").changes.some((c) => c.key === "supports"), "client gets «editor»");
  const m = await meta(ids.maioris);
  assert.equal(m[L + "address"], MAIORIS.address, "a dry run writes nothing");
  assert.equal(m[L + "street"], MAIORIS.street);
});

test("the real run: the new fields, the old meta out, the backup, the definition of 2.0.0 — and the page says exactly the same", async () => {
  const r = await migrate(false);
  assert.equal(r.summary.differences, 0, JSON.stringify(r.locations.map((l) => l.differences)));
  const m = await meta(ids.maioris);
  for (const k of ["address", "schedule", "map_url", "map_embed", "static_map"]) assert.equal(m[L + k], undefined, k + " removed");
  assert.equal(m[L + "map_query"], "Lavandería Ecológica Mallorca - Maioris (Autoservicio)");
  const backup = JSON.parse(m._estrategia_web_location_backup_1_8_0);
  assert.equal(backup.address, MAIORIS.address, "the backup keeps the old values");
  assert.equal(backup.map_embed, MAIORIS.map_embed);
  const odd = await meta(ids.odd);
  assert.equal(odd[L + "address"], "Calle Mayor 1, Palma", "not read: kept, and still shown");
  // The definition is 2.0.0's: no retired field, the new ones, the groups.
  await wp("/types");
  const state = await ct("/state");
  const keys = Object.keys(state.meta.eweb_location);
  assert.ok(keys.includes(L + "map_query") && !keys.includes(L + "address") && !keys.includes(L + "schedule"), keys.join(", "));
  const raw = await ct("/constructor-raw?name=eweb_location");
  const street = raw.row.definition.fields.find((f) => f.name === L + "street");
  assert.equal(street.type, "textarea");
  assert.equal(street.wpml, "translate");
  assert.equal(raw.row.definition.fields_box, "main");
  assert.ok((await ct("/constructor-raw?name=eweb_client")).row.definition.supports.includes("editor"));
  // The page.
  assert.equal(await shown("maioris-address"), MAIORIS.address);
  assert.equal(await shown("maioris-schedule"), MAIORIS.schedule);
  assert.equal(await shown("son-oms-address"), SON_OMS.address);
  assert.equal(await shown("maioris-map_url"), MAIORIS.map_url.replace(/&/g, "&amp;"), "the link, derived from the query, byte for byte");
  assert.equal(await shown("maioris-static_map"), image.source_url, "the static map from the media library");
  // The company.
  const company = (await wp("/settings")).json.estrategia_web_site;
  assert.equal(company.schedule, "");
  assert.equal(company.hours, week(["mo", "tu", "we", "th", "fr", "sa", "su"], "08:00-23:00"));
});

test("a second run changes nothing; filling in by hand what could not be read lets the next run remove the old meta (listing the DIFFERENCE)", async () => {
  const again = await migrate(false);
  const by = Object.fromEntries(again.locations.map((l) => [l.slug, l]));
  assert.deepEqual([by.maioris.changes, by.maioris.removed, by["son-oms"].changes, by["son-oms"].removed], [[], [], [], []]);
  assert.deepEqual(again.definitions, [], "the definitions are 2.0.0's already");
  assert.equal(by.rara.not_parsed.length, 4, "still not readable, still kept");
  const r = await wp(`/${type("location")}/${ids.odd}`, { method: "POST", body: { meta: { [L + "street"]: "Calle Mayor, 1", [L + "locality"]: "Palma", [L + "postal_code"]: "07001", [L + "hours"]: week(["mo", "tu", "we", "th", "fr"], "08:00-20:00"), [L + "map_query"]: "Sede rara Palma", [L + "static_map_id"]: image.id } } });
  assert.equal(r.status, 200, r.text);
  const last = (await migrate(false)).locations.find((l) => l.slug === "rara");
  assert.deepEqual(last.not_parsed, []);
  assert.equal(last.removed.length, 4);
  assert.deepEqual(last.differences.find((d) => d.what === "hours text"), { what: "hours text", before: "L-V 8-20", after: "Abierto de lunes a viernes\nDe 8:00 a 20:00" });
});
