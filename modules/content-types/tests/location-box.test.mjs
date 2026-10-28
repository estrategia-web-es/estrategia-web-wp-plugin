// NIVEL: 2 — Playground WordPress. NOT LEVEL 1 BECAUSE: it WRITES through WordPress's own meta-box save, and the screen is the HTML the server sends: the 2.0.0 meta box in the MAIN area of the block editor (location, testimonial, client) — its groups, «For Google» closed, no sidebar panel — and its save, posted the way the block editor posts legacy meta boxes (the base form + the «normal» location to _wpMetaBoxUrl), with no browser. What only the box's JavaScript does (the media picker, «Copy Monday») is not here: it is sugar over these same inputs.
//
// Seen red (27-10-2026), the first run, for real: «the save» stored the
// street with «\r\n» — what a browser's <textarea> sends — and the derived
// address compares line by line. The textarea sanitizer now normalizes to
// «\n» (Registrar::sanitizer()).
//
// 28-10-2026, from ~30 s: the reset and «enabled_types» in one request
// (estrategia-web-site-test/v1/setup) with the login alongside; the three
// edit pages read at once and the location's reused for its save; no
// after() reset (the next file resets). Seen red (28-10-2026) after the
// change, expecting the «google» group open and another phone: both red.
import { test, before } from "node:test";
import assert from "node:assert/strict";
import { adminSession, credentials, testApi, upload, png, wp } from "../../../tests/lib.mjs";

const L = "estrategia_web_location_";
let session;
let id;
let image;

