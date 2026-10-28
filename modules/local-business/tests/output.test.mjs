// NIVEL: 2 — Playground WordPress. NOT LEVEL 1 BECAUSE: what is tested is the JSON-LD the server prints in a real page — which location a page is tied to (a binding inside a group), the hours sanitizer and the business type's schema through REST, and the merge with the SEO plugins' doubles through THEIR filters at wp_head (tests/wp/) — none of which exists without WordPress rendering; the graph itself is graph.test.mjs (level 1). No browser.
//
// Seen red (22-10-2026): with Output::into_host() not marking $delivered, «with Rank Math» failed («no script of
// ours next to theirs»: two scripts); with Data::bound_slugs() not reading inner blocks, «a page tied to a location»
// failed (no script at all: the binding sits inside a group, as on Lavandería's pages).
//
// 28-10-2026, from ~50 s: the content is created in parallel, every page is read
// ONCE (the three SEO doubles through ?ew_lb_host=, one request each, in
// parallel, instead of switching the option and reading one after another),
// and the company hours are saved in before() so the «with locations» front
// page is the one the departments test already reads.
// Seen red (28-10-2026) with Data::bound_slugs() not reading inner blocks
// (the tied page: no script at all) and, for the doubles, with the expected
// Rank Math Organization name changed («their settings win»).
import { test, before } from "node:test";
import assert from "node:assert/strict";
import { reset, wp, saveCompany, seedPage, html, type, upload, png, BASE, credentials } from "../../../tests/lib.mjs";
import { checkLocalBusiness, checkOrganization, jsonLdBlocks, nodesOf } from "./google-rules.mjs";

const SRC = "estrategia-web-site/location";
const HOURS = { mo: ["08:00-16:00"], tu: ["08:00-16:00"], we: ["08:00-16:00"], th: ["08:00-16:00"], fr: ["08:00-16:00"], sa: ["08:00-16:00"] };
let sonOms, noData, staticMap, tied, none;
const got = {};

before(async () => {
  // credentials() first: two wp() calls racing for the first credentials would each create
  // a new application password and invalidate the other's (a 401).
  // No POST /host any more (round 2): every read names its SEO double with ?ew_lb_host=, and nothing
  // stores one in the option since round 1.
  await Promise.all([reset(), credentials()]);
  const [, s, map] = await Promise.all([
    saveCompany({ enabled_types: ["location"], company_name: "Lavandería de pruebas", phone: "971 00 00 00", email: "info@ejemplo.test", hours: { tu: "13:00-16:00, 20:00-23:30", we: "13:00-16:00" } }),
    wp("/settings", { method: "POST", body: { estrategia_web_local_business_type: "DryCleaningOrLaundry", estrategia_web_social_links: [{ name: "", url: "https://www.facebook.com/ejemplo", icon: "facebook" }] } }),
    upload("mapa-son-oms.png", "image/png", png(8, 6)),
  ]);
  assert.equal(s.status, 200, s.text);
  assert.equal(map.status, 201, map.text);
  staticMap = map.json.source_url;
  const make = async (body) => {
    const r = await wp(`/${type("location")}`, { method: "POST", body: { status: "publish", ...body } });
    assert.equal(r.status, 201, r.text);
    return r.json;
  };
  let bad;
  [sonOms, noData, tied, none, bad] = await Promise.all([
    make({
      title: "Lavandería Industrial",
      slug: "son-oms",
      meta: {
        estrategia_web_location_phone: "+34 871 049 656",
        // 2.0.0: two lines, what the page shows; Google gets them on one.
        estrategia_web_location_street: "Calle Camp Franc, 11, Nave 14\nPolígono Son Oms",
        estrategia_web_location_postal_code: "07199",
        estrategia_web_location_locality: "Palma",
        estrategia_web_location_region: "Islas Baleares",
        // Sent unsorted with an overlap and a bad range: what is stored is canonical (the hours sanitizer through REST).
        estrategia_web_location_hours: JSON.stringify({ sa: ["08:00-12:00", "11:00-16:00"], ...Object.fromEntries(Object.entries(HOURS).filter(([d]) => d !== "sa")), su: ["99:00-10:00"] }),
        estrategia_web_location_latitude: 39.5379694,
        estrategia_web_location_longitude: 2.7349615,
        estrategia_web_location_static_map_id: map.json.id,
      },
    }),
    // A location whose address was never filled in: it publishes nothing for itself.
    make({ title: "Sede sin datos", slug: "sin-datos", meta: { estrategia_web_location_phone: "971 00 00 01" } }),
    seedPage(
      `<!-- wp:group -->
<div class="wp-block-group"><!-- wp:paragraph ${JSON.stringify({ metadata: { bindings: { content: { source: SRC, args: { slug: "son-oms", key: "estrategia_web_location_phone" } } } } })} -->
<p>x</p>
<!-- /wp:paragraph --></div>
<!-- /wp:group -->`,
      "lavanderia-industrial"
    ),
    seedPage("<!-- wp:paragraph --><p>Nada</p><!-- /wp:paragraph -->", "sin-sede"),
    // A business type not in the list: refused, and the pages read below still carry the stored one.
    wp("/settings", { method: "POST", body: { estrategia_web_local_business_type: "Laundry" } }),
  ]);
  got.badType = bad.status;
  // Every page once, in parallel: plain, and the tied one with each SEO double.
  const reads = { tied: tied.url, own: sonOms.link, home: BASE + "/", none: none.url, rankmath: tied.url + "?ew_lb_host=rankmath", local: tied.url + "?ew_lb_host=rankmath-local", yoast: tied.url + "?ew_lb_host=yoast" };
  const texts = await Promise.all(Object.values(reads).map(async (u) => (await html(u)).text));
  Object.keys(reads).forEach((k, i) => (got[k] = texts[i]));
});

