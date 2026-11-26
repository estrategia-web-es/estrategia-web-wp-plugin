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
import { seed, settings, loadForm, hiddenFields, submit, GOOD, TEAM, FAIL, FORM, testApi, BASE } from "./lib.mjs";

// The URL of the run's WordPress, escaped for a regular expression (with EW_PORT it is not 9404).
const SITE = BASE.replace(/[.*+?^${}()|[\]\\/]/g, "\\$&");
const html = async (url) => (await fetch(url)).text();

// 2.11.0: a dossier form with a File field, seeded and loaded with the contact one (no request more).
// Its decisions (types by bytes, what replaces what, which notifications, when a link opens, what is
// missing) are level 1 in pure.test.mjs; here only what needs WordPress: PHP receiving a multipart
// upload, the file outside the web root, the link's page and headers, the owner's download, the row.
const DOSSIER = `<!-- wp:estrategia-web-forms/form {"formId":"ayuda","title":"Ayuda","dossier":true} -->
<!-- wp:estrategia-web-forms/text {"label":"Empresa","name":"empresa","required":true,"requiredToSave":true} /-->
<!-- wp:estrategia-web-forms/email {"label":"Correo","name":"email","required":true,"requiredToSave":true} /-->
<!-- wp:estrategia-web-forms/text {"label":"IAE","name":"iae","required":true,"placeholder":"501.3","help":"Si no lo sabes, sube el modelo 036."} /-->
<!-- wp:estrategia-web-forms/file {"label":"Escrituras","name":"escrituras","required":true,"accept":"pdf","maxMb":1} /-->
<!-- wp:estrategia-web-forms/notification {"label":"Equipo","to":"${TEAM}","subject":"Expediente {{empresa}}","when":"always"} /-->
<!-- wp:estrategia-web-forms/notification {"label":"Enlace","to":"{{email}}","subject":"Tu solicitud","body":"Tu enlace: {{dossier_url}}"} /-->
<!-- /wp:estrategia-web-forms/form -->`;
const OWNER = "nicolae@ejemplo.test";
const PDF = (tag) => new Blob([`%PDF-1.4\n% ${tag}\n%%EOF\n`], { type: "application/pdf" });
const unescape = (s) => s.replace(/&#038;|&amp;/g, "&");
const tokenIn = (text) => text.match(/af_dossier=([A-Za-z0-9_-]{43})/)?.[1] ?? null;
/** As the form's JavaScript sends a form with files: multipart. */
async function sendFiles(hiddenOf, fields, files = {}) {
  const body = new FormData();
  for (const [k, v] of Object.entries(hiddenOf)) body.append(k, v);
  for (const [k, v] of Object.entries(fields)) body.append(`fields[${k}]`, v);
  for (const [k, [name, blob]] of Object.entries(files)) body.append(`files[${k}][]`, blob, name);
  body.append("_af_js", "1");
  const res = await fetch(BASE + "/wp-json/estrategia-web-forms/v1/submit", { method: "POST", body });
  return { status: res.status, json: await res.json() };
}

let page, form, hidden, good, sent, dossierPage, dossierHidden, dossier;
before(async () => {
  // min_seconds 30: the too-early case is rejected on purpose; then it goes back to 1.
  ({ pages: { contact: page, ayuda: dossierPage } } = await seed({
    settings: { min_seconds: 30, retention_days: 30 },
    pages: [
      { key: "contact", slug: "contacto", title: "Contacto", content: FORM },
      { key: "ayuda", slug: "ayuda", title: "Ayuda", content: DOSSIER },
    ],
  }));
  [{ html: form, hidden }, { hidden: dossierHidden }] = await Promise.all([loadForm(page.url), loadForm(dossierPage.url)]);
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
  assert.match(team.html, /<th[^>]*>Teléfono<\/th><td[^>]*>\+34 600 000 000<\/td>/, "{{all_fields}} as an HTML table");
  assert.match(team.text, /Mensaje: \nHola,\nquería información\./, "and as lines in the text version");
  assert.deepEqual(ack.to, ["ana@ejemplo.test"], "the acknowledgement goes to the email the visitor typed");
  assert.match(ack.text, /^Hola Ana, gracias por escribir\.\nServicio: Planchado/);
  assert.match(ack.text, new RegExp(`Desde ${SITE}/contacto/ el \\S.* en Sitio de pruebas:\\nNombre: Ana\\n`), "fixed markers with spaces and capitals inside the braces");
  assert.match(ack.html, /<th[^>]*>Teléfono<\/th>/, "{{all_fields}} is the HTML table");
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

test("dossier (2.11.0): a half-way save with a file — refused by its bytes, stored outside the web, the link only in the sender's email", async () => {
  const half = { empresa: "Stanila Reformas", email: OWNER };
  const [fake, saved] = await Promise.all([
    sendFiles(dossierHidden, { ...half, empresa: "fake" }, { escrituras: ["escrituras.pdf", new Blob(["<!doctype html><script>alert(1)</script>"])] }),
    sendFiles(dossierHidden, half, { escrituras: ["Escrituras Stanila.pdf", PDF("A1")] }),
  ]);
  assert.equal(fake.status, 422, "an HTML page called .pdf");
  assert.ok(fake.json.errors.escrituras);
  assert.equal(saved.status, 200, JSON.stringify(saved.json));
  const token = tokenIn(unescape(saved.json.redirect || ""));
  assert.ok(token, "the answer sends the page to the dossier's own link");

  const s = await testApi("/state");
  const rows = s.submissions.filter((r) => r.form_id === "ayuda");
  assert.equal(rows.length, 1, "the refused one left nothing");
  const row = rows[0];
  assert.equal(row.complete, 0);
  assert.deepEqual(row.missing, ["iae"], "IAE missing; the file is there");
  assert.ok(!JSON.stringify(row).includes(token), "only the token's sha256 is stored");
  const file = row.data.find((d) => d.name === "escrituras").files[0];
  const where = await testApi("/dossier", { method: "POST", body: { paths: [file.path] } });
  assert.equal(where.public, false, "outside the web root: " + where.dir);
  assert.equal(where.exists[file.path], true);
  const toOwner = s.mails.filter((m) => m.to.includes(OWNER));
  assert.equal(toOwner.length, 1);
  assert.ok(toOwner[0].text.includes("af_dossier=" + token), "{{dossier_url}} in the acknowledgement");
  assert.ok(!s.mails.some((m) => m.to.includes(TEAM) && m.text.includes(token)), "and not in the team's email");
  dossier = { token, row, file };
});

test("dossier (2.11.0): the link opens it filled in and privately; the owner downloads, a wrong token does not; completing it keeps the row and deletes the replaced file", async () => {
  const url = dossierPage.url + (dossierPage.url.includes("?") ? "&" : "?") + "af_dossier=" + dossier.token;
  const res = await fetch(url);
  const opened = await res.text();
  assert.match(res.headers.get("cache-control") || "", /no-store/);
  assert.equal(res.headers.get("referrer-policy"), "no-referrer");
  assert.match(res.headers.get("x-robots-tag") || "", /noindex/);
  assert.match(opened, /value="Stanila Reformas"/);
  assert.match(opened, /Still missing: IAE/);
  // The help line (2.11.0): under the field, read with it, and the placeholder kept for the example.
  // Each attribute on its own: WordPress 7.2 sorts them (aria-describedby now comes before name).
  const iae = opened.match(/<input[^>]*name="fields\[iae\]"[^>]*>/)?.[0] || "";
  for (const a of ['aria-describedby="af-ayuda-iae-help af-ayuda-iae-error"', 'placeholder="501.3"']) assert.ok(iae.includes(a), `the iae field lacks ${a}: ${iae}`);
  assert.match(opened, /<p id="af-ayuda-iae-help" class="estrategia-web-forms__help">Si no lo sabes, sube el modelo 036\.<\/p>/);
  assert.match(opened, /<p id="af-ayuda-escrituras-help" class="estrategia-web-forms__help">PDF\. Up to 1 MB per file\.<\/p>/, "a File field without help says what it accepts");
  assert.doesNotMatch(opened, /name="fields\[empresa\]"[^>]*-help/, "no help, no aria-describedby pointing to a line that does not exist");
  const link = unescape(opened.match(/href="([^"]*\/file\?[^"]*)"/)?.[1] ?? "");
  const [own, wrong] = await Promise.all([fetch(link), fetch(link.replace(dossier.token, "x".repeat(43)))]);
  assert.equal(own.status, 200);
  assert.match(own.headers.get("content-disposition") || "", /attachment/);
  assert.match(await own.text(), /^%PDF-1\.4\n% A1/);
  assert.equal(wrong.status, 404);

  const done = await sendFiles(hiddenFields(opened), { empresa: "Stanila Reformas", email: OWNER, iae: "501.3" }, { escrituras: ["firmadas.pdf", PDF("A2")] });
  assert.equal(done.status, 200, JSON.stringify(done.json));
  const row = (await testApi("/state")).submissions.find((r) => r.form_id === "ayuda");
  assert.equal(row.id, dossier.row.id, "the same row");
  assert.equal(row.complete, 1);
  const now = row.data.find((d) => d.name === "escrituras").files[0];
  const [where] = await Promise.all([
    testApi("/dossier", { method: "POST", body: { paths: [dossier.file.path, now.path] } }),
    // For the purge below: sent 31 days ago, but changed today.
    testApi("/backdate", { method: "POST", body: { id: row.id, days: 31 } }),
  ]);
  assert.deepEqual(where.exists, { [dossier.file.path]: false, [now.path]: true });
});

test("dossier (2.11.0): «send me the link» writes only to the stored address, answers the same either way, and the old link stops working", async () => {
  const ask = (email) => fetch(BASE + "/wp-json/estrategia-web-forms/v1/dossier-link", { method: "POST", body: new URLSearchParams({ ...dossierHidden, af_dossier_email: email }), redirect: "manual" });
  const before = (await testApi("/mails")).length;
  const [known, unknown] = await Promise.all([ask(OWNER.toUpperCase()), ask("nadie@ejemplo.test")]);
  const back = (r) => unescape(r.headers.get("location") || "").replace(/af_result=[a-z0-9]+/, "");
  assert.equal(known.status, 303);
  assert.equal(back(known), back(unknown), "it does not reveal whether there is a dossier");
  const mails = (await testApi("/mails")).slice(before);
  assert.deepEqual(mails.map((m) => m.to[0]), [OWNER]);
  const fresh = tokenIn(unescape(mails[0].html));
  assert.ok(fresh && fresh !== dossier.token);
  const old = await html(dossierPage.url + (dossierPage.url.includes("?") ? "&" : "?") + "af_dossier=" + dossier.token);
  assert.match(old, /expired or is no longer valid/);
});

test("scheduled purge: deletes what is older than the retention (30 days) and leaves the rest", async () => {
  // Three rows now: the good one, the no-JS one and the dossier (sent 31 days ago, changed today: it
  // counts from its last change). The good one, 31 days old. At once, the next test's rate limit (it
  // only counts the submissions after it).
  const [r] = await Promise.all([testApi("/purge", { method: "POST", body: { backdate: { id: good.id, days: 31 } } }), settings({ rate_limit: 1, rate_window: 10 })]);
  assert.equal(r.scheduled, true, "the purge is scheduled in WP-Cron");
  assert.equal(r.last_purge.deleted, 1);
  assert.equal(r.left.length, 2);
  assert.ok(!r.left.includes(Number(good.id)));
  assert.ok(r.left.includes(dossier.row.id), "the dossier stays: 31 days since it was sent, 0 since its last change");
});

test("per-IP limit: the submission after the limit in the window is rejected", async () => {
  assert.equal((await submit(hidden, GOOD)).status, 200);
  const over = await submit(hidden, GOOD);
  assert.equal(over.status, 429);
  assert.equal(over.json.code, "rate_limit");
});
