// NIVEL: 3 — browser. NOT LEVEL 2 BECAUSE: what is tested is what the browser requests from Google and when, and what ends up in dataLayer (Consent Mode) when the buttons of the REAL notice are clicked; none of it exists until the page runs.
//
// Google tags (1.1.0). What it does: header of
// modules/google-tags/includes/class-google-tags.php. Google is NOT called:
// everything going to googletagmanager.com is intercepted, logged and
// answered with an empty script. OUR side is tested (when it is requested and
// which consent commands are issued), not what gtag.js does with them.
//
// 28-10-2026: one test per configuration (basic GA4, Ads, advanced), each a
// save and its visitors. The three basic-GA4 tests were one visitor's story
// told three times (seven loads; now three): nothing before choosing, maps
// only, accept, return, withdraw. The Ads visitors (the JS form event and the
/// three native-form cases) are independent and run AT THE SAME TIME (the
// native ones open the form page directly: it carries the notice too), and
// the form's anti-spam minimum fill time is 1 s here (3 s by default).
// Seen failing on 2026-10-14 with two mutants: load() always called (basic
// mode requested Google before accepting) and the analytics «update» tied to
// marketing. Seen red (28-10-2026), one break per test: ad_storage expected
// «granted» with Analytics accepted (1); a conversion expected after
// rejecting (2, native case «rejected»); another GTM id expected (3).
//
// Round 2 (28-10-2026): before() is ONE /setup request (reset, modules,
// notice, test 2's google-tags option and the forms settings stored as they
// are, the two pages); tests 1 and 3 carry their configuration on the page
// URL (?ew-options,
// modules/consent/tests/wp/estrategia-web-cookies-test.php), so nothing is
// saved between tests and the three run at the same time (describe with
// concurrency). Seen red again with the same three breaks.
//
// How to maintain: one test per mode and per category; if the category
// mapping changes (class header), it changes here. The trap: the forms
// module's rate limit (per IP, shared by every file) and minimum fill time
// are set to 0 and 1 s here and put back to the defaults (5 and 3 s) in
// after(), because the site reset() does not.
import { describe, test, before, after } from "node:test";
import assert from "node:assert/strict";
import { BASE, playwright, testApi } from "../../../tests/lib.mjs";

let browser;
let pageUrl;
let formUrl;
const cookiesReset = () => fetch(BASE + "/wp-json/estrategia-web-cookies-test/v1/reset", { method: "POST" });
const formsSettings = (body) => fetch(BASE + "/wp-json/estrategia-web-forms-test/v1/settings", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify(body) });

before(async () => {
  // ONE request (/setup, tests/wp/estrategia-web-site-test.php): the reset,
  // every module on (google-tags is off by default), the notice declaring
  // «maps», the pages, and two options stored as they are:
  // - google-tags: test 2's configuration, Ads with its form conversion
  //   label (its native form redirects, and a query on the URL would not
  //   survive it; tests 1 and 3 carry theirs on the URL);
  // - forms: four submissions from the same IP (other tests may have used the
  //   bucket) and the anti-spam minimum fill time down to 1 s: not what is
  //   tested here.
  const { pages } = await testApi("/setup", {
    method: "POST",
    body: {
      modules: "all",
      consent: { categories: ["maps"] },
      options: {
        estrategia_web_google_tags: { ga4: "", ads: "AW-123456789", ads_form_label: "AW-123456789/AbCd_ef-12", gtm: "", mode: "basic" },
        estrategia_web_forms_settings: { rate_limit: 0, min_seconds: 1 },
      },
      pages: [
        { slug: "prueba-google", content: "<!-- wp:paragraph --><p>Hola</p><!-- /wp:paragraph -->" },
        {
          slug: "prueba-google-form",
          content:
            '<!-- wp:estrategia-web-forms/form {"formId":"contacto","title":"Contacto"} --><!-- wp:estrategia-web-forms/text {"label":"Nombre","name":"nombre","required":true} /--><!-- wp:estrategia-web-forms/submit {"text":"Enviar"} /--><!-- /wp:estrategia-web-forms/form -->',
        },
      ],
    },
  });
  [pageUrl, formUrl] = pages.map((p) => p.url);
  browser = await playwright().chromium.launch();
});
after(async () => {
  await browser?.close();
  await Promise.all([formsSettings({ rate_limit: 5, min_seconds: 3 }), cookiesReset()]); // The forms defaults.
});

