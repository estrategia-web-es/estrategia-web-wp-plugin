// NIVEL: 2 — Playground WordPress, because it WRITES: submissions in the table, intercepted emails, rate-limit transients.
//
// Everything here is done WITHOUT a browser, crafting the request: that is exactly
// what a bot or someone bypassing the browser's validation would do.
import { test, before } from "node:test";
import assert from "node:assert/strict";
import { reset, settings, mails, submissions, seedPage, loadForm, submit, GOOD, TEAM, FAIL, waitMinTime, testApi, BASE } from "./lib.mjs";

// The URL of the run's WordPress, escaped for a regular expression (with EW_PORT it is not 9404).
const SITE = BASE.replace(/[.*+?^${}()|[\]\\/]/g, "\\$&");

let page;
before(async () => {
  await reset();
  page = await seedPage();
});

test("the public HTML contains no notification recipients or templates", async () => {
  const { html, hidden } = await loadForm(page.url);
  assert.match(html, /name="fields\[nombre\]"/, "the form is rendered");
  assert.ok(hidden._af_def && hidden._af_token, "it carries definition and token");
  for (const secret of [TEAM, FAIL, "Nuevo mensaje de", "Hemos recibido tu mensaje", "Aviso al equipo", "{{"]) {
    assert.ok(!html.includes(secret), `the public HTML contains «${secret}»`);
  }
});

test("an empty required field fails on the server even if nobody validates it before", async () => {
  await reset();
  const { hidden } = await loadForm(page.url);
  await waitMinTime();
  const r = await submit(hidden, { ...GOOD, mensaje: "", privacidad: "" });
  assert.equal(r.status, 422);
  assert.equal(r.json.ok, false);
  assert.ok(r.json.errors.mensaje, "error on mensaje");
  assert.ok(r.json.errors.privacidad, "consent is required even if the block does not say required");
  assert.equal((await submissions()).length, 0, "nothing is saved");
  assert.equal((await mails()).length, 0, "no notification goes out");
});

test("malformed email and an option not in the list: server error", async () => {
  const { hidden } = await loadForm(page.url);
  await waitMinTime();
  const r = await submit(hidden, { ...GOOD, email: "not-an-email", servicio: "Tintorería" });
  assert.equal(r.status, 422);
  assert.ok(r.json.errors.email);
  assert.ok(r.json.errors.servicio);
});

test("filled honeypot: answers success and neither saves nor notifies", async () => {
  await reset();
  const { hidden } = await loadForm(page.url);
  await waitMinTime();
  const r = await submit(hidden, GOOD, { extra: { af_website: "http://spam.example" } });
  assert.equal(r.status, 200);
  assert.equal(r.json.ok, true, "the bot is not told it failed");
  assert.equal((await submissions()).length, 0);
  assert.equal((await mails()).length, 0);
});

test("minimum time: submitting too early is rejected", async () => {
  await reset();
  await settings({ min_seconds: 30 });
  const { hidden } = await loadForm(page.url);
  const r = await submit(hidden, GOOD);
  assert.equal(r.status, 403);
  assert.equal(r.json.code, "token_too_fast");
  assert.equal((await submissions()).length, 0);
  await settings({ min_seconds: 1 });
});

test("tampered token or one from another definition: rejected", async () => {
  await reset();
  const { hidden } = await loadForm(page.url);
  await waitMinTime();
  const forged = { ...hidden, _af_token: String(Math.floor(Date.now() / 1000) - 60) + "." + "a".repeat(40) };
  assert.equal((await submit(forged, GOOD)).json.code, "token_invalid");
  const noDef = { ...hidden, _af_def: "0".repeat(32) };
  assert.equal((await submit(noDef, GOOD)).json.code, "unknown_form");
  assert.equal((await submissions()).length, 0);
});

test("per-IP limit: the third submission in the window is rejected", async () => {
  await reset();
  await settings({ rate_limit: 2, rate_window: 10 });
  const { hidden } = await loadForm(page.url);
  await waitMinTime();
  assert.equal((await submit(hidden, GOOD)).status, 200);
  assert.equal((await submit(hidden, GOOD)).status, 200);
  const third = await submit(hidden, GOOD);
  assert.equal(third.status, 429);
  assert.equal(third.json.code, "rate_limit");
  assert.equal((await submissions()).length, 2);
  await settings({ rate_limit: 0 });
});

