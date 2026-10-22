// NIVEL: 1 — no browser, no emulator. The schema.org graph (includes/class-graph.php) with the machine's PHP: the Organization, a location as a LocalBusiness checked against Google's rules (google-rules.mjs), when a location is left out, the merge into Rank Math's keyed graph and Yoast's list, and the type list against the location template's.
//
// If there is no `php` on the PATH the test FAILS (it is not skipped).
//
// Seen red (22-10-2026): with merge() not stepping aside, «a host that already has a local business» failed (two
// local businesses); with merge() adding our Organization next to Rank Math's, «one Organization» failed (two);
// with a location missing its town kept, «left out» failed.
import { test } from "node:test";
import assert from "node:assert/strict";
import { spawnSync } from "node:child_process";
import fs from "node:fs";
import path from "node:path";
import { checkLocalBusiness, checkOrganization } from "./google-rules.mjs";

const HERE = import.meta.dirname;
const run = (cases) => {
  const r = spawnSync("php", [path.join(HERE, "php", "graph.php")], { encoding: "utf8", input: JSON.stringify(cases) });
  assert.equal(r.status, 0, "php did not run: " + (r.error?.message || r.stderr || r.stdout));
  return JSON.parse(r.stdout);
};
const one = (fn, ...args) => run([{ fn, args }])[0];
const TYPES = one("types");

const COMPANY = {
  id: "https://x.test/#organization",
  name: "Lavandería Mallorca",
  url: "https://x.test/",
  logo: "https://x.test/logo.svg",
  telephone: "+34 626 078 816",
  email: "info@x.test",
  same_as: ["https://www.facebook.com/x", "mailto:info@x.test", "https://www.facebook.com/x", ""],
};
const SON_OMS = {
  id: "https://x.test/sedes/son-oms/#localbusiness",
  type: "DryCleaningOrLaundry",
  name: "Lavandería Industrial",
  url: "https://x.test/lavanderia-industrial/",
  telephone: "+34 871 049 656",
  email: "",
  image: "https://x.test/mapa-son-oms.webp",
  street: "Calle Camp Franc, 11, Nave 14",
  postal_code: "07199",
  locality: "Palma",
  region: "Islas Baleares",
  country: "es",
  hours: '{"mo":["08:00-16:00"],"tu":["08:00-16:00"],"we":["08:00-16:00"],"th":["08:00-16:00"],"fr":["08:00-16:00"],"sa":["08:00-16:00"]}',
  latitude: 39.5379694,
  longitude: 2.7349615,
  parent: "https://x.test/#organization",
};

