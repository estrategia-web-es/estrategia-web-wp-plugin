// NIVEL: 2 — Playground WordPress. NOT LEVEL 1 BECAUSE: what is tested is what the REAL image editor writes on upload (the WebP files, the main file and original_image) and what core's srcset does with the content's stored URLs after regenerating.
//
// WebP (0.5.1, includes/class-webp.php):
// - an uploaded JPEG generates its sizes as WebP, and the main file
//   (“full”) too: the uploaded one stays as original_image;
// - with the estrategia_web_site_webp filter set to false, sizes come out JPEG;
// - THE TRAP, with what is already uploaded: on regenerating (what `wp media
//   regenerate` does, imitated by the test route) the URLs stored in the
//   content do NOT change, but the meta does (the main one becomes .webp). The
//   main .jpg URL still serves but IS LEFT WITHOUT SRCSET (the whole JPEG
//   at any width); an intermediate size's (…-768x480.jpg) points to a
//   deleted file. Writing back the media library's current URL brings the
//   srcset back, in WebP.
// Which formats become WebP (PNG too), the off switch and «this PHP cannot
// write WebP» are decisions of Webp::output_format(): level 1,
// webp-pure.test.mjs.
//
// Requests (28-10-2026): ONE JPEG generated and uploaded twice; the PNG
// upload and the webp-support check went to level 1 (a PHP without WebP
// fails the first test anyway: its sizes come out .jpg); the «filter off»
// upload IS the trap's photo (it was uploaded twice); independent requests
// go together (Promise.all).
//
// Seen failing on 2026-10-06 before class-webp.php was written: sizes came
// out as .jpg. And the first version of this test assumed the original
// stayed JPEG with its srcset in WebP: it failed, and that is how the real
// shape of the trap became known.
// Seen red (28-10-2026) with output_format() ignoring the filter (the
// «filter off» photo came out .webp) and with the first test expecting 30
// sizes.
// Round 2 (28-10-2026): reset and «keep the originals» in ONE request (core /setup). Seen red
// with that option's name misspelt (the original was deleted: no original_image).
import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { testApi, seedPage, html, wp, credentials } from "../../../tests/lib.mjs";

// Unique names per run: Playground persists between runs and a repeated
// name gets a suffix (-1) or clashes with files from the previous run.
const RUN = Date.now().toString(36);
let photo;
const upload = async (name) => {
  const form = new FormData();
  form.append("file", new Blob([photo], { type: "image/jpeg" }), name);
  form.append("title", "prueba-" + name); // «prueba-»: reset() deletes it.
  const r = await wp("/media", { method: "POST", raw: form });
  assert.equal(r.status, 201, r.text);
  return r.json;
};
const webpSetup = (enabled) => testApi("/webp-setup", { method: "POST", body: { enabled } });
// Whether the URL serves an image. The status is not enough: Playground answers
// 200 with a WordPress page for anything that does not exist.
const servesImage = async (url) => {
  const res = await fetch(url);
  return res.ok && /^image\//.test(res.headers.get("content-type") || "");
};
const sizeFiles = (json) => Object.entries(json.media_details.sizes).filter(([name]) => name !== "full");

// The uploads module (Default: on) DELETES the original WordPress keeps when
// converting to WebP, and this test looks at exactly that original: deletion
// is switched off while this test runs and restored when it ends (reset()
// does not touch that option).
const keepOriginals = (keep) => wp("/settings", { method: "POST", body: { estrategia_web_uploads: { delete_original: !keep } } });

before(async () => {
  let jpeg;
  // The reset and «keep the originals» in ONE request (round 2, the core /setup route: the option
  // goes through update_option, so through the setting's own sanitize, as over REST).
  [, jpeg] = await Promise.all([
    testApi("/setup", { method: "POST", body: { options: { estrategia_web_uploads: { delete_original: false } } } }),
    testApi(`/jpeg?w=1600&h=1000`),
    credentials(),
  ]);
  photo = Buffer.from(jpeg.base64, "base64");
});
after(() => keepOriginals(false));

