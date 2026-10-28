// NIVEL: 2 — Playground WordPress. NOT LEVEL 1 BECAUSE: what is tested is what WordPress serves with the module on (the rendered post, core's comments feed and REST comment endpoint), and those only exist with WordPress running.
//
// comments-off module (Default: off):
// - before switching it on (a check that the test tests something, and that
//   it ships off): the open post accepts a comment and the comment shows on
//   the page;
// - on: the comment no longer shows, nor does the form, commenting over REST
//   says "closed", the comments feed gives 404 and <head> does not link it;
// - nothing is written to the database: switched off, the post is open
//   again with its comment.
//
// Requests (28-10-2026): independent requests go together (Promise.all);
// the modules list is read once (it was read before each switch).
// Seen red (28-10-2026) with the module's comments_open filter removed (the
// on-test: commenting over REST went through, 201), with its header at
// «Default: on» (the off-test: «ships off») and with the switched-off test
// expecting comment_status «closed».
import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { BASE, reset, testApi, wp, html, credentials } from "../../../tests/lib.mjs";

const RUN = Date.now().toString(36);
const TEXT = `Test comment ${RUN}`;
let post, defaults;

const setModule = (on) => testApi("/modules", { method: "POST", body: { active: on ? defaults.concat("comments-off") : defaults } });

before(async () => {
  await Promise.all([reset(), credentials()]);
  const r = await wp("/posts", { method: "POST", body: { title: "With comments " + RUN, status: "publish", comment_status: "open", ping_status: "open" } });
  assert.equal(r.status, 201, r.text);
  post = r.json;
  const c = await wp("/comments", { method: "POST", body: { post: post.id, content: TEXT } });
  assert.equal(c.status, 201, "with the module off, commenting works: " + c.text);
});
// The post is not a type reset() deletes: removed here. The modules list is reset() 's.
after(() => post && wp(`/posts/${post.id}?force=true`, { method: "DELETE" }));

test("comments-off: off (default), the comment shows", async () => {
  const [modules, page] = await Promise.all([testApi("/modules"), html(post.link)]);
  assert.ok(!modules.loaded.includes("comments-off"), "ships off");
  defaults = modules.loaded;
  assert.ok(page.text.includes(TEXT), "the comment is rendered with the module off");
});

test("comments-off: on, no comments, no form, no commenting over REST, no feed", async () => {
  await setModule(true);
  const [page, c, feed, postFeed] = await Promise.all([
    html(post.link),
    wp("/comments", { method: "POST", body: { post: post.id, content: "another " + RUN } }),
    fetch(BASE + "/comments/feed/"),
    fetch(post.link + "feed/"),
  ]);
  assert.equal(page.status, 200);
  assert.ok(!page.text.includes(TEXT), "the comment is not rendered");
  assert.ok(!/id="respond"|comment-form/.test(page.text), "no comment form");
  assert.ok(!/comments\/feed|application\/rss\+xml[^>]*Comentarios|feed\/"[^>]*comment/i.test(page.text.match(/<head>[\s\S]*<\/head>/)?.[0] || ""), "<head> does not link comment feeds");
  assert.equal(c.status, 403, "commenting over REST is closed: " + c.text);
  assert.equal(feed.status, 404, "the comments feed does not exist");
  assert.equal(postFeed.status, 404, "nor does a single post's comments feed");
});

test("comments-off: writes nothing; switched off, everything is back as it was", async () => {
  await setModule(false);
  const [r, page] = await Promise.all([wp(`/posts/${post.id}?context=edit`), html(post.link)]);
  assert.equal(r.json.comment_status, "open");
  assert.ok(page.text.includes(TEXT));
});
