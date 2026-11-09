// NIVEL: 2 — Playground WordPress. NOT LEVEL 1 BECAUSE: what is tested is WordPress's own wp_kses() on a
// code banner (2.10.2) and what the banner's edit screen says about it: the list in includes/class-kses.php
// only matters as wp_kses() reads it, and the warning is printed by the meta box.
//
// The four banners that must come out WHOLE are the four kinds of code banner Gastronòmicament had on
// 09-11-2026 (six banners: a linked image, a <div>, an <iframe>, and one with an external script and two
// inline ones), rebuilt with example URLs; the AdSense one stands for the scripts. The fifth is the trap
// of the header of class-kses.php: inline JavaScript with «&&», which the filter breaks, so the edit
// screen must say so.
// Seen red (09-11-2026): with «script» out of Kses::BANNER the AdSense banner lost its two scripts and
// its screen listed them; with the warning taken out of the meta box, the «&&» case passed in silence.
//
// How to maintain: a new kind of ad code that must work goes in CODES, with what must come out of it.
// The trap: the site reset does not delete banners, so before() and after() delete this test's own
// (title prefix «prueba-codigo»), and the page is found again by its slug.
import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { BASE, testApi, wp, adminSession } from "../../../tests/lib.mjs";

const ZONE = "prueba-codigo";
const SLUG = "prueba-banners-codigo";

const CODES = {
  image: {
    code: '<a href="https://example.com/oferta" target="_blank"><img src="https://example.com/b.jpg" alt="Oferta" width="300" height="250"></a>',
    out: ['href="https://example.com/oferta"', 'src="https://example.com/b.jpg"', 'width="300"'],
  },
  div: {
    code: '<div class="anunci" style="text-align:center">Anunci de prova</div>',
    out: ['class="anunci"', 'style="text-align:center"', "Anunci de prova"],
  },
  iframe: {
    code: '<iframe src="https://example.com/ad" width="300" height="250" frameborder="0" scrolling="no" loading="lazy"></iframe>',
    out: ['<iframe src="https://example.com/ad"', 'frameborder="0"', 'scrolling="no"'],
  },
  adsense: {
    code:
      '<script async src="https://pagead2.googlesyndication.com/pagead/js/adsbygoogle.js?client=ca-pub-1" crossorigin="anonymous"></script>' +
      '<ins class="adsbygoogle" style="display:block" data-ad-client="ca-pub-1" data-ad-slot="2"></ins>' +
      "<script>(adsbygoogle = window.adsbygoogle || []).push({});</script>",
    out: ["adsbygoogle.js?client=ca-pub-1", 'crossorigin="anonymous"', 'data-ad-slot="2"', "(adsbygoogle = window.adsbygoogle || []).push({});"],
  },
};
const BROKEN = "<script>if (window.a && window.b) { window.c = 1; }</script>";

const ids = {};
let pageId;

async function clean() {
  const { json } = await wp("/eweb-banners?per_page=100&status=any&search=prueba-codigo&context=edit");
  for (const b of json || []) await wp(`/eweb-banners/${b.id}?force=true`, { method: "DELETE" });
  const { json: terms } = await wp(`/eweb_banner_zone?per_page=100&search=${ZONE}`);
  for (const t of terms || []) await wp(`/eweb_banner_zone/${t.id}?force=true`, { method: "DELETE" });
}

before(async () => {
  await testApi("/setup", { method: "POST", body: { modules: ["banners"] } });
  await clean();
  const { json: zone } = await wp("/eweb_banner_zone", { method: "POST", body: { name: ZONE, slug: ZONE } });
  for (const [key, code] of [...Object.entries(CODES).map(([k, c]) => [k, c.code]), ["broken", BROKEN]]) {
    const { status, json } = await wp("/eweb-banners", {
      method: "POST",
      body: { title: "prueba-codigo " + key, status: "publish", meta: { estrategia_web_banner_code: code }, eweb_banner_zone: [zone.id] },
    });
    assert.equal(status, 201, key + ": " + JSON.stringify(json));
    assert.equal(json.meta.estrategia_web_banner_code, code, key + ": the code was not saved as written");
    ids[key] = json.id;
  }
  const content = `<!-- wp:estrategia-web/banner-zone {"zone":"${ZONE}","count":0} /-->`;
  const { json: found } = await wp(`/pages?slug=${SLUG}&status=any&context=edit`);
  const body = { title: "Prueba banners de código", slug: SLUG, status: "publish", content };
  const { json: saved } = found?.[0] ? await wp(`/pages/${found[0].id}`, { method: "POST", body }) : await wp("/pages", { method: "POST", body });
  pageId = saved.id;
});

after(clean);

// The HTML of one banner of the zone, by its post id.
const banner = (html, id) => {
  const m = html.match(new RegExp(`<div class="estrategia-web-banner estrategia-web-banner--code" data-id="${id}"[^>]*>([\\s\\S]*?)</div>(?=<div class="estrategia-web-banner|</div>)`));
  return m ? m[1] : null;
};

test("the four kinds of code banner come out of the filter whole", async () => {
  const res = await fetch(`${BASE}/?page_id=${pageId}`);
  const html = await res.text();
  assert.equal(res.status, 200);
  for (const [key, { out }] of Object.entries(CODES)) {
    const got = banner(html, ids[key]);
    assert.ok(got !== null, `${key}: the banner is not in the zone`);
    for (const piece of out) assert.ok(got.includes(piece), `${key}: «${piece}» is missing from\n${got}`);
  }
});

test("inline JavaScript with && is broken by the filter (the trap the edit screen warns about)", async () => {
  const html = await (await fetch(`${BASE}/?page_id=${pageId}`)).text();
  const got = banner(html, ids.broken);
  assert.ok(got !== null, "the banner is not in the zone");
  assert.ok(got.includes("&amp;&amp;"), "if wp_kses() stops doing this, the warning can go:\n" + got);
});

test("the edit screen warns only about the code the filter changes", async () => {
  const s = await adminSession();
  const warning = (text) => text.match(/<div class="notice notice-warning inline">[\s\S]*?<\/div>/)?.[0] ?? "";
  for (const key of Object.keys(CODES)) {
    const { status, text } = await s.get(`/wp-admin/post.php?post=${ids[key]}&action=edit`);
    assert.equal(status, 200, key);
    assert.equal(warning(text), "", `${key}: it warns about a code that comes out whole`);
  }
  const { text } = await s.get(`/wp-admin/post.php?post=${ids.broken}&action=edit`);
  assert.match(warning(text), /<code>script: if \(window\.a &amp;&amp; window\.b\)/, "the «&&» case is not listed");
});

test("the list's state pills get their CSS through wp_add_inline_style", async () => {
  const s = await adminSession();
  const { text } = await s.get("/wp-admin/edit.php?post_type=eweb_banner");
  assert.match(text, /\.ewb-state\{display:inline-block/);
});
