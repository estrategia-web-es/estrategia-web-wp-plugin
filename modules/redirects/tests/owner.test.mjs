// NIVEL: 2 — Playground WordPress. NOT LEVEL 1 BECAUSE: what is tested is what WordPress does on a real request and on the real Modules screen when another plugin's constants are defined before ours loads (the module's boot on plugins_loaded, its menu item, its 404 handler); which plugin owns the redirects, from which constant and option, is level 1 (pure.test.mjs, Conflicts::owner()).
//
// 2.0.1 (David, 28-10-2026): when the SEO plugin has its own redirect
// manager on — Rank Math with its Redirections module, or Yoast SEO
// Premium —, it OWNS the redirects and this module steps aside by itself:
// the site stops redirecting, its screen and menu item go, and the Modules
// screen says who has them. Played with the constants the real plugins
// define (/fake-seo of tests/wp/estrategia-web-redirects-test.php). Its own
// file (it was a case of server.test.mjs) so neither goes over the level 2
// time limit when the machine is busy.
//
// Seen red (28-10-2026): before the Spanish string existed (the Modules
// screen said it in English), and with module.php ignoring owner() (Rank
// Math's source still answered 301).
import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { api, get } from "./lib.mjs";
import { adminSession } from "../../../tests/lib.mjs";

const SOURCE = "/viejo-propietario/";
let s;

before(async () => {
  // The core reset, the module's tables and the module on in ONE request; one rule; the login at once.
  [, s] = await Promise.all([api("/reset", { method: "POST", body: { core: true, on: true } }), adminSession()]);
  await api("/rule", { method: "POST", body: { source: SOURCE, target: "/", type: 301, active: true } });
});
// The module's tables and the fake plugin: the core reset() does not undo them.
after(() => api("/reset", { method: "POST" }));

const plain = (html) => html.replace(/<[^>]+>/g, " ").replace(/\s+/g, " ");
const modules = async () => (await s.get("/wp-admin/admin.php?page=estrategia-web")).text;
/** The module's row on the Modules screen, as text. */
const row = (html) => plain(html.match(/<tr>(?:(?!<\/tr>)[\s\S])*estrategia-web-module-redirects[\s\S]*?<\/tr>/)?.[0] ?? "(no row)");

test("an SEO plugin with its own redirects: the module steps aside and the Modules screen says who has them", async () => {
  await api("/fake-seo", { method: "POST", body: { plugin: "rank-math" } });
  const [rm, rmScreen, rmPage] = await Promise.all([get(SOURCE), modules(), s.get("/wp-admin/admin.php?page=estrategia-web-redirects")]);
  assert.equal(rm.status, 404, "Rank Math with Redirections on: ours does not redirect");
  assert.match(row(rmScreen), /Redirecciones .*Las redirecciones las gestiona Rank Math\./);
  assert.doesNotMatch(rmPage.text, /<h1[^>]*>Redirecciones<\/h1>/, "nor its screen");
  assert.doesNotMatch(rmScreen, /page=estrategia-web-redirects/, "nor its menu item");

  // Rank Math with its Redirections module OFF: ours works again.
  await api("/fake-seo", { method: "POST", body: { plugin: "rank-math", modules: ["sitemap"] } });
  assert.equal((await get(SOURCE)).status, 301);

  await api("/fake-seo", { method: "POST", body: { plugin: "yoast-premium" } });
  const [yo, yoScreen] = await Promise.all([get(SOURCE), modules()]);
  assert.equal(yo.status, 404, "Yoast Premium: its Redirect manager has no off switch");
  assert.match(row(yoScreen), /Las redirecciones las gestiona Yoast SEO Premium\./);
});
