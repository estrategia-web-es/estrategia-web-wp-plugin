// NIVEL: 2 — Playground WordPress. NOT LEVEL 1 BECAUSE: what is tested is the dossier mode and the File field as WordPress serves them — a multipart upload received by PHP, the file written outside the web root, the private link that opens the form filled in (with its no-cache, no-index, no-referrer headers), the owner's and the team's downloads, the «send me my link» and «ask the client» emails, and the purge taking the files with it. The pure rules (file types by their bytes, who receives the link, what is missing) are level 1 in pure.test.mjs.
//
// ONE seed and ONE form load for the whole file, and the cases in the file's
// order, each reading what the one before left: the first save creates the
// dossier, the link opens it, the second save completes it, «send me my link»
// replaces its link, the team asks for something (another link) and revokes
// it, and the purge goes last. Two dossiers: A (the one followed) and B (only
// created, for the purge).
//
// Seen red (20-11-2026), with these mutants one at a time: Validator ignoring
// the dossier mode (every «Required» blocks the half-way save: the first test,
// and the rest after it); Dossier::private_page_headers() returning at once
// (the link's page without no-store: the second test); the replaced file not
// deleted (the third test). And before the mount of /estrategia-web-forms-private
// in tests/run.mjs, the files written by one Playground worker were missing in
// the other: three tests red for a reason that does not exist on a real server.
import { test, before } from "node:test";
import assert from "node:assert/strict";
import { adminSession } from "../../../tests/lib.mjs";
import { seed, loadForm, hiddenFields, testApi, waitMinTime, TEAM, BASE } from "./lib.mjs";

const FORM = `<!-- wp:estrategia-web-forms/form {"formId":"ayuda","title":"Ayuda digitalización","dossier":true} -->
<!-- wp:estrategia-web-forms/text {"label":"Empresa","name":"empresa","required":true,"requiredToSave":true} /-->
<!-- wp:estrategia-web-forms/email {"label":"Correo","name":"email","required":true,"requiredToSave":true} /-->
<!-- wp:estrategia-web-forms/text {"label":"IAE","name":"iae","required":true} /-->
<!-- wp:estrategia-web-forms/file {"label":"Escrituras","name":"escrituras","required":true,"accept":"pdf","maxMb":1} /-->
<!-- wp:estrategia-web-forms/checkbox {"name":"privacidad","consent":true} /-->
<!-- wp:estrategia-web-forms/submit {"text":"Guardar"} /-->
<!-- wp:estrategia-web-forms/notification {"label":"Aviso al equipo","to":"${TEAM}","subject":"Expediente {{empresa}}","body":"{{all_fields}}\\nFalta: {{missing_fields}}","when":"always"} /-->
<!-- wp:estrategia-web-forms/notification {"label":"Enlace al cliente","to":"{{email}}","subject":"Tu solicitud","body":"Tu enlace: {{dossier_url}}\\nFalta: {{missing_fields}}"} /-->
<!-- /wp:estrategia-web-forms/form -->`;

