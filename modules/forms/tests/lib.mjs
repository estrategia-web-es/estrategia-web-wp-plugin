// NIVEL: BANCO DE TRABAJO — not a test. Shared helpers for the level 2 and 3 tests.
//
// Talks to the Playground WordPress that tests/run.mjs at the plugin ROOT
// starts (port 9404, the same for all modules since 1.1.0; before that, 9402)
// and to the test routes in
// modules/forms/tests/wp/estrategia-web-forms-test.php.
import { createRequire } from "node:module";

export const BASE = process.env.AF_BASE || process.env.AS_BASE || "http://127.0.0.1:9404";

// Playwright without its own node_modules: the one in portal-hs (CLAUDE.md, 3.9).
export const playwright = () => createRequire("E:/Repositories/portal-hs/package.json")("playwright");

export async function testApi(path, { method = "GET", body } = {}) {
  const res = await fetch(BASE + "/wp-json/estrategia-web-forms-test/v1" + path, {
    method,
    headers: body ? { "Content-Type": "application/json" } : {},
    body: body ? JSON.stringify(body) : undefined,
  });
  if (!res.ok) throw new Error(`${method} ${path} -> ${res.status} ${await res.text()}`);
  return res.json();
}

// First the CORE reset (tests/wp/estrategia-web-site-test.php) and
// then our own, both in ONE request since 28-10-2026 (this module's /reset
// runs the core one first). Since 1.1.0 all modules share WordPress, and whatever
// another test leaves behind stays: measured on 14-10-2026, the cookie notice
// seeded by consent-browser.test.mjs was still open here, its «Guardar»
// button matched button[type=submit] and the four tests in
// browser.test.mjs hung waiting. Order matters: ours goes
// AFTER because it sets «estrategia_web_test_suite = forms» (the language).
export const reset = () => seed();
/**
 * reset() AND the file's seed in ONE request (28-10-2026: each request to the
 * test WordPress costs ~1,3 s whatever it does). The options (core reset,
 * en_US marker, locale, settings, form posts, pages with «{{id:<key>}}»,
 * WPML translations, log rows) and what it returns: the /reset route in
 * wp/estrategia-web-forms-test.php.
 *   const { pages } = await seed({ pages: [{ key: "contact", slug: "contacto", content: FORM }] });
 */
export const seed = (options = {}) => testApi("/reset", { method: "POST", body: options });
export const settings = (s) => testApi("/settings", { method: "POST", body: s });
export const mails = () => testApi("/mails");
export const submissions = () => testApi("/submissions");
/** { mails, submissions } in one request. */
export const state = () => testApi("/state");

// The notification recipients: what must NEVER appear in the public HTML.
export const TEAM = "equipo@ejemplo.test";
export const FAIL = "fail@ejemplo.test";

// The fixed markers, in English: the old Spanish names stopped being aliases
// in 2.0.1 (modules/forms/tests/marker-migration.test.mjs renames them).
export const FORM = `<!-- wp:estrategia-web-forms/form {"formId":"contacto","title":"Contacto"} -->
<!-- wp:estrategia-web-forms/text {"label":"Nombre","name":"nombre","required":true,"width":"half"} /-->
<!-- wp:estrategia-web-forms/email {"label":"Correo","name":"email","required":true,"width":"half"} /-->
<!-- wp:estrategia-web-forms/tel {"label":"Teléfono","name":"telefono"} /-->
<!-- wp:estrategia-web-forms/select {"label":"Servicio","name":"servicio","options":"Lavado\\nPlanchado"} /-->
<!-- wp:estrategia-web-forms/textarea {"label":"Mensaje","name":"mensaje","required":true} /-->
<!-- wp:estrategia-web-forms/checkbox {"name":"privacidad","consent":true} /-->
<!-- wp:estrategia-web-forms/submit {"text":"Enviar consulta"} /-->
<!-- wp:estrategia-web-forms/notification {"label":"Aviso al equipo","to":"${TEAM}","replyTo":"{{email}}","subject":"Nuevo mensaje de {{nombre}}","body":"Desde {{page_url}} el {{submission_date}} en {{site_name}}:\\n\\n{{all_fields}}"} /-->
<!-- wp:estrategia-web-forms/notification {"label":"Acuse al remitente","to":"{{email}}","subject":"Hemos recibido tu mensaje","body":"Hola {{nombre}}, gracias por escribir.\\nServicio: {{servicio}}\\nDesde {{ Page_URL }} el {{submission_date}} en {{site_name}}:\\n{{all_fields}}"} /-->
<!-- wp:estrategia-web-forms/notification {"label":"Aviso que falla","to":"${FAIL}","subject":"Copia"} /-->
<!-- /wp:estrategia-web-forms/form -->`;

export async function seedPage(content = FORM, slug = "contacto") {
  return testApi("/page", { method: "POST", body: { slug, title: "Contacto", content } });
}

/** The hidden fields of the rendered form (_af_def, _af_token, _af_page). */
export function hiddenFields(html) {
  const out = {};
  for (const m of html.matchAll(/<input type="hidden" name="(_af_[a-z]+)" value="([^"]*)"/g)) out[m[1]] = m[2].replace(/&amp;/g, "&");
  return out;
}

export async function loadForm(url) {
  const html = await (await fetch(url)).text();
  return { html, hidden: hiddenFields(html) };
}

/**
 * Submission as the JS would do it (JSON response) or as a browser without JS (redirect).
 * { state: true } (JS only): the answer also brings `state` = { mails, submissions } as they are when
 * the submission ends (wp/estrategia-web-forms-test.php, «X-EW-Forms-Test-State»): no state() after it.
 */
export async function submit(hidden, fields, { js = true, extra = {}, state: withState = false } = {}) {
  const body = new URLSearchParams();
  for (const [k, v] of Object.entries(hidden)) body.append(k, v);
  for (const [k, v] of Object.entries(fields)) body.append(`fields[${k}]`, v);
  for (const [k, v] of Object.entries(extra)) body.append(k, v);
  if (js) body.append("_af_js", "1");
  const res = await fetch(BASE + "/wp-json/estrategia-web-forms/v1/submit", {
    method: "POST",
    body,
    redirect: "manual",
    headers: { "User-Agent": "estrategia-web-forms-test/1.0", ...(withState ? { "X-EW-Forms-Test-State": "1" } : {}) },
  });
  const json = js ? await res.json() : null;
  const state = json?._test_state ?? null;
  if (json) delete json._test_state;
  return { status: res.status, location: res.headers.get("location"), json, state };
}

export const GOOD = {
  nombre: "Ana",
  email: "ana@ejemplo.test",
  telefono: "+34 600 000 000",
  servicio: "Planchado",
  mensaje: "Hola,\nquería información.",
  privacidad: "1",
};

/**
 * The minimum fill time IS what is being measured (Armazón, row 7: the only
 * legitimate fixed wait). The tests set min_seconds = 1.
 */
export const waitMinTime = () => new Promise((r) => setTimeout(r, 1100));
