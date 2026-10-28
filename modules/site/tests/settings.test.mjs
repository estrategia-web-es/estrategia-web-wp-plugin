// NIVEL: 2 — Playground WordPress. NOT LEVEL 1 BECAUSE: what is tested is the estrategia_web_site option through the real /wp/v2/settings — WordPress's schema validation (a 400 for an unknown type or slug), the merge with what is stored, the sanitize callback as register_setting runs it, and the capability check for an author; and the «Datos de la empresa» admin screen as WordPress serves it to a logged-in administrator (its form, the menu) and what options.php does with what it posts (the settings error for a bad email, the merge that leaves enabled_types alone), plus that a page's editor does not carry the content types' fields box; and the SVG upload through the media library's REST API — our prefilter refusing by role and by the allow_svg option, the sanitizer hooked into BOTH upload paths (multipart and sideload), the dimensions WordPress stores (the sanitizer itself is pure.test.mjs, level 1). Plain HTML and forms (adminSession(), tests/lib.mjs): no browser.
//
// 28-10-2026: the requests that do not depend on each other in parallel (the
// two 400s and the author's 403 do not change the option); the extra save
// that only re-proved «a partial save does not erase the others» is gone
// (the partial save test proves it), and so is the final clean-up (the next
// file resets). Round 2 (same day): the GET after the first save is gone (the
// save's own response is WordPress reading the stored option back);
// svg.test.mjs merged in (its uploads need the same option's allow_svg on:
// they run once, after the first save; the «off» case goes last);
// company-screen.test.mjs merged in (the
// same option, through the screen instead of REST; the login starts with the
// reset). The screen came down from editor.test.mjs (level 3) in round 1:
// nothing in it needs a browser — the test even removed the input's
// type=email so the browser would not block the submit. «A page does not get
// the fields» came from meta-panel-editor.test.mjs (level 3): it is the
// content-types module's code, in the server's HTML; its natural home is
// content-types/tests/location-box.test.mjs.
// Seen red (28-10-2026) with the expected WhatsApp digits changed («wa.me
// needs digits only»), with the author's expected status changed to 200,
// with the expected screen error text changed, with the box's id
// expected on the page, with the expected width of the hostile SVG changed,
// and with the author's SVG expected to be uploaded.
import { test, before } from "node:test";
import assert from "node:assert/strict";
import { adminSession, reset, wp, saveCompany, upload, png } from "../../../tests/lib.mjs";

let session, newPage;
before(async () => {
  [, session] = await Promise.all([reset(), adminSession()]);
  newPage = await session.get("/wp-admin/post-new.php?post_type=page");
});

const GOOD = {
  company_name: "Lavandería de Prueba",
  legal_name: "Prueba SL",
  tax_id: "B00000000",
  email: "hola@ejemplo.test",
  phone: "971 12 34 56",
  whatsapp: "+34 600 111 222",
  whatsapp_message: "Hola, quería información",
  address: "Calle Mayor 1\n07001 Palma",
  schedule: "L-V 9-14",
  map_url: "https://maps.google.com/?q=Palma",
  social: { instagram: "https://instagram.com/prueba" },
  enabled_types: ["client", "location"],
  allow_svg: true,
};

test("save via REST: what comes back is what is stored — all keys, WhatsApp digits only, line breaks preserved", async () => {
  const saved = await saveCompany(GOOD);
  assert.equal(saved.company_name, GOOD.company_name);
  assert.equal(saved.email, GOOD.email);
  assert.equal(saved.whatsapp, "34600111222", "wa.me needs digits only, with the country code");
  assert.equal(saved.address, GOOD.address);
  assert.equal(saved.social.instagram, GOOD.social.instagram);
  assert.equal(saved.social.facebook, "", "networks not sent exist, empty");
  assert.deepEqual(saved.enabled_types, ["client", "location"]);
  assert.equal(saved.allow_svg, true);
});

// SVG uploads (moved here from svg.test.mjs, round 2, 28-10-2026: the same option, allow_svg).
const HOSTILE = `<?xml version="1.0"?>
<svg xmlns="http://www.w3.org/2000/svg" width="120" height="60" viewBox="0 0 120 60" onload="alert('onload')">
  <script>alert('script')</script>
  <foreignObject><div xmlns="http://www.w3.org/1999/xhtml">x</div></foreignObject>
  <rect width="120" height="60" fill="#c00"/>
</svg>`;

