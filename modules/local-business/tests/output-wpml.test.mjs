// NIVEL: 2 — Playground WordPress with REAL WPML. NOT LEVEL 1 BECAUSE: what is tested is which location WPML lets the module find for an ENGLISH page (the bug of 22-10-2026 was WPML adding «AND ID = <the English one>» to the module's query), and that only exists in a WordPress running WPML; the graph itself is graph.test.mjs (level 1). NOT LEVEL 3: what is checked is the JSON-LD in the HTML the server sends.
// WPML: REAL — runs against the WordPress with WPML (tests/run.mjs, «WPML»).
//
// LOCAL BUSINESS DATA FOR GOOGLE (22-10-2026, schema.org), IN BOTH LANGUAGES.
// Two locations, each with its English translation, two pages tied to one
// each (a binding to the location inside a group, as Lavandería's pages
// are) and their English translations:
//  1. Without an SEO plugin: on / and /en/ the Organization with the two
//     locations as departments (in /en/, their English names); on the tied
//     pages and on each location's own page, its LocalBusiness
//     (DryCleaningOrLaundry), valid with Google's rules (google-rules.mjs:
//     required and recommended), url = that page, in English the English name
//     and the /en/ url. ONE JSON-LD script per page and one Organization.
//  2. With the Rank Math double (estrategia-web-lb-test/v1/host): one script,
//     theirs; our location inside, pointing to THEIR Organization
//     (…/#person); no other Organization.
//
// Moved from Lavandería (tests/local-business-wpml.test.mjs) on 25-10-2026,
// steps 1 and 2, on locations and pages of its own. Lavandería keeps its step
// 3 (its deploy step despliegue/negocio-local.php on a site like the
// published one): that is Lavandería's code, not the plugin's.
// Seen red there, for real (22-10-2026): /en/self-service-laundry/ without
// any JSON-LD, because tied_to() looked the location up with a WP_Query that
// WPML filtered (fixed in includes/class-data.php). The plugin's own test
// could not see it: it had no WPML. NEEDS the local WPML copy: without it,
// it FAILS (tests/run.mjs, «WPML»).
//
// How to maintain: if Google's rules change, google-rules.mjs changes, not
// this. The English translations of the locations carry their own address
// and hours (a translator's job), as on Lavandería.
//
// Seen red (25-10-2026, here): expecting the Spanish name on the English tied
// page, step 1 fails («the name»); restored, green. And for real while
// porting: without the /config call of before() (WPML re-reading the XML),
// the front page listed the four locations, both languages, as departments.
import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { reset, wp, saveCompany, html, type, BASE, wpml, requireWpml, upload, png } from "../../../tests/lib.mjs";
import { checkLocalBusiness, checkOrganization, jsonLdBlocks, nodesOf } from "./google-rules.mjs";

const TYPE = "DryCleaningOrLaundry";
const host = async (name) => {
  const r = await fetch(BASE + "/wp-json/estrategia-web-lb-test/v1/host", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ host: name }) });
  assert.equal(r.status, 200);
};
const HOURS = { mo: ["08:00-16:00"], tu: ["08:00-16:00"], we: ["08:00-16:00"], th: ["08:00-16:00"], fr: ["08:00-16:00"] };
const LOCATIONS = {
  maioris: { es: "Autoservicio de pruebas", en: "Test self-service laundry", street: "Carrer de Can Maioris, 2, Local 12", postal: "07141", lat: 39.6, lon: 2.75, phone: "+34 871 000 001" },
  "son-oms": { es: "Lavandería industrial de pruebas", en: "Test industrial laundry", street: "Calle Camp Franc, 11, Nave 14", postal: "07199", lat: 39.54, lon: 2.73, phone: "+34 871 000 002" },
};
const meta = (l) => ({
  estrategia_web_location_phone: l.phone,
  estrategia_web_location_street: l.street,
  estrategia_web_location_postal_code: l.postal,
  estrategia_web_location_locality: "Palma",
  estrategia_web_location_region: "Islas Baleares",
  estrategia_web_location_hours: JSON.stringify(HOURS),
  estrategia_web_location_latitude: l.lat,
  estrategia_web_location_longitude: l.lon,
  // 1.8.0: the static map is only the media library image (Google's recommended «image»).
  estrategia_web_location_static_map_id: mapId,
});
let mapId = 0;
const tiedContent = (slug) => `<!-- wp:group -->
<div class="wp-block-group"><!-- wp:paragraph ${JSON.stringify({ metadata: { bindings: { content: { source: "estrategia-web-site/location", args: { slug, key: "estrategia_web_location_phone" } } } } })} -->
<p>x</p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->`;
const SLUGS = { maioris: ["autoservicio-lbw", "self-service-lbw"], "son-oms": ["industrial-lbw", "industrial-laundry-lbw"] };
const urls = {};

