// NIVEL: 2 — Playground WordPress, because it WRITES: uploads logos, creates posts with a featured image and renders a Query like Lavandería's client cards.
//
// featured-images module:
// - a fixed-width core/post-featured-image (82px) gets sizes="82px" (with
//   "auto, " if it is lazy), also inside a carousel, which used to set its
//   own;
// - the srcset has the small 200 px candidate (estrategia-web-small);
// - alt="" when the alt repeats the title the card shows next to it; it
//   stays if it describes something else, if the template does not show the
//   title or if the image is a link.
import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { reset, upload, png, seedPage, html, wp } from "../../../tests/lib.mjs";

const RUN = Date.now().toString(36);
const posts = [];

const makePost = async (title, alt) => {
  const media = await upload(`logo-${RUN}-${posts.length}.png`, "image/png", png(300, 224));
  assert.equal(media.status, 201, media.text);
  await wp(`/media/${media.json.id}`, { method: "POST", body: { alt_text: alt } });
  const r = await wp("/posts", { method: "POST", body: { title, status: "publish", featured_media: media.json.id, content: RUN } });
  assert.equal(r.status, 201, r.text);
  posts.push(r.json.id);
  return { media: media.json, post: r.json };
};

const query = (inner, id) =>
  `<!-- wp:query {"queryId":${id},"query":{"perPage":10,"postType":"post","search":"${RUN}","order":"asc","orderBy":"title","inherit":false}} --><div class="wp-block-query"><!-- wp:post-template -->${inner}<!-- /wp:post-template --></div><!-- /wp:query -->`;
const chip = (image = '{"width":"82px","height":"54px","sizeSlug":"medium"}', title = true) =>
  `<!-- wp:group {"className":"chip"} --><div class="wp-block-group chip"><!-- wp:post-featured-image ${image} /-->${title ? '<!-- wp:post-title {"level":0} /-->' : ""}</div><!-- /wp:group -->`;

/** The <img class="wp-post-image"> of each list, by the wrapper class. */
const imgs = (text, marker) => {
  const at = text.indexOf(marker);
  assert.ok(at >= 0, "missing " + marker);
  const end = text.indexOf("</ul>", at);
  return [...text.slice(at, end).matchAll(/<img [^>]*wp-post-image[^>]*>/g)].map((m) => m[0]);
};
const attr = (tag, name) => tag.match(new RegExp(`\\s${name}="([^"]*)"`))?.[1];

let page;
before(async () => {
  await reset();
  await makePost(`Hotel Beta ${RUN}`, `Hotel Beta ${RUN}`);
  await makePost(`Hotel Alpha ${RUN}`, `Hotel facade with palm trees`);
  page = await seedPage(
    `<!-- wp:group {"className":"lista-a"} --><div class="wp-block-group lista-a">${query(chip(), 31)}</div><!-- /wp:group -->` +
      `<!-- wp:group {"className":"lista-b"} --><div class="wp-block-group lista-b">${query(chip(undefined, false), 32)}</div><!-- /wp:group -->` +
      `<!-- wp:group {"className":"lista-c"} --><div class="wp-block-group lista-c">${query(chip('{"isLink":true,"width":"82px"}'), 33)}</div><!-- /wp:group -->` +
      `<!-- wp:estrategia-web/carousel {"label":"Clients","className":"lista-d","perViewMobile":1,"perViewTablet":1,"perViewDesktop":1} -->${query(chip(), 34)}<!-- /wp:estrategia-web/carousel -->`,
    `cards-${RUN}`
  );
});
after(async () => {
  for (const id of posts) await wp(`/posts/${id}?force=true`, { method: "DELETE" });
});

test("featured-images: fixed width in px → sizes of that width, with the 200 candidate in the srcset", async () => {
  const { text } = await html(page.url);
  for (const tag of imgs(text, "lista-a")) {
    assert.match(attr(tag, "sizes"), /^(auto, )?82px$/, tag);
    assert.match(attr(tag, "srcset"), /-200x149\.\w+ 200w/, "the small size is missing: " + tag);
  }
});

test("featured-images: inside the carousel too (the carousel respects a fixed sizes)", async () => {
  const { text } = await html(page.url);
  const list = imgs(text, "lista-d");
  assert.equal(list.length, 2);
  for (const tag of list) assert.match(attr(tag, "sizes"), /^(auto, )?82px$/, tag);
});

test("featured-images: empty alt ONLY if it repeats the title the card shows next to it", async () => {
  const { text } = await html(page.url);
  // Ordered by title: Alpha (alt describing the photo), Beta (alt = title).
  const alts = (marker) => imgs(text, marker).map((tag) => attr(tag, "alt"));
  assert.deepEqual(alts("lista-a"), ["Hotel facade with palm trees", ""], "only an alt that repeats the title becomes decorative; one describing the photo is left alone");
  assert.deepEqual(alts("lista-b"), ["Hotel facade with palm trees", `Hotel Beta ${RUN}`], "with no title in the template, the alt is the only thing naming it");
  // With isLink, core sets the TITLE as alt (it is the link's name): it stays.
  assert.deepEqual(alts("lista-c"), [`Hotel Alpha ${RUN}`, `Hotel Beta ${RUN}`], "a linked image keeps its alt (it is the link's name)");
});
