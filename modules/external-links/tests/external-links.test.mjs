// NIVEL: 2 — Playground WordPress. NOT LEVEL 1 BECAUSE: what is tested is the SERVED PAGE: the filter runs in core's template output buffer (wp_template_enhancement_output_buffer), which only exists when rendering a real page.
//
// External links in a new tab (0.5.2, includes/class-external-links.php):
// - external (wa.me, like Lavandería's «Hablar con un asesor»): target, rel
//   with noopener and aria-label with the text + «(se abre en una pestaña nueva)»;
// - internal, www, relative, anchors, tel: and mailto: untouched;
// - with target (even _self), untouched; with rel, noopener is appended
//   without removing nofollow/sponsored; with aria-label, the notice is
//   appended at the end; an image link takes the alt;
// - the cookie notice's «Powered by» is not duplicated;
// - content over REST (what the editor reads) is not touched;
// - with the estrategia_web_site_external_links filter set to false, nothing.
// What is external case by case (another domain, subdomains, schemes) is at
// level 1 (modules/site/tests/php/pure.php); here, only what can be seen by rendering.
//
// Requests (28-10-2026): the page is fetched ONCE and every rendering
// assertion reads that copy; the «www» link is built from BASE (the host is
// what is compared, never the port: is_external() strips «www.» and ignores
// the port, so it holds on any shard's port).
//
// Seen failing on 2026-10-06 by switching the filter off (external-links-setup
// enabled:false with the switched-on assertions): the external link came out
// without target.
// Seen red (28-10-2026), three at once: is_external() returning true for
// every href (the internal/www links got target="_blank"), the notice not
// appended to the link text, and the «already has a target» skip removed
// (the «Powered by» got an aria-label): the first four tests red. Then
// maybe_enable() ignoring the filter: «switched off» got target="_blank".
// Round 2 (28-10-2026): reset, notice and page in ONE request (core /setup). Seen red again with
// is_external() always true («x-int with target»).
import { test, before } from "node:test";
import assert from "node:assert/strict";
import { BASE, testApi, html, wp, credentials } from "../../../tests/lib.mjs";

const NOTICE = "(se abre en una pestaña nueva)";
const WWW = BASE.replace("://", "://www.");

const CONTENT = `
<!-- wp:buttons -->
<div class="wp-block-buttons"><!-- wp:button -->
<div class="wp-block-button"><a id="x-ext" class="wp-block-button__link wp-element-button" href="https://wa.me/34600111222?text=Hola">Hablar con un asesor</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons -->
<p>
<a id="x-int" href="${BASE}/contacto/">Interno</a>
<a id="x-www" href="${WWW}/contacto/">Con www</a>
<a id="x-rel" href="/contacto/">Relativo</a>
<a id="x-anchor" href="#arriba">Ancla</a>
<a id="x-tel" href="tel:+34971123456">Teléfono</a>
<a id="x-mail" href="mailto:hola@web.test">Correo</a>
<a id="x-target" href="https://facebook.com/x" target="_self">Con target</a>
<a id="x-relkeep" href="https://patrocinador.test/" rel="nofollow sponsored">Con rel</a>
<a id="x-aria" href="https://maps.google.com/?q=x" aria-label="Cómo llegar">Mapa</a>
<a id="x-img" href="https://instagram.com/x"><img src="/x.png" alt="Instagram"></a>
</p>`;

// Attributes of the <a> tag with that id, already decoded (&quot; &amp;…).
function anchor(page, id) {
  const tag = page.match(new RegExp(`<a\\b[^>]*\\bid="${id}"[^>]*>`))?.[0];
  assert.ok(tag, `link ${id} is missing`);
  const attrs = {};
  for (const m of tag.matchAll(/([\w-]+)(?:="([^"]*)"|='([^']*)')?/g).drop(1)) {
    attrs[m[1].toLowerCase()] = (m[2] ?? m[3] ?? "").replace(/&quot;/g, '"').replace(/&#039;|&#39;/g, "'").replace(/&amp;/g, "&");
  }
  return attrs;
}

let url, text, restPage;
before(async () => {
  // ONE request (round 2, the core /setup route): reset, the cookie notice (with a declared
  // category it shows, and with it the «Powered by») and the page.
  const [setup] = await Promise.all([
    testApi("/setup", { method: "POST", body: { consent: { categories: ["maps"], powered_by: { show: true } }, pages: [{ slug: "enlaces-externos", content: CONTENT }] } }),
    credentials(),
  ]);
  ({ url } = setup.pages[0]);
  // The page and what the editor reads, at the same time: both with the filter on.
  const [page, rest] = await Promise.all([html(url), wp(`/pages?slug=enlaces-externos&context=edit`)]);
  assert.equal(page.status, 200);
  text = page.text;
  restPage = rest.json[0];
});
// No after(): the next file's reset() deletes the consent and external-links options.

test("external (the WhatsApp button): target _blank, rel noopener and a notice for screen readers", () => {
  const a = anchor(text, "x-ext");
  assert.equal(a.target, "_blank");
  assert.equal(a.rel, "noopener");
  assert.equal(a["aria-label"], `Hablar con un asesor ${NOTICE}`);
});

test("internal, www, relative, anchors, tel: and mailto: stay as they were", () => {
  for (const id of ["x-int", "x-www", "x-rel", "x-anchor", "x-tel", "x-mail"]) {
    const a = anchor(text, id);
    assert.equal(a.target, undefined, `${id} with target`);
    assert.equal(a.rel, undefined, `${id} with rel`);
    assert.equal(a["aria-label"], undefined, `${id} with aria-label`);
  }
});

test("with target it is not touched; with rel noopener is appended; with aria-label the notice is appended; an image gives its alt", () => {
  assert.deepEqual(anchor(text, "x-target"), { id: "x-target", href: "https://facebook.com/x", target: "_self" });
  const rel = anchor(text, "x-relkeep");
  assert.equal(rel.target, "_blank");
  assert.equal(rel.rel, "nofollow sponsored noopener");
  assert.equal(anchor(text, "x-aria")["aria-label"], `Cómo llegar ${NOTICE}`);
  assert.equal(anchor(text, "x-img")["aria-label"], `Instagram ${NOTICE}`);
});

test("the cookie notice's «Powered by» is not duplicated", () => {
  const tag = text.match(/<a\b[^>]*href="https:\/\/estrategiaweb\.es\/"[^>]*>/)?.[0];
  assert.ok(tag, "the «Powered by» does not show");
  assert.equal(tag.match(/\btarget=/g).length, 1);
  assert.match(tag, /\brel="noopener"/);
  assert.doesNotMatch(tag, /aria-label/);
});

test("front end only: content over REST (what the editor reads) is not touched; switched off with the filter: nothing", async () => {
  assert.doesNotMatch(restPage.content.raw, /target="_blank"/);
  assert.doesNotMatch(restPage.content.rendered, /target="_blank"/);

  await testApi("/external-links-setup", { method: "POST", body: { enabled: false } });

  const a = anchor((await html(url)).text, "x-ext");
  assert.equal(a.target, undefined, "switched off with the estrategia_web_site_external_links filter");
  assert.equal(a.rel, undefined);
});
