// NIVEL: 1 — no browser, no emulator. A location's derived texts and links (includes/class-location-data.php: pure PHP) with Lavandería's real strings, and the parsers the migration uses; the machine's PHP runs them (tests/php/location-data.php).
//
// If there is no `php` on the PATH the test FAILS (it is not skipped).
//
// The cases are Lavandería Mallorca's real values (content/semilla/sedes.json
// and en/sedes.json of its repo, 27-10-2026): what each location SAYS today
// must be what 1.8.0 derives from its parts, or the site would change when it
// migrates. If a default format changes, these fail first.
//
// Seen red (27-10-2026): with the Spanish «every_day» as «cada día» (the
// Lavandería strings failed in two places) and with rawurlencode() instead of
// urlencode() in maps_url() (the stored link has «+» for spaces).
import { test } from "node:test";
import assert from "node:assert/strict";
import { spawnSync } from "node:child_process";
import path from "node:path";

const HERE = import.meta.dirname;
const run = (cases) => {
  const r = spawnSync("php", [path.join(HERE, "php", "location-data.php")], { encoding: "utf8", input: JSON.stringify(cases) });
  assert.equal(r.status, 0, "php did not run: " + (r.error?.message || r.stderr || r.stdout));
  return JSON.parse(r.stdout);
};
const one = (fn, ...args) => run([{ fn, args }])[0];

const days = (list, range) => Object.fromEntries(list.map((d) => [d, [range]]));
const EVERY_DAY = days(["mo", "tu", "we", "th", "fr", "sa", "su"], "08:00-23:00");
const MON_SAT = days(["mo", "tu", "we", "th", "fr", "sa"], "08:00-16:00");

// Lavandería, as stored today (texts) and as 1.8.0 stores it (parts).
const MAIORIS = {
  text: "Centro Comercial Maioris\nCalle Imperial, 2, Local 12, Urb. Maioris Décima\n07609 Llucmajor, Islas Baleares",
  textEn: "Maioris Shopping Centre\nCalle Imperial, 2, Local 12, Urb. Maioris Décima\n07609 Llucmajor, Islas Baleares",
  parts: { street: "Centro Comercial Maioris\nCalle Imperial, 2, Local 12, Urb. Maioris Décima", postal_code: "07609", locality: "Llucmajor", region: "Islas Baleares", country: "ES" },
  hours: "Abierto todos los días\nDe 8:00 a 23:00",
  hoursEn: "Open every day\n8:00am – 11:00pm",
  query: "Lavandería Ecológica Mallorca - Maioris (Autoservicio)",
  url: "https://www.google.com/maps/search/?api=1&query=Lavander%C3%ADa+Ecol%C3%B3gica+Mallorca+-+Maioris+%28Autoservicio%29",
  embed: "https://www.google.com/maps/embed?origin=mfe&pb=!1m3!2m1!1sLavander%C3%ADa+Ecol%C3%B3gica+Mallorca+-+Maioris+%28Autoservicio%29!6i15",
};
const SON_OMS = {
  text: "Calle Camp Franc, 11 Nave 14\nPolígono Son Oms\n07199 Palma, Islas Baleares",
  parts: { street: "Calle Camp Franc, 11 Nave 14\nPolígono Son Oms", postal_code: "07199", locality: "Palma", region: "Islas Baleares", country: "ES" },
  hours: "Abierto de lunes a sábado\nDe 8:00 a 16:00",
  hoursEn: "Open Monday to Saturday\n8:00am – 4:00pm",
  query: "Lavandería Ecológica Mallorca - Polígono Son Oms (Lavandería industrial)",
  url: "https://www.google.com/maps/search/?api=1&query=Lavander%C3%ADa+Ecol%C3%B3gica+Mallorca+-+Pol%C3%ADgono+Son+Oms+%28Lavander%C3%ADa+industrial%29",
  embed: "https://www.google.com/maps/embed?origin=mfe&pb=!1m3!2m1!1sLavander%C3%ADa+Ecol%C3%B3gica+Mallorca+-+Pol%C3%ADgono+Son+Oms+%28Lavander%C3%ADa+industrial%29!6i15",
};

test("address: Lavandería's two locations, in Spanish and English, say exactly what they say today", () => {
  assert.equal(one("address_text", MAIORIS.parts, "es"), MAIORIS.text);
  assert.equal(one("address_text", { ...MAIORIS.parts, street: "Maioris Shopping Centre\nCalle Imperial, 2, Local 12, Urb. Maioris Décima" }, "en"), MAIORIS.textEn);
  assert.equal(one("address_text", SON_OMS.parts, "es"), SON_OMS.text);
});

test("address: an empty part takes its separator with it, empty lines go, nothing at all is \"\"", () => {
  assert.equal(one("address_text", { ...MAIORIS.parts, region: "" }, "es"), "Centro Comercial Maioris\nCalle Imperial, 2, Local 12, Urb. Maioris Décima\n07609 Llucmajor");
  assert.equal(one("address_text", { ...MAIORIS.parts, postal_code: "" }, "es"), "Centro Comercial Maioris\nCalle Imperial, 2, Local 12, Urb. Maioris Décima\nLlucmajor, Islas Baleares");
  assert.equal(one("address_text", { street: "  Calle 1 \n\n", locality: "Palma" }, "fr"), "Calle 1\nPalma", "unknown language: English format; street lines trimmed");
  assert.equal(one("address_text", {}, "es"), "");
  assert.equal(one("street_line", SON_OMS.parts.street), "Calle Camp Franc, 11 Nave 14, Polígono Son Oms", "schema.org's streetAddress: one line");
});