async function open(url = pageUrl) {
  const ctx = await browser.newContext({ viewport: { width: 1200, height: 900 } });
  const page = await ctx.newPage();
  page.google = [];
  page.errors = [];
  page.on("pageerror", (e) => page.errors.push(e.message));
  await ctx.route("https://www.googletagmanager.com/**", (route) => {
    page.google.push(route.request().url());
    return route.fulfill({ status: 200, contentType: "text/javascript", body: "/* fake gtag */" });
  });
  await page.goto(url, { waitUntil: "networkidle" });
  await page.waitForFunction(() => window.estrategiaWebConsent);
  return { ctx, page };
}
/** The page with the google-tags option `tags` for its requests only (?ew-options, modules/consent/tests/wp/estrategia-web-cookies-test.php). */
const withTags = (url, tags) => url + "?ew-options=" + encodeURIComponent(JSON.stringify({ estrategia_web_google_tags: tags }));
const layer = (page) => page.evaluate(() => window.dataLayer.map((a) => (typeof a.length === "number" ? Array.from(a) : a)));
const lastUpdate = async (page) => (await layer(page)).filter((e) => e[0] === "consent" && e[1] === "update").pop()?.[2];
const conversions = async (page) => (await layer(page)).filter((e) => e[0] === "event" && e[1] === "conversion");

