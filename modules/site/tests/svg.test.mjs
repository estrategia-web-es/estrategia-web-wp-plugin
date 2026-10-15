// NIVEL: 2 — Playground WordPress, because it WRITES: uploads files to the media library through the REST API as administrator and as author.
import { test, before } from "node:test";
import assert from "node:assert/strict";
import { reset, saveCompany, upload, wp, png } from "../../../tests/lib.mjs";

const HOSTILE = `<?xml version="1.0"?>
<svg xmlns="http://www.w3.org/2000/svg" width="120" height="60" viewBox="0 0 120 60" onload="alert('onload')">
  <script>alert('script')</script>
  <foreignObject><div xmlns="http://www.w3.org/1999/xhtml">x</div></foreignObject>
  <rect width="120" height="60" fill="#c00"/>
</svg>`;

// Core turns ANY REST upload error into a 500
// (rest_upload_unknown_error), including the «no» from our prefilter. What
// proves the «no» is ours and not just any failure is the message.
function assertDenied(r) {
  assert.notEqual(r.status, 201, `an SVG that should not have been uploaded was: ${r.text}`);
  assert.match(r.json?.message || "", /No tienes permiso para subir ficheros SVG/, r.text);
}

// Uploaded files are named «prueba-…» on purpose: reset() (tests/wp) deletes
// the attachments whose title starts with «prueba-»; any other name is left
// behind in the media library.
before(async () => {
  await reset();
  await saveCompany({ allow_svg: true });
});

test("an SVG with <script> is uploaded by an administrator SANITIZED: no script, no on*, with its dimensions", async () => {
  const r = await upload("prueba-logo.svg", "image/svg+xml", HOSTILE);
  assert.equal(r.status, 201, r.text);
  assert.equal(r.json.mime_type, "image/svg+xml");
  assert.equal(r.json.media_details.width, 120);
  assert.equal(r.json.media_details.height, 60);

  const stored = await (await fetch(r.json.source_url)).text();
  assert.match(stored, /<rect width="120" height="60" fill="#c00"\/>/, "the drawing stays");
  for (const bad of ["<script", "alert", "onload", "foreignObject"]) assert.ok(!stored.includes(bad), `the stored file still has «${bad}»`);
});

test("through the API's other path (raw body = sideload) it also comes out sanitized", async () => {
  const r = await wp("/media", {
    method: "POST",
    raw: HOSTILE,
    headers: { "Content-Type": "image/svg+xml", "Content-Disposition": 'attachment; filename="prueba-crudo.svg"' },
  });
  assert.equal(r.status, 201, r.text);
  const stored = await (await fetch(r.json.source_url)).text();
  assert.ok(!stored.includes("<script"), "sideload not sanitized");
});

test("the media library (wp.media) receives the SVG's width, height and full size", async () => {
  const r = await upload("prueba-medidas.svg", "image/svg+xml", '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 300 100"><circle cx="50" cy="50" r="40"/></svg>');
  assert.equal(r.status, 201, r.text);
  assert.equal(r.json.media_details.width, 300);
  assert.equal(r.json.media_details.sizes.full.width, 300);
  assert.equal(r.json.media_details.sizes.full.height, 100);
});

test("an author user can NOT upload SVG (even with allow_svg enabled); a PNG yes", async () => {
  const r = await upload("prueba-autor.svg", "image/svg+xml", '<svg xmlns="http://www.w3.org/2000/svg"/>', "author");
  assertDenied(r);
  const ok = await upload("prueba-autor.png", "image/png", png(), "author");
  assert.equal(ok.status, 201, "the author can upload files: the no is because of the SVG");
});

test("with allow_svg disabled, not even the administrator can", async () => {
  await saveCompany({ allow_svg: false });
  const r = await upload("prueba-off.svg", "image/svg+xml", '<svg xmlns="http://www.w3.org/2000/svg"/>');
  assertDenied(r);
});
