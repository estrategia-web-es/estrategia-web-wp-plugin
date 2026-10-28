// NIVEL: 2 — Playground WordPress, no browser. NOT LEVEL 1 BECAUSE: what is tested is the CLASSIC screen (post.php of a type without «editor»): its form posts, WordPress writes the post and redirects, and our notice is printed on the page after the redirect — WordPress's own save path (edit_post, wp_insert_post_data) with our hook in it, which only exists with the server. NOT LEVEL 3: the form, the redirect and the notice are all HTML the server sends; it is posted as the browser would, with its nonce (adminSession().submit, tests/lib.mjs).
//
// «required» on the classic screen (Registrar::check_required_classic()):
// the fixture gment_winery (tests/fixtures/valid/) has no «editor», so its
// fields are the meta box, and two of them are required.
//
// Moved down from level 3 on 28-10-2026 (~39 s with a browser): nothing in
// it was painted by JavaScript — the meta box, core's #message and our
// notice are PHP — and the «no JavaScript errors» check was about core's
// classic screen, not ours.
//
// Seen red (17-10-2026): before check_required_classic() existed, «publish
// with a required field empty» failed — the winery was PUBLISHED with no town
// (status "publish" instead of "draft") — and «emptying a required field of a
// published winery» failed with the town stored as "".
// Seen red (28-10-2026) at level 2, one expected value changed in each
// test (status publish, a notice where there is none, an empty town in the
// screen): the three red; restored, green.
import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { BASE, adminSession, credentials, testApi, wp } from "../../../tests/lib.mjs";

const ct = async (path, { method = "GET", body } = {}) => {
  const res = await fetch(BASE + "/wp-json/estrategia-web-ct-test/v1" + path, { method, headers: body ? { "Content-Type": "application/json" } : {}, body: body ? JSON.stringify(body) : undefined });
  if (!res.ok) throw new Error(`${method} ${path} -> ${res.status} ${await res.text()}`);
  return res.json();
};

let s;
before(async () => {
  // reset() and the theme files on (the option the ct-test setup route sets) in one request: registered from the next one.
  const site = testApi("/setup", { method: "POST", body: { options: { estrategia_web_ct_test: 1 } } });
  [, s] = await Promise.all([site, adminSession(), credentials()]);
});
// What reset() does not undo: the theme files switched on (off also deletes the gment_ posts).
after(() => ct("/setup", { method: "POST", body: { on: false } }));

const F = (key) => `estrategia_web_ct[gment_winery_${key}]`;
const notice = (html) => html.match(/<div class="notice notice-error" id="estrategia-web-ct-required"><p>([^<]*)<\/p>/)?.[1] ?? null;
const input = (html, key) => html.match(new RegExp(`<input[^>]*id="estrategia-web-ct-gment_winery_${key}"[^>]*>`))?.[0] ?? "";
/** Clicks Publish / Update on `screen`: posts its form as the browser does, follows the redirect back to post.php and reads what was stored (at once). */
async function publish(screen, changes) {
  const r = await s.submit(screen, "post.php", { ...changes, publish: "Publicar" });
  assert.equal(r.status, 302, "the save redirects");
  assert.match(r.location, /post\.php\?post=\d+&action=edit/);
  const id = Number(new URL(r.location, BASE).searchParams.get("post"));
  const [page, post] = await Promise.all([s.get(r.location), wp(`/gment_winery/${id}?context=edit`)]);
  return { id, html: page.text, post: post.json };
}

let id, screen, post;

test("publish with a required field empty: it stays a draft, everything typed is kept, and the notice names the field", async () => {
  const fresh = await s.get("/wp-admin/post-new.php?post_type=gment_winery");
  assert.equal(fresh.status, 200);
  assert.doesNotMatch(fresh.text, /block-editor-page/, "the classic screen, not the block editor");
  ({ id, html: screen, post } = await publish(fresh.text, { post_title: "Bodega Son Prim", [F("founded")]: "1990", [F("web")]: "https://sonprim.test/" }));
  assert.equal(post.status, "draft", "not published");
  assert.equal(post.title.raw, "Bodega Son Prim");
  assert.equal(post.meta.gment_winery_founded, 1990, "what was typed is saved");
  assert.equal(post.meta.gment_winery_web, "https://sonprim.test/");
  assert.match(notice(screen) ?? "", /Municipio/, "the empty required field, by its label in the site language");
  assert.doesNotMatch(notice(screen), /Fundada/, "founded was given");
  // Core's own message must not say «published».
  assert.doesNotMatch(screen.match(/<div id="message"[^>]*>([\s\S]*?)<\/div>/)?.[1] ?? "", /publicad|published/i, "core's message is the draft one");
});

test("filling it in and publishing again: published, no notice", async () => {
  ({ html: screen, post } = await publish(screen, { [F("town")]: "Binissalem" }));
  assert.equal(post.status, "publish");
  assert.equal(post.meta.gment_winery_town, "Binissalem");
  assert.equal(notice(screen), null);
});

test("emptying a required field of a published winery: it stays published and the field keeps its previous value", async () => {
  ({ html: screen, post } = await publish(screen, { [F("town")]: "", [F("web")]: "https://sonprim.test/es/" }));
  assert.equal(post.status, "publish", "a live page is not taken offline");
  assert.equal(post.meta.gment_winery_town, "Binissalem", "the previous value");
  assert.equal(post.meta.gment_winery_web, "https://sonprim.test/es/", "the other fields are saved");
  assert.match(notice(screen) ?? "", /Municipio/);
  assert.match(input(screen, "town"), /value="Binissalem"/, "the screen shows what is stored");
});