describe("google tags in the browser (the three configurations at the same time)", { concurrency: true }, () => {
test("1. basic GA4: nothing to Google before choosing nor with maps only; accepting requests gtag.js and grants ONLY analytics; returning loads it; withdrawing denies, deletes _ga and stops it", async () => {
  const { ctx, page } = await open(withTags(pageUrl, { ga4: "G-TEST12345", ads: "", gtm: "", mode: "basic" }));
  const first = (await layer(page))[0];
  assert.deepEqual(first.slice(0, 2), ["consent", "default"], "the «default» is the FIRST thing in dataLayer");
  assert.equal(first[2].analytics_storage, "denied");
  assert.equal(first[2].ad_storage, "denied");
  assert.deepEqual(page.google, [], "no choice yet");

  // Maps only is not Analytics.
  await page.getByRole("button", { name: "Configurar", exact: true }).click();
  await page.getByRole("checkbox", { name: /Mapas y contenido de terceros/ }).check();
  await page.getByRole("button", { name: "Guardar la selección" }).click();
  await page.waitForTimeout(300);
  assert.deepEqual(page.google, [], "maps only is not Analytics");
  assert.equal((await lastUpdate(page))?.analytics_storage, "denied");

  // Accept all: gtag.js with the id, ONLY analytics_storage granted.
  await page.evaluate(() => window.estrategiaWebConsent.open());
  await page.getByRole("button", { name: "Aceptar todas" }).click();
  await page.waitForFunction(() => window.dataLayer.some((a) => a[0] === "config"));
  assert.ok(page.google.some((u) => u.startsWith("https://www.googletagmanager.com/gtag/js?id=G-TEST12345")), page.google.join("\n"));
  const update = await lastUpdate(page);
  assert.equal(update.analytics_storage, "granted");
  assert.equal(update.ad_storage, "denied", "the site does not ask about Advertising: it is not granted");
  assert.ok((await layer(page)).some((e) => e[0] === "config" && e[1] === "G-TEST12345"));

  // On returning, with the choice saved, it loads without clicking anything.
  page.google.length = 0;
  await page.reload({ waitUntil: "networkidle" });
  await page.waitForFunction(() => window.dataLayer.some((a) => a[0] === "config"));
  assert.equal(page.google.length, 1);

  // Withdrawing Analytics denies and deletes _ga and _ga_*.
  await page.evaluate(() => {
    document.cookie = "_ga=GA1.1.123.456; Path=/";
    document.cookie = "_ga_TEST12345=GS1.1.789; Path=/";
  });
  await page.evaluate(() => window.estrategiaWebConsent.open());
  await page.getByRole("button", { name: "Rechazar todas" }).click();
  await page.waitForTimeout(200);
  assert.equal((await lastUpdate(page)).analytics_storage, "denied");
  const left = (await ctx.cookies()).map((c) => c.name).filter((n) => n.startsWith("_ga"));
  assert.deepEqual(left, [], "Google cookies remain after withdrawing consent");

  // And after rejecting, the next page asks Google for nothing.
  page.google.length = 0;
  await page.reload({ waitUntil: "networkidle" });
  assert.deepEqual(page.google, [], "rejecting");
  assert.deepEqual(page.errors, []);
  await ctx.close();
});

// A form submitted WITHOUT going through view.js (form.submit() skips the
// submit event: the native POST, as without JS or before hydration) comes
// back with ?af_result=… and the form fires form-sent with «returned».
// Seen failing on 17-10-2026 without the «pending» queue (the signal arrives
// before the notice says what was accepted, and was lost) and without
// waiting for consent (counted with Advertising rejected).
test("2. Ads: the form conversion only with Advertising accepted — by JS event, and a form sent WITHOUT JavaScript counts once (even if accepted on the page that comes back)", async () => {
  // The stored configuration (before()): Ads with its form conversion label.

  // The visitor is already on the form page (open(formUrl): it carries the notice too, so no extra load).
  async function sendNative(page) {
    await page.fill("#af-contacto-nombre", "Ana");
    await page.waitForTimeout(1200); // The form's minimum fill time, set to 1 s in before(): what the server checks.
    await Promise.all([page.waitForURL(/af_result=/), page.evaluate(() => document.getElementById("af-contacto").submit())]);
    await page.waitForLoadState("networkidle");
    await page.waitForFunction(() => window.estrategiaWebConsent);
    await page.waitForTimeout(300);
  }

  const byEvent = async () => {
    const { ctx, page } = await open();
    const send = () => page.evaluate(() => document.dispatchEvent(new CustomEvent("estrategia-web:form-sent", { detail: { form: "contacto" } })));
    await send();
    assert.equal((await conversions(page)).length, 0, "without consent there is no conversion");
    await page.getByRole("button", { name: "Aceptar todas" }).click();
    await page.waitForFunction(() => window.dataLayer.some((a) => a[0] === "config"));
    const update = await lastUpdate(page);
    assert.equal(update.ad_storage, "granted");
    assert.equal(update.ad_user_data, "granted");
    assert.equal(update.ad_personalization, "granted");
    assert.equal(update.analytics_storage, "denied", "the site does not ask about Analytics");
    await send();
    assert.deepEqual((await conversions(page))[0][2], { send_to: "AW-123456789/AbCd_ef-12" });
    await ctx.close();
  };
  // Native 1. Advertising accepted beforehand: one conversion; reloading does not add another.
  const acceptedBefore = async () => {
    const { ctx, page } = await open(formUrl);
    await page.getByRole("button", { name: "Aceptar todas" }).click();
    await sendNative(page);
    assert.match(page.url(), /af_result=/);
    assert.deepEqual((await conversions(page)).map((e) => e[2]), [{ send_to: "AW-123456789/AbCd_ef-12" }]);
    await page.reload({ waitUntil: "networkidle" });
    await page.waitForTimeout(300);
    assert.equal((await conversions(page)).length, 0, "the reload is not a new submission");
    await ctx.close();
  };
  // Native 2. No choice yet: it waits; rejecting sends nothing.
  const rejected = async () => {
    const { ctx, page } = await open(formUrl);
    await sendNative(page);
    assert.equal((await conversions(page)).length, 0, "nothing before choosing");
    await page.getByRole("button", { name: "Rechazar todas" }).click();
    await page.waitForTimeout(300);
    assert.equal((await conversions(page)).length, 0, "nothing after rejecting");
    await ctx.close();
  };
  // Native 3. No choice yet, and the visitor accepts on the page that comes back: it is sent then.
  const acceptedAfter = async () => {
    const { ctx, page } = await open(formUrl);
    await sendNative(page);
    await page.getByRole("button", { name: "Aceptar todas" }).click();
    await page.waitForFunction(() => window.dataLayer.some((a) => a[0] === "event" && a[1] === "conversion"));
    assert.equal((await conversions(page)).length, 1);
    assert.deepEqual(page.errors, []);
    await ctx.close();
  };
  await Promise.all([byEvent(), acceptedBefore(), rejected(), acceptedAfter()]);
});

test("3. advanced: Google is requested RIGHT AWAY, with everything denied, before choosing", async () => {
  const { ctx, page } = await open(withTags(pageUrl, { ga4: "G-TEST12345", ads: "", ads_form_label: "", gtm: "GTM-ABCD123", mode: "advanced" }));
  assert.ok(page.google.some((u) => u.includes("gtag/js?id=G-TEST12345")), "gtag.js");
  assert.ok(page.google.some((u) => u.includes("gtm.js?id=GTM-ABCD123")), "gtm.js");
  assert.ok(!(await layer(page)).some((e) => e[0] === "consent" && e[1] === "update" && e[2].analytics_storage === "granted"));
  await ctx.close();
});
});
