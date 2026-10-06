// NIVEL: 2 — WordPress de Playground, porque ESCRIBE: sube imágenes a la mediateca por REST y los tamaños los genera el editor de imágenes del PHP de verdad.
//
// WebP (0.5.1, includes/class-webp.php):
// - el editor de este PHP escribe WebP (si no, lo demás no probaría nada);
// - un JPEG y un PNG subidos generan sus tamaños en WebP, y también el
//   fichero principal («full»): el subido queda como original_image;
// - con el filtro armazon_site_webp a false, los tamaños salen en JPEG;
// - LA TRAMPA, con lo ya subido: al regenerar (lo que hace `wp media
//   regenerate`, imitado por la ruta de pruebas) las URL guardadas en el
//   contenido NO cambian, pero el meta sí (el principal pasa a .webp). La
//   URL .jpg principal sigue sirviendo pero SE QUEDA SIN SRCSET (el JPEG
//   entero a cualquier ancho); la de un tamaño intermedio (…-768x480.jpg)
//   apunta a un fichero borrado. Volviendo a escribir la URL actual de la
//   mediateca, vuelve el srcset en WebP.
//
// Vista fallar el 06-10-2026 antes de escribir class-webp.php: los tamaños
// salían en .jpg. Y la primera versión de esta prueba suponía que el
// original se quedaba en JPEG con su srcset en WebP: falló, y así se supo
// cómo es de verdad la trampa.
import { test, before } from "node:test";
import assert from "node:assert/strict";
import { reset, testApi, upload, png, seedPage, html, wp } from "./lib.mjs";

// Nombres únicos por pasada: Playground persiste entre pasadas y un nombre
// repetido sale con sufijo (-1) o choca con ficheros de la pasada anterior.
const RUN = Date.now().toString(36);
const jpeg = async (w = 1600, h = 1000) => Buffer.from((await testApi(`/jpeg?w=${w}&h=${h}`)).base64, "base64");
const webpSetup = (enabled) => testApi("/webp-setup", { method: "POST", body: { enabled } });
// Si la URL sirve una imagen. No basta con el estado: Playground contesta 200
// con una página de WordPress a lo que no existe.
const servesImage = async (url) => {
  const res = await fetch(url);
  return res.ok && /^image\//.test(res.headers.get("content-type") || "");
};
const sizeFiles = (json) => Object.entries(json.media_details.sizes).filter(([name]) => name !== "full");

before(async () => {
  await reset();
});

test("webp (0.5.1): el editor de imágenes de este PHP escribe WebP", async () => {
  assert.equal((await testApi("/webp-support")).webp, true, "sin soporte de WebP esta prueba no prueba nada");
});

test("webp (0.5.1): un JPEG subido genera sus tamaños en WebP, también el «full»; el .jpg queda como original_image", async () => {
  const r = await upload(`webp-${RUN}-1.jpg`, "image/jpeg", await jpeg());
  assert.equal(r.status, 201, r.text);
  // Medido el 06-10-2026: WordPress convierte también el fichero principal y
  // guarda el subido como original_image (no se borra, pero no se sirve).
  assert.ok(r.json.source_url.endsWith(`/webp-${RUN}-1.webp`), `el fichero principal no pasa a WebP: ${r.json.source_url}`);
  assert.equal(r.json.media_details.original_image, `webp-${RUN}-1.jpg`);
  const sizes = sizeFiles(r.json);
  assert.ok(sizes.length >= 3, `pocos tamaños: ${sizes.map(([n]) => n)}`);
  for (const [name, size] of sizes) {
    assert.match(size.file, /\.webp$/, `${name}: ${size.file} no es WebP`);
    assert.equal(size.mime_type, "image/webp", `${name}: ${size.mime_type}`);
    assert.equal(await servesImage(size.source_url), true, `${size.source_url} no se sirve`);
  }
});

test("webp (0.5.1): un PNG subido también", async () => {
  const r = await upload(`webp-${RUN}-2.png`, "image/png", png(1200, 800, [30, 120, 200]));
  assert.equal(r.status, 201, r.text);
  for (const [name, size] of sizeFiles(r.json)) {
    assert.match(size.file, /\.webp$/, `${name}: ${size.file} no es WebP`);
  }
});

