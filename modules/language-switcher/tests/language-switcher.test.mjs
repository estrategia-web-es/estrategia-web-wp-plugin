// NIVEL: 2 — Playground WordPress with REAL WPML. NOT LEVEL 1 BECAUSE: the block renders only what WPML's wpml_active_languages returns for THIS page (its translation's URL, the current language), and that only exists in a WordPress running WPML. NOT LEVEL 3: what is checked here is the markup the server sends (the code, aria-expanded, hidden, hreflang/lang, where each link goes, the notice's words); opening and closing it is language-switcher-browser.test.mjs.
// WPML: REAL — runs against the WordPress with WPML (tests/run.mjs, «WPML»).
//
// estrategia-web/language-switcher with real WPML (es by default, en in /en/),
// on a Spanish page and its English translation, both carrying the block:
//  1. each page answers 200 with <html lang> of its language; the switcher
//     says its code («ES» / «EN»), starts closed (aria-expanded "false", the
//     list with hidden), and its link to the other language carries hreflang
//     and lang and goes EXACTLY to the translation (and back).
//  2. in /en/, the cookie notice says «Accept all» and «Reject all» and no
//     «Aceptar» / «Rechazar» (the plugin's strings follow WPML's language).
//
// Moved from Lavandería (tests/language-switcher-html.test.mjs, where it
// walked its 13 page pairs) on 25-10-2026: one pair is enough for the block,
// the 13 pairs, the header placement and the English menu are Lavandería's
// content and stay there. NEEDS the local WPML copy: without it, it FAILS
// (tests/run.mjs, «WPML»); without WPML the block renders nothing at all.
//
// How to maintain: if the block's markup changes (render.php), the selectors
// here and in language-switcher-browser.test.mjs change with it, in the same
// commit. Edge case: a page with no translation gets WPML's front page in
// the other language (skip_missing 0, render.php); that is not tested here.
//
// Seen red (25-10-2026): expecting «EN» in the Spanish page's switcher, step 1
// fails on the first page; restored, green.
import { test, before } from "node:test";
import assert from "node:assert/strict";
import { reset, wp, testApi, BASE, page, wpml, requireWpml } from "../../../tests/lib.mjs";

const BLOCK = '<!-- wp:estrategia-web/language-switcher /-->\n<!-- wp:paragraph --><p>Idiomas</p><!-- /wp:paragraph -->';
let es;
let en;

before(async () => {
  await requireWpml({ st: false });
  await reset();
  await wpml("/purge", { slugs: ["prueba-idiomas", "language-test"] });
  await testApi("/consent-setup", { method: "POST", body: { categories: ["maps"] } });
  const r = await wp("/pages", { method: "POST", body: { title: "Prueba idiomas", slug: "prueba-idiomas", status: "publish", content: BLOCK } });
  assert.equal(r.status, 201, r.text);
  const tr = await wpml("/translate", { kind: "post", type: "page", original: r.json.id, title: "Language test", slug: "language-test", content: BLOCK });
  es = "/prueba-idiomas/";
  en = new URL(tr.url).pathname;
  assert.equal(en, "/en/language-test/", "the translation is not in /en/");
});

const pathOf = (href) => new URL(href, BASE).pathname;

test("1. each page and its translation: code, closed, hreflang/lang and the exact pair, both ways", async () => {
  for (const [from, to, lang, other] of [[es, en, "es", "en"], [en, es, "en", "es"]]) {
    const { status, document } = await page(from);
    assert.equal(status, 200, `${from} -> ${status}`);
    assert.match(document.documentElement.lang, new RegExp(`^${lang}`), `${from}: <html lang> is not ${lang}`);
    const switcher = document.querySelector(".estrategia-web-language");
    assert.ok(switcher, `${from}: no language switcher`);
    const toggle = switcher.querySelector(".estrategia-web-language__toggle");
    const list = switcher.querySelector(".estrategia-web-language__list");
    assert.equal(toggle?.querySelector("span[lang]")?.textContent.trim(), lang.toUpperCase(), `${from}: the switcher does not say ${lang.toUpperCase()}`);
    assert.equal(toggle.getAttribute("aria-expanded"), "false", `${from}: the switcher starts open`);
    assert.ok(list?.hasAttribute("hidden"), `${from}: the list comes without hidden (it would show before opening)`);
    const link = list.querySelector(`a[hreflang="${other}"]`);
    assert.ok(link, `${from}: no link with hreflang="${other}"`);
    assert.equal(link.getAttribute("lang"), other, `${from}: the link has no lang="${other}"`);
    assert.equal(pathOf(link.getAttribute("href")), to, `${from}: the switcher goes to ${pathOf(link.getAttribute("href"))} and not to its pair ${to}`);
  }
});

test("2. in /en/ the cookie notice is in English", async () => {
  const { document } = await page(en);
  const notice = document.querySelector(".estrategia-web-consent");
  assert.ok(notice, `${en}: no cookie notice in the HTML`);
  const text = notice.textContent.replace(/\s+/g, " ");
  assert.match(text, /Accept all/, `the notice in ${en} does not say «Accept all»: ${text.slice(0, 200)}`);
  assert.match(text, /Reject all/, `the notice in ${en} does not say «Reject all»`);
  assert.doesNotMatch(text, /Aceptar|Rechazar/, `the notice in ${en} is in Spanish`);
});