// Core turns ANY REST upload error into a 500
// (rest_upload_unknown_error), including the «no» from our prefilter. What
// proves the «no» is ours and not just any failure is the message.
function assertDenied(r) {
  assert.notEqual(r.status, 201, `an SVG that should not have been uploaded was: ${r.text}`);
  assert.match(r.json?.message || "", /No tienes permiso para subir ficheros SVG/, r.text);
}

// Uploaded files are named «prueba-…» on purpose: reset() (tests/wp) deletes
// the attachments whose title starts with «prueba-»; any other name is left
// behind in the media library.
// Once, by the first SVG test (allow_svg is on since the first save of this file).
let up, uploading;
const svgUploads = () => (uploading ??= (async () => {
  const [multipart, sideload, sizes, authorSvg, authorPng] = await Promise.all([
    upload("prueba-logo.svg", "image/svg+xml", HOSTILE),
    wp("/media", { method: "POST", raw: HOSTILE, headers: { "Content-Type": "image/svg+xml", "Content-Disposition": 'attachment; filename="prueba-crudo.svg"' } }),
    upload("prueba-medidas.svg", "image/svg+xml", '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 300 100"><circle cx="50" cy="50" r="40"/></svg>'),
    upload("prueba-autor.svg", "image/svg+xml", '<svg xmlns="http://www.w3.org/2000/svg"/>', "author"),
    upload("prueba-autor.png", "image/png", png(), "author"),
  ]);
  assert.equal(multipart.status, 201, multipart.text);
  assert.equal(sideload.status, 201, sideload.text);
  const [stored, storedRaw] = await Promise.all([multipart, sideload].map(async (r) => (await fetch(r.json.source_url)).text()));
  up = { multipart, stored, storedRaw, sizes, authorSvg, authorPng };
})());

test("an SVG with <script> is uploaded by an administrator SANITIZED: no script, no on*, with its dimensions", async () => {
  await svgUploads();
  const r = up.multipart;
  assert.equal(r.json.mime_type, "image/svg+xml");
  assert.equal(r.json.media_details.width, 120);
  assert.equal(r.json.media_details.height, 60);
  assert.match(up.stored, /<rect width="120" height="60" fill="#c00"\/>/, "the drawing stays");
  for (const bad of ["<script", "alert", "onload", "foreignObject"]) assert.ok(!up.stored.includes(bad), `the stored file still has «${bad}»`);
});

test("through the API's other path (raw body = sideload) it also comes out sanitized", async () => {
  await svgUploads();
  assert.ok(!up.storedRaw.includes("<script"), "sideload not sanitized");
});

test("the media library (wp.media) receives the SVG's width, height and full size", async () => {
  await svgUploads();
  const r = up.sizes;
  assert.equal(r.status, 201, r.text);
  assert.equal(r.json.media_details.width, 300);
  assert.equal(r.json.media_details.sizes.full.width, 300);
  assert.equal(r.json.media_details.sizes.full.height, 100);
});

test("an author user can NOT upload SVG (even with allow_svg enabled); a PNG yes", async () => {
  await svgUploads();
  assertDenied(up.authorSvg);
  assert.equal(up.authorPng.status, 201, "the author can upload files: the no is because of the SVG");
});

test("partial save: a PUT with one key does not erase the others", async () => {
  const saved = await saveCompany({ phone: "971 99 99 99" });
  assert.equal(saved.phone, "971 99 99 99");
  assert.equal(saved.company_name, GOOD.company_name);
  assert.deepEqual(saved.enabled_types, ["client", "location"]);
});

test("sanitizing: an invalid email, URL and WhatsApp are NOT saved and the previous value stays", async () => {
  const saved = await saveCompany({ email: "not-an-email", map_url: "javascript:alert(1)", whatsapp: "12", company_name: "<b>Acme</b> SL" });
  assert.equal(saved.email, GOOD.email);
  assert.equal(saved.map_url, GOOD.map_url);
  assert.equal(saved.whatsapp, "34600111222");
  assert.equal(saved.company_name, "Acme SL", "no HTML tags");
});

