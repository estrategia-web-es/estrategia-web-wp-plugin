// NIVEL: 1 — no browser or emulator. The pure classes (markers, IP, token, preselection) with the machine's PHP, and one rule of the base CSS.
//
// If there is no `php` in the PATH, the test FAILS (it is not skipped): a test
// that is silently skipped looks like it covers something. On this machine it is in
// C:\wamp64\bin\php\php8.3.6.
import { test } from "node:test";
import assert from "node:assert/strict";
import { spawnSync } from "node:child_process";
import path from "node:path";
import fs from "node:fs";

const r = spawnSync("php", [path.join(import.meta.dirname, "php", "pure.php")], { encoding: "utf8" });
assert.equal(r.status, 0, "php did not run: " + (r.error?.message || r.stderr));
const out = JSON.parse(r.stdout);

test("markers: known, with spaces and uppercase, empty and unknown", () => {
  assert.equal(out.known, "Hola Ana <b>");
  assert.equal(out.spaces_case, "Hola Ana <b>");
  assert.equal(out.empty_known, "Tel: .", "an empty optional field is replaced by nothing");
  assert.equal(out.unknown_kept, "Mal: {{emial}}", "a typo in the template has to be visible in the email");
});

test("markers: escaped in HTML except those declared as already-built HTML", () => {
  assert.equal(out.escaped, "Ana &lt;b&gt;");
  assert.equal(out.raw_not_escaped, "<table>");
});

test("fixed markers: the English names; the old Spanish ones are no longer aliases (2.0.1)", () => {
  assert.equal(out.fixed_current, "Nombre: Ana|15/10/2026 10:00|https://example.test/contacto/|Lavandería");
  // Unknown markers stay verbatim, and {{fecha}} is now the FIELD «fecha».
  assert.equal(out.fixed_old, "{{todos_los_campos}}|el 3 de mayo|{{url_pagina}}|{{ Nombre_Sitio }}");
});

// 2.0.1: Marker_Migration's pure part. Seen red (28-10-2026) with a mutant
// that gave every notification the FIRST form's identifier («presupuesto»'s
// Copia came out as «contacto»).
test("markers migration: the four old names, with spaces and capitals; nothing else", () => {
  assert.equal(out.mig_text, "{{all_fields}}|{{submission_date}}|{{page_url}}|{{site_name}}|{{nombre}}|{{fechas}}");
});

