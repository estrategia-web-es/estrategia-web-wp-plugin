// NIVEL: 2 — WordPress de Playground, porque ESCRIBE: enciende el módulo, crea una entrada con un comentario y mira la página, el feed y la API REST.
//
// Módulo comments-off (Default: off):
// - antes de encenderlo (comprobación de que la prueba prueba algo): la
//   entrada abierta acepta un comentario y el comentario se ve en la página;
// - encendido: el comentario ya no se ve, ni el formulario, comentar por REST
//   da «cerrado», el feed de comentarios da 404 y el <head> no lo enlaza;
// - nada se escribe en la base de datos: apagado, la entrada vuelve a estar
//   abierta con su comentario.
import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { BASE, reset, testApi, wp, html } from "../../../tests/lib.mjs";

const RUN = Date.now().toString(36);
const TEXT = `Comentario de prueba ${RUN}`;
let post;

const setModule = async (on) => {
  const m = await testApi("/modules");
  const base = (m.saved ?? m.loaded).filter((s) => s !== "comments-off");
  await testApi("/modules", { method: "POST", body: { active: on ? base.concat("comments-off") : base } });
};

before(async () => {
  await reset();
  const r = await wp("/posts", { method: "POST", body: { title: "Con comentarios " + RUN, status: "publish", comment_status: "open", ping_status: "open" } });
  assert.equal(r.status, 201, r.text);
  post = r.json;
  const c = await wp("/comments", { method: "POST", body: { post: post.id, content: TEXT } });
  assert.equal(c.status, 201, "con el módulo apagado se puede comentar: " + c.text);
});
after(async () => {
  if (post) await wp(`/posts/${post.id}?force=true`, { method: "DELETE" });
  await testApi("/modules", { method: "POST", body: { active: null } });
});

test("comments-off: apagado (por defecto), el comentario se ve", async () => {
  assert.ok(!(await testApi("/modules")).loaded.includes("comments-off"), "nace apagado");
  const page = await html(post.link);
  assert.ok(page.text.includes(TEXT), "el comentario se pinta con el módulo apagado");
});

test("comments-off: encendido, ni comentarios, ni formulario, ni comentar por REST, ni feed", async () => {
  await setModule(true);
  const page = await html(post.link);
  assert.equal(page.status, 200);
  assert.ok(!page.text.includes(TEXT), "el comentario no se pinta");
  assert.ok(!/id="respond"|comment-form/.test(page.text), "sin formulario de comentarios");
  assert.ok(!/comments\/feed|application\/rss\+xml[^>]*Comentarios|feed\/"[^>]*comment/i.test(page.text.match(/<head>[\s\S]*<\/head>/)?.[0] || ""), "el <head> no enlaza feeds de comentarios");

  const c = await wp("/comments", { method: "POST", body: { post: post.id, content: "otro " + RUN } });
  assert.equal(c.status, 403, "comentar por REST está cerrado: " + c.text);

  const feed = await fetch(BASE + "/comments/feed/");
  assert.equal(feed.status, 404, "el feed de comentarios no existe");
  const postFeed = await fetch(post.link + "feed/");
  assert.equal(postFeed.status, 404, "ni el de los comentarios de una entrada");
});

test("comments-off: no escribe nada; apagado, todo vuelve como estaba", async () => {
  await setModule(false);
  const r = await wp(`/posts/${post.id}?context=edit`);
  assert.equal(r.json.comment_status, "open");
  assert.ok((await html(post.link)).text.includes(TEXT));
});
