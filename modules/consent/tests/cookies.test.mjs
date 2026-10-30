// NIVEL: 2 — Playground WordPress. NOT LEVEL 1 BECAUSE: what is tested is the detector REST route (its limits per IP and per hour, origin, logged-in users, what it stores in the database) and the detector state the site prints in the notice for a visitor and for a logged-in session; the registry's pure rules are level 1 (cookies-pure.test.mjs).
//
// The cookie detector (1.1.0): what visitors' browsers report. What it is
// and why: headers of includes/class-cookie-registry.php and
// class-cookie-rest.php. The admin route, the policy table and the notice's
// «Preferences» are cookie-registry.test.mjs; the browser side (that
// accepting sends only the names) is the browser scenarios 13-cookies-accept
// and 14-cookies-reject.
//
// Helper routes: /consent-setup (tests/wp/, plays a theme that declares
// categories) and those of modules/consent/tests/wp/estrategia-web-cookies-test.php
// (reset, detector limits, the raw option).
//
// 28-10-2026: one reset for the file (it was a reset per test, and the
// whole file 100 s), the detector cases are ONE sequence on one stored
// setup, and the requests that do not depend on each other go together.
// The admin, table and notice cases moved to cookie-registry.test.mjs.
// Seen red (28-10-2026) with from_logged_in_user() forced to false in
// class-cookie-rest.php (the logged-in report is rate-limited like a
// visitor's, 429, instead of the silent 202).
//
// How to maintain: a new detector limit gets its step here with the filter
// set through /setup; never lower the real limits to make it pass.
// The traps of the sequence: the per-IP interval (10 min) is real only in
// its first step, then /setup sets it to 0; and the hourly cap counts every
// ACCEPTED report of the file (not the 400s, the 403s, the 429s nor the
// logged-in ones, which return before counting): the reports that count are
// sent one at a time, or the count races and the «cap» step stops being exact.
import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { BASE, adminSession, credentials, html, reset, testApi, wp } from "../../../tests/lib.mjs";

const cookiesTest = async (path, body) => {
  const res = await fetch(BASE + "/wp-json/estrategia-web-cookies-test/v1" + path, {
    method: body === undefined ? "GET" : "POST",
    headers: { "Content-Type": "application/json" },
    body: body === undefined ? undefined : JSON.stringify(body),
  });
  if (!res.ok) throw new Error(`${path} -> ${res.status} ${await res.text()}`);
  return res.json();
};
const raw = async () => (await cookiesTest("/raw")).raw ?? {};
const front = async () => (await html(BASE + "/?nocache=" + Date.now())).text;

/** Detector POST as a browser would send it. */
const report = (names, headers = {}) =>
  fetch(BASE + "/wp-json/estrategia-web/v1/cookies/seen", {
    method: "POST",
    headers: { "Content-Type": "application/json", ...headers },
    body: JSON.stringify({ names }),
  });

before(async () => {
  await reset();
  await Promise.all([cookiesTest("/reset", {}), testApi("/consent-setup", { method: "POST", body: { categories: ["maps"] } }), credentials()]);
});
// The site reset() does not delete the registry, its settings or the
// detector's transients: the other files share this WordPress.
after(() => cookiesTest("/reset", {}));

test("detector: only NAMES are stored; per IP, size, origin, per hour; a logged-in user adds nothing; the page carries its state; off = 403", async () => {
  const login = adminSession(); // In the background: it is only needed further down.

  // Stores only names, replies 202 (hour count: 1); the same IP within the
  // interval (the real 10 min) gives 429.
  const r = await report(["_ga", "_ga_ABC123", "wp-wpml_current_language", "x=secret", "<b>"]);
  assert.equal(r.status, 202, await r.text());
  assert.equal((await report(["another"])).status, 429, "the same client within the 10 minutes");

  // None of these count for the hour, nor depend on the interval: more than
  // 60 names, a foreign origin, and (1.7.0, Lavandería production
  // 24-10-2026: an admin's wp-settings-* and wp-wpml_current_admin_language_*
  // landed as pending) a logged-in user's report, which answers the same and
  // adds nothing («Logged-in users» in includes/class-cookie-rest.php).
  const session = await login;
  const [many, evil, logged] = await Promise.all([
    report(Array.from({ length: 61 }, (_, i) => "n" + i)),
    report(["b1"], { Origin: "https://evil.example" }),
    report(["wp-settings-1", "wp-wpml_current_admin_language_d41d8"], { Cookie: session.cookie(), Origin: BASE }),
    cookiesTest("/setup", { interval: 0, cap: 3 }),
  ]);
  assert.equal(many.status, 400);
  assert.equal(evil.status, 403);
  assert.equal(logged.status, 202, "same answer as always: nothing revealed");

  // These count, one at a time: the site's own origin (2), an anonymous
  // visitor (3); with cap 3 the next one in the hour, from anyone, is refused.
  assert.equal((await report(["b2"], { Origin: BASE })).status, 202, "the site's own origin is allowed");
  assert.equal((await report(["anonymous_probe"])).status, 202);
  assert.equal((await report(["a3"])).status, 429, "fourth in the hour with cap 3");

  const stored = await raw();
  assert.deepEqual(Object.keys(stored).sort(), ["_ga", "_ga_*", "anonymous_probe", "b2", "wp-wpml_current_language"], "only the accepted names: not «another» (429), not the logged-in user's, not «a3» (over the cap)");
  assert.ok(!JSON.stringify(stored).includes("secret") && !JSON.stringify(stored).includes("<b>"), "no values and no HTML");
  assert.equal(stored["wp-wpml_current_language"].category, "necessary");
  assert.equal(stored["_ga"].status, "pending");
  assert.equal(stored.anonymous_probe.status, "pending", "anonymous reports still come in");

  // The page with the notice carries the detector URL, the period and the
  // delay — but not for the logged-in user.
  const [visitor, editor] = await Promise.all([front(), session.get("/?nocache=" + Date.now()).then((p) => p.text)]);
  const config = visitor.match(/"detector":\{"url":"([^"]+)","days":(\d+),"delay":(\d+)\}/);
  assert.ok(config, "the detector configuration is missing from the notice state");
  assert.match(config[1].replace(/\\\//g, "/"), /\/wp-json\/estrategia-web\/v1\/cookies\/seen$/);
  assert.equal(config[2], "7");
  assert.ok(editor.includes("estrategia-web-consent"), "the logged-in page does have the notice");
  assert.ok(!editor.includes('"detector":{'), "but no detector configuration");

  // Off: 403, and the page does not carry the detector.
  const off = await wp("/settings", { method: "POST", body: { estrategia_web_cookies_settings: { detector: false, ask: [] } } });
  assert.equal(off.status, 200, off.text);
  const [refused, page] = await Promise.all([report(["b3"]), front()]);
  assert.equal(refused.status, 403);
  assert.ok(!page.includes('"detector":{'), "when off, the page does not carry the detector configuration");
});