test("webp (0.5.1): con el filtro armazon_site_webp a false, los tamaños salen en JPEG", async () => {
  await webpSetup(false);
  try {
    const r = await upload(`webp-${RUN}-3.jpg`, "image/jpeg", await jpeg());
    assert.equal(r.status, 201, r.text);
    for (const [name, size] of sizeFiles(r.json)) {
      assert.match(size.file, /\.jpg$/, `${name}: ${size.file} no debería ser WebP con el filtro apagado`);
    }
  } finally {
    await webpSetup(null);
  }
});

test("webp (0.5.1): LA TRAMPA — al regenerar lo ya subido, las URL del contenido no cambian: la .jpg principal se queda sin srcset y la de un tamaño intermedio se rompe", async () => {
  // Una foto subida ANTES (WebP apagado), insertada en una página a tamaño
  // completo y a 768 (medium_large), como lo guarda el editor.
  await webpSetup(false);
  const r = await upload(`webp-${RUN}-4.jpg`, "image/jpeg", await jpeg());
  assert.equal(r.status, 201, r.text);
  const id = r.json.id;
  const full = r.json.source_url;
  const mid = r.json.media_details.sizes.medium_large.source_url;
  assert.match(mid, /-768x480\.jpg$/);
  const image = (src, slug) =>
    `<!-- wp:image {"id":${id},"sizeSlug":"${slug}"} --><figure class="wp-block-image size-${slug}"><img src="${src}" alt="${slug}" class="wp-image-${id}"/></figure><!-- /wp:image -->`;
  const page = await seedPage(image(full, "full") + image(mid, "medium_large"), "prueba-webp-regenerar");
  const imgs = async () => {
    const { text } = await html(page.url);
    const tag = (alt) => text.match(new RegExp(`<img[^>]*alt="${alt}"[^>]*>`))?.[0] || "";
    const attr = (t, a) => t.match(new RegExp(`\\s${a}="([^"]*)"`))?.[1] ?? null;
    return Object.fromEntries(["full", "medium_large"].map((alt) => [alt, { src: attr(tag(alt), "src"), srcset: attr(tag(alt), "srcset") }]));
  };
  const before = await imgs();
  assert.ok(before.full.srcset && before.medium_large.srcset, "antes de regenerar, las dos tienen srcset");
  assert.ok(!/\.webp/.test(before.full.srcset), "antes, el srcset es JPEG");

  // Se activa el WebP y se regenera, como `wp media regenerate` sin --skip-delete.
  await webpSetup(null);
  const regen = await testApi(`/regenerate/${id}`, { method: "POST", body: {} });
  assert.match(regen.sizes.medium_large, /-768x480\.webp$/, JSON.stringify(regen));

  assert.ok(regen.file.endsWith(`/webp-${RUN}-4.webp`), `el fichero principal del meta no pasa a .webp: ${regen.file}`);

  const after = await imgs();
  assert.equal(after.full.src, full, "la URL del original guardada en el contenido no cambia");
  assert.equal(after.medium_large.src, mid, "la URL del tamaño intermedio guardada en el contenido no cambia");
  // El .jpg principal sigue en disco (es el original_image) y se sirve...
  assert.equal(await servesImage(after.full.src), true, "el .jpg principal sigue existiendo");
  // ...pero ya no casa con el meta: WordPress no le pone srcset y el
  // navegador baja el JPEG entero a cualquier ancho. PEOR que antes.
  assert.equal(after.full.srcset, null, "esperado: el .jpg principal se queda sin srcset");
  assert.equal(await servesImage(after.medium_large.src), false, "esperado: el .jpg intermedio se borró al regenerar");
  assert.equal(after.medium_large.srcset, null, "esperado: sin srcset, porque el .jpg ya no está en el meta");

  // El arreglo: volver a escribir en el contenido la URL que da ahora la
  // mediateca (source_url, ya .webp). Con ella vuelve el srcset, en WebP.
  const now = (await wp(`/media/${id}`)).json.source_url;
  assert.ok(now.endsWith(`/webp-${RUN}-4.webp`), now);
  await seedPage(image(now, "full"), "prueba-webp-regenerar");
  const fixed = await imgs();
  assert.match(fixed.full.srcset || "", /-768x480\.webp/, "con la URL nueva vuelve el srcset, en WebP");
});
