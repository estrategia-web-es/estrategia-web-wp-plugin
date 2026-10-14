// NIVEL: 2 — WordPress de Playground, porque ESCRIBE: sube logos, crea entradas con imagen destacada y pinta una Consulta como las fichas de cliente de Lavandería.
//
// Módulo featured-images:
// - un core/post-featured-image de ancho fijo (82px) lleva sizes="82px" (con
//   «auto, » si es diferida), también dentro de un carrusel, que antes le
//   ponía el suyo;
// - el srcset tiene el candidato pequeño de 200 px (estrategia-web-small);
// - alt="" cuando el alt repite el título que la ficha pinta al lado; se
//   queda si describe otra cosa, si la plantilla no pinta el título o si la
//   imagen es un enlace.
import { test, before, after } from "node:test";
import assert from "node:assert/strict";
import { reset, upload, png, seedPage, html, wp } from "../../../tests/lib.mjs";

const RUN = Date.now().toString(36);
const posts = [];

const makePost = async (title, alt) => {
  const media = await upload(`logo-${RUN}-${posts.length}.png`, "image/png", png(300, 224));
  assert.equal(media.status, 201, media.text);
  await wp(`/media/${media.json.id}`, { method: "POST", body: { alt_text: alt } });
  const r = await wp("/posts", { method: "POST", body: { title, status: "publish", featured_media: media.json.id, content: RUN } });
  assert.equal(r.status, 201, r.text);
  posts.push(r.json.id);
  return { media: media.json, post: r.json };
};

const query = (inner, id) =>
  `<!-- wp:query {"queryId":${id},"query":{"perPage":10,"postType":"post","search":"${RUN}","order":"asc","orderBy":"title","inherit":false}} --><div class="wp-block-query"><!-- wp:post-template -->${inner}<!-- /wp:post-template --></div><!-- /wp:query -->`;
const chip = (image = '{"width":"82px","height":"54px","sizeSlug":"medium"}', title = true) =>
  `<!-- wp:group {"className":"chip"} --><div class="wp-block-group chip"><!-- wp:post-featured-image ${image} /-->${title ? '<!-- wp:post-title {"level":0} /-->' : ""}</div><!-- /wp:group -->`;

/** The <img class="wp-post-image"> of each list, by the wrapper class. */
const imgs = (text, marker) => {
  const at = text.indexOf(marker);
  assert.ok(at >= 0, "falta " + marker);
  const end = text.indexOf("</ul>", at);
  return [...text.slice(at, end).matchAll(/<img [^>]*wp-post-image[^>]*>/g)].map((m) => m[0]);
};
const attr = (tag, name) => tag.match(new RegExp(`\\s${name}="([^"]*)"`))?.[1];

let page;
before(async () => {
  await reset();
  await makePost(`Hotel Uno ${RUN}`, `Hotel Uno ${RUN}`);
  await makePost(`Hotel Dos ${RUN}`, `Fachada del hotel con palmeras`);
  page = await seedPage(
    `<!-- wp:group {"className":"lista-a"} --><div class="wp-block-group lista-a">${query(chip(), 31)}</div><!-- /wp:group -->` +
      `<!-- wp:group {"className":"lista-b"} --><div class="wp-block-group lista-b">${query(chip(undefined, false), 32)}</div><!-- /wp:group -->` +
      `<!-- wp:group {"className":"lista-c"} --><div class="wp-block-group lista-c">${query(chip('{"isLink":true,"width":"82px"}'), 33)}</div><!-- /wp:group -->` +
      `<!-- wp:estrategia-web/carousel {"label":"Clientes","className":"lista-d","perViewMobile":1,"perViewTablet":1,"perViewDesktop":1} -->${query(chip(), 34)}<!-- /wp:estrategia-web/carousel -->`,
    `fichas-${RUN}`
  );
});
after(async () => {
  for (const id of posts) await wp(`/posts/${id}?force=true`, { method: "DELETE" });
});

test("featured-images: ancho fijo en px → sizes de ese ancho, con el candidato de 200 en el srcset", async () => {
  const { text } = await html(page.url);
  for (const tag of imgs(text, "lista-a")) {
    assert.match(attr(tag, "sizes"), /^(auto, )?82px$/, tag);
    assert.match(attr(tag, "srcset"), /-200x149\.\w+ 200w/, "falta el tamaño pequeño: " + tag);
  }
});

test("featured-images: dentro del carrusel también (el carrusel respeta un sizes fijo)", async () => {
  const { text } = await html(page.url);
  const list = imgs(text, "lista-d");
  assert.equal(list.length, 2);
  for (const tag of list) assert.match(attr(tag, "sizes"), /^(auto, )?82px$/, tag);
});

test("featured-images: alt vacío SOLO si repite el título que la ficha pinta al lado", async () => {
  const { text } = await html(page.url);
  // Orden por título: Dos (alt que describe la foto), Uno (alt = título).
  const alts = (marker) => imgs(text, marker).map((tag) => attr(tag, "alt"));
  assert.deepEqual(alts("lista-a"), ["Fachada del hotel con palmeras", ""], "solo pasa a decorativo el alt que repite el título; el que describe la foto no se toca");
  assert.deepEqual(alts("lista-b"), ["Fachada del hotel con palmeras", `Hotel Uno ${RUN}`], "sin título en la plantilla, el alt es lo único que lo nombra");
  // Con isLink el núcleo pone de alt el TÍTULO (es el nombre del enlace): se queda.
  assert.deepEqual(alts("lista-c"), [`Hotel Dos ${RUN}`, `Hotel Uno ${RUN}`], "una imagen enlace conserva su alt (es el nombre del enlace)");
});
