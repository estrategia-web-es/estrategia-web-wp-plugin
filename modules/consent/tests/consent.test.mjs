// NIVEL: 2 — Playground WordPress. NOT LEVEL 1 BECAUSE: what is tested is the HTML the site PRINTS (the notice, its links and the «Powered by» line) from what a theme declares through filters, block render callbacks, shortcodes and block aliases; that only exists rendered by WordPress.
//
// Cookie notice (0.5.0, came from the parent theme). What it is and why, in
// the header of includes/class-consent.php. What happens in the browser
// (buttons, cookie, JS API, event, contrast) is consent-browser.test.mjs
// (level 3).
//
// The «site» that declares categories plays the theme's functions.php: here
// through ?ew-consent-setup=<json> on the page request itself (the same
// filters of tests/wp/estrategia-web-site-test.php read it; see
// modules/consent/tests/wp/estrategia-web-cookies-test.php), so each case is
// ONE request. All the cases read ONE seeded page that carries the four
// «Cookie settings» links (new block, the two old aliases, the shortcode):
// the notice is printed in wp_footer on every page.
//
// Seen failing on 05-10-2026 with four mutants, each one caught: rel with
// nofollow, the revision with '2', the «Powered by» filter not applied and
// the armazon/consent and armazon-site/consent aliases not registered.
// Seen red (28-10-2026), one break per case: «the theme» declaring «maps»
// in case 1 (the notice is printed); the expected revision computed with
// '|2' (case 2); the rel expected «noopener nofollow» (case 3); the changed
// text expected «Made by Others» (case 4); the hybrid category expected
// «maps» (case 5).
// Merged on 28-10-2026: the revision/cookie case and «Powered by off by
// default» read the same page as «with categories» (case 2), and the link
// cases the same page as cases 1 and 2.
//
// How to maintain: if the cookie format or the revision formula changes, the
// revision assertion of case 2 MUST go red: changing it makes every visitor
// see the notice again. The test is not «fixed» without deciding that.
// Round 2 (28-10-2026): every page is requested at once in before() (ALL:
// a new case adds its line there), and the hybrid Matomo case passes the
// company option on the request (?ew-options) instead of saving it, so no
// case leaves anything stored for the next. The trap: a case whose page is
// missing from ALL still works, but is requested late, on its own.
import { test, before } from "node:test";
import assert from "node:assert/strict";
import crypto from "node:crypto";
import { BASE, html, testApi } from "../../../tests/lib.mjs";

const NOTICE = 'id="estrategia-web-consent"';
let path;
/** The seeded page as a visitor gets it, with «the theme» declaring `setup` for this request. */
/** The seeded page with «the theme» declaring `setup` (and other options, ?ew-options) for that request; each one requested once. */
const pages = new Map();
const front = (setup = {}, options) => {
  const key = JSON.stringify([setup, options]);
  if (!pages.has(key)) {
    const extra = options ? "&ew-options=" + encodeURIComponent(JSON.stringify(options)) : "";
    pages.set(key, html(`${BASE}${path}?ew-consent-setup=${encodeURIComponent(JSON.stringify(setup))}${extra}&nocache=${Date.now()}`).then((r) => r.text));
  }
  return pages.get(key);
};
// Every page the cases read, requested at once when the seed is there (round 2, 28-10-2026).
const ALL = [
  [{}],
  [{ categories: ["maps", "statistics"] }],
  [{ categories: ["maps"], powered_by: { show: true } }],
  [{ categories: ["maps"], powered_by: { show: true, text: "Made by Other", url: "https://other.example/" } }],
  [{ categories: ["maps"], powered_by: { show: true, url: "" } }],
  [{ categories: ["maps"], powered_by: { show: true, url: "javascript:alert(1)" } }],
  [{ categories: ["maps"], powered_by: false }],
  [{}, { estrategia_web_site: { matomo_url: "https://stats.example.test/", matomo_site_id: 3, matomo_mode: "hybrid" } }],
];
const offered = (page) => [...page.matchAll(/name="estrategia-web-consent-category"\s+value="([^"]+)"/g)].map((m) => m[1]);
const links = (page) =>
  [...page.matchAll(/<p ([^>]*estrategia-web-consent-link[^>]*)><button type="button" class="estrategia-web-consent-link__button" aria-controls="estrategia-web-consent"[^>]*>([^<]*)<\/button><\/p>/g)].map((m) => ({ cls: m[1], label: m[2] }));

/** The notice's «Powered by» line: { html, text, anchor: { href, target, rel } | null }, or null. */
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

before(async () => {
  // ONE request: the reset and the page (/setup, tests/wp/estrategia-web-site-test.php).
  const { pages } = await testApi("/setup", {
    method: "POST",
    body: {
      pages: [
        {
          slug: "test-cookie-link",
          content:
            '<!-- wp:estrategia-web-site/consent {"label":"My cookies"} /-->' +
            "<!-- wp:estrategia-web-site/consent /-->" +
            "<!-- wp:armazon/consent /-->" +
            '<!-- wp:shortcode -->[estrategia_web_consent_settings label="Via shortcode"]<!-- /wp:shortcode -->',
        },
      ],
    },
  });
  path = new URL(pages[0].url).pathname;
  for (const [setup, options] of ALL) front(setup, options).catch(() => {}); // Awaited (and reported) by each case.
});

