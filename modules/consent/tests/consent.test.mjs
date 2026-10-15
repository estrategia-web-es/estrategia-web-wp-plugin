// NIVEL: 2 — Playground WordPress, because what is tested is the HTML the site PRINTS (the notice, the link and the «Powered by» line) according to the filters and the option saved through REST.
//
// Cookie notice (0.5.0, came from the parent theme). What it is and why, in
// the header of includes/class-consent.php. What happens in the browser
// (buttons, cookie, JS API, event, Matomo with the real notice, contrast) is
// in tests/consent-browser.test.mjs (level 3).
//
// The «site» that declares categories is the consent-setup test route
// (tests/wp/estrategia-web-site-test.php), which plays the theme's
// functions.php.
//
// Seen failing on 05-10-2026 with four mutants, each one caught: rel with
// nofollow, the revision with '2', the «Powered by» filter not applied and
// the armazon/consent and armazon-site/consent aliases not registered.
//
// How to maintain: if the cookie format or the revision formula changes, the
// «the revision is the parent's» test MUST go red: changing it makes every
// visitor see the notice again. The test is not «fixed» without deciding
// that.
import { test, before } from "node:test";
import assert from "node:assert/strict";
import crypto from "node:crypto";
import { BASE, html, reset, saveCompany, seedPage, testApi } from "../../../tests/lib.mjs";

const setup = (body) => testApi("/consent-setup", { method: "POST", body });
const front = async (path = "/") => (await html(BASE + path + (path.includes("?") ? "&" : "?") + "nocache=" + Date.now())).text;
const NOTICE = 'id="estrategia-web-consent"';

/** The notice's «Powered by» line: { html, anchor: { href, target, rel, text } | null }. */
function powered(page) {
  const m = page.match(/<p class="estrategia-web-consent__powered">([\s\S]*?)<\/p>/);
  if (!m) return null;
  const a = m[1].match(/<a ([^>]*)>([\s\S]*?)<\/a>/);
  const attr = (name) => (a?.[1].match(new RegExp(`${name}="([^"]*)"`)) || [])[1];
  return {
    html: m[1],
    text: m[1].replace(/<span class="estrategia-web-consent__sr">[\s\S]*?<\/span>/, "").replace(/<[^>]+>/g, "").trim(),
    anchor: a ? { href: attr("href"), target: attr("target"), rel: attr("rel") } : null,
  };
}

before(reset);

test("consent (0.5.0): the plugin carries the notice; with no declared categories it does NOT show (nor its module)", async () => {
  await reset();
  const r = await setup({});
  assert.equal(r.active, true, "with a theme that ships no notice, the notice belongs to the plugin");
  assert.deepEqual(r.categories, []);
  const page = await front();
  assert.ok(!page.includes(NOTICE), "no categories, no notice");
  assert.ok(!page.includes("estrategia-web-site-consent-view-script-module"), "no categories, the module is not loaded");
});

test("consent (0.5.0): with categories the notice shows, with the categories in stable order and the three buttons", async () => {
  await reset();
  await setup({ categories: ["maps", "statistics"] });
  const page = await front();
  assert.ok(page.includes(NOTICE), "the notice is missing");
  const offered = [...page.matchAll(/name="estrategia-web-consent-category"\s+value="([^"]+)"/g)].map((m) => m[1]);
  assert.deepEqual(offered, ["statistics", "maps"], "order of the known categories, not the filter's");
  for (const action of ["accept", "reject", "configure"]) {
    assert.ok(page.includes(`data-estrategia-web-consent-action="${action}"`), `the ${action} button is missing`);
  }
  assert.ok(page.includes("estrategia-web-site-consent-view-script-module"), "the notice module");
  assert.ok(page.includes("Aceptar todas") && page.includes("Rechazar todas"), "texts translated from the estrategia-web-site domain");
});

test("consent (0.5.0): the revision and the cookie are THE PARENT'S (whoever already chose does not see the notice again)", async () => {
  await reset();
  const md5 = (s) => crypto.createHash("md5").update(s).digest("hex").slice(0, 8);
  // The armazon-wp-padre 0.6.0 formula, inc/consent.php: md5( implode( ',', categories ) . '|' . '1' ).
  const r = await setup({ categories: ["statistics", "maps"] });
  assert.equal(r.revision, md5("statistics,maps|1"));
  const page = await front();
  assert.match(page, new RegExp(`"revision":"${r.revision}"`), "the Interactivity state carries the same revision");
  assert.match(page, /"cookieName":"estrategia_web_consent"/, "the same cookie");
});

