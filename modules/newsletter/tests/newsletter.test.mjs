// NIVEL: 2 — Playground WordPress. NOT LEVEL 1 BECAUSE: what is tested is what the module does with a real WordPress — the posts it proposes and reads (categories, featured image, the photo's files on disk), its REST routes and their permissions, the settings saved through options.php — and the HTTP it sends to Mailchimp through wp_remote_*, caught by pre_http_request (the fake Mailchimp of tests/wp/estrategia-web-newsletter-test.php). The rules themselves are level 1 (pure.test.mjs).
//
// The real Mailchimp is NEVER called: the test WordPress is offline and the
// fake answers *.api.mailchimp.com. What the fake cannot prove (that the real
// API accepts this body) is said in the CHANGELOG entry of 2.7.0.
//
// Seen red (02-11-2026), one break per case: create_draft() without the
// DELETE after a failed PUT (an orphan draft left in Mailchimp), the drag-and-
// drop check removed (the 04-09-2026 trap), and the key put back in the
// settings field's value.
//
// How to maintain: a new route or Mailchimp call gets its case here, and its
// answer in the fake. The trap: the seed's posts are the newest of the site
// on purpose (they must be in «latest»); another test that seeds newer posts
// in the same WordPress at the same time is not possible (one file per
// WordPress at a time, tests/run.mjs).
import { test, before } from "node:test";
import assert from "node:assert/strict";
import { BASE, adminSession, credentials, testApi, wp } from "../../../tests/lib.mjs";

const KEY = "0123456789abcdef0123456789abcdef-us21";
let seed;

const nl = async (path, { as = "administrator", body } = {}) => {
  const r = await fetch(BASE + "/wp-json/estrategia-web/v1/newsletter/" + path, {
    method: body ? "POST" : "GET",
    headers: { Authorization: await auth(as), ...(body ? { "Content-Type": "application/json" } : {}) },
    body: body ? JSON.stringify(body) : undefined,
  });
  return { status: r.status, json: await r.json() };
};
const auth = async (as) => {
  const who = (await credentials())[as];
  return "Basic " + Buffer.from(`${who.user}:${who.password}`).toString("base64");
};
const fake = (path, body) =>
  fetch(BASE + "/wp-json/estrategia-web-newsletter-test/v1" + path, {
    method: body ? "POST" : "GET",
    headers: body ? { "Content-Type": "application/json" } : {},
    body: body ? JSON.stringify(body) : undefined,
  }).then((r) => r.json());

before(async () => {
  await testApi("/setup", {
    method: "POST",
    body: { modules: ["newsletter"], options: { estrategia_web_newsletter: { api_key: KEY, featured: "prova-portada", list_id: "", email: "", block: "" } } },
  });
  seed = await fake("/seed", {});
});

test("an author cannot use any route; the key is not in /wp/v2/settings", async () => {
  for (const r of [await nl("posts", { as: "author" }), await nl("preview", { as: "author", body: { ids: [seed.a] } }), await nl("draft", { as: "author", body: { ids: [seed.a] } })]) {
    assert.equal(r.status, 403);
  }
  const { json } = await wp("/settings");
  assert.ok(!("estrategia_web_newsletter" in json), "the option is not exposed");
  assert.ok(!JSON.stringify(json).includes(KEY));
});

test("proposed: the featured category's posts, and the latest; a search finds; drafts never", async () => {
  const { status, json } = await nl("posts");
  assert.equal(status, 200);
  assert.deepEqual(json.featured.map((p) => p.id).slice(0, 2), [seed.a, seed.c], "featured, newest first, without B");
  assert.deepEqual(json.latest.map((p) => p.id).slice(0, 3), [seed.a, seed.b, seed.c]);
  assert.equal(json.latest[0].label, "Actualitat", "the label is not the featured category");
  assert.ok(!json.latest.some((p) => p.id === seed.draft));
  const found = await nl("posts?search=" + encodeURIComponent("vins & tapes"));
  assert.deepEqual(found.json.results.map((p) => p.id), [seed.b]);
  assert.equal(found.json.results[0].title, "Nl prova B: vins & tapes", "the title decoded, not «&amp;»");
});

test("preview: in the chosen order, the subject is the first title, lead cut, photo without WebP, warnings said", async () => {
  const { status, json } = await nl("preview", { body: { ids: [seed.b, seed.a, seed.c] } });
  assert.equal(status, 200, JSON.stringify(json));
  assert.equal(json.subject, "Nl prova B: vins & tapes");
  const [b, a, c] = json.items;
  assert.equal(a.excerpt, "La temporada de pesca del raor amb una població plenament recuperada a les Illes, i més…");
  assert.equal(c.excerpt, "Contingut.", "no excerpt: from the content");
  assert.equal(a.image, seed.base + "/nl-amb-jpeg-1024x576.jpg", "the large size's .jpg next to its WebP");
  assert.equal(b.image, seed.base + "/nl-nomes-webp-1024x576.webp");
  assert.equal(json.warnings.length, 2, JSON.stringify(json.warnings));
  assert.match(json.warnings[0], /WebP/);
  // The default templates: the blocks in order, escaped, inside the plain email with Mailchimp's footer.
  const order = ["Nl prova B: vins &amp; tapes", "Nl prova A: el raor", "Nl prova C: sense foto"].map((t) => json.html.indexOf(">" + t + "</a>"));
  assert.ok(order.every((i, n) => i > 0 && (n === 0 || i > order[n - 1])), JSON.stringify(order));
  assert.match(json.html, /\*\|UNSUB\|\*/);
  assert.ok(!json.html.includes("{{"), "no placeholder left");
});

