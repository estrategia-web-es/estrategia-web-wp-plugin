// NIVEL: 2 — Playground WordPress. NOT LEVEL 1 BECAUSE: what is tested is the submission ENDPOINT as WordPress serves it — the rendered form's hidden fields, the REST route, what it WRITES (submissions in the table, intercepted wp_mail, rate-limit transients), the 303 back to the page and the page it renders then. The decisions it calls (token, markers, IP) are level 1 in pure.test.mjs.
//
// Everything here is done WITHOUT a browser, crafting the request: that is exactly
// what a bot or someone bypassing the browser's validation would do.
//
// ONE form load for the whole file (28-10-2026, from 75 requests to ~20):
// the token is not single-use (it expires after 7 days), so every case
// submits the same hidden fields; and the rejections are not checked one by
// one for «nothing saved»: the good submission finds exactly ONE row and
// three emails, which proves that none of the rejected ones before it
// (each with its own «nombre») saved or notified anything. The order of the
// tests matters and is the file's: min_seconds is 30 for the first case only,
// and the rate limit goes last because it counts every submission after it.
// Round 2 (28-10-2026, ~19 rounds of requests to ~12): the submissions that
// do not depend on each other are sent two at a time — the six of the first
// cases in before(), the two no-JS ones together, the purge with the rate
// limit's setting — and each test asserts its own answer; the good one
// answers the emails and the log itself (submit(…, { state: true })).
//
// Seen red (28-10-2026), every test, with these mutants of the plugin: min_seconds
// passed as 0 to Token::verify («too early» → 200); only «too fast» tokens
// rejected (the tampered one passed); the honeypot branch removed (the good
// submission found «honeypot» in the log); the select's option check removed
// (no error on «servicio»); the rate limit's «>=» as «>» (the second one →
// 200); the purge's cutoff two days older (deleted 0); Blocks::announce()
// saving «announced» as false (the reload carried the signal again); the
// 303 without its «-status» anchor; and a «{{email}}» comment in the
// rendered form (the public HTML test).
// Seen red again after round 2 (28-10-2026), every test, with the mutants above (one by one where a test reads what the one before left).
import { test, before } from "node:test";
import assert from "node:assert/strict";
import { seed, settings, loadForm, submit, GOOD, TEAM, FAIL, FORM, testApi, BASE } from "./lib.mjs";

// The URL of the run's WordPress, escaped for a regular expression (with EW_PORT it is not 9404).
const SITE = BASE.replace(/[.*+?^${}()|[\]\\/]/g, "\\$&");
const html = async (url) => (await fetch(url)).text();

let page, form, hidden, good, sent;
before(async () => {
  // min_seconds 30: the too-early case is rejected on purpose; then it goes back to 1.
  ({ pages: { contact: page } } = await seed({ settings: { min_seconds: 30, retention_days: 30 }, pages: [{ key: "contact", slug: "contacto", title: "Contacto", content: FORM }] }));
  ({ html: form, hidden } = await loadForm(page.url));
  // Round 2 (28-10-2026): the submissions that do not depend on each other go at once, here, and each
  // test below asserts its own answer. First the four the server answers BEFORE looking at min_seconds
  // or that min_seconds = 30 rejects (honeypot and definition go before the token; a forged
  // signature is invalid at any age), then, with min_seconds = 1, the invalid one and the good one.
  const forged = { ...hidden, _af_token: String(Math.floor(Date.now() / 1000) - 60) + "." + "a".repeat(40) };
  const [tooEarly, forgedToken, noDefinition, honeypot] = await Promise.all([
    submit(hidden, { ...GOOD, nombre: "too-early" }),
    submit(forged, { ...GOOD, nombre: "forged" }),
    submit({ ...hidden, _af_def: "0".repeat(32) }, { ...GOOD, nombre: "no-definition" }),
    submit(hidden, { ...GOOD, nombre: "honeypot" }, { extra: { af_website: "http://spam.example" } }),
  ]);
  await settings({ min_seconds: 1 });
  const [invalid, goodOne] = await Promise.all([
    submit(hidden, { ...GOOD, nombre: "invalid", email: "not-an-email", servicio: "Tintorería", mensaje: "", privacidad: "" }),
    submit(hidden, { ...GOOD, campo_inventado: "x" }, { state: true }), // It answers the emails and the log too.
  ]);
  sent = { tooEarly, forgedToken, noDefinition, honeypot, invalid, good: goodOne };
});

test("the public HTML contains no notification recipients or templates", () => {
  assert.match(form, /name="fields\[nombre\]"/, "the form is rendered");
  assert.ok(hidden._af_def && hidden._af_token, "it carries definition and token");
  for (const secret of [TEAM, FAIL, "Nuevo mensaje de", "Hemos recibido tu mensaje", "Aviso al equipo", "{{"]) {
    assert.ok(!form.includes(secret), `the public HTML contains «${secret}»`);
  }
});

test("minimum time: submitting too early is rejected", () => {
  assert.equal(sent.tooEarly.status, 403);
  assert.equal(sent.tooEarly.json.code, "token_too_fast");
});

test("required fields, a malformed email and an option not in the list fail on the server even if nobody validates them before", () => {
  const r = sent.invalid;
  assert.equal(r.status, 422);
  assert.equal(r.json.ok, false);
  assert.deepEqual(Object.keys(r.json.errors).sort(), ["email", "mensaje", "privacidad", "servicio"], "consent is required even if the block does not say required");
});

test("tampered token or one from another definition: rejected", () => {
  assert.equal(sent.forgedToken.json.code, "token_invalid");
  assert.equal(sent.noDefinition.json.code, "unknown_form");
});

