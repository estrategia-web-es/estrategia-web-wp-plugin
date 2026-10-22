// NIVEL: BANCO DE TRABAJO — not a test: the checks the tests share. What Google asks of a LocalBusiness and an
// Organization in JSON-LD, written down so a test can say «valid» without a network call to the Rich Results test.
//
// Source: Google Search Central, «Local business (LocalBusiness) structured data» and «Organization»
// (read 22-10-2026). REQUIRED for a LocalBusiness: name and address (a PostalAddress). RECOMMENDED: telephone,
// openingHoursSpecification, geo (latitude and longitude, numbers), url, image; plus what schema.org itself demands
// of each piece (dayOfWeek from DayOfWeek, opens/closes as hh:mm[:ss], an ISO 3166 country).
//
// Used by modules/local-business/tests/ and by the sites' tests (Lavandería imports it from the plugin folder it
// mounts: LM_PLUGIN). How to maintain: when Google changes its page, here and only here; each rule says what it
// checks in its message, which is what a red test shows.
const DAYS = ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday"];
const TIME = /^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/;
const types = (node) => [].concat(node?.["@type"] ?? []);

/** Problems of a LocalBusiness node: { errors (required / invalid), warnings (recommended missing) }. */
export function checkLocalBusiness(node, knownTypes) {
  const errors = [];
  const warnings = [];
  const t = types(node);
  if (!t.length || (knownTypes && !t.some((x) => knownTypes.includes(x)))) errors.push(`@type ${JSON.stringify(node?.["@type"])} is not a LocalBusiness type`);
  if (!node?.name || typeof node.name !== "string") errors.push("name is required");
  const a = node?.address;
  if (!a || typeof a !== "object") errors.push("address is required (a PostalAddress)");
  else {
    if (a["@type"] !== "PostalAddress") errors.push("address must be a PostalAddress");
    if (!a.streetAddress) errors.push("address.streetAddress is missing");
    if (!a.addressLocality && !a.postalCode) errors.push("address needs addressLocality or postalCode");
    if (a.addressCountry && !/^[A-Z]{2}$/.test(a.addressCountry)) errors.push(`address.addressCountry «${a.addressCountry}» is not an ISO 3166 code`);
  }
  for (const key of ["telephone", "openingHoursSpecification", "geo", "url", "image"]) if (node?.[key] === undefined) warnings.push(`${key} (recommended) is missing`);
  for (const [k, v] of Object.entries(node || {})) if (v === "" || (Array.isArray(v) && !v.length)) errors.push(`${k} is empty`);
  if (node?.geo) {
    if (node.geo["@type"] !== "GeoCoordinates") errors.push("geo must be GeoCoordinates");
    if (typeof node.geo.latitude !== "number" || Math.abs(node.geo.latitude) > 90) errors.push("geo.latitude must be a number within ±90");
    if (typeof node.geo.longitude !== "number" || Math.abs(node.geo.longitude) > 180) errors.push("geo.longitude must be a number within ±180");
  }
  for (const spec of [].concat(node?.openingHoursSpecification ?? [])) {
    if (spec["@type"] !== "OpeningHoursSpecification") errors.push("openingHoursSpecification entries must be OpeningHoursSpecification");
    if (![].concat(spec.dayOfWeek ?? []).length || [].concat(spec.dayOfWeek).some((d) => !DAYS.includes(d))) errors.push(`dayOfWeek ${JSON.stringify(spec.dayOfWeek)} is not a list of DayOfWeek`);
    if (!TIME.test(spec.opens || "") || !TIME.test(spec.closes || "")) errors.push(`opens/closes «${spec.opens}»–«${spec.closes}» are not hh:mm`);
  }
  for (const key of ["url", "image"]) if (node?.[key] && !/^https?:\/\//.test(node[key])) errors.push(`${key} must be an absolute URL`);
  return { errors, warnings };
}

/** Problems of an Organization node (Google: no required property; url and logo recommended, logo absolute). */
export function checkOrganization(node) {
  const errors = [];
  if (!types(node).includes("Organization")) errors.push(`@type ${JSON.stringify(node?.["@type"])} is not Organization`);
  if (!node?.name) errors.push("name is missing");
  for (const key of ["url", "logo"]) if (node?.[key] && typeof node[key] === "string" && !/^https?:\/\//.test(node[key])) errors.push(`${key} must be an absolute URL`);
  for (const u of [].concat(node?.sameAs ?? [])) if (!/^https?:\/\//.test(u)) errors.push(`sameAs «${u}» is not a web address`);
  return { errors };
}

/** Every <script type="application/ld+json"> of an HTML page, parsed. */
export function jsonLdBlocks(html) {
  const out = [];
  for (const m of html.matchAll(/<script[^>]*type=["']application\/ld\+json["'][^>]*>([\s\S]*?)<\/script>/gi)) out.push(JSON.parse(m[1]));
  return out;
}

/** All the nodes of a page's JSON-LD (the @graph of each block, or the block itself). */
export function nodesOf(html) {
  return jsonLdBlocks(html).flatMap((b) => (Array.isArray(b["@graph"]) ? b["@graph"] : [b]));
}