test("preview: a draft or a nonexistent id is refused, not skipped", async () => {
  assert.equal((await nl("preview", { body: { ids: [seed.a, seed.draft] } })).status, 400);
  assert.equal((await nl("preview", { body: { ids: [999999] } })).status, 400);
});

test("draft: model = last campaign sent, a NEW campaign without template, the same html as the preview, and nothing sent", async () => {
  await fake("/mock", { mode: "ok" });
  const preview = (await nl("preview", { body: { ids: [seed.a, seed.c] } })).json;
  const { status, json } = await nl("draft", { body: { ids: [seed.a, seed.c] } });
  assert.equal(status, 200, JSON.stringify(json));
  assert.equal(json.url, "https://us21.admin.mailchimp.com/campaigns/edit?id=4321");
  assert.equal(json.recipients, 348);
  assert.equal(json.model.subject, "Setmana passada");
  const log = await fake("/log");
  assert.deepEqual(log.map((r) => `${r.method} ${r.path}`), ["GET campaigns", "POST campaigns", "PUT campaigns/new1/content", "GET campaigns/new1"]);
  assert.ok(log.every((r) => r.url.startsWith("https://us21.api.mailchimp.com/3.0/")), "the key's datacenter");
  assert.ok(log.every((r) => r.auth === "Basic " + Buffer.from("estrategia-web:" + KEY).toString("base64")));
  assert.match(log[0].url, /status=sent/);
  assert.doesNotMatch(log[0].url, /list_id=/, "no audience set: the account's last one");
  const body = log[1].body;
  assert.equal(body.type, "regular");
  assert.equal(body.recipients.list_id, "L1");
  assert.equal(body.recipients.segment_opts.conditions[0].field, "interests-abc", "the segment «Consentiment = Butlletí» goes with it");
  assert.equal(body.settings.subject_line, "Nl prova A: el raor");
  assert.equal(body.settings.from_name, "Gastronòmicament");
  assert.ok(!("template_id" in body.settings) && !("drag_and_drop" in body.settings), "the 04-09-2026 trap");
  assert.equal(log[2].body.html, preview.html, "what is previewed is what goes");
});

test("draft: an audience in the settings looks for the model in it", async () => {
  await fake("/option", { list_id: "aud123" });
  await fake("/mock", { mode: "ok" });
  await nl("draft", { body: { ids: [seed.a] } });
  assert.match((await fake("/log"))[0].url, /list_id=aud123/);
  await fake("/option", { list_id: "" });
});

test("draft: if Mailchimp makes it drag-and-drop or the content fails, the new campaign is deleted and the screen gets Mailchimp's reason", async () => {
  await fake("/mock", { mode: "dnd" });
  let r = await nl("draft", { body: { ids: [seed.a] } });
  assert.equal(r.status, 502);
  assert.match(r.json.message, /drag-and-drop|arrossega|arrastrar/i);
  assert.equal((await fake("/log")).at(-1).method + " " + (await fake("/log")).at(-1).path, "DELETE campaigns/new1");

  await fake("/mock", { mode: "put_fails" });
  r = await nl("draft", { body: { ids: [seed.a] } });
  assert.equal(r.status, 502);
  assert.match(r.json.message, /400.*The html is not valid/);
  assert.deepEqual((await fake("/log")).map((x) => x.method), ["GET", "POST", "PUT", "DELETE"]);

  await fake("/mock", { mode: "no_model" });
  r = await nl("draft", { body: { ids: [seed.a] } });
  assert.equal(r.status, 502);
  assert.deepEqual((await fake("/log")).map((x) => x.method), ["GET"], "nothing created without a model");
});

test("settings: an empty key keeps the stored one, it is never printed; a bad key and a template without {{blocks}} are refused", async () => {
  const s = await adminSession();
  const page = await s.get("/wp-admin/admin.php?page=estrategia-web-settings&tab=newsletter");
  assert.equal(page.status, 200);
  assert.ok(!page.text.includes(KEY), "the key is not in the page");
  assert.ok(page.text.includes('placeholder="…def-us21"'), "only its last 8 characters, as a placeholder");
  await s.submit(page.text, "options.php", { "estrategia_web_newsletter[featured]": "Prova Portada", "estrategia_web_newsletter[email]": "<html>sense marcador</html>" });
  let saved = await fake("/option");
  assert.equal(saved.api_key, KEY, "empty field: kept");
  assert.equal(saved.featured, "prova-portada");
  assert.equal(saved.email, "", "a template without {{blocks}} is not saved");
  const again = await s.get("/wp-admin/admin.php?page=estrategia-web-settings&tab=newsletter");
  await s.submit(again.text, "options.php", { "estrategia_web_newsletter[api_key]": "no-datacenter" });
  saved = await fake("/option");
  assert.equal(saved.api_key, KEY, "a key without datacenter is not saved");
});

test("the screen: under Posts, with the plugin's header, the data inline and the draft button only with a key", async () => {
  const s = await adminSession();
  const r = await s.get("/wp-admin/edit.php?page=estrategia-web-newsletter");
  assert.equal(r.status, 200);
  assert.match(r.text, /<div class="wrap ew-screen estrategia-web-newsletter">/);
  assert.match(r.text, /<p class="ew-lead">[^<]{20,}<\/p>/);
  assert.match(r.text, /window\.estrategiaWebNewsletter = \{/);
  assert.match(r.text, /modules\/newsletter\/assets\/newsletter\.js/);
  assert.match(r.text, /<iframe class="ew-newsletter__frame" sandbox=""/, "the templates' HTML runs nowhere");
  assert.doesNotMatch(r.text, /data-no-key/);
  assert.ok(!r.text.includes(KEY));
});