test("1. with no declared categories there is no notice, no module and no «Cookie settings» link (nothing to reopen)", async () => {
  const page = await front({});
  assert.ok(!page.includes(NOTICE), "no categories, no notice");
  assert.ok(!page.includes("estrategia-web-site-consent-view-script-module"), "no categories, the module is not loaded");
  assert.ok(!page.includes("estrategia-web-consent-link"), "without categories, the link is not printed");
});

test("2. with categories: the notice in stable order with its three buttons, the PARENT's revision and cookie, no «Powered by» by default, and the three links", async () => {
  const page = await front({ categories: ["maps", "statistics"] });
  assert.ok(page.includes(NOTICE), "the notice is missing");
  assert.deepEqual(offered(page), ["statistics", "maps"], "order of the known categories, not the filter's");
  for (const action of ["accept", "reject", "configure"]) {
    assert.ok(page.includes(`data-estrategia-web-consent-action="${action}"`), `the ${action} button is missing`);
  }
  assert.ok(page.includes("estrategia-web-site-consent-view-script-module"), "the notice module");
  assert.ok(page.includes("Aceptar todas") && page.includes("Rechazar todas"), "texts translated from the estrategia-web-site domain");

  // The armazon-wp-padre 0.6.0 formula, inc/consent.php: md5( implode( ',', categories ) . '|' . '1' ).
  const revision = crypto.createHash("md5").update("statistics,maps|1").digest("hex").slice(0, 8);
  assert.match(page, new RegExp(`"revision":"${revision}"`), "the revision is not the parent's: whoever already chose would see the notice again");
  assert.match(page, /"cookieName":"estrategia_web_consent"/, "the same cookie");

  // 1.0.0: «Powered by» OFF by default (WordPress.org, guideline 10).
  assert.equal(powered(page), null, "without the filter there is no «Powered by» line");

  // The link block with its label and with the default one, and the
  // shortcode. The OLD name armazon/consent prints nothing since 2.0.1 (its
  // alias went with the Armazón migrator).
  const found = links(page);
  assert.equal(found.length, 3, `there should be three links and there are ${found.length}`);
  assert.match(found[0].cls, /wp-block-estrategia-web-site-consent/);
  assert.equal(found[0].label, "My cookies");
  assert.match(found[1].cls, /wp-block-estrategia-web-site-consent\b/);
  assert.equal(found[1].label, "Configurar cookies");
  assert.equal(found[2].label, "Via shortcode");
});

test("3. «Powered by Estrategia Web» switched on: after the buttons, linked, new tab, rel=noopener and WITHOUT nofollow", async () => {
  const page = await front({ categories: ["maps"], powered_by: { show: true } });
  const p = powered(page);
  assert.ok(p, "the «Powered by» line is missing");
  assert.equal(p.text, "Powered by Estrategia Web");
  assert.equal(p.anchor?.href, "https://estrategiaweb.es/");
  assert.equal(p.anchor.target, "_blank");
  assert.equal(p.anchor.rel, "noopener", "rel must be noopener and nothing else (no nofollow: it counts as a link)");
  assert.ok(!/nofollow|sponsored|ugc/.test(p.html), "no nofollow, sponsored or ugc");
  assert.match(p.html, /estrategia-web-consent__sr">\s*\(se abre en una pestaña nueva\)/, "new-tab notice for screen readers");
  assert.ok(page.indexOf("estrategia-web-consent__actions") < page.indexOf("estrategia-web-consent__powered"), "it goes AFTER the buttons");
});

test("4. the estrategia_web_site_consent_powered_by filter: changes it, keeps what is not passed, drops empty and non-http(s) urls, and false removes it", async () => {
  const [changed, empty, script, removed] = await Promise.all([
    front({ categories: ["maps"], powered_by: { show: true, text: "Made by Other", url: "https://other.example/" } }),
    front({ categories: ["maps"], powered_by: { show: true, url: "" } }),
    front({ categories: ["maps"], powered_by: { show: true, url: "javascript:alert(1)" } }),
    front({ categories: ["maps"], powered_by: false }),
  ]);
  let p = powered(changed);
  assert.equal(p.text, "Made by Other");
  assert.equal(p.anchor.href, "https://other.example/");
  p = powered(empty);
  assert.equal(p.text, "Powered by Estrategia Web", "whatever is not passed is kept");
  assert.equal(p.anchor, null, "empty url: text without a link");
  assert.equal(powered(script).anchor, null, "a non-http(s) url is discarded");
  assert.ok(removed.includes(NOTICE), "control: the notice is still there");
  assert.equal(powered(removed), null, "__return_false removes it");
});

test("5. hybrid Matomo declares «statistics» by itself: the notice shows without the theme declaring anything", async () => {
  // The company option with hybrid Matomo, for that request (?ew-options): nothing is saved.
  assert.deepEqual(offered(await front({}, { estrategia_web_site: { matomo_url: "https://stats.example.test/", matomo_site_id: 3, matomo_mode: "hybrid" } })), ["statistics"]);
});
