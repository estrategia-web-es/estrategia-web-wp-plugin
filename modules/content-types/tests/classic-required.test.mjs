// NIVEL: 3 — browser. NEEDS A BROWSER BECAUSE: what is tested is the CLASSIC screen (post.php of a type without «editor»): its form posts, WordPress writes the post and redirects, and the notice is painted after the redirect. Only a real form submit with its nonce goes through that path; REST does not.
//
// «required» on the classic screen (Registrar::check_required_classic()):
// the fixture gment_winery (tests/fixtures/valid/) has no «editor», so its
// fields are the meta box, and two of them are required.
//
// Seen red (17-10-2026): before check_required_classic() existed, «publish
// with a required field empty» failed — the winery was PUBLISHED with no town
// (status "publish" instead of "draft") — and «emptying a required field of a
// published winery» failed with the town stored as "".
import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { BASE, playwright, reset, testApi, wp } from "../../../tests/lib.mjs";

const ct = async (path, { method = "GET", body } = {}) => {
  const res = await fetch(BASE + "/wp-json/estrategia-web-ct-test/v1" + path, { method, headers: body ? { "Content-Type": "application/json" } : {}, body: body ? JSON.stringify(body) : undefined });
  if (!res.ok) throw new Error(`${method} ${path} -> ${res.status} ${await res.text()}`);
  return res.json();
};

let browser, p;
const errors = [];

before(async () => {
  await reset();
  const { loaded } = await testApi("/modules");
  await testApi("/modules", { method: "POST", body: { active: [...new Set([...loaded, "content-types"])] } });
  await ct("/setup", { method: "POST", body: { on: true } });
  await ct("/state");
  browser = await playwright().chromium.launch();
  p = await (await browser.newContext({ viewport: { width: 1400, height: 1000 } })).newPage();
  p.on("pageerror", (e) => errors.push(e.message));
  await p.goto(BASE + "/wp-admin/");
  if (p.url().includes("wp-login.php")) {
    await p.fill("#user_login", "admin");
    await p.fill("#user_pass", "password");
    await Promise.all([p.waitForURL(/wp-admin/), p.click("#wp-submit")]);
  }
});
after(async () => {
  await browser?.close();
  await ct("/setup", { method: "POST", body: { on: false } });
  await reset();
});

const field = (key) => p.locator(`#estrategia-web-ct-${key}`);
/** Clicks Publish / Update and waits for the redirect back to post.php. */
async function submit() {
  await Promise.all([p.waitForURL(/post\.php\?post=\d+&action=edit/, { timeout: 30000 }), p.click("#publish")]);
  return Number(new URL(p.url()).searchParams.get("post"));
}
const stored = async (id) => (await wp(`/gment_winery/${id}?context=edit`)).json;

let id;

test("publish with a required field empty: it stays a draft, everything typed is kept, and the notice names the field", async () => {
  await p.goto(BASE + "/wp-admin/post-new.php?post_type=gment_winery");
  assert.equal(await p.locator(".block-editor").count(), 0, "the classic screen, not the block editor");
  await p.fill("#title", "Bodega Son Prim");
  await field("gment_winery_founded").fill("1990");
  await field("gment_winery_web").fill("https://sonprim.test/");
  id = await submit();
  const post = await stored(id);
  assert.equal(post.status, "draft", "not published");
  assert.equal(post.title.raw, "Bodega Son Prim");
  assert.equal(post.meta.gment_winery_founded, 1990, "what was typed is saved");
  assert.equal(post.meta.gment_winery_web, "https://sonprim.test/");
  const notice = await p.locator("#estrategia-web-ct-required").textContent();
  assert.match(notice, /Municipio/, "the empty required field, by its label in the site language");
  assert.doesNotMatch(notice, /Fundada/, "founded was given");
  // Core's own message must not say «published».
  assert.doesNotMatch(await p.locator("#message").textContent(), /publicad/i, "core's message is the draft one");
});

test("filling it in and publishing again: published, no notice", async () => {
  await field("gment_winery_town").fill("Binissalem");
  await submit();
  const post = await stored(id);
  assert.equal(post.status, "publish");
  assert.equal(post.meta.gment_winery_town, "Binissalem");
  assert.equal(await p.locator("#estrategia-web-ct-required").count(), 0);
});

test("emptying a required field of a published winery: it stays published and the field keeps its previous value", async () => {
  await field("gment_winery_town").fill("");
  await field("gment_winery_web").fill("https://sonprim.test/es/");
  await submit();
  const post = await stored(id);
  assert.equal(post.status, "publish", "a live page is not taken offline");
  assert.equal(post.meta.gment_winery_town, "Binissalem", "the previous value");
  assert.equal(post.meta.gment_winery_web, "https://sonprim.test/es/", "the other fields are saved");
  assert.match(await p.locator("#estrategia-web-ct-required").textContent(), /Municipio/);
  assert.equal(await field("gment_winery_town").inputValue(), "Binissalem", "the screen shows what is stored");
});

test("no JavaScript errors on the classic screen", () => {
  assert.deepEqual(errors, []);
});