test("filled honeypot: answers success (the bot is not told it failed)", () => {
  assert.equal(sent.honeypot.status, 200);
  assert.equal(sent.honeypot.json.ok, true);
});

test("good submission: logged with everything and the N notifications go out with markers replaced; none of the rejected ones left a row or an email", async () => {
  const r = sent.good;
  assert.equal(r.status, 200);
  assert.equal(r.json.ok, true);

  const { submissions, mails } = r.state;
  assert.deepEqual(submissions.map((s) => s.data.find((d) => d.name === "nombre")?.value), ["Ana"], "only the good one is in the log (too-early, invalid, forged, no-definition and honeypot saved nothing)");
  const [row] = submissions;
  good = row;
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

  assert.equal(mails.length, 3, "all three notifications are attempted, and no rejected submission notified anything");
  const [team, ack] = mails;
  assert.deepEqual(team.to, [TEAM]);
  assert.equal(team.subject, "Nuevo mensaje de Ana");
  assert.ok(team.headers.includes("Reply-To: ana@ejemplo.test"), "reply-to comes from the email field");
  for (const m of [team, ack]) {
    assert.ok(m.headers.some((h) => /^From: Sitio de pruebas <wordpress@[^>]+>$/.test(h)), "signed with the site name, not «WordPress»:\n" + m.headers.join("\n"));
  }
  assert.match(team.html, new RegExp(`Desde ${SITE}/contacto/`));
  assert.match(team.html, /Sitio de pruebas/);
  assert.match(team.html, /<th[^>]*>Teléfono<\/th><td[^>]*>\+34 600 000 000<\/td>/, "{{todos_los_campos}} as an HTML table");
  assert.match(team.text, /Mensaje: \nHola,\nquería información\./, "and as lines in the text version");
  assert.deepEqual(ack.to, ["ana@ejemplo.test"], "the acknowledgement goes to the email the visitor typed");
  assert.match(ack.text, /^Hola Ana, gracias por escribir\.\nServicio: Planchado/);
  assert.match(ack.text, new RegExp(`Alias: ${SITE}/contacto/ el \\S.* en Sitio de pruebas:\\nNombre: Ana\\n`), "the old Spanish markers still work (aliases until 2.0.0)");
  assert.match(ack.html, /<th[^>]*>Teléfono<\/th>/, "{{todos_los_campos}} is still the HTML table");
  for (const m of mails) {
    assert.ok(!m.html.includes("{{") && !m.text.includes("{{") && !m.subject.includes("{{"), "no unreplaced marker");
  }
});

const SIGNAL = /<script class="estrategia-web-forms-sent"[^>]*>\s*document\.dispatchEvent\(new CustomEvent\('estrategia-web:form-sent',\{detail:\{"form":"af-contacto","returned":true\}\}\)\);\s*(\/\*[^*]*\*\/\s*)?<\/script>/;

let okWithoutJs, okFirst;
test("without JS, with an error: 303 to the page, the field marked, what was typed kept, and no conversion signal", async () => {
  // At once, the good one of the next test, and their return pages at once too.
  let r;
  [r, okWithoutJs] = await Promise.all([submit(hidden, { ...GOOD, mensaje: "" }, { js: false }), submit(hidden, GOOD, { js: false })]);
  assert.equal(r.status, 303);
  assert.match(r.location, new RegExp(`^${SITE}/contacto/\\?af_result=[a-z0-9]{20}#af-contacto-status$`));
  let back;
  [back, okFirst] = await Promise.all([html(r.location), okWithoutJs.status === 303 ? html(okWithoutJs.location) : ""]);
  assert.match(back, /aria-invalid="true"/, "the field with an error is marked");
  assert.match(back, /value="Ana"/, "what was typed is kept");
  assert.ok(!back.includes("estrategia-web-forms-sent"), "an error is not a conversion");
});

// Seen failing on 17-10-2026 with two mutants in Blocks::announce(): without
// the «announced» mark (the reload fired it again) and announcing errors too.
test("without JS, OK: the page that comes back fires «estrategia-web:form-sent» ONCE (for the Ads conversion)", async () => {
  const ok = okWithoutJs; // Sent, and its page read once, with the case before.
  assert.equal(ok.status, 303);
  const first = okFirst;
  const around = (h) => (h.match(/[\s\S]{0,200}estrategia-web-forms-sent[\s\S]{0,300}/) || h.match(/af-contacto-status[\s\S]{0,400}/) || [""])[0];
  assert.ok(SIGNAL.test(first), "the return page carries the signal:\n" + around(first));
  assert.equal(first.match(/estrategia-web:form-sent/g).length, 1, "once in the page");
  const again = await html(ok.location);
  assert.match(again, /Thank you|Gracias/, "the message is still there on reload");
  assert.ok(!again.includes("estrategia-web-forms-sent"), "but the signal is not: the conversion would count twice");
});

test("scheduled purge: deletes what is older than the retention (30 days) and leaves the rest", async () => {
  // Two rows now: the good one and the no-JS one. The good one, 31 days old. At once, the next test's
  // rate limit (it only counts the submissions after it).
  const [r] = await Promise.all([testApi("/purge", { method: "POST", body: { backdate: { id: good.id, days: 31 } } }), settings({ rate_limit: 1, rate_window: 10 })]);
  assert.equal(r.scheduled, true, "the purge is scheduled in WP-Cron");
  assert.equal(r.last_purge.deleted, 1);
  assert.equal(r.left.length, 1);
  assert.notEqual(r.left[0], Number(good.id));
});

test("per-IP limit: the submission after the limit in the window is rejected", async () => {
  assert.equal((await submit(hidden, GOOD)).status, 200);
  const over = await submit(hidden, GOOD);
  assert.equal(over.status, 429);
  assert.equal(over.json.code, "rate_limit");
});