test("hours: Lavandería's texts in Spanish and English, and the Catalan default", () => {
  assert.equal(one("hours_text", EVERY_DAY, "es"), MAIORIS.hours);
  assert.equal(one("hours_text", MON_SAT, "es"), SON_OMS.hours);
  assert.equal(one("hours_text", JSON.stringify(EVERY_DAY), "en_US"), MAIORIS.hoursEn, "the stored JSON text works too");
  assert.equal(one("hours_text", MON_SAT, "en"), SON_OMS.hoursEn);
  assert.equal(one("hours_text", MON_SAT, "ca"), "Obert de dilluns a dissabte\nDe 8:00 a 16:00");
  assert.equal(one("hours_text", "", "es"), "");
});

test("hours: several sets, one line each; split days as a list; two ranges a day", () => {
  const week = { ...days(["mo", "tu", "we", "th", "fr"], "09:00-14:00"), sa: ["10:00-14:00"] };
  week.mo = week.tu = week.we = week.th = week.fr = ["09:00-14:00", "16:00-20:00"];
  assert.equal(one("hours_text", week, "es"), "De lunes a viernes: de 9:00 a 14:00 y de 16:00 a 20:00\nSábado: de 10:00 a 14:00");
  assert.equal(one("hours_text", week, "en"), "Monday to Friday: 9:00am – 2:00pm and 4:00pm – 8:00pm\nSaturday: 10:00am – 2:00pm");
  assert.equal(one("hours_text", days(["mo", "tu", "th", "fr"], "09:00-13:00"), "es"), "Abierto lunes, martes, jueves y viernes\nDe 9:00 a 13:00", "two runs of two: a list, not two ranges");
  assert.equal(one("hours_text", { ...days(["mo", "tu", "we", "th", "fr"], "09:00-13:00"), su: ["09:00-13:00"] }, "es"), "Abierto de lunes a viernes y domingo\nDe 9:00 a 13:00");
  assert.equal(one("time", "00:30", "g:ia"), "12:30am");
  assert.equal(one("time", "12:05", "h:i A"), "12:05 PM");
});

test("map: the link and the embed derived from the query are byte for byte the ones Lavandería stores", () => {
  for (const l of [MAIORIS, SON_OMS]) {
    assert.equal(one("maps_url", l.query), l.url);
    assert.equal(one("embed_url", l.query), l.embed);
  }
  assert.equal(one("maps_url", "  "), "");
  assert.ok(one("embed_url", "A!B").includes("A%21B"), "«!» is pb's separator: encoded");
});

test("migration parsers: the query comes back out of the stored link and embed; what cannot be read is null", () => {
  for (const l of [MAIORIS, SON_OMS]) {
    assert.equal(one("query_from_maps_url", l.url), l.query);
    assert.equal(one("query_from_embed", l.embed), l.query);
  }
  assert.equal(one("query_from_maps_url", "https://maps.google.com/?q=Maioris"), "Maioris");
  assert.equal(one("query_from_maps_url", "https://www.google.es/maps/place/Lavander%C3%ADa+X/@39.4,2.7,17z"), "Lavandería X");
  assert.equal(one("query_from_maps_url", "https://maps.app.goo.gl/AbC123"), null, "a short link has no query");
  assert.equal(one("query_from_embed", "https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3077!2d2.7!3d39.5"), null, "a place embed has no query");
  assert.equal(one("query_from_embed", "https://www.google.com/maps/embed/v1/place?key=K&q=Palma+Centro"), "Palma Centro");
});

test("migration parsers: Lavandería's address and hours texts give back their parts, and deriving them again gives the same text", () => {
  for (const l of [MAIORIS, SON_OMS]) {
    const parts = one("parse_address", l.text);
    assert.deepEqual(parts, { ...l.parts, country: "" });
    assert.equal(one("address_text", parts, "es"), l.text);
  }
  assert.equal(one("parse_address", "Calle 1\n07001 Palma\nEspaña").country, "ES");
  assert.equal(one("parse_address", "Calle Mayor 1, Palma"), null, "no postal-code line: not parsed");
  assert.equal(one("parse_address", "Calle 1\n07001 Palma\nFrance"), null, "a line after it that is not Spain: not parsed");

  assert.deepEqual(one("parse_hours", MAIORIS.hours), EVERY_DAY);
  assert.deepEqual(one("parse_hours", SON_OMS.hours), MON_SAT);
  assert.deepEqual(one("parse_hours", MAIORIS.hoursEn), EVERY_DAY);
  assert.deepEqual(one("parse_hours", SON_OMS.hoursEn), MON_SAT);
  assert.deepEqual(one("parse_hours", "Lunes a viernes: 9-14 y 16-20\nSábados de 10:00 a 13:30"), { ...days(["mo", "tu", "we", "th", "fr"], "x"), mo: ["09:00-14:00", "16:00-20:00"], tu: ["09:00-14:00", "16:00-20:00"], we: ["09:00-14:00", "16:00-20:00"], th: ["09:00-14:00", "16:00-20:00"], fr: ["09:00-14:00", "16:00-20:00"], sa: ["10:00-13:30"] });
  assert.deepEqual(one("parse_hours", "Dissabte a dilluns, 10-14"), { mo: ["10:00-14:00"], sa: ["10:00-14:00"], su: ["10:00-14:00"] }, "a range across Sunday");
  assert.equal(one("parse_hours", "L-V 8-20"), null, "single letters are not read: reported, never guessed");
  assert.equal(one("parse_hours", "Abierto todos los días"), null, "days without times");
  assert.equal(one("parse_hours", "Cerrado por vacaciones"), null);
});
