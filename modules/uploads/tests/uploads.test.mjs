// NIVEL: 2 — Playground WordPress. NOT LEVEL 1 BECAUSE: what is tested is what the real upload path does with our settings (core's big-image scaling with the PHP image editor, the files left on disk, the limit the upload screen announces).
//
// uploads module (replaces Lavandería's uploads MU-plugin):
// - the defaults (20 MB / 2560 px / delete the original) are visible over
//   REST (/wp/v2/settings, show_in_rest); defaults and sanitizing as such are
//   level 1 (uploads-pure.test.mjs);
// - with the threshold at 1200 a 1600×1000 photo ends up 1200 wide, with no
//   original_image in the meta and WITHOUT the original file on disk;
// - with "Delete the original" off, the original stays;
// - the upload screen announces the setting's limit.
//
/// Requests (28-10-2026): ONE JPEG, 1600×1000 (it was 3000×2000: every size
// is made from it in PHP-wasm, and all that matters is that it is over the
// threshold), generated and uploaded under two names
// (it was three, one per test); the 2560 upload is gone: 2560 is also
// core's own big_image_size_threshold, so it could not tell our code from
// core's — the 1200 one can. The upload screen is read with adminSession()
// (plain HTML, no browser: the limit is printed by the server).
// Seen red (28-10-2026) with the threshold filter returning core's 2560
// (the 1200 photo came out 2560 wide), with upload_size_limit() ignoring
// the setting (no «7 MB» on the screen) and with the expected default
// max_mb at 21.
import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { reset, testApi, wp, adminSession, credentials } from "../../../tests/lib.mjs";

const RUN = Date.now().toString(36);
let photo;
const setUploads = async (value) => {
  const r = await wp("/settings", { method: "POST", body: { estrategia_web_uploads: value } });
  assert.equal(r.status, 200, r.text);
  return r.json.estrategia_web_uploads;
};
// The upload helper of tests/lib.mjs, with the photo made once.
const upload = async (name) => {
  const form = new FormData();
  form.append("file", new Blob([photo], { type: "image/jpeg" }), name);
  form.append("title", "prueba-" + name); // «prueba-»: reset() deletes it.
  const r = await wp("/media", { method: "POST", raw: form });
  assert.equal(r.status, 201, r.text);
  return r.json;
};
// Whether the URL serves a real image. Playground answers 200 with a
// WordPress page for anything that does not exist: check the content type.
const isImage = async (url) => {
  const res = await fetch(url);
  return res.ok && (res.headers.get("content-type") || "").startsWith("image/");
};
const dirOf = (url) => url.slice(0, url.lastIndexOf("/") + 1);

let defaults;
before(async () => {
  let jpeg;
  [, jpeg, defaults] = await Promise.all([reset(), testApi(`/jpeg?w=1600&h=1000`), credentials().then(() => wp("/settings"))]);
  photo = Buffer.from(jpeg.base64, "base64");
});
// reset() does not touch this option: back to the defaults.
after(() => setUploads({ max_mb: 20, threshold: 2560, delete_original: true }));

test("uploads: defaults 20 MB / 2560 px / delete original, visible over REST", () => {
  assert.deepEqual(defaults.json.estrategia_web_uploads, { max_mb: 20, threshold: 2560, delete_original: true });
});

test("uploads: with the threshold at 1200, a 1600×1000 photo ends up 1200, without original_image and without the original on disk", async () => {
  const saved = await setUploads({ threshold: 1200, delete_original: true });
  assert.equal(saved.threshold, 1200);
  const r = await upload(`upload-${RUN}.jpg`);
  assert.equal(r.media_details.width, 1200);
  assert.equal(r.media_details.original_image, undefined, "the meta must not point to the original");
  const [scaled, original] = await Promise.all([isImage(r.source_url), isImage(dirOf(r.source_url) + `upload-${RUN}.jpg`)]);
  assert.ok(scaled, "the scaled copy is served: " + r.source_url);
  assert.equal(original, false, "the uploaded original is no longer on disk");
});

test("uploads: with \"Delete the original\" off, the original stays; the upload screen announces the setting's limit", async () => {
  await setUploads({ delete_original: false, max_mb: 7 });
  const [r, screen] = await Promise.all([
    upload(`keep-${RUN}.jpg`),
    adminSession().then((s) => s.get("/wp-admin/media-new.php")),
  ]);
  assert.equal(r.media_details.original_image, `keep-${RUN}.jpg`);
  assert.ok(await isImage(dirOf(r.source_url) + `keep-${RUN}.jpg`), "the original is still served");
  assert.equal(screen.status, 200);
  assert.match(screen.text.replace(/<[^>]+>/g, ""), /\b7 MB\b/, "the screen must announce 7 MB");
});
