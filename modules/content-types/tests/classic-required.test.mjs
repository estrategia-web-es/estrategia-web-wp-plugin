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
// The repeater (24-11-2026): its test is here because the meta box is this
// form (gment_winery has a repeater, «visits»). Seen red with
// save_meta_box() writing the JSON without wp_slash(): the quote's
// backslash lost, the stored text no longer JSON, so nothing was stored
// (REST gave ""); restored, green.
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

// The repeater in the meta box (24-11-2026, class-repeater.php): the same form, rows posted as
// estrategia_web_ct[gment_winery_visits][<row>][<subfield>]. What JavaScript adds (the buttons) is the browser scenario 16's.
const V = (row, sub) => `estrategia_web_ct[gment_winery_visits][${row}][${sub}]`;
test("repeater in the meta box: rows saved in the ORDER of the form, each subfield sanitized, a quote surviving the unslash; emptied, nothing stored", async () => {
  assert.match(screen, /<fieldset class="estrategia-web-ct-repeater" id="estrategia-web-ct-gment_winery_visits"[^>]*data-item-label="Visita"[^>]*data-max="3"/, "the repeater, with its row name and max for the script");
  assert.match(screen, /<input type="hidden" name="estrategia_web_ct\[gment_winery_visits\]" value="">/, "sent even with no rows: removing every row is a change");
  assert.match(screen, /<fieldset class="estrategia-web-ct-row" data-row="0" data-spare=""><legend>Visita 1<\/legend>/, "no rows yet: one spare empty row, so it works without JavaScript");
  // Row 9 BEFORE row 0 (as a moved row would be): the numbers are only names, the order is the form's.
  ({ html: screen, post } = await publish(screen, {
    [V(9, "dia")]: "2026-12-05",
    [V(9, "hora")]: "11:00",
    [V(9, "nota")]: 'Tast "jove"\r\nAmb formatge',
    [V(0, "dia")]: "2026-12-12",
    [V(0, "hora")]: "9:00",
    [V(0, "nota")]: "",
  }));
  assert.equal(post.status, "publish");
  assert.deepEqual(post.meta.gment_winery_visits, [
    { dia: "2026-12-05", hora: "11:00", nota: 'Tast "jove"\nAmb formatge' },
    { dia: "2026-12-12" },
  ], "REST gives the list; «9:00» is not HH:MM; the textarea's \\r\\n is \\n; the quote kept (wp_slash before update_post_meta)");
  assert.match(screen, /<legend>Visita 2<\/legend>[\s\S]*?value="2026-12-12"/, "the screen shows the stored rows in their order");
  assert.match(screen, /data-row="2" data-spare=""/, "two rows and max 3: one spare row");
  // Without JavaScript a row is removed by emptying it.
  ({ html: screen, post } = await publish(screen, { [V(0, "dia")]: "", [V(0, "hora")]: "", [V(0, "nota")]: "", [V(1, "dia")]: "" }));
  assert.deepEqual(post.meta.gment_winery_visits, [], "empty rows are not stored: nothing left");
});
