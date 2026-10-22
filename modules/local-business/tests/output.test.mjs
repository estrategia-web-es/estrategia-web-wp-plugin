// NIVEL: 2 — Playground WordPress, because it WRITES: enables the location type, creates two locations with the new fields over REST, a page tied to one; what is checked is the JSON-LD the server renders (alone, with a Rank Math double, with Rank Math's Local SEO double and with a Yoast double: tests/wp/), no browser.
//
// Seen red (22-10-2026): with Output::into_host() not marking $delivered, «with Rank Math» failed («no script of
// ours next to theirs»: two scripts); with Data::bound_slugs() not reading inner blocks, «a page tied to a location»
// failed (no script at all: the binding sits inside a group, as on Lavandería's pages).
import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { reset, wp, saveCompany, seedPage, html, type, BASE } from "../../../tests/lib.mjs";
import { checkLocalBusiness, checkOrganization, jsonLdBlocks, nodesOf } from "./google-rules.mjs";

const host = async (name) => {
  const r = await fetch(BASE + "/wp-json/estrategia-web-lb-test/v1/host", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ host: name }) });
  assert.equal(r.status, 200);
};
const SRC = "estrategia-web-site/location";
const HOURS = { mo: ["08:00-16:00"], tu: ["08:00-16:00"], we: ["08:00-16:00"], th: ["08:00-16:00"], fr: ["08:00-16:00"], sa: ["08:00-16:00"] };
let sonOms;
let tied;

before(async () => {
  await reset();
  await host("");
  await saveCompany({ enabled_types: ["location"], company_name: "Lavandería de pruebas", phone: "971 00 00 00", email: "info@ejemplo.test" });
  const s = await wp("/settings", { method: "POST", body: { estrategia_web_local_business_type: "DryCleaningOrLaundry", estrategia_web_social_links: [{ name: "", url: "https://www.facebook.com/ejemplo", icon: "facebook" }] } });
  assert.equal(s.status, 200, s.text);
  const make = async (body) => {
    const r = await wp(`/${type("location")}`, { method: "POST", body: { status: "publish", ...body } });
    assert.equal(r.status, 201, r.text);
    return r.json;
  };
  sonOms = await make({
    title: "Lavandería Industrial",
    slug: "son-oms",
    meta: {
      estrategia_web_location_phone: "+34 871 049 656",
      estrategia_web_location_street: "Calle Camp Franc, 11, Nave 14",
      estrategia_web_location_postal_code: "07199",
      estrategia_web_location_locality: "Palma",
      estrategia_web_location_region: "Islas Baleares",
      // Sent unsorted with an overlap and a bad range: what is stored is canonical (the hours sanitizer through REST).
      estrategia_web_location_hours: JSON.stringify({ sa: ["08:00-12:00", "11:00-16:00"], ...Object.fromEntries(Object.entries(HOURS).filter(([d]) => d !== "sa")), su: ["99:00-10:00"] }),
      estrategia_web_location_latitude: 39.5379694,
      estrategia_web_location_longitude: 2.7349615,
      estrategia_web_location_static_map: BASE + "/wp-content/uploads/mapa-son-oms.webp",
    },
  });
  // A location whose new fields were never filled in: it publishes nothing for itself.
  await make({ title: "Sede sin datos", slug: "sin-datos", meta: { estrategia_web_location_address: "Solo texto" } });
  tied = await seedPage(
    `<!-- wp:group -->
<div class="wp-block-group"><!-- wp:paragraph ${JSON.stringify({ metadata: { bindings: { content: { source: SRC, args: { slug: "son-oms", key: "estrategia_web_location_phone" } } } } })} -->
<p>x</p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->`,
    "lavanderia-industrial"
  );
});

after(() => host(""));

test("the hours field through REST: stored canonical (sorted, merged, the bad range dropped)", async () => {
  const r = await wp(`/${type("location")}/${sonOms.id}?context=edit`);
  assert.equal(r.json.meta.estrategia_web_location_hours, JSON.stringify(HOURS));
  assert.equal(r.json.meta.estrategia_web_location_country, "ES", "the template's default");
});