test("refused without touching the option: an unknown content type or rewrite slug (400), and an author (403)", async () => {
  const [types, slugs, author] = await Promise.all([
    wp("/settings", { method: "POST", body: { estrategia_web_site: { enabled_types: ["client", "services"] } } }),
    wp("/settings", { method: "POST", body: { estrategia_web_site: { rewrite_slugs: { services: "services-slug" } } } }),
    wp("/settings", { as: "author", method: "POST", body: { estrategia_web_site: { phone: "000" } } }),
  ]);
  assert.equal(types.status, 400, "a content type that does not exist is rejected entirely");
  assert.equal(slugs.status, 400, "only the plugin's types and taxonomies");
  assert.equal(author.status, 403, "an author cannot change the data");
  // That the option is untouched is read from the next save's response (the rewrite_slugs test).
});

test("rewrite_slugs (0.2.0): sanitize_title, empty by default, merge by key", async () => {
  const a = await saveCompany({ rewrite_slugs: { client: "My Client!" } });
  assert.notEqual(a.phone, "000", "the author's refused save left nothing (previous test)");
  assert.deepEqual(a.enabled_types, ["client", "location"], "nor did the refused types (previous test)");
  assert.equal(a.rewrite_slugs.client, "my-client");
  assert.equal(a.rewrite_slugs.client_sector, "", "the ones not sent exist, empty");
  assert.equal(a.rewrite_slugs.portfolio_category, "");

  const b = await saveCompany({ rewrite_slugs: { client_sector: "sector-x" } });
  assert.equal(b.rewrite_slugs.client, "my-client", "saving one does not erase the others");
  assert.equal(b.rewrite_slugs.client_sector, "sector-x");
});

// Since 17-10-2026 the content types are switched on and off in «Content types» (content-types module): the screen
// links there and does not send enabled_types, which therefore stays as it was. Read AFTER the REST tests:
// the form carries the stored values, and posting it is what a person saving the screen does.
let screen;
test("«Datos de la empresa» screen: Company and Contact only, Content types in the menu, no type checkboxes, no SVG", async () => {
  screen = await session.get("/wp-admin/admin.php?page=estrategia-web-site");
  assert.equal(screen.status, 200);
  assert.match(screen.text, /<h1[^>]*>\s*Datos de la empresa\s*</);
  assert.ok(!screen.text.includes('name="estrategia_web_site[enabled_types][]"'), "no type checkboxes any more");
  // 1.7.0: only Company and Contact here; Content types and Social networks are in the menu, SVG in Settings › Media.
  assert.match(screen.text, /id="adminmenu"[\s\S]*href=['"][^'"]*page=estrategia-web-content-types['"]/, "Content types is in the menu");
  const wrap = screen.text.slice(screen.text.indexOf('class="wrap'));
  assert.ok(!wrap.includes('name="estrategia_web_site[allow_svg]"'), "SVG is not on this screen any more");
});

test("saving the screen: warns about the mistyped email, keeps the good one and leaves enabled_types alone", async () => {
  const r = await session.submit(screen.text, "options.php", { "estrategia_web_site[company_name]": "Company from the screen", "estrategia_web_site[email]": "this-is-not-an-email" });
  assert.equal(r.status, 302, r.text.slice(0, 300));
  assert.match(r.location, /settings-updated/);
  const [after, now] = await Promise.all([session.get(r.location), wp("/settings")]);
  assert.match(after.text, /correo electrónico no es válido/);
  const saved = now.json.estrategia_web_site;
  assert.equal(saved.company_name, "Company from the screen");
  assert.equal(saved.email, GOOD.email, "the good email was kept");
  assert.deepEqual(saved.enabled_types, ["client", "location"], "the screen does not touch enabled_types");
  assert.equal(saved.phone, "971 99 99 99", "what was not touched stays the same");
});

test("a page does not get the content types' fields (neither the box nor the panel)", () => {
  assert.equal(newPage.status, 200);
  assert.match(newPage.text, /block-editor-page/, "the block editor's page");
  assert.ok(!newPage.text.includes('id="estrategia-web-content-types"'), "no fields box");
  assert.ok(!newPage.text.includes("estrategiaWebContentTypesPanel"), "no sidebar panel");
});

// Last: it turns allow_svg off.
test("with allow_svg disabled, not even the administrator can", async () => {
  await saveCompany({ allow_svg: false });
  assertDenied(await upload("prueba-off.svg", "image/svg+xml", '<svg xmlns="http://www.w3.org/2000/svg"/>'));
});