test("the hours field through REST: stored canonical (sorted, merged, the bad range dropped)", () => {
  assert.equal(sonOms.meta.estrategia_web_location_hours, JSON.stringify(HOURS));
  assert.equal(sonOms.meta.estrategia_web_location_country, "ES", "the template's default");
});

test("the type of business over REST: one of the list, anything else a 400 and the previous one stays (the pages read after it)", () => {
  assert.equal(got.badType, 400);
  assert.ok(nodesOf(got.tied).some((n) => n["@type"] === "DryCleaningOrLaundry"), "the stored type is still the one published");
});

test("without an SEO plugin, a page tied to a location: our own script, its LocalBusiness valid with every recommended property, and the Organization it points to", () => {
  const text = got.tied;
  assert.equal(jsonLdBlocks(text).length, 1, "one JSON-LD script");
  assert.match(text, /class="estrategia-web-local-business"/, "without an SEO plugin, the script is ours");
  const nodes = nodesOf(text);
  const lb = nodes.find((n) => n["@type"] === "DryCleaningOrLaundry");
  assert.ok(lb, JSON.stringify(nodes));
  assert.deepEqual(checkLocalBusiness(lb), { errors: [], warnings: [] });
  assert.equal(lb.url, tied.url, "url: the page being read");
  assert.equal(lb["@id"], sonOms.link + "#localbusiness", "@id: the location's own page");
  assert.equal(lb.name, "Lavandería Industrial");
  assert.equal(lb.telephone, "+34 871 049 656");
  assert.equal(lb.image, staticMap, "the static map of the media library");
  assert.equal(lb.address.streetAddress, "Calle Camp Franc, 11, Nave 14, Polígono Son Oms", "the street's two lines on one (2.0.0)");
  const org = nodes.find((n) => n["@type"] === "Organization");
  assert.deepEqual(checkOrganization(org), { errors: [] });
  assert.equal(lb.parentOrganization["@id"], org["@id"]);
  assert.deepEqual(org.sameAs, ["https://www.facebook.com/ejemplo"]);
});

test("the location's own page and the front page (departments: only the location that can be one; the company hours ignored)", () => {
  assert.equal(nodesOf(got.own).find((n) => n["@type"] === "DryCleaningOrLaundry").url, sonOms.link);
  const home = nodesOf(got.home);
  assert.equal(home.length, 1, "the front page: the Organization alone");
  assert.equal(home[0].name, "Lavandería de pruebas");
  assert.deepEqual(home[0].department.map((d) => d.name), ["Lavandería Industrial"], "«Sede sin datos» is left out, not half published");
  assert.equal(home[0].openingHoursSpecification, undefined, "with locations, each has its own hours, not the company's");
  assert.deepEqual(jsonLdBlocks(got.none), [], "a page about no location: nothing");
});

test("with Rank Math: ONE graph, theirs — our LocalBusiness inside, their Organization completed, not duplicated", () => {
  const text = got.rankmath;
  assert.equal(jsonLdBlocks(text).length, 1, "no script of ours next to theirs");
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

test("with Rank Math's Local SEO publishing its own local business: ours steps aside on that page", () => {
  assert.deepEqual(nodesOf(got.local).map((n) => n.name ?? n["@type"]), ["RM Local", "WebSite", "WebPage"]);
});

test("with Yoast: our nodes appended to its list, its Organization completed", () => {
  const text = got.yoast;
  assert.equal(jsonLdBlocks(text).length, 1);
  const nodes = nodesOf(text);
  assert.deepEqual(nodes.map((n) => [].concat(n["@type"])[0]), ["Organization", "WebPage", "DryCleaningOrLaundry"]);
  assert.equal(nodes[0].name, "Yoast name");
  assert.equal(nodes[2].parentOrganization["@id"], nodes[0]["@id"]);
});

// 2.0.0. Seen red (27-10-2026) before Data::company() passed the hours: no openingHoursSpecification.
test("a site WITHOUT locations: the Company details hours on the front page's Organization, typed as the business", async () => {
  await Promise.all([sonOms.id, noData.id].map((id) => wp(`/${type("location")}/${id}?force=true`, { method: "DELETE" })));
  const org = nodesOf((await html(BASE + "/")).text)[0];
  assert.deepEqual(org["@type"], ["Organization", "DryCleaningOrLaundry"]);
  assert.deepEqual(org.openingHoursSpecification.map((s) => [s.dayOfWeek, s.opens, s.closes]), [[["Tuesday", "Wednesday"], "13:00", "16:00"], [["Tuesday"], "20:00", "23:30"]]);
  assert.deepEqual(checkOrganization(org), { errors: [] });
});
