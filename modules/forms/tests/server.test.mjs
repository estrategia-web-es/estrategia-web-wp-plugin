// NIVEL: 2 — WordPress de Playground, porque ESCRIBE: envíos en la tabla, correos interceptados, transitorios del límite.
//
// Todo lo de aquí se hace SIN navegador, fabricando la petición: es justo lo
// que haría un robot o alguien que se salta la validación del navegador.
import { test, before } from "node:test";
import assert from "node:assert/strict";
import { reset, settings, mails, submissions, seedPage, loadForm, submit, GOOD, TEAM, FAIL, waitMinTime, testApi } from "./lib.mjs";

let page;
before(async () => {
  await reset();
  page = await seedPage();
});

test("el HTML público no contiene destinatarios ni plantillas de los avisos", async () => {
  const { html, hidden } = await loadForm(page.url);
  assert.match(html, /name="fields\[nombre\]"/, "el formulario se pinta");
  assert.ok(hidden._af_def && hidden._af_token, "lleva definición y token");
  for (const secret of [TEAM, FAIL, "Nuevo mensaje de", "Hemos recibido tu mensaje", "Aviso al equipo", "{{"]) {
    assert.ok(!html.includes(secret), `el HTML público contiene «${secret}»`);
  }
});

test("un obligatorio vacío falla en el servidor aunque nadie lo valide antes", async () => {
  await reset();
  const { hidden } = await loadForm(page.url);
  await waitMinTime();
  const r = await submit(hidden, { ...GOOD, mensaje: "", privacidad: "" });
  assert.equal(r.status, 422);
  assert.equal(r.json.ok, false);
  assert.ok(r.json.errors.mensaje, "error en mensaje");
  assert.ok(r.json.errors.privacidad, "el consentimiento es obligatorio aunque el bloque no diga required");
  assert.equal((await submissions()).length, 0, "no se guarda nada");
  assert.equal((await mails()).length, 0, "no sale ningún aviso");
});

test("correo mal formado y opción que no está en la lista: error de servidor", async () => {
  const { hidden } = await loadForm(page.url);
  await waitMinTime();
  const r = await submit(hidden, { ...GOOD, email: "no-es-correo", servicio: "Tintorería" });
  assert.equal(r.status, 422);
  assert.ok(r.json.errors.email);
  assert.ok(r.json.errors.servicio);
});

test("honeypot relleno: contesta éxito y no guarda ni avisa", async () => {
  await reset();
  const { hidden } = await loadForm(page.url);
  await waitMinTime();
  const r = await submit(hidden, GOOD, { extra: { af_website: "http://spam.example" } });
  assert.equal(r.status, 200);
  assert.equal(r.json.ok, true, "al robot no se le dice que ha fallado");
  assert.equal((await submissions()).length, 0);
  assert.equal((await mails()).length, 0);
});

test("tiempo mínimo: enviar antes de tiempo se rechaza", async () => {
  await reset();
  await settings({ min_seconds: 30 });
  const { hidden } = await loadForm(page.url);
  const r = await submit(hidden, GOOD);
  assert.equal(r.status, 403);
  assert.equal(r.json.code, "token_too_fast");
  assert.equal((await submissions()).length, 0);
  await settings({ min_seconds: 1 });
});

test("token manipulado o de otra definición: rechazado", async () => {
  await reset();
  const { hidden } = await loadForm(page.url);
  await waitMinTime();
  const forged = { ...hidden, _af_token: String(Math.floor(Date.now() / 1000) - 60) + "." + "a".repeat(40) };
  assert.equal((await submit(forged, GOOD)).json.code, "token_invalid");
  const noDef = { ...hidden, _af_def: "0".repeat(32) };
  assert.equal((await submit(noDef, GOOD)).json.code, "unknown_form");
  assert.equal((await submissions()).length, 0);
});

