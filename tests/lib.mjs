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

/**
 * A wp-admin session WITHOUT a browser (1.7.0): logs in through wp-login.php
 * as Playground's admin (admin / password) and keeps the cookies by hand.
 * For level 2 tests of admin screens that are plain HTML and forms
 * (redirects, which tabs exist, options.php saves). Anything that needs the
 * screen's JavaScript is level 3.
 *
 *   const s = await adminSession();
 *   const r = await s.get("/wp-admin/admin.php?page=…");      // { status, location, text }
 *   await s.submit(r.text, "options.php", { "estrategia_web_site[phone]": "1" });
 *
 * - get() does NOT follow redirects: `location` says where it would go.
 * - submit() takes the HTML of a page, finds the FIRST <form> whose action
 *   ends like `action` (default «options.php»), sends ALL its fields as
 *   the browser would (inputs with a name, checked checkboxes/radios only,
 *   textareas, selects' selected option) with `changes` on top, and does not
 *   follow the redirect either. It is a regex reader, enough for WordPress's
 *   own markup: a form with a field it cannot read is a level 3 test.
 * - cookie: the Cookie header, to send it with a plain fetch.
 */
export async function adminSession(user = "admin", password = "password") {
  const jar = new Map([["wordpress_test_cookie", "WP%20Cookie%20check"]]);
  const keep = (res) => {
    for (const c of res.headers.getSetCookie?.() ?? []) {
      const [pair] = c.split(";");
      const i = pair.indexOf("=");
      const name = pair.slice(0, i).trim();
      const value = pair.slice(i + 1).trim();
      if (value === "deleted" || /expires=Thu, 01[- ]Jan[- ]1970/i.test(c)) jar.delete(name);
      else jar.set(name, value);
    }
  };
  const cookie = () => [...jar].map(([k, v]) => `${k}=${v}`).join("; ");
  const request = async (path, init = {}) => {
    const res = await fetch(path.startsWith("http") ? path : BASE + path, { redirect: "manual", ...init, headers: { Cookie: cookie(), ...(init.headers || {}) } });
    keep(res);
    return { status: res.status, location: res.headers.get("location") || "", text: await res.text() };
  };
  const login = await request("/wp-login.php", {
    method: "POST",
    headers: { "Content-Type": "application/x-www-form-urlencoded" },
    body: new URLSearchParams({ log: user, pwd: password, "wp-submit": "Log In", testcookie: "1", redirect_to: BASE + "/wp-admin/" }).toString(),
  });
  if (login.status !== 302 || ![...jar.keys()].some((k) => k.startsWith("wordpress_logged_in_"))) {
    throw new Error("could not log in as " + user + ": " + login.status);
  }
  // As a browser reads an attribute. Numeric entities matter: WordPress writes «&» as &#038; (esc_attr), and
  // without decoding it the referer of settings_fields() lost «&tab=…» (seen in red, 24-10-2026).
  const decode = (s) =>
    s
      .replace(/&#x([0-9a-f]+);/gi, (_, h) => String.fromCodePoint(parseInt(h, 16)))
      .replace(/&#(\d+);/g, (_, d) => String.fromCodePoint(Number(d)))
      .replace(/&quot;/g, '"').replace(/&lt;/g, "<").replace(/&gt;/g, ">").replace(/&amp;/g, "&");
  const attr = (tag, name) => {
    const m = tag.match(new RegExp(`\\s${name}\\s*=\\s*("([^"]*)"|'([^']*)')`, "i"));
    return m ? decode(m[2] ?? m[3]) : null;
  };
  const fields = (form) => {
    const out = [];
    for (const [tag] of form.matchAll(/<input\b[^>]*>/gi)) {
      const name = attr(tag, "name");
      const type = (attr(tag, "type") || "text").toLowerCase();
      if (!name || ["submit", "button", "image", "file", "reset"].includes(type)) continue;
      if ((type === "checkbox" || type === "radio") && !/\schecked\b/i.test(tag)) continue;
      out.push([name, attr(tag, "value") ?? (type === "checkbox" ? "on" : "")]);
    }
    for (const m of form.matchAll(/<textarea\b([^>]*)>([\s\S]*?)<\/textarea>/gi)) {
      const name = attr(m[1], "name");
      if (name) out.push([name, decode(m[2])]);
    }
    for (const m of form.matchAll(/<select\b([^>]*)>([\s\S]*?)<\/select>/gi)) {
      const name = attr(m[1], "name");
      const options = [...m[2].matchAll(/<option\b[^>]*>/gi)].map(([o]) => o);
      const chosen = options.find((o) => /\sselected\b/i.test(o)) ?? options[0];
      if (name && chosen) out.push([name, attr(chosen, "value") ?? ""]);
    }
    return out;
  };
  return {
    cookie,
    get: (path) => request(path),
    async submit(page, action = "options.php", changes = {}) {
      const form = [...page.matchAll(/<form\b([^>]*)>([\s\S]*?)<\/form>/gi)].find((m) => (attr(m[1], "action") || "").endsWith(action));
      if (!form) throw new Error("no form with action …" + action);
      const body = new URLSearchParams();
      const changed = new Set(Object.keys(changes));
      for (const [k, v] of fields(form[2])) if (!changed.has(k)) body.append(k, v);
      for (const [k, v] of Object.entries(changes)) for (const one of [].concat(v)) body.append(k, one);
      const target = attr(form[1], "action");
      return request(target.startsWith("http") ? target : BASE + "/wp-admin/" + target, {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: body.toString(),
      });
    },
  };
}

export const html = async (url) => {
  const res = await fetch(url);
  return { status: res.status, text: await res.text() };
};

/**
 * The page at `url` as a visitor gets it (no cookies, no JavaScript), parsed
 * with jsdom (portal-hs's, like Playwright) to use querySelector on the HTML
 * THE SERVER SENDS. jsdom runs no scripts and lays nothing out: no positions,
 * no computed styles, no innerText; whatever needs those is level 3. The
 * empty VirtualConsole silences its complaints about CSS it does not know.
 * Returns { status, text, document }.
 */
export async function page(url) {
  const { JSDOM, VirtualConsole } = createRequire("E:/Repositories/portal-hs/package.json")("jsdom");
  const res = await fetch(url.startsWith("http") ? url : BASE + url);
  const text = await res.text();
  return { status: res.status, text, document: new JSDOM(text, { url: res.url, virtualConsole: new VirtualConsole() }).window.document };
}

/**
 * REAL WPML (25-10-2026): the routes of tests/wp/estrategia-web-wpml-test.php.
 * Throws with the route's message on any error (a WPML internal that changed
 * answers 500 saying which).
 *
 *   await requireWpml();                               first line of every WPML test: FAILS without it
 *   await wpml("/translate", { kind: "post", type: "page", original: id, title, slug, content })
 */
export async function wpml(path, body) {
  const res = await fetch(BASE + "/wp-json/estrategia-web-wpml-test/v1" + path, {
    method: body ? "POST" : "GET",
    headers: body ? { "Content-Type": "application/json" } : {},
    body: body ? JSON.stringify(body) : undefined,
  });
  const text = await res.text();
  if (!res.ok) throw new Error(`wpml ${path} -> ${res.status} ${text.slice(0, 400)}`);
  return JSON.parse(text);
}

/**
 * The test needs real WPML: it FAILS (never skips) when this WordPress has
 * none, saying how to get it. The rule and why: header of tests/run.mjs, «WPML».
 */
export async function requireWpml({ st = true } = {}) {
  const res = await fetch(BASE + "/wp-json/estrategia-web-wpml-test/v1/state");
  if (res.status === 404) {
    throw new Error("this WordPress has no WPML: this test needs the local copy (tests/run.mjs, «WPML»: EW_WPML_DIR or tests/fixtures/wpml/)");
  }
  const state = await res.json();
  if (st && !state.st) throw new Error("WPML without String Translation: this test needs wpml-string-translation in the local copy");
  if (!state.setup_complete || !state.verified) throw new Error("WPML half installed (tests/wp/setup-wpml.php did not finish): " + JSON.stringify(state));
  return state;
}

/** wp-admin opened once as the administrator: String Translation builds its .mo and its tables only after that (see the WPML tests). */
export async function openWpmlScreen(path = "/wp-admin/admin.php?page=wpml-string-translation%2Fmenu%2Fstring-translation.php") {
  const s = await adminSession();
  // A fresh WPML may redirect the first wp-admin request (its upgrade or welcome steps): followed, as a browser would.
  let r = await s.get(path);
  for (let hops = 0; r.status >= 300 && r.status < 400 && r.location && hops < 5; hops++) r = await s.get(r.location);
  if (r.status !== 200) throw new Error(`${path} -> ${r.status} ${r.location}`);
  return r;
}