before(async () => {
  await requireWpml({ st: false });
  await reset();
  await host("");
  await wpml("/purge", { slugs: [...Object.values(SLUGS).flat(), "maioris", "son-oms"] });
  await saveCompany({ enabled_types: ["location"], company_name: "Lavandería de pruebas", phone: "971 00 00 00", email: "info@ejemplo.test" });
  const s = await wp("/settings", { method: "POST", body: { estrategia_web_local_business_type: TYPE } });
  assert.equal(s.status, 200, s.text);
  // What a deploy does (Lavandería's re-reads the wpml-config.xml files): until then WPML does not have the
  // location type as translatable, filters nothing by language and the front page lists both languages.
  await wpml("/config", {});
  const map = await upload("mapa-prueba.png", "image/png", png(8, 6));
  assert.equal(map.status, 201, map.text);
  mapId = map.json.id;
  for (const [slug, l] of Object.entries(LOCATIONS)) {
    const r = await wp(`/${type("location")}`, { method: "POST", body: { status: "publish", title: l.es, slug, meta: meta(l) } });
    assert.equal(r.status, 201, r.text);
    const tr = await wpml("/translate", { kind: "post", type: type("location"), original: r.json.id, title: l.en, slug: `${slug}-en`, meta: meta(l) });
    urls[slug] = { own: r.json.link, ownEn: tr.url };
    const [pageEs, pageEn] = SLUGS[slug];
    const p = await wp("/pages", { method: "POST", body: { title: pageEs, slug: pageEs, status: "publish", content: tiedContent(slug) } });
    assert.equal(p.status, 201, p.text);
    const pe = await wpml("/translate", { kind: "post", type: "page", original: p.json.id, title: pageEn, slug: pageEn, content: tiedContent(slug) });
    Object.assign(urls[slug], { tied: p.json.link, tiedEn: pe.url });
  }
});

after(() => host(""));

const decode = (s) => s.replace(/&amp;/g, "&");
const read = async (url) => {
  const r = await html(url);
  assert.equal(r.status, 200, `${url} -> ${r.status}`);
  return { text: r.text, blocks: jsonLdBlocks(r.text), nodes: nodesOf(r.text) };
};
const locals = (nodes) => nodes.filter((n) => [].concat(n["@type"]).includes(TYPE));
const orgs = (nodes) => nodes.filter((n) => [].concat(n["@type"]).includes("Organization"));

/** A page tied to (or of) one location: one script, one LocalBusiness, valid, with the expected name and url. */
async function tied(url, slug, name, rankmath) {
  const { blocks, nodes } = await read(url);
  assert.equal(blocks.length, 1, `${url}: ${blocks.length} JSON-LD scripts (one)`);
  const lb = locals(nodes);
  assert.equal(lb.length, 1, `${url}: ${lb.length} LocalBusiness: ${JSON.stringify(nodes).slice(0, 300)}`);
  const node = lb[0];
  assert.deepEqual(checkLocalBusiness(node), { errors: [], warnings: [] }, `${url}: does not meet Google's rules`);
  assert.equal(node.name, name, `${url}: the name`);
  assert.equal(decode(node.url), url, `${url}: url (the page)`);
  const l = LOCATIONS[slug];
  assert.equal(node.address.streetAddress, l.street);
  assert.equal(node.address.postalCode, l.postal);
  assert.deepEqual(node.geo, { "@type": "GeoCoordinates", latitude: l.lat, longitude: l.lon });
  assert.equal(node.openingHoursSpecification.flatMap((s) => s.dayOfWeek).length, Object.keys(HOURS).length, `${url}: days of the hours`);
  assert.equal(node.telephone, l.phone);
  const o = orgs(nodes);
  assert.equal(o.length, 1, `${url}: ${o.length} Organization (one)`);
  assert.equal(node.parentOrganization["@id"], o[0]["@id"], `${url}: parentOrganization does not point to the page's Organization`);
  if (rankmath) assert.match(o[0]["@id"], /#person$/, `${url}: the Organization should be Rank Math's`);
  return node;
}

async function home(url, names) {
  const { blocks, nodes } = await read(url);
  assert.equal(blocks.length, 1, `${url}: ${blocks.length} JSON-LD scripts`);
  const o = orgs(nodes);
  assert.equal(o.length, 1, `${url}: ${o.length} Organization`);
  assert.deepEqual(checkOrganization(o[0]), { errors: [] });
  assert.deepEqual((o[0].department || []).map((d) => d.name).sort(), [...names].sort(), `${url}: the departments`);
  for (const d of o[0].department) assert.deepEqual(checkLocalBusiness(d).errors, [], `${url}: the department ${d.name}`);
}

async function allPages(rankmath) {
  await home(BASE + "/", Object.values(LOCATIONS).map((l) => l.es));
  await home(BASE + "/en/", Object.values(LOCATIONS).map((l) => l.en));
  for (const [slug, l] of Object.entries(LOCATIONS)) {
    await tied(urls[slug].tied, slug, l.es, rankmath);
    await tied(urls[slug].tiedEn, slug, l.en, rankmath);
  }
  const own = await tied(urls.maioris.own, "maioris", LOCATIONS.maioris.es, rankmath);
  assert.equal(decode(own["@id"]), `${urls.maioris.own}#localbusiness`);
  const ownEn = await tied(urls.maioris.ownEn, "maioris", LOCATIONS.maioris.en, rankmath);
  assert.match(decode(ownEn["@id"]), /\/en\/.*#localbusiness$/, "the English location: its @id is its English page");
}

test("1. without an SEO plugin: /, /en/, the tied pages and the locations' own pages, in both languages", async () => {
  assert.match(urls.maioris.tiedEn, /\/en\//, "the English tied page is not in /en/");
  await allPages(false);
  assert.match((await read(urls.maioris.tied)).text, /class="estrategia-web-local-business"/, "without Rank Math, the script is ours");
});

test("2. with the Rank Math double: one graph, theirs, with our locations inside, in both languages", async () => {
  await host("rankmath");
  await allPages(true);
  const rm = await read(urls.maioris.tiedEn);
  assert.match(rm.text, /class="rank-math-schema"/);
  assert.doesNotMatch(rm.text, /class="estrategia-web-local-business"/, "with Rank Math, our script does not come out");
});
