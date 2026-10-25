// NIVEL: 3 — browser. NOT LEVEL 2 BECAUSE: «stays in the same place while the page scrolls» and «the cookie notice is ON TOP of the button where they overlap» are positions and stacking after layout and scrolling; the HTML does not say them.
//
// The floating WhatsApp button and the cookie notice, two blocks of this
// plugin that share the bottom corner of a phone:
//  1. at 402 and 1280 px, the button is in the SAME place at the top of a
//     long page, halfway and at the end (it is really fixed, not just
//     position: fixed in a stylesheet that something overrides);
//  2. with the notice open, wherever the two overlap, the NOTICE is on top
//     (it is what has to be answered first; at 402 they overlap).
//
// Moved from Lavandería (tests/whatsapp-fixed.test.mjs, its points 1 and 3)
// on 25-10-2026: the plugin-generic part, on a page of its own. Lavandería
// keeps what is its design: the distance to the corner from its kit (38 /
// 16 px) and that the button covers nothing of its footer. That the button
// has position: fixed and its safe-area insets is whatsapp.test.mjs.
//
// How to maintain: if the notice or the button change their z-index or
// position, this is what says whether they still stack right.
// The traps: the notice shows in every new context (it is what step 2
// needs); and every scroll here is behavior: "instant" — with
// scroll-behavior: smooth on the root (some themes, Lavandería's), a plain
// scrollTo() ANIMATES and 100 ms later the page is halfway (06-10-2026).
//
// Seen red (25-10-2026): expecting «button on top», step 2 fails at 402;
// restored, green.
import { test, before } from "node:test";
import { withBrowser, assert, BASE_URL } from "../../../tests/browser.mjs";
import { reset, testApi, seedPage } from "../../../tests/lib.mjs";

let url;
before(async () => {
  await reset();
  await testApi("/consent-setup", { method: "POST", body: { categories: ["maps"] } });
  const page = await seedPage(
    '<!-- wp:paragraph --><p>Arriba</p><!-- /wp:paragraph -->\n<!-- wp:spacer {"height":"3000px"} --><div style="height:3000px" aria-hidden="true" class="wp-block-spacer"></div><!-- /wp:spacer -->\n<!-- wp:paragraph --><p>Abajo</p><!-- /wp:paragraph -->\n<!-- wp:estrategia-web/whatsapp {"phone":"+34 600 111 222","position":"bottom-left"} /-->',
    "prueba-whatsapp-aviso"
  );
  url = page.url;
});

const box = (page) =>
  page.evaluate(() => {
    const r = document.querySelector(".estrategia-web-whatsapp")?.getBoundingClientRect();
    return r && { left: Math.round(r.left), bottom: Math.round(innerHeight - r.bottom), width: Math.round(r.width), height: Math.round(r.height) };
  });

test("whatsapp + notice: fixed while scrolling, and the notice on top where they overlap", () =>
  withBrowser(async ({ newPage }) => {
    const bad = [];
    for (const width of [402, 1280]) {
      const page = await newPage({ viewport: { width, height: 900 } });
      await page.goto(url, { waitUntil: "networkidle" });
      const start = await box(page);
      assert.ok(start, `${width}: no WhatsApp button`);
      assert.ok(await page.evaluate(() => document.documentElement.scrollHeight > innerHeight * 2), `${width}: the page is not long enough to scroll`);
      for (const to of [0.5, 1]) {
        await page.evaluate((f) => scrollTo({ top: (document.documentElement.scrollHeight - innerHeight) * f, behavior: "instant" }), to);
        await page.waitForTimeout(100);
        const now = await box(page);
        if (JSON.stringify(now) !== JSON.stringify(start)) bad.push(`${width}: it moves when scrolling (${JSON.stringify(start)} → ${JSON.stringify(now)})`);
      }
      const covered = await page.evaluate(() => {
        const a = document.querySelector(".estrategia-web-whatsapp").getBoundingClientRect();
        const notice = document.querySelector("#estrategia-web-consent");
        if (!notice || !notice.getBoundingClientRect().height) return "no notice";
        const n = notice.getBoundingClientRect();
        const x = Math.max(a.left, n.left) + 1;
        const y = Math.max(a.top, n.top) + 1;
        const overlap = x < Math.min(a.right, n.right) && y < Math.min(a.bottom, n.bottom);
        return overlap ? (notice.contains(document.elementFromPoint(x, y)) ? "notice on top" : "button on top") : "no overlap";
      });
      if (covered === "button on top" || covered === "no notice") bad.push(`${width}: with the cookie notice open → ${covered}`);
      if (width === 402 && covered === "no overlap") bad.push("402: the notice and the button do not overlap, so step 2 checks nothing (did the layout change?)");
      assert.deepEqual(page.errors, [], page.errors.join("\n"));
      await page.context().close();
    }
    assert.deepEqual(bad, [], `WhatsApp and the notice:\n${bad.join("\n")}`);
  })
);