const OWNER = "nicolae@ejemplo.test";
const PDF = (tag) => new Blob([`%PDF-1.4\n% ${tag}\n%%EOF\n`], { type: "application/pdf" });
const decode = (s) => s.replace(/&#038;|&amp;/g, "&");
const tokenIn = (text) => text.match(/af_dossier=([A-Za-z0-9_-]{43})/)?.[1] ?? null;

/** A submission as the form's JavaScript sends it: multipart, with the files. */
async function send(hidden, fields, files = {}, extra = {}) {
  const body = new FormData();
  for (const [k, v] of Object.entries(hidden)) body.append(k, v);
  for (const [k, v] of Object.entries(fields)) body.append(`fields[${k}]`, v);
  for (const [k, [name, blob]] of Object.entries(files)) body.append(`files[${k}][]`, blob, name);
  for (const [k, v] of Object.entries(extra)) body.append(k, v);
  body.append("_af_js", "1");
  const res = await fetch(BASE + "/wp-json/estrategia-web-forms/v1/submit", { method: "POST", body, headers: { "X-EW-Forms-Test-State": "1" } });
  const json = await res.json();
  const state = json._test_state;
  delete json._test_state;
  return { status: res.status, json, state };
}

const state = () => testApi("/state");
/** The page's URL ready for a token (with or without pretty permalinks). */
const prefix = () => page.url + (page.url.includes("?") ? "&" : "?") + "af_dossier=";
const dossierInfo = (body) => testApi("/dossier", { method: "POST", body });

let page, hidden, rejected, first, rowA, tokenA;
before(async () => {
  ({ pages: { ayuda: page } } = await seed({ settings: { retention_days: 365 }, pages: [{ key: "ayuda", slug: "ayuda", title: "Ayuda", content: FORM }] }));
  ({ hidden } = await loadForm(page.url));
  await waitMinTime();
  const half = { empresa: "Stanila Reformas", email: OWNER, privacidad: "1" };
  const [noEmail, fake, good, other] = await Promise.all([
    send(hidden, { ...half, email: "" }),
    send(hidden, { ...half, empresa: "fake" }, { escrituras: ["escrituras.pdf", new Blob(["<!doctype html><script>alert(1)</script>"], { type: "application/pdf" })] }),
    send(hidden, half, { escrituras: ["Escrituras Stanila.pdf", PDF("A1")] }),
    send(hidden, { empresa: "Otra SL", email: "otra@ejemplo.test", privacidad: "1" }, { escrituras: ["b.pdf", PDF("B1")] }),
  ]);
  rejected = { noEmail, fake };
  first = good;
  const s = await state();
  rowA = s.submissions.find((r) => r.owner_email === OWNER);
  tokenA = tokenIn(decode(first.json.redirect || ""));
  first.mails = s.mails; // Read after the four: each one's own state is a moment while the others run.
  first.all = s.submissions;
});

test("saving half-way: only what a save needs is required; the dossier is stored as incomplete, with its file outside the web", async () => {
  assert.equal(rejected.noEmail.status, 422, "without the owner's email there is nowhere to send the link");
  assert.ok(rejected.noEmail.json.errors.email);
  assert.equal(rejected.fake.status, 422, "an HTML page called .pdf is refused by its bytes");
  assert.match(rejected.fake.json.errors.escrituras, /not accepted/);

  assert.equal(first.status, 200, JSON.stringify(first.json));
  assert.ok(tokenA, "the answer sends the page to the dossier's own link: " + first.json.redirect);
  assert.match(first.json.message, /^Saved\./, "short: the page it lands on lists what is missing (next test)");
  assert.doesNotMatch(first.json.message, /IAE/, "not twice: the notice above the fields already says it");
  assert.equal(first.all.length, 2, "A and B; none of the rejected ones");
  assert.equal(rowA.complete, 0);
  assert.deepEqual(rowA.missing, ["iae"]);
  assert.equal(rowA.access_hash.length, 64, "only the sha256 of the token is stored");
  assert.ok(!JSON.stringify(rowA).includes(tokenA), "the token itself is nowhere in the row");

  const file = rowA.data.find((d) => d.name === "escrituras").files[0];
  assert.equal(file.name, "Escrituras Stanila.pdf");
  const where = await dossierInfo({ paths: [file.path] });
  assert.equal(where.public, false, "the folder is not under the web root: " + where.dir);
  assert.ok(!where.dir.startsWith(where.abspath), where.dir);
  assert.equal(where.exists[file.path], true);

  const ack = first.mails.find((m) => m.to.includes(OWNER));
  assert.ok(ack, "the acknowledgement goes to the owner");
  assert.ok(ack.text.includes("af_dossier=" + tokenA), "with the link in {{dossier_url}}");
  assert.match(ack.text, /Falta: IAE/);
  const team = first.mails.filter((m) => m.to.includes(TEAM));
  assert.equal(team.length, 2, "the team is told of both dossiers");
  assert.ok(!team.some((m) => m.text.includes(tokenA)), "the team's email does not carry the client's link");
});

let opened;
test("the link opens the form filled in, privately; the owner downloads their file and nobody else does", async () => {
  const url = prefix() + tokenA;
  const res = await fetch(url);
  opened = await res.text();
  assert.match(res.headers.get("cache-control") || "", /no-store/);
  assert.equal(res.headers.get("referrer-policy"), "no-referrer");
  assert.match(res.headers.get("x-robots-tag") || "", /noindex/);
  assert.match(opened, /value="Stanila Reformas"/);
  assert.match(opened, /name="_af_dossier" value="[A-Za-z0-9_-]{43}"/);
  assert.match(opened, /Still missing: IAE/);
  assert.match(opened, /enctype="multipart\/form-data"/);
  assert.doesNotMatch(opened, /name="files\[escrituras\]\[\]"[^>]*required/, "a file already sent is not asked for again");
  assert.doesNotMatch(opened, /name="fields\[iae\]"[^>]*required/, "the browser does not insist on what can wait");

  const link = decode(opened.match(/href="([^"]*\/file\?[^"]*)"/)?.[1] ?? "");
  assert.ok(link.includes("d=" + tokenA), link);
  const got = await fetch(link);
  assert.equal(got.status, 200);
  assert.match(got.headers.get("content-disposition") || "", /attachment/);
  assert.match(await got.text(), /^%PDF-1\.4\n% A1/);
  const wrong = await fetch(link.replace(tokenA, "x".repeat(43)));
  assert.equal(wrong.status, 404);
});

let second;
test("completing it with the link: the same row, complete, a new file replacing the old one, and only the «always» notification", async () => {
  const mailsBefore = (await state()).mails.length;
  const h = hiddenFields(opened);
  await waitMinTime();
  second = await send(h, { empresa: "Stanila Reformas", email: OWNER, iae: "501.3", privacidad: "1" }, { escrituras: ["escrituras-firmadas.pdf", PDF("A2")] });
  assert.equal(second.status, 200, JSON.stringify(second.json));
  const row = second.state.submissions.find((r) => r.id === rowA.id);
  assert.equal(second.state.submissions.length, 2, "no new row");
  assert.equal(row.complete, 1);
  assert.deepEqual(row.missing, []);
  assert.deepEqual(row.history.map((e) => e.what), ["created", "updated"]);
  assert.deepEqual(row.history[1].fields, ["iae", "escrituras"]);
  const oldPath = rowA.data.find((d) => d.name === "escrituras").files[0].path;
  const newPath = row.data.find((d) => d.name === "escrituras").files[0].path;
  const where = await dossierInfo({ paths: [oldPath, newPath] });
  assert.deepEqual(where.exists, { [oldPath]: false, [newPath]: true }, "one file field: the new one replaces the old one, which is deleted");
  const mails = second.state.mails.slice(mailsBefore);
  assert.deepEqual(mails.map((m) => m.to[0]), [TEAM], "the team's notification is «always»; the client's link is only on creation");
  rowA = row;
});

let tokenB;
test("«send me my link»: a new link to the stored email only, the same answer either way, and the old link stops working", async () => {
  const mailsBefore = (await state()).mails.length;
  const ask = (email) => {
    const body = new URLSearchParams({ ...hidden, af_dossier_email: email });
    return fetch(BASE + "/wp-json/estrategia-web-forms/v1/dossier-link", { method: "POST", body, redirect: "manual" });
  };
  const [known, unknown] = await Promise.all([ask(OWNER.toUpperCase()), ask("nadie@ejemplo.test")]);
  assert.equal(known.status, 303);
  assert.equal(unknown.status, 303);
  const back = (r) => decode(r.headers.get("location")).replace(/af_result=[a-z0-9]+/, "");
  assert.equal(back(known), back(unknown), "the answer does not reveal whether there is a dossier");
  const mails = (await state()).mails.slice(mailsBefore);
  assert.equal(mails.length, 1);
  assert.deepEqual(mails[0].to, [OWNER]);
  tokenB = tokenIn(decode(mails[0].html));
  assert.ok(tokenB && tokenB !== tokenA, "a new token");
  const old = await (await fetch(prefix() +tokenA)).text();
  assert.match(old, /expired or is no longer valid/);
  assert.match(old, /<details class="estrategia-web-forms__link" open>/, "and the «send me my link» box comes open");
});

test("the team: the dossier in the submission, its file, «ask the client» (note + new link) and «revoke link»", async () => {
  const s = await adminSession();
  const detail = (await s.get(`/wp-admin/admin.php?page=estrategia-web-forms&action=view&id=${rowA.id}`)).text;
  assert.match(detail, /<h2>Dossier<\/h2>/);
  assert.match(detail, /Complete/);
  const adminFile = decode(detail.match(/href="([^"]*action=estrategia_web_forms_file[^"]*)"/)?.[1] ?? "");
  assert.ok(adminFile, "the file is linked through admin-post");
  const got = await s.get(adminFile);
  assert.match(got.text, /^%PDF-1\.4\n% A2/);
  const noNonce = await s.get(adminFile.replace(/_wpnonce=[a-f0-9]+/, "_wpnonce=0"));
  assert.notEqual(noNonce.status, 200);
  const anonymous = await fetch(adminFile, { redirect: "manual" });
  assert.doesNotMatch(await anonymous.text(), /^%PDF/, "without a session there is no file");

  const mailsBefore = (await state()).mails.length;
  const asked = await s.submit(detail, "admin-post.php", { note: "Falta el poder del administrador." });
  assert.equal(asked.status, 302);
  assert.match(asked.location, /dossier=asked/);
  const mail = (await state()).mails.slice(mailsBefore)[0];
  assert.deepEqual(mail.to, [OWNER]);
  assert.match(mail.html, /Falta el poder del administrador\./);
  const tokenC = tokenIn(decode(mail.html));
  assert.ok(tokenC && tokenC !== tokenB);
  assert.match(await (await fetch(prefix() +tokenB)).text(), /expired or is no longer valid/, "asking issues a new link: there is only ever one");
  assert.match(await (await fetch(prefix() +tokenC)).text(), /value="Stanila Reformas"/);

  const after = (await s.get(`/wp-admin/admin.php?page=estrategia-web-forms&action=view&id=${rowA.id}`)).text;
  const revoke = after.match(/<form[^>]*>(?:(?!<\/form>)[\s\S])*estrategia_web_forms_revoke[\s\S]*?<\/form>/)?.[0] ?? "";
  const nonce = revoke.match(/name="_wpnonce" value="([a-f0-9]+)"/)?.[1];
  assert.ok(nonce, "there is a «Revoke link» form");
  const res = await fetch(BASE + "/wp-admin/admin-post.php", {
    method: "POST",
    redirect: "manual",
    headers: { Cookie: s.cookie(), "Content-Type": "application/x-www-form-urlencoded" },
    body: new URLSearchParams({ action: "estrategia_web_forms_revoke", id: String(rowA.id), _wpnonce: nonce }).toString(),
  });
  assert.equal(res.status, 302);
  assert.match(await (await fetch(prefix() +tokenC)).text(), /expired or is no longer valid/);
  const refused = await send({ ...hiddenFields(opened), _af_dossier: tokenC }, { empresa: "x", email: OWNER, privacidad: "1" });
  assert.equal(refused.status, 403);
  assert.equal(refused.json.code, "dossier_invalid");
});

test("an expired link opens nothing and downloads nothing", async () => {
  const s = await state();
  const rowB = s.submissions.find((r) => r.owner_email === "otra@ejemplo.test");
  const mails = s.mails.filter((m) => m.to.includes("otra@ejemplo.test"));
  const token = tokenIn(mails[0].text);
  const file = rowB.data.find((d) => d.name === "escrituras").files[0];
  assert.equal((await fetch(`${BASE}/wp-json/estrategia-web-forms/v1/file?d=${token}&f=${file.id}`)).status, 200, "before expiring it works");
  await dossierInfo({ expire: rowB.id });
  assert.equal((await fetch(`${BASE}/wp-json/estrategia-web-forms/v1/file?d=${token}&f=${file.id}`)).status, 404);
  assert.match(await (await fetch(prefix() +token)).text(), /expired or is no longer valid/);
});

test("the purge counts a dossier from its last change, and takes its files with it", async () => {
  const s = await state();
  const rowB = s.submissions.find((r) => r.owner_email === "otra@ejemplo.test");
  const fileB = rowB.data.find((d) => d.name === "escrituras").files[0].path;
  const fileA = rowA.data.find((d) => d.name === "escrituras").files[0].path;
  await testApi("/backdate", { method: "POST", body: { id: rowA.id, days: 400 } });
  const purge = await testApi("/purge", { method: "POST", body: { backdate: { id: rowB.id, days: 400 } } });
  assert.deepEqual(purge.left, [rowA.id], "A was sent 400 days ago but changed today; B only sent");
  const where = await dossierInfo({ paths: [fileA, fileB] });
  assert.deepEqual(where.exists, { [fileA]: true, [fileB]: false });
});