test("webp (0.5.1): an uploaded JPEG generates its sizes as WebP, the “full” too; the .jpg stays as original_image", async () => {
  const r = await upload(`webp-${RUN}-1.jpg`);
  // Measured on 2026-10-06: WordPress also converts the main file and
  // keeps the uploaded one as original_image (not deleted, but not served).
  assert.ok(r.source_url.endsWith(`/webp-${RUN}-1.webp`), `the main file does not become WebP (does this PHP's editor write WebP? /webp-support): ${r.source_url}`);
  assert.equal(r.media_details.original_image, `webp-${RUN}-1.jpg`);
  const sizes = sizeFiles(r);
  assert.ok(sizes.length >= 3, `too few sizes: ${sizes.map(([n]) => n)}`);
  for (const [name, size] of sizes) {
    assert.match(size.file, /\.webp$/, `${name}: ${size.file} is not WebP`);
    assert.equal(size.mime_type, "image/webp", `${name}: ${size.mime_type}`);
  }
  // With the next test's switch at the same time: the files are already written.
  const [served] = await Promise.all([Promise.all(sizes.map(([, size]) => servesImage(size.source_url))), webpSetup(false)]);
  assert.deepEqual(served, sizes.map(() => true), "every WebP size is served");
});

test("webp (0.5.1): with the filter set to false sizes come out JPEG; THE TRAP — on regenerating, the content URLs do not change: the main .jpg loses its srcset and an intermediate size's breaks", async () => {
  // A photo uploaded BEFORE (WebP off), inserted in a page at full size
  // and at 768 (medium_large), as the editor stores it. (WebP was switched off at the end of the previous test.)
  const r = await upload(`webp-${RUN}-4.jpg`);
  for (const [name, size] of sizeFiles(r)) {
    assert.match(size.file, /\.jpg$/, `${name}: ${size.file} should not be WebP with the filter off`);
  }
  const id = r.id;
  const full = r.source_url;
  const mid = r.media_details.sizes.medium_large.source_url;
  assert.match(mid, /-768x480\.jpg$/);
  const image = (src, slug) =>
    `<!-- wp:image {"id":${id},"sizeSlug":"${slug}"} --><figure class="wp-block-image size-${slug}"><img src="${src}" alt="${slug}" class="wp-image-${id}"/></figure><!-- /wp:image -->`;
  const page = await seedPage(image(full, "full") + image(mid, "medium_large"), "prueba-webp-regenerar");
  const imgs = async () => {
    const { text } = await html(page.url);
    const tag = (alt) => text.match(new RegExp(`<img[^>]*alt="${alt}"[^>]*>`))?.[0] || "";
    const attr = (t, a) => t.match(new RegExp(`\\s${a}="([^"]*)"`))?.[1] ?? null;
    return Object.fromEntries(["full", "medium_large"].map((alt) => [alt, { src: attr(tag(alt), "src"), srcset: attr(tag(alt), "srcset") }]));
  };
  const [before] = await Promise.all([imgs(), webpSetup(null)]); // The page's render does not depend on the switch: only uploads do.
  assert.ok(before.full.srcset && before.medium_large.srcset, "before regenerating, both have a srcset");
  assert.ok(!/\.webp/.test(before.full.srcset), "before, the srcset is JPEG");

  // WebP is back on and the photo is regenerated, like `wp media regenerate` without --skip-delete.
  const regen = await testApi(`/regenerate/${id}`, { method: "POST", body: {} });
  assert.match(regen.sizes.medium_large, /-768x480\.webp$/, JSON.stringify(regen));
  assert.ok(regen.file.endsWith(`/webp-${RUN}-4.webp`), `the meta's main file does not become .webp: ${regen.file}`);

  const [after, now] = await Promise.all([imgs(), wp(`/media/${id}`).then((m) => m.json.source_url)]);
  assert.equal(after.full.src, full, "the original's URL stored in the content does not change");
  assert.equal(after.medium_large.src, mid, "the intermediate size's URL stored in the content does not change");
  const [fullServed, midServed] = await Promise.all([servesImage(after.full.src), servesImage(after.medium_large.src)]);
  // The main .jpg is still on disk (it is the original_image) and is served...
  assert.equal(fullServed, true, "the main .jpg still exists");
  // ...but it no longer matches the meta: WordPress gives it no srcset and the
  // browser downloads the whole JPEG at any width. WORSE than before.
  assert.equal(after.full.srcset, null, "expected: the main .jpg is left without srcset");
  assert.equal(midServed, false, "expected: the intermediate .jpg was deleted on regenerating");
  assert.equal(after.medium_large.srcset, null, "expected: no srcset, because the .jpg is no longer in the meta");

  // The fix: write back into the content the URL the media library now
  // gives (source_url, already .webp). With it the srcset comes back, in WebP.
  assert.ok(now.endsWith(`/webp-${RUN}-4.webp`), now);
  await seedPage(image(now, "full"), "prueba-webp-regenerar");
  const fixed = await imgs();
  assert.match(fixed.full.srcset || "", /-768x480\.webp/, "with the new URL the srcset comes back, in WebP");
});