const decode = (s) => s.replace(/&#(\d+);/g, (_, d) => String.fromCodePoint(Number(d))).replace(/&quot;/g, '"').replace(/&lt;/g, "<").replace(/&gt;/g, ">").replace(/&amp;/g, "&");
const attr = (tag, name) => {
  const m = tag.match(new RegExp(`\\s${name}\\s*=\\s*("([^"]*)"|'([^']*)')`, "i"));
  return m ? decode(m[2] ?? m[3]) : null;
};
/** The fields a browser would send from one <form class="…">. */
const formFields = (html, cls) => {
  const form = html.match(new RegExp(`<form class="${cls}"[^>]*>([\\s\\S]*?)</form>`));
  assert.ok(form, "no form ." + cls);
  const out = [];
  for (const [tag] of form[1].matchAll(/<input\b[^>]*>/gi)) {
    const name = attr(tag, "name");
    const type = (attr(tag, "type") || "text").toLowerCase();
    if (!name || /\sdisabled\b/i.test(tag) || ["submit", "button"].includes(type)) continue;
    if ((type === "checkbox" || type === "radio") && !/\schecked\b/i.test(tag)) continue;
    out.push([name, attr(tag, "value") ?? ""]);
  }
  for (const m of form[1].matchAll(/<textarea\b([^>]*)>([\s\S]*?)<\/textarea>/gi)) if (attr(m[1], "name")) out.push([attr(m[1], "name"), decode(m[2])]);
  for (const m of form[1].matchAll(/<select\b([^>]*)>([\s\S]*?)<\/select>/gi)) {
    const options = [...m[2].matchAll(/<option\b[^>]*>/gi)].map(([o]) => o);
    const chosen = options.find((o) => /\sselected\b/i.test(o)) ?? options[0];
    if (attr(m[1], "name") && chosen) out.push([attr(m[1], "name"), attr(chosen, "value") ?? ""]);
  }
  return out;
};

before(async () => {
  // reset() and «enabled_types» in one request (the template copies are stored there, or at the latest on the next request), the login alongside.
  [, session] = await Promise.all([testApi("/setup", { method: "POST", body: { company: { enabled_types: ["location", "client", "testimonial"] } } }), adminSession(), credentials()]);
  const [up, r] = await Promise.all([
    upload("mapa-caja.png", "image/png", png(8, 6)),
    wp("/eweb_location", { method: "POST", body: { title: "Maioris", slug: "maioris-caja", status: "publish", meta: { [L + "phone"]: "+34 626 078 816", [L + "postal_code"]: "07609", [L + "locality"]: "Llucmajor", [L + "region"]: "Islas Baleares" } } }),
  ]);
  assert.equal(up.status, 201, up.text);
  assert.equal(r.status, 201, r.text);
  image = up.json;
  id = r.json.id;
});

let edit; // The location's edit page, read once.

test("the edit pages: the block editor, the fields in a meta box in the main area — the location's grouped, «For Google» closed —, no sidebar panel", async () => {
  const [p, ...others] = await Promise.all([session.get(`/wp-admin/post.php?post=${id}&action=edit`), ...["eweb_testimonial", "eweb_client"].map((t) => session.get(`/wp-admin/post-new.php?post_type=${t}`))]);
  edit = p;
  // Testimonials and clients: the block editor too (the client got «editor» in 2.0.0), with their fields in the main meta box.
  for (const [i, t] of ["eweb_testimonial", "eweb_client"].entries()) {
    assert.equal(others[i].status, 200, t);
    assert.match(others[i].text, /block-editor-page/, t + ": block editor");
    assert.match(others[i].text.match(/<form class="metabox-location-normal"[^>]*>([\s\S]*?)<\/form>/)?.[1] ?? "", /id="estrategia-web-content-types"/, t + ": its fields in the main area");
    assert.ok(!others[i].text.includes("estrategiaWebContentTypesPanel"), t + ": no sidebar panel");
  }
  assert.equal(p.status, 200);
  assert.match(p.text, /block-editor-page/, "the block editor");
  assert.ok(!p.text.includes("estrategiaWebContentTypesPanel"), "no sidebar panel: one place to edit the fields");
  const normal = p.text.match(/<form class="metabox-location-normal"[^>]*>([\s\S]*?)<\/form>/)[1];
  assert.match(normal, /id="estrategia-web-content-types"/, "in the «normal» (main) area");
  const groups = [...normal.matchAll(/<details class="estrategia-web-ct-group" data-group="([a-z]+)"( open)?>/g)].map((m) => [m[1], !!m[2]]);
  assert.deepEqual(groups, [["contact", true], ["address", true], ["hours", true], ["map", true], ["google", false]]);
  assert.match(normal, /<textarea[^>]*name="estrategia_web_ct\[estrategia_web_location_street\]"/, "the street: two lines");
  assert.equal([...normal.matchAll(/name="estrategia_web_ct\[estrategia_web_location_hours\]\[(mo|tu|we|th|fr|sa|su)\]"/g)].length, 7);
  assert.match(normal, /class="estrategia-web-ct-preview"/, "what the site shows, under its group");
});

test("the save, as the block editor posts it: every group's values stored, the street on two lines, the hours day by day; the derived texts follow", async () => {
  const p = edit;
  const url = decode(p.text.match(/_wpMetaBoxUrl = "([^"]+)"/)[1]);
  const body = new URLSearchParams([...formFields(p.text, "metabox-base-form"), ...formFields(p.text, "metabox-location-normal")]);
  const set = (k, v) => body.set(`estrategia_web_ct[${L}${k}]`, v);
  set("street", "Centro Comercial Maioris\r\nCalle Imperial, 2, Local 12, Urb. Maioris Décima");
  for (const d of ["mo", "tu", "we", "th", "fr", "sa", "su"]) set(`hours][${d}`, "08:00-23:00");
  set("map_query", "Lavandería Ecológica Mallorca - Maioris (Autoservicio)");
  set("static_map_id", String(image.id));
  set("latitude", "39.4555458");
  const r = await fetch(url, { method: "POST", headers: { Cookie: session.cookie(), "Content-Type": "application/x-www-form-urlencoded" }, body: body.toString(), redirect: "manual" });
  assert.ok(r.status < 400, "meta box save: " + r.status);
  const [stored, again] = await Promise.all([wp(`/eweb_location/${id}?context=edit`), session.get(`/wp-admin/post.php?post=${id}&action=edit`)]);
  const m = stored.json.meta;
  assert.equal(m[L + "street"], "Centro Comercial Maioris\nCalle Imperial, 2, Local 12, Urb. Maioris Décima");
  assert.equal(m[L + "hours"], JSON.stringify(Object.fromEntries(["mo", "tu", "we", "th", "fr", "sa", "su"].map((d) => [d, ["08:00-23:00"]]))));
  assert.equal(m[L + "map_query"], "Lavandería Ecológica Mallorca - Maioris (Autoservicio)");
  assert.equal(m[L + "static_map_id"], image.id);
  assert.equal(m[L + "latitude"], 39.4555458);
  assert.equal(m[L + "phone"], "+34 626 078 816", "what was not touched stays");
  const preview = again.text.match(/data-group="address"[\s\S]*?class="estrategia-web-ct-preview"><strong>[^<]*<\/strong><div>([\s\S]*?)<\/div>/)[1];
  assert.equal(decode(preview.replace(/<br\s*\/?>\n?/g, "\n")), "Centro Comercial Maioris\nCalle Imperial, 2, Local 12, Urb. Maioris Décima\n07609 Llucmajor, Islas Baleares", "the address the site shows, derived");
});
