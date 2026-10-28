// NIVEL: 2 — Playground WordPress with REAL WPML. NOT LEVEL 1 BECAUSE: what is tested is which location WPML lets the module find for an ENGLISH page (the bug of 22-10-2026 was WPML adding «AND ID = <the English one>» to the module's query) and which locations the front page lists per language; that only exists in a WordPress running WPML. The graph itself is graph.test.mjs (level 1); Google's rules, the SEO plugins' merge and the rest of the output, output.test.mjs (level 2, no WPML).
// WPML: REAL — runs against the WordPress with WPML (tests/run.mjs, «WPML»).
//
// LOCAL BUSINESS DATA FOR GOOGLE (22-10-2026, schema.org), IN BOTH LANGUAGES.
// One location with its English translation and one page tied to it (a
// binding to the location inside a group, as Lavandería's pages are) with its
// English translation. What OUR code does with WPML, and nothing else:
//  - the English tied page finds its location: ONE LocalBusiness, the English
//    name, url = the /en/ page (the Spanish one is output.test.mjs);
//  - the location's own English page: its @id is its /en/ page;
//  - / and /en/ list the location as a department ONCE, in their language
//    (not both translations).
// Set up in two requests of this module's tests/wp/ (wpml-options, then
// wpml-seed: the location type must be enabled in a request before the one
// that creates locations) and the four pages read in parallel. No wp() call:
// no application password to create.
//
// 28-10-2026, from 132 s to what the runner prints: it had two locations and
// a second case with the Rank Math double reading every page again. Neither
// was about WPML: the merge with Rank Math is output.test.mjs (the same
// Data::tied_to() finds the location for both), and a second location only
// repeated the first one's checks. Google's recommended properties (the
// static map as «image») are output.test.mjs too: here only the errors.
//
// Moved from Lavandería (tests/local-business-wpml.test.mjs) on 25-10-2026.
// Lavandería keeps its step 3 (its deploy step despliegue/negocio-local.php):
// that is Lavandería's code, not the plugin's.
// Seen red there, for real (22-10-2026): /en/self-service-laundry/ without
// any JSON-LD, because tied_to() looked the location up with a WP_Query that
// WPML filtered (fixed in includes/class-data.php). NEEDS the local WPML
// copy: without it, it FAILS (tests/run.mjs, «WPML»).
// Seen red (28-10-2026) with the expected English name on the English tied
// page changed to the Spanish one («the name»), and with the seed's
// load_config_run() removed (the front pages' departments).
//
// How to maintain: if Google's rules change, google-rules.mjs changes, not
// this. The English translation of the location carries its own address and
// hours (a translator's job), as on Lavandería.
import { test, before } from "node:test";
import assert from "node:assert/strict";
import { reset, html, type, BASE, requireWpml } from "../../../tests/lib.mjs";
import { checkLocalBusiness, jsonLdBlocks, nodesOf } from "./google-rules.mjs";

const TYPE = "DryCleaningOrLaundry";
const lb = async (path, body) => {
  const r = await fetch(BASE + "/wp-json/estrategia-web-lb-test/v1" + path, { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify(body) });
  const text = await r.text();
  assert.equal(r.status, 200, `${path}: ${text.slice(0, 400)}`);
  return JSON.parse(text);
};
const L = { es: "Autoservicio de pruebas", en: "Test self-service laundry", street: "Carrer de Can Maioris, 2, Local 12", postal: "07141", lat: 39.6, lon: 2.75, phone: "+34 871 000 001" };
const META = {
  estrategia_web_location_phone: L.phone,
  estrategia_web_location_street: L.street,
  estrategia_web_location_postal_code: L.postal,
  estrategia_web_location_locality: "Palma",
  estrategia_web_location_region: "Islas Baleares",
  estrategia_web_location_hours: JSON.stringify({ mo: ["08:00-16:00"], tu: ["08:00-16:00"] }),
  estrategia_web_location_latitude: L.lat,
  estrategia_web_location_longitude: L.lon,
};
const tiedContent = `<!-- wp:group -->
<div class="wp-block-group"><!-- wp:paragraph ${JSON.stringify({ metadata: { bindings: { content: { source: "estrategia-web-site/location", args: { slug: "maioris-lbw", key: "estrategia_web_location_phone" } } } } })} -->
<p>x</p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->`;

const decode = (s) => s.replace(/&amp;/g, "&");
const read = async (url) => {
  const r = await html(url);
  assert.equal(r.status, 200, `${url} -> ${r.status}`);
  return { blocks: jsonLdBlocks(r.text), nodes: nodesOf(r.text) };
};
let urls, pages;

before(async () => {
  await Promise.all([requireWpml({ st: false }), reset()]);
  // The Rank Math double's option is never set on this WordPress (output.test.mjs runs on the normal one).
  await lb("/wpml-options", {
    options: {
      estrategia_web_site: { enabled_types: ["location"], company_name: "Lavandería de pruebas", phone: "971 00 00 00", email: "info@ejemplo.test" },
      estrategia_web_local_business_type: TYPE,
    },
  });
  urls = await lb("/wpml-seed", {
    type: type("location"),
    location: { slug: "maioris-lbw", title: L.es, title_en: L.en, meta: META },
    page: { slug: "autoservicio-lbw", slug_en: "self-service-lbw", title: "Autoservicio LBW", title_en: "Self-service LBW", content: tiedContent },
  });
  assert.match(urls.tiedEn, /\/en\//, "the English tied page is not in /en/");
  const list = [BASE + "/", BASE + "/en/", urls.tiedEn, urls.ownEn];
  const got = await Promise.all(list.map(read));
  pages = Object.fromEntries(list.map((u, i) => [u, got[i]]));
});

const locals = (nodes) => nodes.filter((n) => [].concat(n["@type"]).includes(TYPE));
const orgs = (nodes) => nodes.filter((n) => [].concat(n["@type"]).includes("Organization"));

/** A page tied to (or of) the location: one script, one LocalBusiness, with the expected name and url. */
function tied(url, name) {
  const { blocks, nodes } = pages[url];
  assert.equal(blocks.length, 1, `${url}: ${blocks.length} JSON-LD scripts (one)`);
  const found = locals(nodes);
  assert.equal(found.length, 1, `${url}: ${found.length} LocalBusiness: ${JSON.stringify(nodes).slice(0, 300)}`);
  const node = found[0];
  assert.deepEqual(checkLocalBusiness(node).errors, [], `${url}: does not meet Google's required properties`);
  assert.equal(node.name, name, `${url}: the name`);
  assert.equal(decode(node.url), url, `${url}: url (the page)`);
  assert.equal(node.address.streetAddress, L.street);
  assert.equal(node.telephone, L.phone);
  const o = orgs(nodes);
  assert.equal(o.length, 1, `${url}: ${o.length} Organization (one)`);
  assert.equal(node.parentOrganization["@id"], o[0]["@id"]);
  return node;
}

test("the English tied page finds its location: the English one, url its /en/ page", () => {
  tied(urls.tiedEn, L.en);
});

test("the location's own English page: its LocalBusiness, @id its /en/ page", () => {
  const own = tied(urls.ownEn, L.en);
  assert.match(decode(own["@id"]), /\/en\/.*#localbusiness$/);
});

test("/ and /en/: the location as a department once, in the page's language", () => {
  for (const [url, name] of [[BASE + "/", L.es], [BASE + "/en/", L.en]]) {
    const o = orgs(pages[url].nodes);
    assert.equal(o.length, 1, `${url}: ${o.length} Organization`);
    assert.deepEqual((o[0].department || []).map((d) => d.name), [name], `${url}: the departments`);
  }
});
