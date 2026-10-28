// NIVEL: 2 — Playground WordPress. NOT LEVEL 1 BECAUSE: it WRITES: a 1.7.0 site (its stored location definition and locations with the duplicated meta, Lavandería's real values) goes through the 2.0.0 migration — dry run, real run, a second run that changes nothing, the backup — and the page bound to the location says the same before and after. Level 1 (location-data.test.mjs) has the derivations; this is the database.
//
// 28-10-2026, from ~60 s: the reset, «enabled_types» and the page in one
// request (estrategia-web-site-test/v1/setup), the three locations at once,
// the bound page read ONCE per moment (before and after) instead of once
// per paragraph, and what the real run left read in one batch. Round 2:
// the 1.7.0 definition, the image and the three locations in ONE request
// (ct-test /location-seed), their meta stored as given — what a 1.7.0
// database holds — instead of through today's REST. Seen red (round 2):
// expecting no static map from the seeded image, «the real run» failed;
// restored, green. Then the REST calls batched (batch.mjs): the dry run and
// its read in one request, the reads after the real run in one, the second
// run, the hand-filled location and the third run in one. Seen red again,
// the four tests (the three breaks above, and 3 old meta removed instead
// of 4); restored, green.
//
// Seen red (27-10-2026), the first runs, for real: the street came out on
// ONE line after the real run — the request that migrates had registered
// the old one-line «text» sanitizer for it (Location_Migration::write(),
// «THE TRAP»).
// Seen red (28-10-2026) after the batching, one expected value changed in
// each reworked test (Son Oms' address for Maioris, the address gone after
// the dry run, the link not escaped): the three red; restored, green.
import { test, before } from "node:test";
import assert from "node:assert/strict";
import { BASE, credentials, testApi, png, type } from "../../../tests/lib.mjs";
import { batch } from "./batch.mjs";

const ct = async (path, { method = "GET", body } = {}) => {
  const res = await fetch(BASE + "/wp-json/estrategia-web-ct-test/v1" + path, { method, headers: body ? { "Content-Type": "application/json" } : {}, body: body ? JSON.stringify(body) : undefined });
  if (!res.ok) throw new Error(`${method} ${path} -> ${res.status} ${await res.text()}`);
  return res.json();
};
// As calls of a batch (batch.mjs): several in one request.
const migrate = (dry) => ({ method: "POST", path: "/estrategia-web-ct-test/v1/location-migrate", body: { dry } });
const meta = (id) => ({ path: `/estrategia-web-ct-test/v1/location-meta?id=${id}` });
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
/** The bound page as it is now; shown(cls) reads one paragraph of it. */
const sedes = async () => {
  const page = await (await fetch(BASE + "/prueba-sedes/")).text();
  return (cls) => {
    const m = page.match(new RegExp(`<p class="[^"]*t-${cls}[^"]*">([\\s\\S]*?)</p>`));
    return m ? m[1].replace(/<br\s*\/?>\n?/g, "\n").trim() : null;
  };
};

before(async () => {
  // reset(), «enabled_types» (its templates stored) and the bound page, in one request.
  const content = [paragraph("maioris", "address"), paragraph("maioris", "schedule"), paragraph("son-oms", "address"), paragraph("maioris", "map_url"), paragraph("maioris", "static_map")].join("\n\n");
  await Promise.all([
    testApi("/setup", { method: "POST", body: { company: { enabled_types: ["location", "client", "testimonial"], schedule: "Abierto todos los días\nDe 8:00 a 23:00" }, pages: [{ slug: "prueba-sedes", content }] } }),
    credentials(),
  ]);
  // The 1.7.0 definition, the map image and the three locations with their 1.7.0 meta as a 1.7.0 site has it: one request (ct-test /location-seed).
  const parts = (street, cp, town) => ({ [L + "street"]: street, [L + "postal_code"]: cp, [L + "locality"]: town, [L + "region"]: "Islas Baleares", [L + "country"]: "ES" });
  const locations = [
    {
      slug: "maioris",
      title: "Lavandería Autoservicio Maioris",
      meta: {
        ...Object.fromEntries(["address", "schedule", "map_url", "map_embed"].map((k) => [L + k, MAIORIS[k]])),
        [L + "static_map"]: "@image.url",
        [L + "static_map_id"]: "@image.id",
        ...parts(MAIORIS.street, "07609", "Llucmajor"),
        [L + "hours"]: week(["mo", "tu", "we", "th", "fr", "sa", "su"], "08:00-23:00"),
      },
    },
    {
      slug: "son-oms",
      title: "Lavandería Industrial",
      meta: {
        ...Object.fromEntries(["address", "schedule", "map_url", "map_embed"].map((k) => [L + k, SON_OMS[k]])),
        ...parts(SON_OMS.street, "07199", "Palma"),
        [L + "hours"]: week(["mo", "tu", "we", "th", "fr", "sa"], "08:00-16:00"),
      },
    },
    // What a site that never filled in the parts has, written in ways nothing can read back.
    {
      slug: "rara",
      title: "Sede rara",
      meta: { [L + "address"]: "Calle Mayor 1, Palma", [L + "schedule"]: "L-V 8-20", [L + "map_url"]: "https://maps.app.goo.gl/AbC123", [L + "static_map"]: "assets/maps/rara.webp" },
    },
  ];
  const seeded = await ct("/location-seed", { method: "POST", body: { image: { name: "mapa-maioris.png", base64: Buffer.from(png(8, 6)).toString("base64") }, locations } });
  image = seeded.image;
  ({ maioris: ids.maioris, "son-oms": ids.sonOms, rara: ids.odd } = seeded.ids);
});