test("good submission: logged with everything and the N notifications go out with markers replaced", async () => {
  await reset();
  const { hidden } = await loadForm(page.url);
  await waitMinTime();
  const r = await submit(hidden, { ...GOOD, campo_inventado: "x" });
  assert.equal(r.status, 200);
  assert.equal(r.json.ok, true);

  const [row] = await submissions();
  assert.ok(row, "there is a submission in the log");
  assert.equal(row.form_id, "contacto");
  assert.equal(row.form_title, "Contacto");
  assert.equal(row.page_url, page.url);
  assert.equal(Number(row.post_id), page.id);
  assert.ok(row.lang, "the language is recorded");
  assert.equal(row.ip, "127.0.0.0", "truncated IP, never the full one");
  assert.equal(row.user_agent, "estrategia-web-forms-test/1.0");
  assert.equal(row.status, "partial", "two notifications OK and one failed");
  assert.deepEqual(row.data.map((d) => d.name), ["nombre", "email", "telefono", "servicio", "mensaje", "privacidad"], "only the definition's fields");
  assert.deepEqual(
    row.notifications.map((n) => [n.label, n.to, n.status]),
    [
      ["Aviso al equipo", TEAM, "sent"],
      ["Acuse al remitente", "ana@ejemplo.test", "sent"],
      ["Aviso que falla", FAIL, "failed"],
    ]
  );
  assert.ok(row.notifications[2].error, "the failure stores its reason");

  const sent = await mails();
  assert.equal(sent.length, 3, "all three notifications are attempted");
  const [team, ack] = sent;
  assert.deepEqual(team.to, [TEAM]);
  assert.equal(team.subject, "Nuevo mensaje de Ana");
  assert.ok(team.headers.includes("Reply-To: ana@ejemplo.test"), "reply-to comes from the email field");
  assert.match(team.html, new RegExp(`Desde ${SITE}/contacto/`));
  assert.match(team.html, /Sitio de pruebas/);
  assert.match(team.html, /<th[^>]*>Teléfono<\/th><td[^>]*>\+34 600 000 000<\/td>/, "{{todos_los_campos}} as an HTML table");
  assert.match(team.text, /Mensaje: \nHola,\nquería información\./, "and as lines in the text version");
  assert.deepEqual(ack.to, ["ana@ejemplo.test"], "the acknowledgement goes to the email the visitor typed");
  assert.match(ack.text, /^Hola Ana, gracias por escribir\.\nServicio: Planchado/);
  assert.match(ack.text, new RegExp(`Alias: ${SITE}/contacto/ el \\S.* en Sitio de pruebas:\\nNombre: Ana\\n`), "the old Spanish markers still work (aliases until 2.0.0)");
  assert.match(ack.html, /<th[^>]*>Teléfono<\/th>/, "{{todos_los_campos}} is still the HTML table");
  for (const m of sent) {
    assert.ok(!m.html.includes("{{") && !m.text.includes("{{") && !m.subject.includes("{{"), "no unreplaced marker");
  }
});

test("without JS: 303 redirect to the page with the result", async () => {
  await reset();
  const { hidden } = await loadForm(page.url);
  await waitMinTime();
  const r = await submit(hidden, { ...GOOD, mensaje: "" }, { js: false });
  assert.equal(r.status, 303);
  assert.match(r.location, new RegExp(`^${SITE}/contacto/\\?af_result=[a-z0-9]{20}#af-contacto-status$`));
  const html = await (await fetch(r.location)).text();
  assert.match(html, /aria-invalid="true"/, "the field with an error is marked");
  assert.match(html, /value="Ana"/, "what was typed is kept");
  assert.equal((await submissions()).length, 0);
});

// Seen failing on 17-10-2026 with two mutants in Blocks::announce(): without
// the «announced» mark (the reload fired it again) and announcing errors too.
test("without JS, OK: the page that comes back fires «estrategia-web:form-sent» ONCE (for the Ads conversion); an error never", async () => {
  await reset();
  const signal = /<script class="estrategia-web-forms-sent"[^>]*>\s*document\.dispatchEvent\(new CustomEvent\('estrategia-web:form-sent',\{detail:\{"form":"af-contacto","returned":true\}\}\)\);\s*(\/\*[^*]*\*\/\s*)?<\/script>/;
  let { hidden } = await loadForm(page.url);
  await waitMinTime();
  const ok = await submit(hidden, GOOD, { js: false });
  assert.equal(ok.status, 303);
  const first = await (await fetch(ok.location)).text();
  const around = (html) => (html.match(/[\s\S]{0,200}estrategia-web-forms-sent[\s\S]{0,300}/) || html.match(/af-contacto-status[\s\S]{0,400}/) || [""])[0];
  assert.ok(signal.test(first), "the return page carries the signal:\n" + around(first));
  assert.equal(first.match(/estrategia-web:form-sent/g).length, 1, "once in the page");
  const again = await (await fetch(ok.location)).text();
  assert.match(again, /Thank you|Gracias/, "the message is still there on reload");
  assert.ok(!again.includes("estrategia-web-forms-sent"), "but the signal is not: the conversion would count twice");

  ({ hidden } = await loadForm(page.url));
  await waitMinTime();
  const bad = await submit(hidden, { ...GOOD, mensaje: "" }, { js: false });
  assert.ok(!(await (await fetch(bad.location)).text()).includes("estrategia-web-forms-sent"), "an error is not a conversion");
});

test("scheduled purge: deletes what is older than the retention and leaves the rest", async () => {
  await reset();
  await settings({ retention_days: 30 });
  const { hidden } = await loadForm(page.url);
  await waitMinTime();
  await submit(hidden, GOOD);
  await submit(hidden, GOOD);
  const [a] = await submissions();
  await testApi("/backdate", { method: "POST", body: { id: a.id, days: 31 } });
  const r = await testApi("/purge", { method: "POST" });
  assert.equal(r.scheduled, true, "the purge is scheduled in WP-Cron");
  assert.equal(r.last_purge.deleted, 1);
  const left = await submissions();
  assert.equal(left.length, 1);
  assert.notEqual(left[0].id, a.id);
});
