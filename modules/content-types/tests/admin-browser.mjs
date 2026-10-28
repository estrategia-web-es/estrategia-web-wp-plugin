// NIVEL: BANCO DE TRABAJO — not a test (the runner only runs *.test.mjs). The browser of this module's level 3 tests, logged in as the administrator.
//
//   const { browser, page, errors } = await adminBrowser();   // in before(), next to the seeding
//   …
//   after(() => browser?.close());
//
// What it saves (28-10-2026): the five level 3 files of this module logged in
// through wp-login.php in the browser — two page loads of the test WordPress,
// ~4-6 s per file — before seeing anything. Here the login is ONE plain POST
// (adminSession(), tests/lib.mjs) and its cookies go into the browser
// context, so the first page the test loads is already the one it tests;
// and it can run at the same time as the seeding (Promise.all).
//
// How to maintain it: if a test needs another user, add the parameter here.
// The trap: WordPress sets its auth cookies with two different paths
// (/wp-admin and /wp-content/plugins) and the logged-in one with «/»; they are
// all given «/» here, which a browser accepts (a broader path), so every
// screen sees them.
import { BASE, adminSession, playwright } from "../../../tests/lib.mjs";

export async function adminBrowser({ width = 1400, height = 1000 } = {}) {
  const [session, browser] = await Promise.all([adminSession(), playwright().chromium.launch()]);
  const context = await browser.newContext({ viewport: { width, height } });
  const { hostname } = new URL(BASE);
  await context.addCookies(
    session
      .cookie()
      .split("; ")
      .map((pair) => {
        const i = pair.indexOf("=");
        return { name: pair.slice(0, i), value: pair.slice(i + 1), domain: hostname, path: "/" };
      })
  );
  const page = await context.newPage();
  const errors = [];
  page.on("pageerror", (e) => errors.push(e.message));
  return { browser, page, errors };
}