test("before migrating, the page shows what it showed in 1.7.0 (the old texts win while they are there)", async () => {
  const shown = await sedes();
  assert.equal(shown("maioris-address"), MAIORIS.address);
  assert.equal(shown("maioris-schedule"), MAIORIS.schedule);
  assert.equal(shown("son-oms-address"), SON_OMS.address);
});

test("the dry run: before → after per location, no DIFFERENCE for Lavandería, what cannot be read listed — and nothing written", async () => {
  // The dry run and, after it in the same request, what the database holds.
  const [{ json: r }, { json: m }] = await batch(migrate(true), meta(ids.maioris));
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
  assert.equal(m[L + "address"], MAIORIS.address, "a dry run writes nothing");
  assert.equal(m[L + "street"], MAIORIS.street);
});

test("the real run: the new fields, the old meta out, the backup, the definition of 2.0.0 — and the page says exactly the same", async () => {
  const [{ json: r }] = await batch(migrate(false));
  assert.equal(r.summary.differences, 0, JSON.stringify(r.locations.map((l) => l.differences)));
  // The next request registers 2.0.0's definition: everything read in it, and the page alongside.
  const [[m, odd, state, raw, client, settings], shown] = await Promise.all([
    batch(meta(ids.maioris), meta(ids.odd), { path: "/estrategia-web-ct-test/v1/state" }, { path: "/estrategia-web-ct-test/v1/constructor-raw?name=eweb_location" }, { path: "/estrategia-web-ct-test/v1/constructor-raw?name=eweb_client" }, { path: "/wp/v2/settings" }).then((all) => all.map((a) => a.json)),
    sedes(),
  ]);
  for (const k of ["address", "schedule", "map_url", "map_embed", "static_map"]) assert.equal(m[L + k], undefined, k + " removed");
  assert.equal(m[L + "map_query"], "Lavandería Ecológica Mallorca - Maioris (Autoservicio)");
  const backup = JSON.parse(m._estrategia_web_location_backup_1_8_0);
  assert.equal(backup.address, MAIORIS.address, "the backup keeps the old values");
  assert.equal(backup.map_embed, MAIORIS.map_embed);
  assert.equal(odd[L + "address"], "Calle Mayor 1, Palma", "not read: kept, and still shown");
  // The definition is 2.0.0's: no retired field, the new ones, the groups.
  const keys = Object.keys(state.meta.eweb_location);
  assert.ok(keys.includes(L + "map_query") && !keys.includes(L + "address") && !keys.includes(L + "schedule"), keys.join(", "));
  const street = raw.row.definition.fields.find((f) => f.name === L + "street");
  assert.equal(street.type, "textarea");
  assert.equal(street.wpml, "translate");
  assert.equal(raw.row.definition.fields_box, "main");
  assert.ok(client.row.definition.supports.includes("editor"));
  // The page.
  assert.equal(shown("maioris-address"), MAIORIS.address);
  assert.equal(shown("maioris-schedule"), MAIORIS.schedule);
  assert.equal(shown("son-oms-address"), SON_OMS.address);
  assert.equal(shown("maioris-map_url"), MAIORIS.map_url.replace(/&/g, "&amp;"), "the link, derived from the query, byte for byte");
  assert.equal(shown("maioris-static_map"), image.source_url, "the static map from the media library");
  // The company.
  const company = settings.estrategia_web_site;
  assert.equal(company.schedule, "");
  assert.equal(company.hours, week(["mo", "tu", "we", "th", "fr", "sa", "su"], "08:00-23:00"));
});

test("a second run changes nothing; filling in by hand what could not be read lets the next run remove the old meta (listing the DIFFERENCE)", async () => {
  // A second run, the hand-filled location and a third run: one request (REST registers 2.0.0's fields since the last one).
  const odd = { method: "POST", path: `/wp/v2/${type("location")}/${ids.odd}`, body: { meta: { [L + "street"]: "Calle Mayor, 1", [L + "locality"]: "Palma", [L + "postal_code"]: "07001", [L + "hours"]: week(["mo", "tu", "we", "th", "fr"], "08:00-20:00"), [L + "map_query"]: "Sede rara Palma", [L + "static_map_id"]: image.id } } };
  const [{ json: again }, r, { json: third }] = await batch(migrate(false), odd, migrate(false));
  const by = Object.fromEntries(again.locations.map((l) => [l.slug, l]));
  assert.deepEqual([by.maioris.changes, by.maioris.removed, by["son-oms"].changes, by["son-oms"].removed], [[], [], [], []]);
  assert.deepEqual(again.definitions, [], "the definitions are 2.0.0's already");
  assert.equal(by.rara.not_parsed.length, 4, "still not readable, still kept");
  assert.equal(r.status, 200, r.text);
  const last = third.locations.find((l) => l.slug === "rara");
  assert.deepEqual(last.not_parsed, []);
  assert.equal(last.removed.length, 4);
  assert.deepEqual(last.differences.find((d) => d.what === "hours text"), { what: "hours text", before: "L-V 8-20", after: "Abierto de lunes a viernes\nDe 8:00 a 20:00" });
});
