// NIVEL: 2 — Playground WordPress. NOT LEVEL 1 BECAUSE: what is tested is what a real WordPress answers to /llms.txt (the headers, the 404 when there is no text, that WordPress's 404 and the redirects module's 404 log never see it, which depends on WHEN parse_request runs), and what the «Settings › llms.txt» tab prints and saves through options.php. Read as plain HTTP and the tab's HTML (tests/lib.mjs adminSession): the button only copies a textarea, and that the draft is in the page is what matters.
//
// What and why: modules/llms (header of module.php). The pure part, the
// cases of every path and of «Other languages», is text.test.mjs (level 1);
// /en/llms.txt with REAL WPML is llms-wpml.test.mjs.
//
// The case that teaches the trap is «the redirects module's 404 log never
// sees /llms.txt»: answered on template_redirect (as Rank Math does), the
// request would be a 404 first and land in that log.
//
// Seen red (31-10-2026): with maybe_serve() moved to template_redirect, only
// that test; and «the module switched off» when the helper switched modules
// off from the list already loaded (llms included), a bug of the test.
import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { BASE, reset, testApi, wp, saveCompany, adminSession } from "../../../tests/lib.mjs";
import { api as redirects } from "../../redirects/tests/lib.mjs";

const OPTION = "estrategia_web_llms";
const TEXT = "# Lavandería de pruebas\n\n> Lavandería autoservicio en Palma.\n\n- [Diseño](https://x.test/dise%C3%B1o/) <https://x.test/>";
const TAB = "/wp-admin/admin.php?page=estrategia-web-settings&tab=llms";

/** The modules loaded right after the core reset (the Default: on ones), read once. */
let defaults;
/** Those plus these. */
async function modules(...extra) {
  await testApi("/modules", { method: "POST", body: { active: [...new Set([...defaults, ...extra])] } });
}
const save = async (texts) => {
  const r = await wp("/settings", { method: "POST", body: { [OPTION]: texts } });
  assert.equal(r.status, 200, r.text);
  return r.json[OPTION];
};
const get = (p, method = "GET") => fetch(BASE + p, { method, redirect: "manual" });

before(async () => {
  await reset();
  await testApi("/options", { method: "POST", body: { names: [OPTION], delete: [OPTION] } });
  defaults = (await testApi("/modules")).loaded.filter((m) => m !== "llms" && m !== "redirects");
  await modules("llms");
});

after(async () => {
  await redirects("/fake-seo", { method: "POST", body: {} });
  await testApi("/options", { method: "POST", body: { names: [OPTION], delete: [OPTION] } });
  await reset();
});

test("without text, /llms.txt is WordPress's normal 404", async () => {
  const r = await get("/llms.txt");
  assert.equal(r.status, 404);
  assert.match(r.headers.get("content-type"), /text\/html/);
});

test("with text: 200, text/plain with charset, noindex, nosniff, and the text as it was written (%xx and <…> included)", async () => {
  // The language code without WPML is the site locale's language (header of class-languages.php).
  const { loaded } = await testApi("/modules");
  assert.ok(loaded.includes("llms"), "the module is not loaded: " + loaded);
  const stored = await save({ es: TEXT, en: TEXT });
  assert.deepEqual(Object.keys(stored).sort(), ["en", "es"]);
  const [r, head] = await Promise.all([get("/llms.txt"), get("/llms.txt", "HEAD")]);
  assert.equal(r.status, 200);
  assert.equal(r.headers.get("content-type"), "text/plain; charset=utf-8");
  assert.equal(r.headers.get("x-robots-tag"), "noindex");
  assert.equal(r.headers.get("x-content-type-options"), "nosniff");
  // Without WPML there is one language: no «Other languages» even with an «en» text stored.
  assert.equal(await r.text(), TEXT + "\n");
  assert.equal(head.status, 200);
  assert.equal(await head.text(), "");
});

test("without WPML, /en/llms.txt is not a llms.txt, and the module switched off serves nothing", async () => {
  assert.equal((await get("/en/llms.txt")).status, 404);
  await modules();
  assert.equal((await get("/llms.txt")).status, 404);
  await modules("llms");
});

