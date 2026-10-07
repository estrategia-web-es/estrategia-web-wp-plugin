// NIVEL: BANCO DE TRABAJO — no es una prueba. Ayudantes comunes de las pruebas de nivel 2 y 3.
//
// Habla con el WordPress de Playground que arranca tests/run.mjs (puerto
// 9404): con la API REST de verdad (/wp/v2/*) como administrador o como autor
// (contraseñas de aplicación que crea tests/wp/estrategia-web-site-test.php), y con
// las rutas de prueba solo para lo que no es lo probado (resetear, sembrar
// una página, mirar la fila cruda de la base de datos).
import { createRequire } from "node:module";
import zlib from "node:zlib";

export const BASE = process.env.AS_BASE || "http://127.0.0.1:9404";

// Playwright sin node_modules propio: el de portal-hs (CLAUDE.md, 3.9).
export const playwright = () => createRequire("E:/Repositories/portal-hs/package.json")("playwright");

export async function testApi(path, { method = "GET", body } = {}) {
  const res = await fetch(BASE + "/wp-json/estrategia-web-site-test/v1" + path, {
    method,
    headers: body ? { "Content-Type": "application/json" } : {},
    body: body ? JSON.stringify(body) : undefined,
  });
  if (!res.ok) throw new Error(`${method} ${path} -> ${res.status} ${await res.text()}`);
  return res.json();
}

export const reset = () => testApi("/reset", { method: "POST" });
export const seedPage = (content, slug = "prueba") => testApi("/page", { method: "POST", body: { slug, title: "Prueba", content } });
export const rawPost = (id) => testApi(`/raw-post/${id}`);

let users;
/** { administrator: {user, password}, author: {user, password} } — se crean una vez por proceso. */
export async function credentials() {
  users ??= await testApi("/users", { method: "POST" });
  return users;
}

/**
 * Petición a /wp/v2 como un rol. Devuelve { status, json }. No lanza en 4xx:
 * hay pruebas que esperan un «no».
 */
export async function wp(path, { as = "administrator", method = "GET", body, raw, headers = {} } = {}) {
  const who = (await credentials())[as];
  const auth = as ? { Authorization: "Basic " + Buffer.from(`${who.user}:${who.password}`).toString("base64") } : {};
  const res = await fetch(BASE + "/wp-json/wp/v2" + path, {
    method,
    headers: { ...auth, ...(body ? { "Content-Type": "application/json" } : {}), ...headers },
    body: raw ?? (body ? JSON.stringify(body) : undefined),
  });
  const text = await res.text();
  let json = null;
  try {
    json = JSON.parse(text);
  } catch {}
  return { status: res.status, json, text };
}

/** Guarda datos de la empresa por REST (fusiona con lo que haya) y devuelve lo que quedó. */
export async function saveCompany(data) {
  const r = await wp("/settings", { method: "POST", body: { estrategia_web_site: data } });
  if (r.status !== 200) throw new Error("guardar ajustes -> " + r.status + " " + r.text);
  return r.json.estrategia_web_site;
}

/** Sube un fichero a la mediateca como multipart (el camino del editor). */
export async function upload(name, type, content, as = "administrator") {
  const form = new FormData();
  form.append("file", new Blob([content], { type }), name);
  form.append("title", "prueba-" + name);
  return wp("/media", { as, method: "POST", raw: form });
}

/** PNG válido de w×h de un color (para imágenes destacadas). Hecho aquí: sin ficheros binarios en el repo. */
export function png(w = 4, h = 2, [r, g, b] = [200, 30, 30]) {
  const chunk = (type, data) => {
    const len = Buffer.alloc(4);
    len.writeUInt32BE(data.length);
    const td = Buffer.concat([Buffer.from(type), data]);
    const crc = Buffer.alloc(4);
    crc.writeUInt32BE(zlib.crc32(td));
    return Buffer.concat([len, td, crc]);
  };
  const ihdr = Buffer.alloc(13);
  ihdr.writeUInt32BE(w, 0);
  ihdr.writeUInt32BE(h, 4);
  ihdr[8] = 8; // bits
  ihdr[9] = 2; // RGB
  const row = Buffer.concat([Buffer.from([0]), Buffer.alloc(w * 3).map((_, i) => [r, g, b][i % 3])]);
  const data = zlib.deflateSync(Buffer.concat(Array(h).fill(row)));
  return Buffer.concat([Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]), chunk("IHDR", ihdr), chunk("IDAT", data), chunk("IEND", Buffer.alloc(0))]);
}

export const html = async (url) => {
  const res = await fetch(url);
  return { status: res.status, text: await res.text() };
};
