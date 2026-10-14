// NIVEL: BANCO DE TRABAJO — no es una prueba. Ayudantes comunes de las pruebas de nivel 2 y 3.
//
// Habla con el WordPress de Playground que arranca tests/run.mjs de la RAÍZ
// del plugin (puerto 9404, el mismo para todos los módulos desde la 1.1.0;
// antes, 9402) y con las rutas de prueba de
// modules/forms/tests/wp/estrategia-web-forms-test.php.
import { createRequire } from "node:module";

export const BASE = process.env.AF_BASE || process.env.AS_BASE || "http://127.0.0.1:9404";

// Playwright sin node_modules propio: el de portal-hs (CLAUDE.md, 3.9).
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

// Primero el reset del NÚCLEO (tests/wp/estrategia-web-site-test.php) y
// después el propio. Desde la 1.1.0 todos los módulos comparten WordPress, y
// lo que deja otra prueba se queda: medido el 14-10-2026, el aviso de cookies
// que siembra consent-browser.test.mjs seguía abierto aquí, su botón
// «Guardar» casaba con button[type=submit] y las cuatro pruebas de
// browser.test.mjs se quedaban esperando. El orden importa: el propio va
// DESPUÉS porque marca «estrategia_web_test_suite = forms» (el idioma).
export const reset = async () => {
  const core = await fetch(BASE + "/wp-json/estrategia-web-site-test/v1/reset", { method: "POST" });
  if (!core.ok) throw new Error("reset del núcleo -> " + core.status);
  return testApi("/reset", { method: "POST" });
};
export const settings = (s) => testApi("/settings", { method: "POST", body: s });
export const mails = () => testApi("/mails");
export const submissions = () => testApi("/submissions");

// Los destinatarios de los avisos: lo que NUNCA debe aparecer en el HTML público.
export const TEAM = "equipo@ejemplo.test";
export const FAIL = "fail@ejemplo.test";

export const FORM = `<!-- wp:estrategia-web-forms/form {"formId":"contacto","title":"Contacto"} -->
<!-- wp:estrategia-web-forms/text {"label":"Nombre","name":"nombre","required":true,"width":"half"} /-->
<!-- wp:estrategia-web-forms/email {"label":"Correo","name":"email","required":true,"width":"half"} /-->
<!-- wp:estrategia-web-forms/tel {"label":"Teléfono","name":"telefono"} /-->
<!-- wp:estrategia-web-forms/select {"label":"Servicio","name":"servicio","options":"Lavado\\nPlanchado"} /-->
<!-- wp:estrategia-web-forms/textarea {"label":"Mensaje","name":"mensaje","required":true} /-->
<!-- wp:estrategia-web-forms/checkbox {"name":"privacidad","consent":true} /-->
<!-- wp:estrategia-web-forms/submit {"text":"Enviar consulta"} /-->
<!-- wp:estrategia-web-forms/notification {"label":"Aviso al equipo","to":"${TEAM}","replyTo":"{{email}}","subject":"Nuevo mensaje de {{nombre}}","body":"Desde {{url_pagina}} el {{fecha}} en {{nombre_sitio}}:\\n\\n{{todos_los_campos}}"} /-->
<!-- wp:estrategia-web-forms/notification {"label":"Acuse al remitente","to":"{{email}}","subject":"Hemos recibido tu mensaje","body":"Hola {{nombre}}, gracias por escribir.\\nServicio: {{servicio}}"} /-->
<!-- wp:estrategia-web-forms/notification {"label":"Aviso que falla","to":"${FAIL}","subject":"Copia"} /-->
<!-- /wp:estrategia-web-forms/form -->`;

export async function seedPage(content = FORM, slug = "contacto") {
  return testApi("/page", { method: "POST", body: { slug, title: "Contacto", content } });
}

/** Los campos ocultos del formulario pintado (_af_def, _af_token, _af_page). */
export function hiddenFields(html) {
  const out = {};
  for (const m of html.matchAll(/<input type="hidden" name="(_af_[a-z]+)" value="([^"]*)"/g)) out[m[1]] = m[2].replace(/&amp;/g, "&");
  return out;
}

export async function loadForm(url) {
  const html = await (await fetch(url)).text();
  return { html, hidden: hiddenFields(html) };
}

/** Envío como lo haría el JS (respuesta JSON) o como un navegador sin JS (redirección). */
export async function submit(hidden, fields, { js = true, extra = {} } = {}) {
  const body = new URLSearchParams();
  for (const [k, v] of Object.entries(hidden)) body.append(k, v);
  for (const [k, v] of Object.entries(fields)) body.append(`fields[${k}]`, v);
  for (const [k, v] of Object.entries(extra)) body.append(k, v);
  if (js) body.append("_af_js", "1");
  const res = await fetch(BASE + "/wp-json/estrategia-web-forms/v1/submit", {
    method: "POST",
    body,
    redirect: "manual",
    headers: { "User-Agent": "estrategia-web-forms-test/1.0" },
  });
  return { status: res.status, location: res.headers.get("location"), json: js ? await res.json() : null };
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
 * El tiempo mínimo de relleno ES lo que se mide (Armazón, fila 7: la única
 * espera fija legítima). Las pruebas ponen min_seconds = 1.
 */
export const waitMinTime = () => new Promise((r) => setTimeout(r, 1100));
