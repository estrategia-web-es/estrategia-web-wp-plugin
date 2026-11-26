// NIVEL: 2 — Playground WordPress. NOT LEVEL 1 BECAUSE: what is tested is that WordPress itself runs our checks on Tools › Site Health (core's site_status_tests, its screen, its REST permission) and that gather() reads a real database; level 1 only sees judge() and the registry with stubs.
//
// site-health module (Default: on):
// - the Site Health screen, as the administrator: core puts the DIRECT
//   results in its SiteHealth script variable (WP_Site_Health::
//   enqueue_scripts), so they are read from the HTML; ours are there with
//   the «Estrategia Web» badge, and the ASYNC ones are listed with our
//   route;
// - two checks end to end: search-engines (blog_public 0 on 127.0.0.1, which
//   is not a staging → critical; back to 1 → good) and company-details (the
//   reset leaves it empty → recommended; filled → good);
// - our REST route: an async check as the administrator, 401 without a
//   session, 404 for a check that does not exist or is not async.
//
// Requests: one /setup per state (it is the whole before() in one request),
// one screen per state, the REST calls together.
//
// Seen red (26-11-2026, on port 9490): with the module's Default at «off»
// (all three: no estrategia_web_* result, no route), with search-engines'
// gather() reading a misspelled option (critical never came), with the
// route's permission_callback at __return_true (the 401 test) and with
// social-profiles' gather() throwing (the «threw» guard of the screen test).
import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { testApi, wp, adminSession, BASE } from "../../../tests/lib.mjs";

const COMPANY = { legal_name: "Pruebas Salud, S.L.", tax_id: "B00000000", address: "Carrer de Prova, 1, Palma", phone: "971 000 000" };
let session;

const setup = (body) => testApi("/setup", { method: "POST", body });
/** The SiteHealth variable core prints on the screen: {site_status: {direct: [...], async: [...]}}. */
async function screen() {
  const r = await session.get("/wp-admin/site-health.php");
  assert.equal(r.status, 200, "the Site Health screen opens");
  const m = r.text.match(/var SiteHealth = (\{[\s\S]*?\});\n/);
  assert.ok(m, "the screen has no SiteHealth variable");
  return JSON.parse(m[1]).site_status;
}
const ours = (list, key) => list.find((t) => t.test === key);

before(async () => {
  await setup({ options: { blog_public: "0" } });
  session = await adminSession();
});
after(() => setup({ options: { blog_public: "1" } }));

test("site-health: on by default; the screen runs our direct checks and lists the async ones with our route", async () => {
  const status = await screen();
  const direct = status.direct.filter((t) => String(t.test).startsWith("estrategia_web_"));
  assert.ok(direct.length >= 8, "our direct checks ran: " + direct.map((t) => t.test).join(", "));
  for (const t of direct) {
    assert.deepEqual(t.badge, { label: "Estrategia Web", color: "blue" }, t.test);
    // Every gather() ran against a real database without throwing (Registry::result()'s «could not check»).
    assert.ok(!/stopped with an error|se ha detenido con un error/.test(t.description), `${t.test} threw: ${t.description}`);
  }
  assert.equal(ours(direct, "estrategia_web_search_engines").status, "critical", "blog_public 0 on a site that is not a staging");
  assert.equal(ours(direct, "estrategia_web_company_details").status, "recommended", "the reset leaves the company empty");
  const asyncTests = Object.fromEntries((status.async || []).map((t) => [t.test, t]));
  for (const id of ["security-headers", "dmarc"]) {
    const t = Object.values(asyncTests).find((a) => String(a.test).includes("/estrategia-web/v1/site-health/" + id));
    assert.ok(t, `async ${id} listed with our route: ` + JSON.stringify(status.async));
    assert.equal(t.has_rest, true);
  }
});

test("site-health: the same checks with the problem fixed are good (gather reads the real options)", async () => {
  await setup({ company: COMPANY, options: { blog_public: "1" } });
  const status = await screen();
  assert.equal(ours(status.direct, "estrategia_web_search_engines").status, "good");
  assert.equal(ours(status.direct, "estrategia_web_company_details").status, "good");
});

test("site-health: the REST route runs an async check for the administrator only, and only async ones", async () => {
  const route = "/../../estrategia-web/v1/site-health/";
  const [dmarc, anon, unknown, direct] = await Promise.all([
    wp(route + "dmarc"),
    fetch(BASE + "/wp-json/estrategia-web/v1/site-health/dmarc"),
    wp(route + "nothing-here"),
    wp(route + "search-engines"),
  ]);
  assert.equal(dmarc.status, 200, dmarc.text);
  assert.equal(dmarc.json.test, "estrategia_web_dmarc");
  assert.equal(dmarc.json.status, "recommended", "127.0.0.1 has no DNS: «could not check»");
  assert.equal(dmarc.json.badge.label, "Estrategia Web");
  assert.equal(anon.status, 401, "no session, no result");
  assert.equal(unknown.status, 404);
  assert.equal(direct.status, 404, "a direct check is not served by the route");
});