test("límite por IP: el tercer envío en la ventana se rechaza", async () => {
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

test("envío bueno: se registra con todo y salen los N avisos con los marcadores sustituidos", async () => {
  await reset();
  const { hidden } = await loadForm(page.url);
  await waitMinTime();
  const r = await submit(hidden, { ...GOOD, campo_inventado: "x" });
  assert.equal(r.status, 200);
  assert.equal(r.json.ok, true);

  const [row] = await submissions();
  assert.ok(row, "hay un envío en el registro");
  assert.equal(row.form_id, "contacto");
  assert.equal(row.form_title, "Contacto");
  assert.equal(row.page_url, page.url);
  assert.equal(Number(row.post_id), page.id);
  assert.ok(row.lang, "el idioma queda registrado");
  assert.equal(row.ip, "127.0.0.0", "IP truncada, nunca entera");
  assert.equal(row.user_agent, "estrategia-web-forms-test/1.0");
  assert.equal(row.status, "partial", "dos avisos bien y uno mal");
  assert.deepEqual(row.data.map((d) => d.name), ["nombre", "email", "telefono", "servicio", "mensaje", "privacidad"], "solo los campos de la definición");
  assert.deepEqual(
    row.notifications.map((n) => [n.label, n.to, n.status]),
    [
      ["Aviso al equipo", TEAM, "sent"],
      ["Acuse al remitente", "ana@ejemplo.test", "sent"],
      ["Aviso que falla", FAIL, "failed"],
    ]
  );
  assert.ok(row.notifications[2].error, "el fallo guarda su motivo");

  const sent = await mails();
  assert.equal(sent.length, 3, "se intentan los tres avisos");
  const [team, ack] = sent;
  assert.deepEqual(team.to, [TEAM]);
  assert.equal(team.subject, "Nuevo mensaje de Ana");
  assert.ok(team.headers.includes("Reply-To: ana@ejemplo.test"), "responder-a sale del campo email");
  assert.match(team.html, /Desde http:\/\/127\.0\.0\.1:9404\/contacto\//);
  assert.match(team.html, /Sitio de pruebas/);
  assert.match(team.html, /<th[^>]*>Teléfono<\/th><td[^>]*>\+34 600 000 000<\/td>/, "{{todos_los_campos}} como tabla en HTML");
  assert.match(team.text, /Mensaje: \nHola,\nquería información\./, "y como líneas en la versión texto");
  assert.deepEqual(ack.to, ["ana@ejemplo.test"], "el acuse va al correo que escribió el visitante");
  assert.match(ack.text, /^Hola Ana, gracias por escribir\.\nServicio: Planchado/);
  for (const m of sent) {
    assert.ok(!m.html.includes("{{") && !m.text.includes("{{") && !m.subject.includes("{{"), "ningún marcador sin sustituir");
  }
});

test("sin JS: redirección 303 a la página con el resultado", async () => {
  await reset();
  const { hidden } = await loadForm(page.url);
  await waitMinTime();
  const r = await submit(hidden, { ...GOOD, mensaje: "" }, { js: false });
  assert.equal(r.status, 303);
  assert.match(r.location, /^http:\/\/127\.0\.0\.1:9404\/contacto\/\?af_result=[a-z0-9]{20}#af-contacto-status$/);
  const html = await (await fetch(r.location)).text();
  assert.match(html, /aria-invalid="true"/, "el campo con error se marca");
  assert.match(html, /value="Ana"/, "lo escrito se conserva");
  assert.equal((await submissions()).length, 0);
});

test("purga programada: borra lo más viejo que la retención y deja lo demás", async () => {
  await reset();
  await settings({ retention_days: 30 });
  const { hidden } = await loadForm(page.url);
  await waitMinTime();
  await submit(hidden, GOOD);
  await submit(hidden, GOOD);
  const [a] = await submissions();
  await testApi("/backdate", { method: "POST", body: { id: a.id, days: 31 } });
  const r = await testApi("/purge", { method: "POST" });
  assert.equal(r.scheduled, true, "la purga está programada en WP-Cron");
  assert.equal(r.last_purge.deleted, 1);
  const left = await submissions();
  assert.equal(left.length, 1);
  assert.notEqual(left[0].id, a.id);
});