test("the type of business over REST: one of the list, anything else a 400 and the previous one stays", async () => {
  const bad = await wp("/settings", { method: "POST", body: { estrategia_web_local_business_type: "Laundry" } });
  assert.equal(bad.status, 400);
  const now = await wp("/settings");
  assert.equal(now.json.estrategia_web_local_business_type, "DryCleaningOrLaundry");
});

test("without an SEO plugin, a page tied to a location: our own script, its LocalBusiness valid with every recommended property, and the Organization it points to", async () => {
  const { text } = await html(tied.url);
  const blocks = jsonLdBlocks(text);
  assert.equal(blocks.length, 1, "one JSON-LD script");
  const nodes = nodesOf(text);
  const lb = nodes.find((n) => n["@type"] === "DryCleaningOrLaundry");
  assert.ok(lb, JSON.stringify(nodes));
  assert.deepEqual(checkLocalBusiness(lb), { errors: [], warnings: [] });
  assert.equal(lb.url, tied.url, "url: the page being read");
  assert.equal(lb["@id"], sonOms.link + "#localbusiness", "@id: the location's own page");
  assert.equal(lb.name, "Lavandería Industrial");
  assert.equal(lb.telephone, "+34 871 049 656");
  assert.equal(lb.image, BASE + "/wp-content/uploads/mapa-son-oms.webp");
  const org = nodes.find((n) => n["@type"] === "Organization");
  assert.deepEqual(checkOrganization(org), { errors: [] });
  assert.equal(lb.parentOrganization["@id"], org["@id"]);
  assert.deepEqual(org.sameAs, ["https://www.facebook.com/ejemplo"]);
});

test("the location's own page and the front page (departments: only the location that can be one)", async () => {
  const own = nodesOf((await html(sonOms.link)).text);
  assert.equal(own.find((n) => n["@type"] === "DryCleaningOrLaundry").url, sonOms.link);
  const home = nodesOf((await html(BASE + "/")).text);
  assert.equal(home.length, 1, "the front page: the Organization alone");
  assert.equal(home[0].name, "Lavandería de pruebas");
  assert.deepEqual(home[0].department.map((d) => d.name), ["Lavandería Industrial"], "«Sede sin datos» is left out, not half published");
  const none = await seedPage("<!-- wp:paragraph --><p>Nada</p><!-- /wp:paragraph -->", "sin-sede");
  assert.deepEqual(jsonLdBlocks((await html(none.url)).text), [], "a page about no location: nothing");
});

test("with Rank Math: ONE graph, theirs — our LocalBusiness inside, their Organization completed, not duplicated", async () => {
  await host("rankmath");
  const { text } = await html(tied.url);
  const blocks = jsonLdBlocks(text);
  assert.equal(blocks.length, 1, "no script of ours next to theirs");
  assert.match(text, /class="rank-math-schema"/);
  const nodes = nodesOf(text);
  const orgs = nodes.filter((n) => [].concat(n["@type"]).includes("Organization"));
  assert.equal(orgs.length, 1, "one Organization");
  assert.equal(orgs[0].name, "RM name", "their settings win");
  assert.equal(orgs[0].telephone, "971 00 00 00", "what theirs lacked, added");
  const lb = nodes.find((n) => n["@type"] === "DryCleaningOrLaundry");
  assert.deepEqual(lb.parentOrganization, { "@id": orgs[0]["@id"] });
  assert.match(orgs[0]["@id"], /#person$/);
});

test("with Rank Math's Local SEO publishing its own local business: ours steps aside on that page", async () => {
  await host("rankmath-local");
  const nodes = nodesOf((await html(tied.url)).text);
  assert.deepEqual(nodes.map((n) => n.name ?? n["@type"]), ["RM Local", "WebSite", "WebPage"]);
});

test("with Yoast: our nodes appended to its list, its Organization completed", async () => {
  await host("yoast");
  const { text } = await html(tied.url);
  assert.equal(jsonLdBlocks(text).length, 1);
  const nodes = nodesOf(text);
  assert.deepEqual(nodes.map((n) => [].concat(n["@type"])[0]), ["Organization", "WebPage", "DryCleaningOrLaundry"]);
  assert.equal(nodes[0].name, "Yoast name");
  assert.equal(nodes[2].parentOrganization["@id"], nodes[0]["@id"]);
});
