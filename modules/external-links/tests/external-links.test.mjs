// NIVEL: 2 — Playground WordPress, because what is tested is the SERVED PAGE: the filter runs in core's template output buffer (wp_template_enhancement_output_buffer), which only exists when rendering a real page.
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
// level 1 (tests/pure.test.mjs); here, only what can be seen by rendering.
//
// Seen failing on 2026-10-06 by switching the filter off (external-links-setup
// enabled:false with the switched-on assertions): the external link came out
// without target.
import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { BASE, reset, seedPage, testApi, html, wp } from "../../../tests/lib.mjs";

const setup = (enabled) => testApi("/external-links-setup", { method: "POST", body: { enabled } });
const NOTICE = "(se abre en una pestaña nueva)";

const CONTENT = `
<!-- wp:buttons -->
<div class="wp-block-buttons"><!-- wp:button -->
<div class="wp-block-button"><a id="x-ext" class="wp-block-button__link wp-element-button" href="https://wa.me/34600111222?text=Hola">Hablar con un asesor</a></div>
<!-- /wp:button --></div>
<!-- /wp:buttons -->
<p>
<a id="x-int" href="${BASE}/contacto/">Interno</a>
<a id="x-www" href="http://www.127.0.0.1:9404/contacto/">Con www</a>
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

let url;
before(async () => {
  await reset();
  // With a declared category the cookie notice shows, and with it the «Powered by».
  await testApi("/consent-setup", { method: "POST", body: { categories: ["maps"], powered_by: { show: true } } });
  ({ url } = await seedPage(CONTENT, "enlaces-externos"));
});
after(() => reset());

test("external (the WhatsApp button): target _blank, rel noopener and a notice for screen readers", async () => {
  const { status, text } = await html(url);
  assert.equal(status, 200);
  const a = anchor(text, "x-ext");
  assert.equal(a.target, "_blank");
  assert.equal(a.rel, "noopener");
  assert.equal(a["aria-label"], `Hablar con un asesor ${NOTICE}`);
});

test("internal, www, relative, anchors, tel: and mailto: stay as they were", async () => {
  const { text } = await html(url);
  for (const id of ["x-int", "x-www", "x-rel", "x-anchor", "x-tel", "x-mail"]) {
    const a = anchor(text, id);
    assert.equal(a.target, undefined, `${id} with target`);
    assert.equal(a.rel, undefined, `${id} with rel`);
    assert.equal(a["aria-label"], undefined, `${id} with aria-label`);
  }
});

test("with target it is not touched; with rel noopener is appended; with aria-label the notice is appended; an image gives its alt", async () => {
  const { text } = await html(url);
  assert.deepEqual(anchor(text, "x-target"), { id: "x-target", href: "https://facebook.com/x", target: "_self" });
  const rel = anchor(text, "x-relkeep");
  assert.equal(rel.target, "_blank");
  assert.equal(rel.rel, "nofollow sponsored noopener");
  assert.equal(anchor(text, "x-aria")["aria-label"], `Cómo llegar ${NOTICE}`);
  assert.equal(anchor(text, "x-img")["aria-label"], `Instagram ${NOTICE}`);
});

test("the cookie notice's «Powered by» is not duplicated", async () => {
  const { text } = await html(url);
  const tag = text.match(/<a\b[^>]*href="https:\/\/estrategiaweb\.es\/"[^>]*>/)?.[0];
  assert.ok(tag, "the «Powered by» does not show");
  assert.equal(tag.match(/\btarget=/g).length, 1);
  assert.match(tag, /\brel="noopener"/);
  assert.doesNotMatch(tag, /aria-label/);
});

test("front end only: content over REST (what the editor reads) is not touched", async () => {
  const page = (await wp(`/pages?slug=enlaces-externos&context=edit`)).json[0];
  assert.doesNotMatch(page.content.raw, /target="_blank"/);
  assert.doesNotMatch(page.content.rendered, /target="_blank"/);
});

test("switched off with the estrategia_web_site_external_links filter: nothing", async () => {
  await setup(false);
  try {
    const a = anchor((await html(url)).text, "x-ext");
    assert.equal(a.target, undefined);
    assert.equal(a.rel, undefined);
  } finally {
    await setup(null);
  }
});