test("a location as a LocalBusiness: every property Google asks for, valid", () => {
  const node = one("local_business", SON_OMS);
  assert.deepEqual(node, {
    "@type": "DryCleaningOrLaundry",
    "@id": "https://x.test/sedes/son-oms/#localbusiness",
    name: "Lavandería Industrial",
    url: "https://x.test/lavanderia-industrial/",
    image: "https://x.test/mapa-son-oms.webp",
    telephone: "+34 871 049 656",
    address: { "@type": "PostalAddress", streetAddress: "Calle Camp Franc, 11, Nave 14", postalCode: "07199", addressLocality: "Palma", addressRegion: "Islas Baleares", addressCountry: "ES" },
    geo: { "@type": "GeoCoordinates", latitude: 39.5379694, longitude: 2.7349615 },
    openingHoursSpecification: [{ "@type": "OpeningHoursSpecification", dayOfWeek: ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday"], opens: "08:00", closes: "16:00" }],
    parentOrganization: { "@id": "https://x.test/#organization" },
  });
  assert.deepEqual(checkLocalBusiness(node, TYPES), { errors: [], warnings: [] });
});

test("a location is left out without a name or an address Google can use; empty properties are dropped", () => {
  assert.equal(one("local_business", { ...SON_OMS, name: " " }), null);
  assert.equal(one("local_business", { ...SON_OMS, street: "" }), null);
  assert.equal(one("local_business", { ...SON_OMS, locality: "", postal_code: "" }), null);
  const bare = one("local_business", { name: "Sede", street: "Calle 1", locality: "Palma", type: "Laundry", country: "Spain", latitude: 0, longitude: 2.7, hours: "" });
  assert.deepEqual(bare, { "@type": "LocalBusiness", name: "Sede", address: { "@type": "PostalAddress", streetAddress: "Calle 1", addressLocality: "Palma" } }, "unknown type → LocalBusiness; a country that is not ISO, a 0 coordinate and no hours: left out, not empty");
  const check = checkLocalBusiness(bare, TYPES);
  assert.deepEqual(check.errors, []);
  assert.deepEqual(check.warnings, ["telephone (recommended) is missing", "openingHoursSpecification (recommended) is missing", "geo (recommended) is missing", "url (recommended) is missing", "image (recommended) is missing"]);
});

test("the Organization: only web links in sameAs, once each; the locations as departments", () => {
  const dept = one("local_business", { ...SON_OMS, parent: "" });
  const org = one("organization", COMPANY, [dept]);
  assert.deepEqual(org.sameAs, ["https://www.facebook.com/x"]);
  assert.equal(org["@id"], "https://x.test/#organization");
  assert.equal(org.department.length, 1);
  assert.equal(org.department[0].parentOrganization, undefined, "a department does not point back to its organization");
  assert.deepEqual(checkOrganization(org), { errors: [] });
  assert.equal(one("organization", { ...COMPANY, same_as: [] }).sameAs, undefined, "no sameAs at all rather than []");
});

const ORG = one("organization", COMPANY);
const LB = one("local_business", SON_OMS);

test("merge into Rank Math (keyed): one Organization — theirs, with what it lacked — and our location pointing to it", () => {
  const host = {
    publisher: { "@type": ["Person", "Organization"], "@id": "https://x.test/#person", name: "Lavandería Mallorca (Rank Math)", logo: { "@type": "ImageObject", url: "https://x.test/rm-logo.svg" } },
    WebSite: { "@type": "WebSite", "@id": "https://x.test/#website" },
  };
  const { graph } = one("merge", host, ORG, [LB]);
  assert.deepEqual(Object.keys(graph), ["publisher", "WebSite", "ewebLocalBusiness"]);
  assert.equal(graph.publisher.name, "Lavandería Mallorca (Rank Math)", "their settings win");
  assert.deepEqual(graph.publisher.logo, host.publisher.logo);
  assert.equal(graph.publisher.telephone, "+34 626 078 816", "what they lacked is added");
  assert.deepEqual(graph.publisher.sameAs, ["https://www.facebook.com/x"]);
  assert.deepEqual(graph.ewebLocalBusiness.parentOrganization, { "@id": "https://x.test/#person" }, "pointing to THEIR @id");
  const all = Object.values(graph);
  assert.equal(all.filter((n) => [].concat(n["@type"]).includes("Organization")).length, 1, "one Organization");
});

test("merge into Yoast (list), and into a graph without an Organization", () => {
  const yoast = [{ "@type": "Organization", "@id": "https://x.test/#organization", name: "Y" }, { "@type": "WebPage", "@id": "https://x.test/p/" }];
  const { graph } = one("merge", yoast, ORG, [LB]);
  assert.ok(Array.isArray(graph));
  assert.equal(graph.length, 3);
  assert.equal(graph[2]["@type"], "DryCleaningOrLaundry");
  const none = one("merge", [{ "@type": "WebPage" }], ORG, [LB]).graph;
  assert.deepEqual(none.map((n) => n["@type"]), ["WebPage", "Organization", "DryCleaningOrLaundry"], "ours is added when they have none");
  assert.deepEqual(one("merge", {}, ORG, []).graph, [ORG], "an empty host gets ours");
});

test("merge: a host that already has a local business on the page → ours steps aside entirely", () => {
  const host = { publisher: { "@type": ["DryCleaningOrLaundry", "Organization"], "@id": "https://x.test/#organization", name: "RM local" } };
  assert.deepEqual(one("merge", host, ORG, [LB]).graph, host);
});

test("standalone: one @graph, or nothing at all", () => {
  const g = one("standalone", ORG, [LB]);
  assert.equal(g["@context"], "https://schema.org");
  assert.equal(g["@graph"].length, 2);
  assert.deepEqual(one("standalone", null, []), []);
});

test("the type list is the same in Types::ALL and in the location template's select", () => {
  const tpl = JSON.parse(fs.readFileSync(path.join(HERE, "..", "..", "content-types", "templates", "location.json"), "utf8"));
  const field = tpl.fields.find((f) => f.name === "estrategia_web_location_business_type");
  assert.deepEqual(field.options.slice(1), TYPES);
  assert.equal(field.options[0].value, "company");
  assert.equal(TYPES[0], "LocalBusiness");
  assert.deepEqual(TYPES.slice(1), [...TYPES.slice(1)].sort(), "alphabetical after LocalBusiness");
});