test("markers migration: only inside notification blocks, byte for byte around them, idempotent", () => {
  const page = out.mig_page;
  assert.match(page, /<p>Escribe \{\{fecha\}\} en tu plantilla<\/p>/, "a paragraph that mentions a marker is not a notification");
  assert.match(page, /"name":"fecha"/, "a field named fecha stays");
  assert.match(page, /"subject":"Nuevo \{\{nombre\}\} el \{\{submission_date\}\}","body":"Desde \{\{page_url\}\} en \{\{site_name\}\}:\\n\\n\{\{all_fields\}\}"/);
  assert.match(page, /"label":"Copia","to":"x@ejemplo.test","body":"\{\{submission_date\}\}"/);
  assert.doesNotMatch(page.split("\n").filter((l) => l.includes("/notification")).join("\n"), /todos_los_campos|url_pagina|nombre_sitio|\{\{\s*fecha/i);
  // Before → after per notification, with the form it belongs to; «Ya nuevo» had nothing to change.
  assert.deepEqual(out.mig_changes, [
    { form_id: "contacto", aviso: "Aviso al equipo", cambios: {
      subject: { antes: "Nuevo {{nombre}} el {{ Fecha }}", despues: "Nuevo {{nombre}} el {{submission_date}}" },
      body: { antes: "Desde {{url_pagina}} en {{nombre_sitio}}:\n\n{{todos_los_campos}}", despues: "Desde {{page_url}} en {{site_name}}:\n\n{{all_fields}}" },
    } },
    { form_id: "presupuesto", aviso: "Copia", cambios: { body: { antes: "{{fecha}}", despues: "{{submission_date}}" } } },
  ]);
  assert.deepEqual(out.mig_again, [true, []], "the second pass changes nothing");
});

test("markers migration: a stored snapshot's notifications; an already new one is null", () => {
  assert.equal(out.mig_snapshot.notifications[0].subject, "{{submission_date}}");
  assert.equal(out.mig_snapshot.notifications[0].body, "{{all_fields}}");
  assert.deepEqual(out.mig_snapshot.fields, [{ name: "fecha" }]);
  assert.equal(out.mig_snapshot_new, null);
});

test("subject on a single line: no header injection", () => {
  assert.equal(out.single_line, "Asunto Bcc: x@y.z");
});

test("truncated IP (GDPR)", () => {
  assert.equal(out.ipv4, "203.0.113.0");
  assert.equal(out.ipv6, "2001:db8:85a3::");
  assert.equal(out.ip_bad, "");
});

test("token: good, too fast, expired, from another definition, tampered", () => {
  assert.equal(out.token_ok, "ok");
  assert.equal(out.token_fast, "too_fast");
  assert.equal(out.token_expired, "expired");
  assert.equal(out.token_other_def, "invalid");
  assert.equal(out.token_tampered, "invalid", "changing the token's time breaks the signature");
  assert.equal(out.token_garbage, "invalid");
});

test("preselection: by position, by exact text and by slug", () => {
  assert.equal(out.pre_position, "Lavandería para barcos y yates", "position starts at 1");
  assert.equal(out.pre_position_str, "Lavandería para barcos y yates", "the position also arrives as text (URL)");
  assert.equal(out.pre_text, "Lavandería para restaurantes");
  assert.equal(out.pre_slug, "Lavandería para restaurantes", "the slug avoids %20 and accents in links");
});

test("preselection: what is not among the options is never rendered", () => {
  assert.equal(out.pre_out_of_range, "", "position outside the list");
  assert.equal(out.pre_zero, "", "0 means «none»");
  assert.equal(out.pre_unknown, "", "a text that is not an option does not leak into the select");
  assert.equal(out.pre_empty_slug, "", "an empty slug matches nothing");
  assert.equal(out.pre_negative, "");
});

test("base style: the plugin does not remove the dropdown's native arrow (the custom chevron belongs to the theme)", () => {
  const css = fs.readFileSync(path.join(import.meta.dirname, "..", "assets", "style.css"), "utf8").replace(/\/\*[\s\S]*?\*\//g, "");
  assert.doesNotMatch(css, /appearance\s*:\s*none/, "with appearance:none in the plugin, a theme that paints its background with the «background» shorthand leaves the select as a plain box");
});

// 2.11.0: the File field's pure parts (includes/class-files.php).
test("files: the type comes from the first bytes, never from the name", () => {
  const f = out.files;
  assert.deepEqual([f.sniff_pdf, f.sniff_jpg, f.sniff_png], ["pdf", "jpg", "png"]);
  assert.equal(f.sniff_html, "", "an HTML page called factura.pdf is not a PDF");
  assert.equal(f.sniff_pdf_late, "", "the signature has to be at the very start");
});

test("files: accepted types, the accept attribute and the names shown", () => {
  const f = out.files;
  assert.deepEqual(f.accepted, ["pdf", "jpg", "png"], "case, dots, jpeg=jpg, unknown and repeated ones dropped");
  assert.deepEqual(f.accepted_none, ["pdf", "jpg", "png"], "nothing valid: all the known types, not none");
  assert.equal(f.accept_attr, ".pdf,application/pdf,.jpg,.jpeg,image/jpeg");
  assert.equal(f.name_path, "escrituras 2026.pdf", "no path, no quotes, and the extension of what it really is");
  assert.equal(f.name_empty, "file.pdf");
  assert.equal(f.name_keeps_utf8, "Modelo 036 — Stanila.pdf");
});

test("files: $_FILES as PHP builds it for files[field][], without the empty slots", () => {
  assert.deepEqual(out.files.normalize, {
    docs: [{ name: "a.pdf", tmp: "/tmp/a", size: 10, error: 0 }],
    foto: [{ name: "b.jpg", tmp: "/tmp/b", size: 20, error: 1 }],
  });
});

// Moved down from level 2 (20-11-2026: the server budget is not raised, David).
test("files: what is wrong with an upload, in order — size (also PHP's limit), received, type by its bytes", () => {
  assert.deepEqual(out.files.problem, {
    ok: "",
    too_big: "too_big",
    php_limit: "too_big",
    not_upload: "not_received",
    partial: "not_received",
    html_as_pdf: "type",
    png_not_ok: "type",
  });
});

test("files: after a save — one-file fields replace (the old one goes), several-files add, «Remove» drops", () => {
  const ids = (r) => ({ files: Object.fromEntries(Object.entries(r.files).map(([k, v]) => [k, v.map((x) => x.id)])), dropped: r.dropped.map((x) => x.id) });
  const m = out.files.merge;
  assert.deepEqual(ids(m.replace_one), { files: { e: ["b"] }, dropped: ["a"] });
  assert.deepEqual(ids(m.add_several), { files: { o: ["a", "b"] }, dropped: [] });
  assert.deepEqual(ids(m.remove), { files: { o: ["a"] }, dropped: ["c"] });
  assert.deepEqual(ids(m.untouched), { files: { e: ["a"] }, dropped: [] });
  assert.deepEqual(ids(m.first_time), { files: { e: ["a"] }, dropped: [] });
});

test("dossier: which notifications go out when it is created and when it is changed", () => {
  assert.deepEqual(out.dossier.on_created, ["team", "ack", "old snapshot"], "a notification without «when» is «created»");
  assert.deepEqual(out.dossier.on_updated, ["team", "changes"], "the client's link is not sent again on every save");
});

test("dossier: a link opens only while it has a hash, has not expired, and on its own form", () => {
  assert.deepEqual(out.dossier.open, { valid: true, any_form: true, other_form: false, expired: false, revoked: false, no_expiry: false });
});

// 2.11.0: the dossier's pure parts (includes/class-dossier.php).
test("dossier: who receives the link and what a save cannot go without", () => {
  const d = out.dossier;
  assert.equal(d.owner_marked, "email", "the first email field marked «Required to save», not simply the first");
  assert.equal(d.owner_first, "email_copia", "none marked: the first email field");
  assert.deepEqual(d.needed, ["empresa", "email", "privacidad"], "marked ones, the owner's email and the consent; not IAE nor the files");
});

test("dossier: what is missing, what changed and the labels", () => {
  const d = out.dossier;
  assert.deepEqual(d.missing_before, ["email", "iae", "escrituras", "privacidad"], "required and empty; a file field without files");
  assert.deepEqual(d.missing_after, ["email", "privacidad"]);
  assert.deepEqual(d.changed, ["iae", "escrituras"], "a new file is a change even with the same text");
  assert.deepEqual(d.labels, ["IAE", "Escrituras", "otros"], "a field without a label shows its name");
});

test("dossier: the token is 32 random bytes in base64url and only its sha256 is stored", () => {
  const d = out.dossier;
  assert.equal(d.token_shape, true);
  assert.equal(d.token_unique, true);
  assert.deepEqual(d.token_bad, [false, false, false]);
  assert.equal(d.hash_is_sha256, true);
});

test("preselection: the first valid source wins; an invalid one yields to the next", () => {
  assert.equal(out.pre_first_wins, "Lavandería para hoteles");
  assert.equal(out.pre_falls_through, "Lavandería para barcos y yates", "a bad URL does not erase the page's value");
  assert.equal(out.pre_none, "");
});
