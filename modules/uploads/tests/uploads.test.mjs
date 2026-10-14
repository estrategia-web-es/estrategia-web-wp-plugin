// NIVEL: 2 — WordPress de Playground, porque ESCRIBE: sube fotos a la mediateca por REST y el editor de imágenes del PHP de verdad las reduce.
//
// Módulo uploads (sustituye al MU-plugin de subidas de Lavandería):
// - una foto de 3000×2000 queda en 2560 de ancho, sin original_image en el
//   meta y SIN el fichero original en el disco;
// - con «Borrar el original» apagado, el original se queda;
// - con el umbral cambiado a 1200, la reducida sale de 1200;
// - los ajustes se leen y se guardan por /wp/v2/settings (show_in_rest).
import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { reset, testApi, upload, wp, BASE } from "../../../tests/lib.mjs";

const RUN = Date.now().toString(36);
const jpeg = async (w, h) => Buffer.from((await testApi(`/jpeg?w=${w}&h=${h}`)).base64, "base64");
const setUploads = async (value) => {
  const r = await wp("/settings", { method: "POST", body: { estrategia_web_uploads: value } });
  assert.equal(r.status, 200, r.text);
  return r.json.estrategia_web_uploads;
};
// Si la URL sirve una imagen de verdad. Playground contesta 200 con una
// página de WordPress a lo que no existe: se mira el tipo.
const isImage = async (url) => {
  const res = await fetch(url);
  return res.ok && (res.headers.get("content-type") || "").startsWith("image/");
};

before(reset);
after(() => setUploads({ max_mb: 20, threshold: 2560, delete_original: true }));

test("uploads: por defecto 20 MB / 2560 px / borrar original, y se ven por REST", async () => {
  const r = await wp("/settings");
  assert.deepEqual(r.json.estrategia_web_uploads, { max_mb: 20, threshold: 2560, delete_original: true });
});

test("uploads: una foto de 3000×2000 queda en 2560, sin original_image y sin el original en el disco", async () => {
  const r = await upload(`subida-${RUN}.jpg`, "image/jpeg", await jpeg(3000, 2000));
  assert.equal(r.status, 201, r.text);
  const d = r.json.media_details;
  assert.equal(d.width, 2560);
  assert.equal(d.original_image, undefined, "el meta no debe apuntar al original");
  assert.ok(await isImage(r.json.source_url), "la reducida se sirve: " + r.json.source_url);
  const dir = r.json.source_url.slice(0, r.json.source_url.lastIndexOf("/") + 1);
  assert.equal(await isImage(dir + `subida-${RUN}.jpg`), false, "el original subido ya no está en el disco");
});

test("uploads: con «Borrar el original» apagado, el original se queda", async () => {
  await setUploads({ delete_original: false });
  const r = await upload(`guarda-${RUN}.jpg`, "image/jpeg", await jpeg(3000, 2000));
  assert.equal(r.status, 201, r.text);
  assert.equal(r.json.media_details.original_image, `guarda-${RUN}.jpg`);
  const dir = r.json.source_url.slice(0, r.json.source_url.lastIndexOf("/") + 1);
  assert.ok(await isImage(dir + `guarda-${RUN}.jpg`), "el original sigue sirviéndose");
});

test("uploads: el umbral es configurable (1200 px)", async () => {
  const saved = await setUploads({ threshold: 1200, delete_original: true });
  assert.equal(saved.threshold, 1200);
  const r = await upload(`umbral-${RUN}.jpg`, "image/jpeg", await jpeg(2000, 1000));
  assert.equal(r.status, 201, r.text);
  assert.equal(r.json.media_details.width, 1200);
});

test("uploads: el límite de subida es el del ajuste (lo anuncia la pantalla de subir medios)", async () => {
  await setUploads({ max_mb: 7 });
  // Un navegador nuevo no tiene la sesión de --login: se entra por wp-login
  // con el admin de Playground (admin / password).
  const { chromium } = (await import("../../../tests/lib.mjs")).playwright();
  const browser = await chromium.launch();
  try {
    const page = await browser.newPage();
    await page.goto(BASE + "/wp-login.php");
    await page.fill("#user_login", "admin");
    await page.fill("#user_pass", "password");
    await Promise.all([page.waitForURL(/wp-admin/), page.click("#wp-submit")]);
    await page.goto(BASE + "/wp-admin/media-new.php");
    const text = await page.locator("body").innerText();
    assert.match(text, /7 MB/, "la pantalla debe anunciar 7 MB");
  } finally {
    await browser.close();
  }
});
