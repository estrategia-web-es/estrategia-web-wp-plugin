// NIVEL: 2 — Playground WordPress. NOT LEVEL 1 BECAUSE: what is tested is our render_block filters on core's real output: a Query of posts with uploaded logos (their srcset, the 200 px size) and the card's title, rendered like Lavandería's client cards.
//
// featured-images module:
// - a fixed-width core/post-featured-image (82px) gets sizes="82px" (with
//   "auto, " if it is lazy), also inside a carousel, which used to set its
//   own;
// - the srcset has the small 200 px candidate (estrategia-web-small);
// - alt="" when the alt repeats the title the card shows next to it; it
//   stays if it describes something else, if the template does not show the
//   title or if the image is a link.
// Requests (28-10-2026): the two logos are uploaded WITH their alt and at
// the same time, the page is read ONCE for the three tests, and the posts
// are deleted at once.
// Seen red (28-10-2026) with the module's sizes filter unhooked (the two
// sizes tests) and with its alt filter unhooked (the alt test).
// Round 2 (28-10-2026): reset and page in ONE request (core /setup), before the posts. Seen red
// again with the sizes filter unhooked.
import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { png, testApi, html, wp, credentials } from "../../../tests/lib.mjs";

const RUN = Date.now().toString(36);
const posts = [];

// One upload WITH its alt (the media endpoint takes alt_text in the same multipart) and one post.
const makePost = async (title, alt, n) => {
  const form = new FormData();
  form.append("file", new Blob([png(300, 224)], { type: "image/png" }), `logo-${RUN}-${n}.png`);
  form.append("title", `prueba-logo-${RUN}-${n}`); // «prueba-»: reset() deletes it.
  form.append("alt_text", alt);
  const media = await wp("/media", { method: "POST", raw: form });
  assert.equal(media.status, 201, media.text);
  assert.equal(media.json.alt_text, alt);
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

let text;
before(async () => {
  // The reset and the page in ONE request (round 2, the core /setup route): the page only names
  // the posts by their search term, so it can exist before them.
  const [setup] = await Promise.all([
    testApi("/setup", { method: "POST", body: { pages: [{ slug: `cards-${RUN}`, content:
    `<!-- wp:group {"className":"lista-a"} --><div class="wp-block-group lista-a">${query(chip(), 31)}</div><!-- /wp:group -->` +
      `<!-- wp:group {"className":"lista-b"} --><div class="wp-block-group lista-b">${query(chip(undefined, false), 32)}</div><!-- /wp:group -->` +
      `<!-- wp:group {"className":"lista-c"} --><div class="wp-block-group lista-c">${query(chip('{"isLink":true,"width":"82px"}'), 33)}</div><!-- /wp:group -->` +
      `<!-- wp:estrategia-web/carousel {"label":"Clients","className":"lista-d","perViewMobile":1,"perViewTablet":1,"perViewDesktop":1} -->${query(chip(), 34)}<!-- /wp:estrategia-web/carousel -->` }] } }),
    credentials(),
  ]);
  await Promise.all([makePost(`Hotel Beta ${RUN}`, `Hotel Beta ${RUN}`, 0), makePost(`Hotel Alpha ${RUN}`, `Hotel facade with palm trees`, 1)]);
  ({ text } = await html(setup.pages[0].url)); // Read once: every test looks at this page.
});
// The posts are not a type reset() deletes: removed here (the logos are, by their «prueba-» title).
after(() => Promise.all(posts.map((id) => wp(`/posts/${id}?force=true`, { method: "DELETE" }))));

test("featured-images: fixed width in px → sizes of that width, with the 200 candidate in the srcset", () => {
  for (const tag of imgs(text, "lista-a")) {
    assert.match(attr(tag, "sizes"), /^(auto, )?82px$/, tag);
    assert.match(attr(tag, "srcset"), /-200x149\.\w+ 200w/, "the small size is missing: " + tag);
  }
});

test("featured-images: inside the carousel too (the carousel respects a fixed sizes)", () => {
  const list = imgs(text, "lista-d");
  assert.equal(list.length, 2);
  for (const tag of list) assert.match(attr(tag, "sizes"), /^(auto, )?82px$/, tag);
});

test("featured-images: empty alt ONLY if it repeats the title the card shows next to it", () => {
  // Ordered by title: Alpha (alt describing the photo), Beta (alt = title).
  const alts = (marker) => imgs(text, marker).map((tag) => attr(tag, "alt"));
  assert.deepEqual(alts("lista-a"), ["Hotel facade with palm trees", ""], "only an alt that repeats the title becomes decorative; one describing the photo is left alone");
  assert.deepEqual(alts("lista-b"), ["Hotel facade with palm trees", `Hotel Beta ${RUN}`], "with no title in the template, the alt is the only thing naming it");
  // With isLink, core sets the TITLE as alt (it is the link's name): it stays.
  assert.deepEqual(alts("lista-c"), [`Hotel Alpha ${RUN}`, `Hotel Beta ${RUN}`], "a linked image keeps its alt (it is the link's name)");
});
