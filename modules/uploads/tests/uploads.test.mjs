// NIVEL: 2 — Playground WordPress, because it WRITES: uploads photos to the media library over REST and the real PHP image editor scales them down.
//
// uploads module (replaces Lavandería's uploads MU-plugin):
// - a 3000×2000 photo ends up 2560 wide, with no original_image in the
//   meta and WITHOUT the original file on disk;
// - with "Delete the original" off, the original stays;
// - with the threshold changed to 1200, the scaled copy is 1200;
// - the settings are read and saved over /wp/v2/settings (show_in_rest).
import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { reset, testApi, upload, wp, BASE } from "../../../tests/lib.mjs";

const RUN = Date.now().toString(36);
const jpeg = async (w, h) => Buffer.from((await testApi(`/jpeg?w=${w}&h=${h}`)).base64, "base64");
const setUploads = async (value) => {
  const r = await wp("/settings", { method: "POST", body: { estrategia_web_uploads: value } });
  assert.equal(r.status, 200, r.text);
  return r.json.estrategia_web_uploads;
};
// Whether the URL serves a real image. Playground answers 200 with a
// WordPress page for anything that does not exist: check the content type.
const isImage = async (url) => {
  const res = await fetch(url);
  return res.ok && (res.headers.get("content-type") || "").startsWith("image/");
};

before(reset);
after(() => setUploads({ max_mb: 20, threshold: 2560, delete_original: true }));

test("uploads: defaults 20 MB / 2560 px / delete original, visible over REST", async () => {
  const r = await wp("/settings");
  assert.deepEqual(r.json.estrategia_web_uploads, { max_mb: 20, threshold: 2560, delete_original: true });
});

test("uploads: a 3000×2000 photo ends up 2560, without original_image and without the original on disk", async () => {
  const r = await upload(`upload-${RUN}.jpg`, "image/jpeg", await jpeg(3000, 2000));
  assert.equal(r.status, 201, r.text);
  const d = r.json.media_details;
  assert.equal(d.width, 2560);
  assert.equal(d.original_image, undefined, "the meta must not point to the original");
  assert.ok(await isImage(r.json.source_url), "the scaled copy is served: " + r.json.source_url);
  const dir = r.json.source_url.slice(0, r.json.source_url.lastIndexOf("/") + 1);
  assert.equal(await isImage(dir + `upload-${RUN}.jpg`), false, "the uploaded original is no longer on disk");
});

test("uploads: with \"Delete the original\" off, the original stays", async () => {
  await setUploads({ delete_original: false });
  const r = await upload(`keep-${RUN}.jpg`, "image/jpeg", await jpeg(3000, 2000));
  assert.equal(r.status, 201, r.text);
  assert.equal(r.json.media_details.original_image, `keep-${RUN}.jpg`);
  const dir = r.json.source_url.slice(0, r.json.source_url.lastIndexOf("/") + 1);
  assert.ok(await isImage(dir + `keep-${RUN}.jpg`), "the original is still served");
});

test("uploads: the threshold is configurable (1200 px)", async () => {
  const saved = await setUploads({ threshold: 1200, delete_original: true });
  assert.equal(saved.threshold, 1200);
  const r = await upload(`threshold-${RUN}.jpg`, "image/jpeg", await jpeg(2000, 1000));
  assert.equal(r.status, 201, r.text);
  assert.equal(r.json.media_details.width, 1200);
});

test("uploads: the upload limit is the setting's (announced on the upload media screen)", async () => {
  await setUploads({ max_mb: 7 });
  // A new browser does not have the --login session: log in through
  // wp-login with Playground's admin (admin / password).
  const { chromium } = (await import("../../../tests/lib.mjs")).playwright();
  const browser = await chromium.launch();
  try {
    const page = await browser.newPage();
    await page.goto(BASE + "/wp-login.php");
    await page.fill("#user_login", "admin");
    await page.fill("#user_pass", "password");
    await Promise.all([page.waitForURL(/wp-admin/), page.click("#wp-submit")]);
    await page.goto(BASE + "/wp-admin/media-new.php");
    const text = await page.locator("body").innerText();
    assert.match(text, /7 MB/, "the screen must announce 7 MB");
  } finally {
    await browser.close();
  }
});
