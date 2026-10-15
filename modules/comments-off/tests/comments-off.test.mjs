// NIVEL: 2 — Playground WordPress, because it WRITES: switches the module on, creates a post with a comment and checks the page, the feed and the REST API.
//
// comments-off module (Default: off):
// - before switching it on (a check that the test tests something): the
//   open post accepts a comment and the comment shows on the page;
// - on: the comment no longer shows, nor does the form, commenting over REST
//   says "closed", the comments feed gives 404 and <head> does not link it;
// - nothing is written to the database: switched off, the post is open
//   again with its comment.
import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { BASE, reset, testApi, wp, html } from "../../../tests/lib.mjs";

const RUN = Date.now().toString(36);
const TEXT = `Test comment ${RUN}`;
let post;

const setModule = async (on) => {
  const m = await testApi("/modules");
  const base = (m.saved ?? m.loaded).filter((s) => s !== "comments-off");
  await testApi("/modules", { method: "POST", body: { active: on ? base.concat("comments-off") : base } });
};

before(async () => {
  await reset();
  const r = await wp("/posts", { method: "POST", body: { title: "With comments " + RUN, status: "publish", comment_status: "open", ping_status: "open" } });
  assert.equal(r.status, 201, r.text);
  post = r.json;
  const c = await wp("/comments", { method: "POST", body: { post: post.id, content: TEXT } });
  assert.equal(c.status, 201, "with the module off, commenting works: " + c.text);
});
after(async () => {
  if (post) await wp(`/posts/${post.id}?force=true`, { method: "DELETE" });
  await testApi("/modules", { method: "POST", body: { active: null } });
});

test("comments-off: off (default), the comment shows", async () => {
  assert.ok(!(await testApi("/modules")).loaded.includes("comments-off"), "ships off");
  const page = await html(post.link);
  assert.ok(page.text.includes(TEXT), "the comment is rendered with the module off");
});

test("comments-off: on, no comments, no form, no commenting over REST, no feed", async () => {
  await setModule(true);
  const page = await html(post.link);
  assert.equal(page.status, 200);
  assert.ok(!page.text.includes(TEXT), "the comment is not rendered");
  assert.ok(!/id="respond"|comment-form/.test(page.text), "no comment form");
  assert.ok(!/comments\/feed|application\/rss\+xml[^>]*Comentarios|feed\/"[^>]*comment/i.test(page.text.match(/<head>[\s\S]*<\/head>/)?.[0] || ""), "<head> does not link comment feeds");

  const c = await wp("/comments", { method: "POST", body: { post: post.id, content: "another " + RUN } });
  assert.equal(c.status, 403, "commenting over REST is closed: " + c.text);

  const feed = await fetch(BASE + "/comments/feed/");
  assert.equal(feed.status, 404, "the comments feed does not exist");
  const postFeed = await fetch(post.link + "feed/");
  assert.equal(postFeed.status, 404, "nor does a single post's comments feed");
});

test("comments-off: writes nothing; switched off, everything is back as it was", async () => {
  await setModule(false);
  const r = await wp(`/posts/${post.id}?context=edit`);
  assert.equal(r.json.comment_status, "open");
  assert.ok((await html(post.link)).text.includes(TEXT));
});