test("the redirects module's 404 log never sees /llms.txt (it is answered on parse_request)", async () => {
  await modules("llms", "redirects");
  await redirects("/reset", { method: "POST" });
  await redirects("/settings", { method: "POST", body: { log_404: true } });
  assert.equal((await get("/llms.txt")).status, 200);
  assert.equal((await get("/no-existe-llms")).status, 404);
  const paths = (await redirects("/log")).map((row) => row.path);
  assert.ok(paths.some((p) => p.includes("no-existe-llms")), "the log is not recording: " + JSON.stringify(paths));
  assert.ok(!paths.some((p) => p.includes("llms.txt")), "/llms.txt reached the 404 log: " + JSON.stringify(paths));
  await modules("llms");
});

test("the tab: the text, a draft from Company details and the pages, and saving keeps the other language", async () => {
  await saveCompany({ company_name: "Lavandería de pruebas", tax_id: "B00000000", phone: "971 00 00 00", address: "Calle Camp Franc, 11\n07199 Palma" });
  const page = await wp("/pages", { method: "POST", body: { title: "Autoservicio llms", slug: "autoservicio-llms", status: "publish", excerpt: "Lavadoras de 9 y 18 kg." } });
  assert.equal(page.status, 201, page.text);
  try {
    const s = await adminSession();
    const tab = await s.get(TAB);
    assert.equal(tab.status, 200, tab.location);
    // The one language's code is the site locale's (es or en, whatever this WordPress runs in): read from the tab.
    const [, code] = tab.text.match(/<textarea[^>]+name="estrategia_web_llms\[([a-z-]+)\]"/) || [];
    assert.ok(code, "no text field in the tab");
    assert.match(tab.text, new RegExp(`name="estrategia_web_llms\\[${code}\\]"[^>]*>[^<]*# Lavandería de pruebas`));
    assert.equal((tab.text.match(/name="estrategia_web_llms\[/g) || []).length, 1, "without WPML, one text");
    const draft = tab.text.match(new RegExp(`<textarea hidden readonly id="estrategia-web-llms-${code}-draft">([\\s\\S]*?)</textarea>`));
    assert.ok(draft, "no draft in the tab");
    assert.match(draft[1], /^# Lavandería de pruebas\n/);
    assert.match(draft[1], /B00000000 · Calle Camp Franc, 11, 07199 Palma · 971 00 00 00/);
    assert.match(draft[1], /- \[Autoservicio llms\]\(http[^)]+autoservicio-llms\/\): Lavadoras de 9 y 18 kg\./);
    assert.match(tab.text, /modules\/llms\/assets\/admin\.js/);
    assert.doesNotMatch(tab.text, /ew-llms-conflict/);
    // Saved through the tab's form: the site's language changes, the other one (not printed without WPML) stays.
    const saved = await s.submit(tab.text, "options.php", { [`estrategia_web_llms[${code}]`]: "# Nuevo" });
    assert.equal(saved.status, 302, saved.text.slice(0, 300));
    const { [OPTION]: stored } = await testApi("/options", { method: "POST", body: { names: [OPTION] } });
    assert.deepEqual(stored, { es: TEXT, en: TEXT, [code]: "# Nuevo" });
  } finally {
    await wp(`/pages/${page.json.id}?force=true`, { method: "DELETE" });
  }
});

test("the tab warns when Rank Math's «llms-txt» module is on, and only then", async () => {
  const s = await adminSession();
  await redirects("/fake-seo", { method: "POST", body: { plugin: "rank-math", modules: ["sitemap", "redirections"] } });
  assert.doesNotMatch((await s.get(TAB)).text, /ew-llms-conflict/);
  await redirects("/fake-seo", { method: "POST", body: { plugin: "rank-math", modules: ["sitemap", "llms-txt"] } });
  assert.match((await s.get(TAB)).text, /class="notice notice-warning inline ew-llms-conflict"><p><strong>Rank Math /);
  await redirects("/fake-seo", { method: "POST", body: {} });
});
