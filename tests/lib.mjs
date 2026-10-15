// NIVEL: BANCO DE TRABAJO — not a test. Shared helpers for the level 2 and 3 tests.
//
// Talks to the Playground WordPress started by tests/run.mjs (port 9404):
// with the real REST API (/wp/v2/*) as administrator or as author
// (application passwords created by tests/wp/estrategia-web-site-test.php),
// and with the test routes only for what is not under test (resetting,
// seeding a page, looking at the raw database row).
import { createRequire } from "node:module";
import zlib from "node:zlib";
import fs from "node:fs";

export const BASE = process.env.AS_BASE || "http://127.0.0.1:9404";

// The REGISTERED name of a post type or taxonomy of the site module (1.2.0):
// type("client") → "eweb_client" (also its REST route). The prefix is read
// from Type_Names so that no test hard-codes it.
export const PREFIX = fs
  .readFileSync(new URL("../modules/site/includes/class-type-names.php", import.meta.url), "utf8")
  .match(/const PREFIX = '([^']*)'/)[1];
export const type = (key) => PREFIX + key;

// Playwright without its own node_modules: portal-hs's (CLAUDE.md, 3.9).
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
/** { administrator: {user, password}, author: {user, password} } — created once per process. */
export async function credentials() {
  users ??= await testApi("/users", { method: "POST" });
  return users;
}

/**
 * Request to /wp/v2 as a role. Returns { status, json }. Does not throw on
 * 4xx: some tests expect a «no».
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

/** Saves company details through REST (merging with what is there) and returns what was stored. */
export async function saveCompany(data) {
  const r = await wp("/settings", { method: "POST", body: { estrategia_web_site: data } });
  if (r.status !== 200) throw new Error("save settings -> " + r.status + " " + r.text);
  return r.json.estrategia_web_site;
}

/** Uploads a file to the media library as multipart (the editor's path). */
export async function upload(name, type, content, as = "administrator") {
  const form = new FormData();
  form.append("file", new Blob([content], { type }), name);
  form.append("title", "prueba-" + name);
  return wp("/media", { as, method: "POST", raw: form });
}

/** Valid w×h PNG of one color (for featured images). Built here: no binary files in the repo. */
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