test("consent (0.5.0): hybrid Matomo declares «statistics» by itself and the notice shows without the theme declaring anything", async () => {
  await reset();
  await saveCompany({ matomo_url: "https://stats.example.test/", matomo_site_id: 3, matomo_mode: "hybrid" });
  const page = await front();
  const offered = [...page.matchAll(/name="estrategia-web-consent-category"\s+value="([^"]+)"/g)].map((m) => m[1]);
  assert.deepEqual(offered, ["statistics"]);
});

test("consent (1.0.0): «Powered by» OFF by default (WordPress.org, guideline 10)", async () => {
  await reset();
  await setup({ categories: ["maps"] });
  const page = await front();
  assert.ok(page.includes(NOTICE), "control: the notice shows");
  assert.equal(powered(page), null, "without the filter there is no «Powered by» line");
});

test("consent (0.5.0): «Powered by Estrategia Web» switched on: at the foot, linked, new tab, rel=noopener and WITHOUT nofollow", async () => {
  await reset();
  await setup({ categories: ["maps"], powered_by: { show: true } });
  const p = powered(await front());
  assert.ok(p, "the «Powered by» line is missing");
  assert.equal(p.text, "Powered by Estrategia Web");
  assert.equal(p.anchor?.href, "https://estrategiaweb.es/");
  assert.equal(p.anchor.target, "_blank");
  assert.equal(p.anchor.rel, "noopener", "rel must be noopener and nothing else (no nofollow: it counts as a link)");
  assert.ok(!/nofollow|sponsored|ugc/.test(p.html), "no nofollow, sponsored or ugc");
  assert.match(p.html, /estrategia-web-consent__sr">\s*\(se abre en una pestaña nueva\)/, "new-tab notice for screen readers");
  const page = await front();
  assert.ok(page.indexOf("estrategia-web-consent__actions") < page.indexOf("estrategia-web-consent__powered"), "it goes AFTER the buttons");
});

test("consent (0.5.0): the «Powered by» is changed and removed with the estrategia_web_site_consent_powered_by filter", async () => {
  await reset();
  await setup({ categories: ["maps"], powered_by: { show: true, text: "Made by Other", url: "https://other.example/" } });
  let p = powered(await front());
  assert.equal(p.text, "Made by Other");
  assert.equal(p.anchor.href, "https://other.example/");

  await setup({ categories: ["maps"], powered_by: { show: true, url: "" } });
  p = powered(await front());
  assert.equal(p.text, "Powered by Estrategia Web", "whatever is not passed is kept");
  assert.equal(p.anchor, null, "empty url: text without a link");

  await setup({ categories: ["maps"], powered_by: { show: true, url: "javascript:alert(1)" } });
  p = powered(await front());
  assert.equal(p.anchor, null, "a non-http(s) url is discarded");

  await setup({ categories: ["maps"], powered_by: false });
  const page = await front();
  assert.ok(page.includes(NOTICE), "control: the notice is still there");
  assert.equal(powered(page), null, "__return_false removes it");
});

test("consent (0.5.0): the «Cookie settings» link with the new name, with the OLD one (alias) and by shortcode", async () => {
  await reset();
  await setup({ categories: ["maps"] });
  const { url } = await seedPage(
    '<!-- wp:estrategia-web-site/consent {"label":"My cookies"} /-->' +
      "<!-- wp:armazon/consent /-->" +
      "<!-- wp:armazon-site/consent /-->" +
      '<!-- wp:shortcode -->[estrategia_web_consent_settings label="Via shortcode"]<!-- /wp:shortcode -->',
    "test-cookie-link"
  );
  const page = await front(new URL(url).pathname);
  const links = [...page.matchAll(/<p ([^>]*estrategia-web-consent-link[^>]*)><button type="button" class="estrategia-web-consent-link__button" aria-controls="estrategia-web-consent"[^>]*>([^<]*)<\/button><\/p>/g)].map((m) => ({ cls: m[1], label: m[2] }));
  assert.equal(links.length, 4, `there should be four links and there are ${links.length}`);
  assert.match(links[0].cls, /wp-block-estrategia-web-site-consent/);
  assert.equal(links[0].label, "My cookies");
  // The two old names (armazon/consent ≤ parent 0.6.0, armazon-site/consent ≤ 0.5.4) print the SAME as the new one.
  assert.match(links[1].cls, /wp-block-estrategia-web-site-consent\b/, "the armazon/consent alias prints like the new block");
  assert.equal(links[1].label, "Configurar cookies");
  assert.match(links[2].cls, /wp-block-estrategia-web-site-consent\b/, "the armazon-site/consent alias prints like the new block");
  assert.equal(links[3].label, "Via shortcode");

  await setup({});
  const none = await front(new URL(url).pathname);
  assert.ok(!none.includes("estrategia-web-consent-link"), "without categories, the link is not printed (there is no notice to reopen)");
});
